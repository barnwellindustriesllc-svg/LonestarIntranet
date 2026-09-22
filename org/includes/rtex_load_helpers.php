<?php
require_once __DIR__ . '/rtex_fsc.php';
// RTEX load invoicing. Historical hourly rows keep their original calculation.
function rtex_stmt(mysqli $db, string $sql, string $types = '', array $params = []): mysqli_stmt {
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException($db->error);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        $message = $stmt->error;
        $stmt->close();
        throw new RuntimeException($message);
    }
    return $stmt;
}

function rtex_ensure_auto_increment(mysqli $db, string $table): void {
    if (!in_array($table, ['rtex_payout_rows', 'rtex_job_rates', 'driver_payouts'], true)) {
        throw new InvalidArgumentException('Unsupported RTEX table.');
    }
    $column = $db->query("SHOW COLUMNS FROM {$table} LIKE 'id'")->fetch_assoc();
    if (!$column) throw new RuntimeException("{$table} is missing its id column.");
    if (stripos($column['Extra'], 'auto_increment') !== false) return;
    $type = $column['Type'];
    $keys = $db->query("SHOW INDEX FROM {$table} WHERE Key_name='PRIMARY'")->fetch_all(MYSQLI_ASSOC);
    if (count($keys) !== 1 || $keys[0]['Column_name'] !== 'id' ||
        !preg_match('/^(tinyint|smallint|mediumint|int|bigint)(\(\d+\))?( unsigned)?$/i', $type)) {
        throw new RuntimeException("{$table}.id requires an integer primary key before automatic ID generation can be restored.");
    }
    // Preserve every existing ID, including zero, when rebuilding the column.
    $mode = (string)$db->query('SELECT @@SESSION.sql_mode')->fetch_row()[0];
    $repairMode = $mode === '' ? 'NO_AUTO_VALUE_ON_ZERO' : $mode . ',NO_AUTO_VALUE_ON_ZERO';
    $db->query("SET SESSION sql_mode='" . $db->real_escape_string($repairMode) . "'");
    try {
        $db->query("ALTER TABLE {$table} MODIFY COLUMN id {$type} NOT NULL AUTO_INCREMENT");
    } finally {
        $db->query("SET SESSION sql_mode='" . $db->real_escape_string($mode) . "'");
    }
}

function ensure_rtex_load_schema(mysqli $db): void {
    rtex_fsc_ensure_schema($db);
    $columns = [
        'billing_mode' => "VARCHAR(10) NOT NULL DEFAULT 'hourly'",
        'job_rate_id' => 'INT UNSIGNED NULL',
        'job_name' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'rate_basis' => "VARCHAR(10) NOT NULL DEFAULT 'tonnage'",
        'tons' => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
        'miles' => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
        'provider_name' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'customer_name' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'product_name' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'work_order' => "VARCHAR(80) NOT NULL DEFAULT ''",
        'source_file_name' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'driver_payout_id' => 'BIGINT UNSIGNED NULL',
        'updated_at' => 'DATETIME NULL',
        // NULL for hourly entries, so their existing source-line identity is preserved.
        'load_ticket_number' => "VARCHAR(80) GENERATED ALWAYS AS (CASE WHEN billing_mode = 'load' THEN ticket_number ELSE NULL END) STORED",
    ];
    foreach ($columns as $column => $definition) {
        $check = $db->query("SHOW COLUMNS FROM rtex_payout_rows LIKE '{$column}'");
        if (!$check) throw new RuntimeException($db->error);
        $exists = $check->num_rows > 0;
        $check->close();
        if (!$exists && !$db->query("ALTER TABLE rtex_payout_rows ADD COLUMN {$column} {$definition}")) {
            throw new RuntimeException($db->error);
        }
    }
    $check = $db->query("SHOW INDEX FROM rtex_payout_rows WHERE Key_name = 'uniq_rtex_load_ticket_date'");
    if (!$check) throw new RuntimeException($db->error);
    $exists = $check->num_rows > 0;
    $check->close();
    if (!$exists && !$db->query("ALTER TABLE rtex_payout_rows ADD UNIQUE KEY uniq_rtex_load_ticket_date (load_ticket_number, work_date)")) {
        throw new RuntimeException($db->error);
    }
    if (!$db->query("CREATE TABLE IF NOT EXISTS rtex_job_rates (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        job_name VARCHAR(255) NOT NULL,
        rate_basis VARCHAR(10) NOT NULL DEFAULT 'tonnage',
        rate DECIMAL(10,2) NOT NULL,
        work_order VARCHAR(80) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_rtex_job_name (job_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) throw new RuntimeException($db->error);
    foreach (['rtex_payout_rows', 'rtex_job_rates', 'driver_payouts'] as $table) {
        rtex_ensure_auto_increment($db, $table);
    }
}

function rtex_load_total(string $basis, float $tons, float $miles, float $rate): float {
    if (!in_array($basis, ['tonnage', 'mileage'], true)) throw new InvalidArgumentException('Choose tonnage or mileage.');
    if (!is_finite($rate) || $rate <= 0 || $rate > 99999999.99) throw new InvalidArgumentException('Enter a job rate greater than zero.');
    $quantity = $basis === 'tonnage' ? $tons : $miles;
    if (!is_finite($tons) || !is_finite($miles) || $tons < 0 || $miles < 0 || $tons > 99999999.99 || $miles > 99999999.99 || $quantity <= 0) {
        throw new InvalidArgumentException($basis === 'tonnage' ? 'Enter net US tons greater than zero.' : 'Enter billable miles greater than zero.');
    }
    $total = round($quantity * $rate, 2);
    if ($total <= 0 || $total > 9999999999.99) throw new InvalidArgumentException('The calculated load total is outside the supported range.');
    return $total;
}

function rtex_valid_date(string $date): bool {
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

function rtex_load_row(mysqli $db, int $id, bool $lock = false): ?array {
    $stmt = rtex_stmt($db, "SELECT * FROM rtex_payout_rows WHERE id=? AND billing_mode='load'" . ($lock ? ' FOR UPDATE' : ''), 'i', [$id]);
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function rtex_job_rates(mysqli $db): array {
    $stmt = rtex_stmt($db, 'SELECT * FROM rtex_job_rates ORDER BY job_name');
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function rtex_save_load(mysqli $db, array $input, ?int $id = null): int {
    $db->begin_transaction();
    try {
        $old = $id !== null ? rtex_load_row($db, $id, true) : null;
        if ($id !== null && !$old) throw new InvalidArgumentException('RTEX load row not found.');
        $row = [];
        foreach (['work_date'=>10, 'ticket_number'=>80, 'provider_name'=>255, 'customer_name'=>255,
                  'product_name'=>255, 'job_number'=>50, 'truck_raw'=>50, 'work_order'=>80] as $key => $max) {
            $row[$key] = trim((string)($input[$key] ?? $old[$key] ?? ''));
            if (strlen($row[$key]) > $max) throw new InvalidArgumentException("{$key} is too long.");
        }
        if (!rtex_valid_date($row['work_date'])) throw new InvalidArgumentException('Enter a valid BOL date.');
        if ($row['ticket_number'] === '') throw new InvalidArgumentException('Enter the BOL ticket number.');

        $row['matched_contact_id'] = (int)($input['matched_contact_id'] ?? 0);
        $row['driver_name'] = driver_contact_name($db, $row['matched_contact_id']);
        if ($row['matched_contact_id'] <= 0 || $row['driver_name'] === '') throw new InvalidArgumentException('Select a matched driver before saving the BOL to payouts.');

        $row['job_rate_id'] = (int)($input['job_rate_id'] ?? 0);
        // Preserve the historical rate when editing a saved row unless explicitly reapplied.
        if ($old && $row['job_rate_id'] === (int)$old['job_rate_id'] && empty($input['apply_current_rate'])) {
            foreach (['job_name','rate_basis','rate','work_order'] as $key) $row[$key] = $old[$key];
        } else {
            $stmt = rtex_stmt($db, 'SELECT job_name, rate_basis, rate, work_order FROM rtex_job_rates WHERE id=?', 'i', [$row['job_rate_id']]);
            $job = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$job) throw new InvalidArgumentException('Select a job from the RTEX Job Rate Key.');
            $row = array_merge($row, $job);
        }
        if (array_key_exists('work_order', $input)) $row['work_order'] = trim((string)$input['work_order']);
        if (!empty($input['compact_entry'])) {
            // Resolve units from the server-side job rate, never from a submitted label.
            $input['tons'] = $row['rate_basis'] === 'tonnage' ? ($input['quantity'] ?? 0) : ($old['tons'] ?? 0);
            $input['miles'] = $row['rate_basis'] === 'mileage' ? ($input['quantity'] ?? 0) : ($old['miles'] ?? 0);
        }
        $row['tons'] = round(parse_money($input['tons'] ?? 0), 2);
        $row['miles'] = round(parse_money($input['miles'] ?? 0), 2);
        $row['rate'] = round((float)$row['rate'], 2);
        $row['total_amount'] = rtex_load_total($row['rate_basis'], $row['tons'], $row['miles'], $row['rate']);
        $row['truck_digits'] = digits_only($row['truck_raw']);
        $row['upload_date'] = $old['upload_date'] ?? tss_today();
        $row['source_file_name'] = $old['source_file_name'] ?? substr((string)($input['source_file_name'] ?? ''), 0, 255);

        $stmt = rtex_stmt($db, 'SELECT id FROM rtex_payout_rows WHERE work_date=? AND ticket_number=? AND (? IS NULL OR id<>?) LIMIT 1 FOR UPDATE',
            'ssii', [$row['work_date'], $row['ticket_number'], $id, $id]);
        $duplicate = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($duplicate) throw new InvalidArgumentException('Ticket / BOL "' . $row['ticket_number'] . '" is already recorded for ' . $row['work_date'] . '. Other ticket numbers can use this same date. Edit the existing ticket or enter a different ticket number.');

        $fields = ['work_date','ticket_number','provider_name','customer_name','product_name','job_number','truck_raw',
            'driver_name','truck_digits','job_rate_id','job_name','rate_basis','rate','tons','miles','total_amount',
            'work_order','matched_contact_id','source_file_name'];
        $params = array_map(static fn($field) => $row[$field], $fields);
        $types = 'sssssssssissddddsis';
        if ($old) {
            $sql = 'UPDATE rtex_payout_rows SET ' . implode(',', array_map(static fn($field) => $field . '=?', $fields))
                . ',updated_at=NOW() WHERE id=?';
            $params[] = $id;
            $stmt = rtex_stmt($db, $sql, $types . 'i', $params);
        } else {
            $fields = array_merge($fields, ['upload_date','invoice_date','sheet_name','source_line_no','billing_mode']);
            // Random source identity avoids colliding with Excel and manual hourly rows.
            array_push($params, $row['upload_date'], $row['work_date'], 'BOL-' . bin2hex(random_bytes(12)), 1, 'load');
            $sql = 'INSERT INTO rtex_payout_rows (' . implode(',', $fields) . ') VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')';
            $stmt = rtex_stmt($db, $sql, $types . 'sssis', $params);
            $id = (int)$db->insert_id;
        }
        $stmt->close();

        $payoutId = (int)($old['driver_payout_id'] ?? 0);
        if ($payoutId > 0) {
            $stmt = rtex_stmt($db, "SELECT id FROM driver_payouts WHERE id=? AND vendor_name='RTEX' FOR UPDATE", 'i', [$payoutId]);
            if (!$stmt->get_result()->fetch_assoc()) $payoutId = 0;
            $stmt->close();
        }
        if ($payoutId > 0) {
            $stmt = rtex_stmt($db, "UPDATE driver_payouts SET payout_date=?,ticket_number=?,driver_name=?,tss_pay=?,driver_contact_id=?
                WHERE id=? AND vendor_name='RTEX'", 'sssdii',
                [$row['work_date'],$row['ticket_number'],$row['driver_name'],$row['total_amount'],$row['matched_contact_id'],$payoutId]);
        } else {
            // No upsert: a duplicate payout must fail and roll back, never overwrite another source.
            $stmt = rtex_stmt($db, "INSERT INTO driver_payouts (payout_date,ticket_number,driver_name,vendor_name,tss_pay,upload_date,driver_contact_id)
                VALUES (?,?,?,'RTEX',?,?,?)", 'sssdsi',
                [$row['work_date'],$row['ticket_number'],$row['driver_name'],$row['total_amount'],$row['upload_date'],$row['matched_contact_id']]);
            $payoutId = (int)$db->insert_id;
        }
        $stmt->close();
        $stmt = rtex_stmt($db, 'UPDATE rtex_payout_rows SET driver_payout_id=? WHERE id=?', 'ii', [$payoutId,$id]);
        $stmt->close();
        if (!$db->commit()) throw new RuntimeException($db->error);
        return $id;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function rtex_delete_loads(mysqli $db, array $ids): int {
    $db->begin_transaction();
    try {
        $count = 0;
        foreach (array_unique($ids, SORT_REGULAR) as $rawId) {
            $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
            if ($id === false) throw new InvalidArgumentException('Invalid RTEX load selection.');
            $row = rtex_load_row($db, $id, true);
            if (!$row) throw new InvalidArgumentException('A selected RTEX load no longer exists.');
            if (isset($row['driver_payout_id'])) {
                $stmt = rtex_stmt($db, "DELETE FROM driver_payouts WHERE id=? AND vendor_name='RTEX'", 'i', [(int)$row['driver_payout_id']]);
                $stmt->close();
            }
            $stmt = rtex_stmt($db, "DELETE FROM rtex_payout_rows WHERE id=? AND billing_mode='load'", 'i', [$id]);
            $count += $stmt->affected_rows;
            $stmt->close();
        }
        if (!$db->commit()) throw new RuntimeException($db->error);
        return $count;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

// Parse the HMA/Helmcamp scale-ticket layout. Never treat the first Net number
// as tons: that column contains pounds. Metric tons are also not billable US tons.
function rtex_parse_bol_text(string $text): array {
    $plain = preg_replace('/[ \t]+/', ' ', str_replace("\r", '', $text));
    $row = ['work_date'=>'','ticket_number'=>'','provider_name'=>'','customer_name'=>'',
        'product_name'=>'','job_number'=>'','work_order'=>'','truck_raw'=>'','tons'=>'','miles'=>''];
    if (preg_match('/(?:Ticket|BOL)\s*(?:No\.?|Number|#)?\s*[:#]?\s*([A-Z0-9-]{3,})/i', $plain, $m)) {
        $row['ticket_number'] = $m[1];
    } elseif (preg_match('/^(.*?)(?=\b\d{1,2}\/\d{1,2}\/\d{4}\b)/s', $plain, $header)) {
        // HMA prints an unlabeled ticket at the upper right, beside the quarry.
        // Require one unambiguous candidate and exclude hyphenated phone numbers.
        preg_match_all('/(?<![\d-])\b(\d{5,8})\b(?![\d-])/', $header[1], $tickets);
        if (count($tickets[1]) === 1) $row['ticket_number'] = $tickets[1][0];
    }
    if (preg_match('/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\b/', $plain, $m)) {
        $date = sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]);
        if (rtex_valid_date($date)) $row['work_date'] = $date;
    }
    foreach (['Location'=>'provider_name','Customer'=>'customer_name','Order'=>'job_number','Product'=>'product_name','Vehicle'=>'truck_raw','P[.]?\s*O[.]?(?![.]?\s+BOX\b)'=>'work_order'] as $label => $field) {
        if (preg_match('/(?:^|\n)\s*' . $label . '\s*[:#]?\s*([^\n]+)/i', $plain, $m)) {
            $value = trim(preg_split('/\s+(?:Pounds|Gross|Tare|Net|Ordered|Received|Remaining)\b/i', $m[1])[0]);
            if ($field === 'truck_raw') $value = preg_split('/\s*-\s*(?=R\s*&\s*E\b)|\s{2,}/i', $value)[0];
            $row[$field] = $value;
        }
    }
    if (preg_match('/\bNet\s*[:=]?\s*([\d,]+(?:\.\d+)?)\s*\*?\s+(\d+(?:\.\d+)?)\s*\*?(?:\s+\d+(?:\.\d+)?)/i', $plain, $m)
        && preg_match('/Pounds\s+Tons\s+Metric/i', $plain)) {
        $row['tons'] = number_format((float)$m[2], 2, '.', '');
    } elseif (preg_match('/(?:^|\n)\s*(\d+(?:\.\d+)?)\s*(?:US\s*)?Tons?\b/i', $plain, $m)) {
        $row['tons'] = number_format((float)$m[1], 2, '.', '');
    } elseif (preg_match('/\bNet\s+(?:US\s+)?Tons?\s*[:=]?\s*(\d+(?:\.\d+)?)/i', $plain, $m)) {
        $row['tons'] = number_format((float)$m[1], 2, '.', '');
    } elseif (preg_match('/\bNet\s*[:=]?\s*([\d,]+(?:\.\d+)?)\s*(?:lb|lbs|pounds)\b/i', $plain, $m)) {
        $row['tons'] = number_format((float)str_replace(',', '', $m[1]) / 2000, 2, '.', '');
    }
    if (preg_match('/\b(?:Mileage|Miles)\s*[:=]\s*(\d+(?:\.\d+)?)/i', $plain, $m)) $row['miles'] = $m[1];
    return $row;
}

function rtex_bol_pages(string $path, string $name, array &$warnings): array {
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg','jpeg','png','tif','tiff','bmp','webp','pdf'], true)) {
        throw new InvalidArgumentException('Use a BOL image or PDF.');
    }
    if ($extension !== 'pdf') {
        if (@getimagesize($path) === false) throw new InvalidArgumentException('The uploaded file is not a readable image.');
        return [nickelrock_ocr_file_text($path, $name, $warnings)];
    }
    if (file_get_contents($path, false, null, 0, 5) !== '%PDF-') throw new InvalidArgumentException('The uploaded file is not a PDF.');
    if (nickelrock_command_exists('pdftotext')) {
        $text = nickelrock_run_command([nickelrock_tool_command('pdftotext'), '-layout', escapeshellarg($path), '-']);
        if (trim($text) !== '') return array_values(array_filter(explode("\f", $text), static fn($page) => trim($page) !== ''));
    }
    if (!nickelrock_command_exists('pdftoppm') || !nickelrock_command_exists('tesseract')) {
        throw new RuntimeException('Scanned PDFs require pdftoppm and Tesseract on the server. You can enter the BOL manually.');
    }
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rtex_bol_' . bin2hex(random_bytes(12));
    if (!mkdir($dir, 0700)) throw new RuntimeException('Unable to create OCR working directory.');
    $pages = [];
    try {
        $prefix = $dir . DIRECTORY_SEPARATOR . 'page';
        nickelrock_run_command([nickelrock_tool_command('pdftoppm'), '-r 220 -png -f 1 -l 51', escapeshellarg($path), escapeshellarg($prefix)]);
        $paths = glob($prefix . '-*.png') ?: [];
        natsort($paths);
        if (count($paths) > 50) throw new InvalidArgumentException('Split PDFs into batches of at most 50 pages.');
        foreach ($paths as $page) $pages[] = nickelrock_ocr_file_text($page, basename($page), $warnings);
    } finally {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
        @rmdir($dir);
    }
    return $pages;
}

function build_rtex_load_invoice_xlsx(array $rows, float $invoiceFscRate = 0.0, ?string $week = null, int $invoiceIndex = 1): string {
    $invoiceFscRate = rtex_fsc_percent($invoiceFscRate);
    $invoiceFscTotal = 0.0;
    $out = [['Date','Job','Ticket / BOL','Truck No.','Driver','Billing Basis','Net US Tons','Rate','Base Freight','P.O. / Work Order','Invoice FSC Rate (%)','Invoice FSC','Invoice Total']];
    foreach ($rows as $row) {
        $n = count($out) + 1;
        // Mileage remains part of pricing without a separate visible Miles column.
        $quantity = $row['rate_basis'] === 'mileage'
            ? number_format((float)$row['miles'], 2, '.', '') : "G{$n}";
        $invoiceFsc = rtex_fsc_amount((float)$row['total_amount'], $invoiceFscRate);
        $invoiceFscTotal += $invoiceFsc;
        $out[] = [$row['work_date'],$row['job_name'],$row['ticket_number'],
            $row['truck_raw'],$row['driver_name'],$row['rate_basis'],(float)$row['tons'],
            (float)$row['rate'],['formula'=>"ROUND({$quantity}*H{$n},2)",'value'=>(float)$row['total_amount']],
            $row['work_order'],$invoiceFscRate,
            ['formula'=>"ROUND(I{$n}*K{$n}/100,2)",'value'=>$invoiceFsc],
            ['formula'=>"I{$n}+L{$n}",'value'=>round((float)$row['total_amount']+$invoiceFsc,2)]];
    }
    $last = count($out);
    $out[] = ['','','','','','Totals',
        ['formula'=>"SUM(G2:G{$last})",'value'=>array_sum(array_column($rows,'tons'))],'',
        ['formula'=>"SUM(I2:I{$last})",'value'=>array_sum(array_column($rows,'total_amount'))], '', '',
        ['formula'=>"SUM(L2:L{$last})",'value'=>round($invoiceFscTotal,2)],
        ['formula'=>"SUM(M2:M{$last})",'value'=>round(array_sum(array_column($rows,'total_amount'))+$invoiceFscTotal,2)]];
    $week = $week ?? rtex_fsc_week($rows[0]['work_date'] ?? date('Y-m-d'));
    return rtex_invoice_from_template($out, $rows[0]['job_name'] ?? '', $week, $invoiceIndex);
}
function rtex_invoice_from_template(array $out, string $job, string $week, int $invoiceIndex): string {
    $path = tempnam(sys_get_temp_dir(), 'rtex_template_');
    if ($path === false) throw new RuntimeException('Cannot create invoice file.');
    try {
        if (!copy(__DIR__ . '/templates/rtex_invoice.xlsx', $path)) throw new RuntimeException('Cannot read RTEX invoice template.');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('Cannot open RTEX invoice template.');
        try {
            $tabName = str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $job);
            $tabName = preg_replace('/[\x00-\x1F]/u', '', $tabName);
            $tabName = trim($tabName, " \t\n\r\0\x0B'");
            preg_match_all('/./us', $tabName, $characters);
            $tabName = rtrim(implode('', array_slice($characters[0], 0, 31)), " '");
            if ($tabName === '') $tabName = 'RTEX Invoice';
            $workbook = new DOMDocument();
            $workbook->loadXML($zip->getFromName('xl/workbook.xml'));
            $sheet = $workbook->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'sheet')->item(0);
            if (!$sheet) throw new RuntimeException('Invoice template has no worksheet.');
            $sheet->setAttribute('name', $tabName);
            if (!$zip->addFromString('xl/workbook.xml', $workbook->saveXML())) throw new RuntimeException('Cannot set invoice worksheet name.');
            $doc = new DOMDocument();
            $doc->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));
            $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $xp = new DOMXPath($doc);
            $xp->registerNamespace('s', $ns);
            $setCell = static function (DOMElement $cell, $value) use ($doc, $ns): void {
                while ($cell->firstChild) $cell->removeChild($cell->firstChild);
                $cell->removeAttribute('t');
                if (is_array($value)) {
                    $formula = preg_replace_callback('/([A-Z]+)(\d+)/', static fn($m) => $m[1] . ((int)$m[2] + 10), $value['formula']);
                    $cell->appendChild($doc->createElementNS($ns,'f'))->appendChild($doc->createTextNode($formula));
                    $cell->appendChild($doc->createElementNS($ns,'v'))->appendChild($doc->createTextNode((string)$value['value']));
                } elseif (is_int($value) || is_float($value)) {
                    $cell->appendChild($doc->createElementNS($ns,'v'))->appendChild($doc->createTextNode((string)$value));
                } else {
                    $cell->setAttribute('t','inlineStr');
                    $text = $cell->appendChild($doc->createElementNS($ns,'is'))->appendChild($doc->createElementNS($ns,'t'));
                    $text->appendChild($doc->createTextNode((string)$value));
                }
            };
            $jobNumber = preg_match('/^(\d+)\s*-/', $job, $match) ? $match[1] : $job;
            foreach (['B9'=>$jobNumber,'J7'=>'LS'.str_replace('-','',$week).'-'.$invoiceIndex,
                'J8'=>(int)((strtotime($week . ' +6 days UTC') - strtotime('1899-12-30 UTC')) / 86400)] as $ref=>$value) {
                $setCell($xp->query('//s:c[@r="'.$ref.'"]')->item(0), $value);
            }
            $sheetData = $xp->query('//s:sheetData')->item(0);
            foreach (array_slice($out,1) as $offset=>$values) {
                $n = $offset + 12;
                $row = $sheetData->appendChild($doc->createElementNS($ns,'row'));
                $row->setAttribute('r',(string)$n);
                foreach ($values as $col=>$value) {
                    $cell = $row->appendChild($doc->createElementNS($ns,'c'));
                    $cell->setAttribute('r',chr(65+$col).$n);
                    $setCell($cell,$value);
                }
            }
            $xp->query('//s:dimension')->item(0)->setAttribute('ref','A2:M'.(count($out)+10));
            if (!$zip->addFromString('xl/worksheets/sheet1.xml',$doc->saveXML())) throw new RuntimeException('Cannot write invoice sheet.');
        } finally { $zip->close(); }
        $content = file_get_contents($path);
        if ($content === false) throw new RuntimeException('Cannot read invoice output.');
        return $content;
    } finally { unlink($path); }
}