<?php
// RTEX FSC is kept outside driver_payouts.tss_pay so brokerage uses base freight only.
function rtex_fsc_week(string $date): string {
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$day || $day->format('Y-m-d') !== $date) throw new InvalidArgumentException('Select a valid FSC payout week.');
    return $day->modify('-' . $day->format('w') . ' days')->format('Y-m-d');
}

function rtex_fsc_percent($value): float {
    $raw = trim((string)$value);
    if ($raw === '' || !is_numeric($raw) || !is_finite((float)$raw) || (float)$raw < 0 || (float)$raw > 100) {
        throw new InvalidArgumentException('FSC percentages must be between 0 and 100.');
    }
    return round((float)$raw, 2);
}

function rtex_fsc_amount(float $baseAmount, float $percentage): float {
    return round($baseAmount * rtex_fsc_percent($percentage) / 100, 2);
}

function rtex_fsc_ensure_schema(mysqli $db): void {
    if (!$db->query("CREATE TABLE IF NOT EXISTS rtex_fsc_settings (
        payout_week_start DATE NOT NULL PRIMARY KEY,
        driver_fsc_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
        invoice_fsc_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) throw new RuntimeException($db->error);
}

function rtex_fsc_settings(mysqli $db, string $week): array {
    $default = ['driver_fsc_rate'=>0.0,'invoice_fsc_rate'=>0.0];
    $check = $db->query("SHOW TABLES LIKE 'rtex_fsc_settings'");
    if (!$check) return $default;
    $exists = $check->num_rows > 0;
    $check->close();
    if (!$exists) return $default;
    $week = rtex_fsc_week($week);
    $stmt = $db->prepare('SELECT driver_fsc_rate,invoice_fsc_rate FROM rtex_fsc_settings WHERE payout_week_start=?');
    $stmt->bind_param('s',$week);
    $stmt->execute();
    $stmt->bind_result($driver,$invoice);
    if ($stmt->fetch()) $default = ['driver_fsc_rate'=>(float)$driver,'invoice_fsc_rate'=>(float)$invoice];
    $stmt->close();
    return $default;
}

function rtex_save_fsc_settings(mysqli $db, string $week, $driver, $invoice): void {
    $week = rtex_fsc_week($week);
    $driver = rtex_fsc_percent($driver);
    $invoice = rtex_fsc_percent($invoice);
    $stmt = $db->prepare("INSERT INTO rtex_fsc_settings (payout_week_start,driver_fsc_rate,invoice_fsc_rate) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE driver_fsc_rate=VALUES(driver_fsc_rate),invoice_fsc_rate=VALUES(invoice_fsc_rate),updated_at=NOW()");
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param('sdd',$week,$driver,$invoice);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $stmt->close();
}

function lonestar_driver_rtex_week_fuel_surcharge_total(mysqli $db, int $driverId, string $start, string $end): float {
    if ($driverId <= 0) return 0.0;
    // The linked payout ensures unsaved drafts and removed payout records do not earn FSC.
    $stmt = $db->prepare("SELECT COALESCE(SUM(ROUND(r.total_amount * r.driver_fsc_rate / 100,2)),0)
        FROM rtex_payout_rows r
        JOIN driver_payouts dp ON dp.id=r.driver_payout_id AND dp.vendor_name='RTEX'
        WHERE r.billing_mode='load' AND r.matched_contact_id=? AND r.work_date BETWEEN ? AND ?");
    if (!$stmt) return 0.0;
    $stmt->bind_param('iss',$driverId,$start,$end);
    $stmt->execute();
    $stmt->bind_result($total);
    $value = $stmt->fetch() ? (float)$total : 0.0;
    $stmt->close();
    return round($value,2);
}

// Use the actual statement rows rather than the report week. This also handles
// off-cycle statements whose loads were worked in a different FSC rate week.
function rtex_driver_statement_fsc(mysqli $db, array $payoutRows): array {
    $out = ['total'=>0.0,'load_gross'=>0.0,'broker_fee'=>0.0,'lines'=>[]];
    if (!$payoutRows) return $out;
    $check = $db->query("SHOW TABLES LIKE 'rtex_payout_rows'");
    if (!$check) return $out;
    $hasTable = $check->num_rows > 0;
    $check->close();
    if (!$hasTable) return $out;
    $check = $db->query("SHOW COLUMNS FROM rtex_payout_rows LIKE 'billing_mode'");
    if (!$check) return $out;
    $exists = $check->num_rows > 0;
    $check->close();
    if (!$exists) return $out;
    $stmt = $db->prepare("SELECT total_amount,driver_fsc_rate FROM rtex_payout_rows WHERE billing_mode='load' AND ticket_number=? AND work_date=? LIMIT 1");
    if (!$stmt) return $out;
    foreach ($payoutRows as $row) {
        $date = (string)($row['payout_date'] ?? '');
        $ticket = (string)($row['ticket_number'] ?? '');
        $stmt->bind_param('ss',$ticket,$date);
        $stmt->execute();
        $stmt->bind_result($base,$percentage);
        $found = $stmt->fetch();
        $stmt->free_result();
        if (!$found) continue;
        $amount = rtex_fsc_amount((float)$base,$percentage);
        $out['load_gross'] += (float)$base;
        $out['total'] += $amount;
        if ($amount != 0.0) $out['lines'][] = [
            'date'=>$date,'ticket'=>$ticket,'label'=>'Driver FSC (' . rtrim(rtrim(number_format($percentage,2,'.',''),'0'),'.') . '%)',
            'amount'=>$amount,
        ];
    }
    $stmt->close();
    $out['total'] = round($out['total'],2);
    $out['load_gross'] = round($out['load_gross'],2);
    $broker = lonestar_vendor_broker_fee_settings($db,'rtex');
    if (($broker['fee_mode'] ?? '') === 'percentage') {
        $out['broker_fee'] = round($out['load_gross'] * (float)$broker['fee_value'] / 100,2);
    }
    return $out;
}
