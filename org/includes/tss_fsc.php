<?php
// TSS FSC rules are effective-dated; applied rates are snapshots on LS Detail rows.
function tss_fsc_number($value, string $label, int $precision = 2): float {
    $raw = str_replace(',', '', trim((string)$value));
    if ($raw === '' || !is_numeric($raw) || !is_finite((float)$raw) || (float)$raw < 0 || (float)$raw > 99999999.9999) {
        throw new InvalidArgumentException("{$label} must be a non-negative number.");
    }
    return round((float)$raw, $precision);
}
function tss_fsc_type($value): string {
    $type = strtolower(trim((string)$value));
    if (in_array($type, ['tonnage','ton','tons','per ton','per-ton'], true)) return 'tonnage';
    if (in_array($type, ['mileage','mile','miles','per mile','per-mile'], true)) return 'mileage';
    if ($type === 'none') return 'none';
    throw new InvalidArgumentException('FSC Type must be Tonnage, Mileage, or None.');
}
function tss_fsc_rule(array $input): array {
    $rule = [];
    foreach (['start_date','end_date'] as $field) {
        $date = (string)($input[$field] ?? '');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('Enter valid start and end dates.');
        $rule[$field] = $date;
    }
    if ($rule['end_date'] < $rule['start_date']) throw new InvalidArgumentException('End date must be on or after start date.');
    $rule['fsc_type'] = tss_fsc_type($input['fsc_type'] ?? '');
    if ($rule['fsc_type'] === 'none') throw new InvalidArgumentException('Choose Tonnage or Mileage for a rate rule.');
    $rule['mileage_match'] = (string)($input['mileage_match'] ?? '');
    if (!in_array($rule['mileage_match'], ['exact','range'], true)) throw new InvalidArgumentException('Choose Exact or Range mileage.');
    $rule['min_miles'] = tss_fsc_number($input['min_miles'] ?? '', 'Mileage');
    $rule['max_miles'] = $rule['mileage_match'] === 'exact' ? $rule['min_miles'] : tss_fsc_number($input['max_miles'] ?? '', 'Maximum mileage');
    if ($rule['max_miles'] < $rule['min_miles']) throw new InvalidArgumentException('Maximum mileage must be at least the minimum.');
    $rule['rate'] = tss_fsc_number($input['rate'] ?? '', 'FSC rate', 4);
    return $rule;
}
function tss_fsc_overlaps(array $a, array $b): bool {
    return $a['fsc_type'] === $b['fsc_type'] && $a['start_date'] <= $b['end_date'] && $a['end_date'] >= $b['start_date']
        && $a['min_miles'] <= $b['max_miles'] && $a['max_miles'] >= $b['min_miles'];
}
function tss_fsc_resolve(array $rules, string $date, $miles, $tons, $type): array {
    $type = tss_fsc_type($type);
    if ($type === 'none') return ['type'=>'none','rate'=>0.0,'amount'=>0.0];
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('A valid delivery date is required for FSC.');
    $miles = tss_fsc_number($miles, 'Load mileage');
    $matches = array_values(array_filter($rules, static fn($r) => $r['fsc_type'] === $type && $date >= $r['start_date'] && $date <= $r['end_date'] && $miles >= (float)$r['min_miles'] && $miles <= (float)$r['max_miles']));
    if (count($matches) !== 1) throw new InvalidArgumentException(count($matches) ? 'Multiple FSC rules match this load.' : 'No FSC rule matches this delivery date, mileage, and FSC Type.');
    $quantity = $type === 'tonnage' ? tss_fsc_number($tons, 'Net tons') : $miles;
    $rate = (float)$matches[0]['rate'];
    $amount = round($quantity * $rate, 2);
    if ($amount > 9999999999.99) throw new InvalidArgumentException('FSC amount exceeds the supported range.');
    return ['type'=>$type,'rate'=>$rate,'amount'=>$amount];
}
function tss_fsc_schema(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS tss_fsc_rules (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, start_date DATE NOT NULL, end_date DATE NOT NULL,
        fsc_type VARCHAR(20) NOT NULL, mileage_match VARCHAR(10) NOT NULL,
        min_miles DECIMAL(12,2) NOT NULL, max_miles DECIMAL(12,2) NOT NULL, rate DECIMAL(12,4) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $check = $db->query("SHOW COLUMNS FROM ls_detail_raw LIKE 'fuel_surcharge_amount'");
    if (!$check->num_rows) $db->query('ALTER TABLE ls_detail_raw ADD COLUMN fuel_surcharge_amount DECIMAL(12,2) NULL');
    $check->close();
}
function tss_fsc_rules(mysqli $db): array {
    return $db->query('SELECT * FROM tss_fsc_rules ORDER BY start_date DESC, fsc_type, min_miles')->fetch_all(MYSQLI_ASSOC);
}
function tss_fsc_save_rule(mysqli $db, array $input): void {
    $rule = tss_fsc_rule($input);
    $id = (int)($input['rule_id'] ?? 0);
    // Serialize overlap checks and writes, including when the table is empty.
    if ((int)$db->query("SELECT GET_LOCK('tss_fsc_rules_write',10)")->fetch_row()[0] !== 1) throw new RuntimeException('FSC rules are busy. Please try again.');
    try {
        $found = $id === 0;
        foreach (tss_fsc_rules($db) as $existing) {
            if ((int)$existing['id'] === $id) { $found = true; continue; }
            if (tss_fsc_overlaps($rule, $existing)) throw new InvalidArgumentException('This rule overlaps another rule of the same FSC Type in both dates and mileage.');
        }
        if (!$found) throw new InvalidArgumentException('FSC rule no longer exists. Reload the page.');
        $values = array_values($rule);
        $sql = $id ? 'UPDATE tss_fsc_rules SET start_date=?,end_date=?,fsc_type=?,mileage_match=?,min_miles=?,max_miles=?,rate=? WHERE id=?'
            : 'INSERT INTO tss_fsc_rules(start_date,end_date,fsc_type,mileage_match,min_miles,max_miles,rate) VALUES(?,?,?,?,?,?,?)';
        if ($id) $values[] = $id;
        $stmt = $db->prepare($sql);
        $stmt->bind_param($id ? 'ssssdddi' : 'ssssddd', ...$values);
        $stmt->execute();
        $stmt->close();
    } finally {
        $db->query("SELECT RELEASE_LOCK('tss_fsc_rules_write')");
    }
}
