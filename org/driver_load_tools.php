<?php

if (!function_exists('dlt_h')) {
    function dlt_h($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES);
    }
}

if (!function_exists('dlt_digits_only')) {
    function dlt_digits_only($value): string {
        return preg_replace('/\D+/', '', (string)$value);
    }
}

if (!function_exists('dlt_norm_spaces')) {
    function dlt_norm_spaces(string $value): string {
        return trim(preg_replace('/\s+/', ' ', $value));
    }
}

if (!function_exists('dlt_norm_name_key')) {
    function dlt_norm_name_key(string $value): string {
        $name = strtolower(dlt_norm_spaces($value));
        $name = preg_replace('/\s*-\s*[a-z]{2,}(\s+[a-z]{2,})*\s*$/i', '', $name);
        return dlt_norm_spaces($name);
    }
}

if (!function_exists('dlt_money_to_float')) {
    function dlt_money_to_float($value): float {
        $raw = preg_replace('/[^0-9\.\-]/', '', (string)$value);
        if ($raw === '' || $raw === '-' || $raw === '.' || $raw === '-.') {
            return 0.0;
        }
        return (float)$raw;
    }
}

if (!function_exists('dlt_parse_int')) {
    function dlt_parse_int($value): int {
        $raw = preg_replace('/[^0-9\-]/', '', (string)$value);
        if ($raw === '' || $raw === '-') {
            return 0;
        }
        return (int)$raw;
    }
}

if (!function_exists('dlt_parse_month_number')) {
    function dlt_parse_month_number($value): ?int {
        $v = strtolower(trim((string)$value));
        if ($v === '') {
            return null;
        }
        if (ctype_digit($v)) {
            $n = (int)$v;
            return ($n >= 1 && $n <= 12) ? $n : null;
        }

        $months = [
            'january' => 1, 'jan' => 1,
            'february' => 2, 'feb' => 2,
            'march' => 3, 'mar' => 3,
            'april' => 4, 'apr' => 4,
            'may' => 5,
            'june' => 6, 'jun' => 6,
            'july' => 7, 'jul' => 7,
            'august' => 8, 'aug' => 8,
            'september' => 9, 'sep' => 9, 'sept' => 9,
            'october' => 10, 'oct' => 10,
            'november' => 11, 'nov' => 11,
            'december' => 12, 'dec' => 12,
        ];

        return $months[$v] ?? null;
    }
}

if (!function_exists('dlt_parse_any_date')) {
    function dlt_parse_any_date($value): ?string {
        $v = trim((string)$value);
        if ($v === '') {
            return null;
        }

        if (preg_match('/^\d+(\.\d+)?$/', $v)) {
            $serial = (int)floor((float)$v);
            if ($serial > 0) {
                $ts = ($serial - 25569) * 86400;
                return gmdate('Y-m-d', $ts);
            }
        }

        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}

if (!function_exists('dlt_make_date')) {
    function dlt_make_date($yearValue, $monthValue, $dayValue): ?string {
        $year = dlt_parse_int($yearValue);
        $month = dlt_parse_month_number($monthValue);
        $day = dlt_parse_int($dayValue);

        if ($year < 2000 || $year > 2100 || $month === null || $day < 1 || $day > 31) {
            return null;
        }
        if (!checkdate($month, $day, $year)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}

if (!function_exists('dlt_table_exists')) {
    function dlt_table_exists(mysqli $mysqli, string $table): bool {
        $stmt = $mysqli->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        return ((int)$count > 0);
    }
}

if (!function_exists('dlt_ensure_tables')) {
    function dlt_ensure_tables(mysqli $mysqli): void {
        $sql = [];

        $sql[] = "
            CREATE TABLE IF NOT EXISTS driver_load_vendor_sources (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              vendor_code VARCHAR(50) NOT NULL,
              vendor_name VARCHAR(100) NOT NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uniq_vendor_code (vendor_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";

        $sql[] = "
            CREATE TABLE IF NOT EXISTS driver_load_uploads (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              vendor_id INT UNSIGNED NOT NULL,
              vendor_name VARCHAR(100) NOT NULL,
              original_filename VARCHAR(255) NOT NULL,
              file_hash CHAR(64) NOT NULL,
              source_date DATE NOT NULL,
              uploaded_by VARCHAR(150) NULL,
              row_count INT UNSIGNED NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_upload_vendor_date (vendor_id, source_date),
              UNIQUE KEY uniq_upload_file_hash (file_hash),
              CONSTRAINT fk_driver_load_uploads_vendor FOREIGN KEY (vendor_id)
                REFERENCES driver_load_vendor_sources(id)
                ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";

        $sql[] = "
            CREATE TABLE IF NOT EXISTS driver_load_daily (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              vendor_id INT UNSIGNED NOT NULL,
              vendor_name VARCHAR(100) NOT NULL,
              driver_contact_id INT NULL,
              driver_key VARCHAR(190) NOT NULL,
              vendor_driver_id VARCHAR(120) NULL,
              driver_name VARCHAR(190) NOT NULL,
              truck_number VARCHAR(60) NULL,
              activity_date DATE NOT NULL,
              activity_datetime DATETIME NULL,
              rate_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              load_count INT NOT NULL DEFAULT 0,
              source_upload_id BIGINT UNSIGNED NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uniq_driver_vendor_day (vendor_id, driver_key, activity_date),
              KEY idx_activity_date (activity_date),
              KEY idx_contact_activity (driver_contact_id, activity_date),
              CONSTRAINT fk_driver_load_daily_vendor FOREIGN KEY (vendor_id)
                REFERENCES driver_load_vendor_sources(id)
                ON DELETE RESTRICT ON UPDATE CASCADE,
              CONSTRAINT fk_driver_load_daily_upload FOREIGN KEY (source_upload_id)
                REFERENCES driver_load_uploads(id)
                ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";

        $sql[] = "
            CREATE TABLE IF NOT EXISTS driver_activity_alert_log (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              driver_contact_id INT NOT NULL,
              vendor_id INT UNSIGNED NOT NULL,
              alert_status ENUM('yellow','red') NOT NULL,
              alert_date DATE NOT NULL,
              last_activity_at DATETIME NULL,
              email_address VARCHAR(190) NULL,
              phone_number VARCHAR(40) NULL,
              emailed_at DATETIME NULL,
              texted_at DATETIME NULL,
              message TEXT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uniq_driver_vendor_status_day (driver_contact_id, vendor_id, alert_status, alert_date),
              KEY idx_alert_date (alert_date),
              CONSTRAINT fk_driver_activity_alert_vendor FOREIGN KEY (vendor_id)
                REFERENCES driver_load_vendor_sources(id)
                ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";

        foreach ($sql as $stmt) {
            if (!$mysqli->query($stmt)) {
                throw new RuntimeException('Unable to create driver load table(s): ' . $mysqli->error);
            }
        }

        $insVendor = $mysqli->prepare(
            "INSERT INTO driver_load_vendor_sources (vendor_code, vendor_name) VALUES ('tss', 'TSS')
             ON DUPLICATE KEY UPDATE vendor_name = VALUES(vendor_name), is_active = 1"
        );
        if ($insVendor) {
            $insVendor->execute();
            $insVendor->close();
        }
    }
}

if (!function_exists('dlt_slugify_vendor')) {
    function dlt_slugify_vendor(string $name): string {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');
        return $slug !== '' ? $slug : 'vendor_' . substr(sha1($name), 0, 8);
    }
}

if (!function_exists('dlt_get_vendor_options')) {
    function dlt_get_vendor_options(mysqli $mysqli): array {
        $out = [];
        $res = $mysqli->query("SELECT id, vendor_code, vendor_name FROM driver_load_vendor_sources WHERE is_active=1 ORDER BY vendor_name");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $out[] = $row;
            }
            $res->close();
        }
        return $out;
    }
}

if (!function_exists('dlt_get_or_create_vendor')) {
    function dlt_get_or_create_vendor(mysqli $mysqli, string $vendorCode = '', string $vendorName = ''): array {
        $vendorCode = strtolower(trim($vendorCode));
        $vendorName = trim($vendorName);

        if ($vendorName !== '' && $vendorCode === '') {
            $vendorCode = dlt_slugify_vendor($vendorName);
        }

        if ($vendorCode === '') {
            $vendorCode = 'tss';
        }

        $stmt = $mysqli->prepare("SELECT id, vendor_code, vendor_name FROM driver_load_vendor_sources WHERE vendor_code=? LIMIT 1");
        $stmt->bind_param('s', $vendorCode);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            if ($vendorName !== '' && strcasecmp($vendorName, $row['vendor_name']) !== 0) {
                $upd = $mysqli->prepare("UPDATE driver_load_vendor_sources SET vendor_name=?, is_active=1 WHERE id=?");
                $upd->bind_param('si', $vendorName, $row['id']);
                $upd->execute();
                $upd->close();
                $row['vendor_name'] = $vendorName;
            }
            return $row;
        }

        $finalName = $vendorName !== '' ? $vendorName : strtoupper($vendorCode);
        $ins = $mysqli->prepare("INSERT INTO driver_load_vendor_sources (vendor_code, vendor_name, is_active) VALUES (?,?,1)");
        $ins->bind_param('ss', $vendorCode, $finalName);
        $ins->execute();
        $id = (int)$ins->insert_id;
        $ins->close();

        return ['id' => $id, 'vendor_code' => $vendorCode, 'vendor_name' => $finalName];
    }
}

if (!function_exists('dlt_parse_delimited_text_rows')) {
    function dlt_parse_delimited_text_rows(string $content): array {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = array_values(array_filter(explode("\n", $content), static function ($line) {
            return trim($line) !== '';
        }));

        if (empty($lines)) {
            return ['headers' => [], 'rows' => []];
        }

        $delimiter = (substr_count($lines[0], "\t") > substr_count($lines[0], ',')) ? "\t" : ',';
        $headers = str_getcsv(array_shift($lines), $delimiter);
        $rows = [];
        foreach ($lines as $line) {
            $rows[] = str_getcsv($line, $delimiter);
        }

        return ['headers' => $headers, 'rows' => $rows];
    }
}

if (!function_exists('dlt_parse_uploaded_file')) {
    function dlt_parse_uploaded_file(string $tmpPath, string $originalName): array {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($ext === 'xlsx') {
            if (!class_exists('Shuchkin\\SimpleXLSX')) {
                return ['error' => 'SimpleXLSX is required to read .xlsx files.'];
            }
            $xlsx = \Shuchkin\SimpleXLSX::parse($tmpPath);
            if (!$xlsx) {
                return ['error' => 'Unable to parse .xlsx file: ' . \Shuchkin\SimpleXLSX::parseError()];
            }
            $allRows = $xlsx->rows();
            if (count($allRows) < 2) {
                return ['error' => 'The uploaded file has no data rows.'];
            }
            $headers = array_map('trim', array_map('strval', $allRows[0]));
            $rows = array_slice($allRows, 1);
            return ['headers' => $headers, 'rows' => $rows];
        }

        $raw = @file_get_contents($tmpPath);
        if ($raw === false || $raw === '') {
            return ['error' => 'Unable to read uploaded file.'];
        }

        if (substr($raw, 0, 2) === "\xFF\xFE") {
            $raw = (string)@iconv('UTF-16LE', 'UTF-8//IGNORE', substr($raw, 2));
        } elseif (substr($raw, 0, 2) === "\xFE\xFF") {
            $raw = (string)@iconv('UTF-16BE', 'UTF-8//IGNORE', substr($raw, 2));
        } elseif (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
            $raw = substr($raw, 3);
        }

        $parsed = dlt_parse_delimited_text_rows($raw);
        if (empty($parsed['headers']) || count($parsed['rows']) === 0) {
            return ['error' => 'No tabular data found in upload.'];
        }
        return $parsed;
    }
}

if (!function_exists('dlt_header_map')) {
    function dlt_header_map(array $headers): array {
        $map = [];
        foreach ($headers as $idx => $header) {
            $key = strtolower(trim((string)$header));
            $map[$key] = $idx;
        }
        return $map;
    }
}

if (!function_exists('dlt_get_idx')) {
    function dlt_get_idx(array $map, array $keys): ?int {
        foreach ($keys as $key) {
            $k = strtolower(trim($key));
            if (array_key_exists($k, $map)) {
                return (int)$map[$k];
            }
        }
        return null;
    }
}

if (!function_exists('dlt_driver_key')) {
    function dlt_driver_key(string $driverId, string $driverName, string $truckNumber): string {
        $idPart = trim($driverId);
        if ($idPart !== '') {
            return 'id:' . strtolower($idPart);
        }
        $namePart = dlt_norm_name_key($driverName);
        $truckPart = dlt_digits_only($truckNumber);
        return 'name:' . $namePart . '|truck:' . $truckPart;
    }
}

if (!function_exists('dlt_build_contact_index')) {
    function dlt_build_contact_index(mysqli $mysqli): array {
        $contactsById = [];
        $nameToIds = [];
        $truckToIds = [];

        $res = $mysqli->query("SELECT id, first_name, last_name, truck_no, alt_truck_no FROM driver_contacts WHERE COALESCE(is_disabled,0)=0");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $id = (int)$row['id'];
                $full = dlt_norm_name_key(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
                $contactsById[$id] = [
                    'id' => $id,
                    'name' => $full,
                    'truck' => dlt_digits_only($row['truck_no'] ?? ''),
                    'alt_truck' => dlt_digits_only($row['alt_truck_no'] ?? ''),
                ];

                if ($full !== '') {
                    $nameToIds[$full][$id] = true;
                }

                foreach ([$row['truck_no'] ?? '', $row['alt_truck_no'] ?? ''] as $truckVal) {
                    $digits = dlt_digits_only($truckVal);
                    if ($digits !== '') {
                        $truckToIds[$digits][$id] = true;
                    }
                }
            }
            $res->close();
        }

        if (dlt_table_exists($mysqli, 'driver_name_aliases')) {
            $resAlias = $mysqli->query("SELECT driver_contact_id, alias_full_norm, alias_first_name, alias_last_name FROM driver_name_aliases");
            if ($resAlias) {
                while ($row = $resAlias->fetch_assoc()) {
                    $id = (int)$row['driver_contact_id'];
                    $alias = trim((string)($row['alias_full_norm'] ?? ''));
                    if ($alias === '') {
                        $alias = trim(($row['alias_first_name'] ?? '') . ' ' . ($row['alias_last_name'] ?? ''));
                    }
                    $alias = dlt_norm_name_key($alias);
                    if ($alias !== '') {
                        $nameToIds[$alias][$id] = true;
                    }
                }
                $resAlias->close();
            }
        }

        return ['name_to_ids' => $nameToIds, 'truck_to_ids' => $truckToIds];
    }
}

if (!function_exists('dlt_match_contact_id')) {
    function dlt_match_contact_id(array $index, string $driverName, string $truckNumber): ?int {
        $nameKey = dlt_norm_name_key($driverName);
        $truckDigits = dlt_digits_only($truckNumber);

        $nameIds = [];
        if ($nameKey !== '' && isset($index['name_to_ids'][$nameKey])) {
            $nameIds = array_keys($index['name_to_ids'][$nameKey]);
        }

        $truckIds = [];
        if ($truckDigits !== '' && isset($index['truck_to_ids'][$truckDigits])) {
            $truckIds = array_keys($index['truck_to_ids'][$truckDigits]);
        }

        if (!empty($nameIds) && !empty($truckIds)) {
            $intersect = array_values(array_intersect($nameIds, $truckIds));
            if (count($intersect) === 1) {
                return (int)$intersect[0];
            }
        }

        if (count($nameIds) === 1) {
            return (int)$nameIds[0];
        }

        if (count($truckIds) === 1) {
            return (int)$truckIds[0];
        }

        return null;
    }
}

if (!function_exists('dlt_parse_driver_rows')) {
    function dlt_parse_driver_rows(array $headers, array $rows): array {
        $map = dlt_header_map($headers);

        $iDriverId = dlt_get_idx($map, ['Driver ID', 'driver id', 'vendor driver id']);
        $iDriverName = dlt_get_idx($map, ['Driver', 'driver', 'driver name']);
        $iTruck = dlt_get_idx($map, ['Truck Number', 'truck number', 'truck #', 'truck']);
        $iRate = dlt_get_idx($map, ['Rate', 'rate', 'amount', 'earnings']);
        $iLoadCount = dlt_get_idx($map, ['Count of Load ID', 'count of load id', 'load count', 'count']);
        $iDate = dlt_get_idx($map, ['Date', 'date', 'Unloaded At', 'unloaded at']);
        $iYear = dlt_get_idx($map, ['Year of date([Unloaded At])', 'year of date([unloaded at])', 'year']);
        $iMonth = dlt_get_idx($map, ['Month of date([Unloaded At])', 'month of date([unloaded at])', 'month']);
        $iDay = dlt_get_idx($map, ['Day of date([Unloaded At])', 'day of date([unloaded at])', 'day']);

        if ($iDriverName === null || $iRate === null || $iLoadCount === null) {
            return [
                'rows' => [],
                'errors' => ['File is missing required columns (Driver, Rate, Count of Load ID).'],
            ];
        }

        $parsed = [];
        $errors = [];

        foreach ($rows as $lineIndex => $row) {
            $driverName = dlt_norm_spaces((string)($row[$iDriverName] ?? ''));
            $driverId = $iDriverId !== null ? trim((string)($row[$iDriverId] ?? '')) : '';
            $truckNumber = $iTruck !== null ? trim((string)($row[$iTruck] ?? '')) : '';
            $rate = dlt_money_to_float($row[$iRate] ?? 0);
            $loadCount = dlt_parse_int($row[$iLoadCount] ?? 0);

            if ($driverName === '' || $loadCount <= 0) {
                continue;
            }

            $date = null;
            if ($iDate !== null) {
                $date = dlt_parse_any_date($row[$iDate] ?? null);
            }
            if ($date === null && $iYear !== null && $iMonth !== null && $iDay !== null) {
                $date = dlt_make_date($row[$iYear] ?? '', $row[$iMonth] ?? '', $row[$iDay] ?? '');
            }

            if ($date === null) {
                $errors[] = 'Row ' . ($lineIndex + 2) . ': unable to parse activity date.';
                continue;
            }

            $parsed[] = [
                'driver_key' => dlt_driver_key($driverId, $driverName, $truckNumber),
                'vendor_driver_id' => $driverId !== '' ? $driverId : null,
                'driver_name' => $driverName,
                'truck_number' => $truckNumber !== '' ? $truckNumber : null,
                'activity_date' => $date,
                'activity_datetime' => $date . ' 00:00:00',
                'rate_amount' => round($rate, 2),
                'load_count' => $loadCount,
            ];
        }

        return ['rows' => $parsed, 'errors' => array_values(array_unique($errors))];
    }
}

if (!function_exists('dlt_upsert_driver_rows')) {
    function dlt_upsert_driver_rows(
        mysqli $mysqli,
        array $vendor,
        string $fileName,
        string $fileHash,
        array $rows,
        string $uploadedBy = ''
    ): array {
        if (empty($rows)) {
            return ['inserted' => 0, 'updated' => 0, 'upload_id' => null];
        }

        $sourceDate = date('Y-m-d');

        $insUpload = $mysqli->prepare(
            "INSERT INTO driver_load_uploads
             (vendor_id, vendor_name, original_filename, file_hash, source_date, uploaded_by, row_count)
             VALUES (?,?,?,?,?,?,?)"
        );
        if (!$insUpload) {
            throw new RuntimeException('Upload log prepare failed: ' . $mysqli->error);
        }

        $vendorId = (int)$vendor['id'];
        $vendorName = (string)$vendor['vendor_name'];
        $rowCount = count($rows);
        $uploadedBy = trim($uploadedBy) !== '' ? $uploadedBy : null;

        $insUpload->bind_param(
            'isssssi',
            $vendorId,
            $vendorName,
            $fileName,
            $fileHash,
            $sourceDate,
            $uploadedBy,
            $rowCount
        );
        $insUpload->execute();
        $uploadId = (int)$insUpload->insert_id;
        $insUpload->close();

        $contactIndex = dlt_build_contact_index($mysqli);

        $stmt = $mysqli->prepare(
            "INSERT INTO driver_load_daily
             (vendor_id, vendor_name, driver_contact_id, driver_key, vendor_driver_id, driver_name, truck_number,
              activity_date, activity_datetime, rate_amount, load_count, source_upload_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               vendor_name=VALUES(vendor_name),
               driver_contact_id=VALUES(driver_contact_id),
               vendor_driver_id=VALUES(vendor_driver_id),
               driver_name=VALUES(driver_name),
               truck_number=VALUES(truck_number),
               activity_datetime=VALUES(activity_datetime),
               rate_amount=VALUES(rate_amount),
               load_count=VALUES(load_count),
               source_upload_id=VALUES(source_upload_id),
               updated_at=NOW()"
        );
        if (!$stmt) {
            throw new RuntimeException('Daily upsert prepare failed: ' . $mysqli->error);
        }

        $inserted = 0;
        $updated = 0;
        foreach ($rows as $row) {
            $contactId = dlt_match_contact_id($contactIndex, $row['driver_name'], (string)($row['truck_number'] ?? ''));
            $contactIdForBind = $contactId ?: null;
            $vendorDriverId = $row['vendor_driver_id'] ?: null;
            $activityDatetime = $row['activity_datetime'] ?: null;
            $truckNumber = $row['truck_number'] ?: null;
            $rate = (float)$row['rate_amount'];
            $loadCount = (int)$row['load_count'];
            $driverKey = (string)$row['driver_key'];
            $driverName = (string)$row['driver_name'];
            $activityDate = (string)$row['activity_date'];

            $stmt->bind_param(
                'isissssssdii',
                $vendorId,
                $vendorName,
                $contactIdForBind,
                $driverKey,
                $vendorDriverId,
                $driverName,
                $truckNumber,
                $activityDate,
                $activityDatetime,
                $rate,
                $loadCount,
                $uploadId
            );
            $stmt->execute();

            if ($stmt->affected_rows === 1) {
                $inserted++;
            } elseif ($stmt->affected_rows === 2) {
                $updated++;
            }
        }

        $stmt->close();

        return ['inserted' => $inserted, 'updated' => $updated, 'upload_id' => $uploadId];
    }
}

if (!function_exists('dlt_week_start_from_date')) {
    function dlt_week_start_from_date(string $ymd): string {
        $ts = strtotime($ymd);
        $dow = (int)date('w', $ts);
        return date('Y-m-d', strtotime('-' . $dow . ' day', $ts));
    }
}

if (!function_exists('dlt_get_week_options')) {
    function dlt_get_week_options(mysqli $mysqli, int $rollingDays = 120): array {
        $options = [];
        $fromDate = date('Y-m-d', strtotime('-' . max(1, $rollingDays) . ' days'));

        $stmt = $mysqli->prepare(
            "SELECT DISTINCT activity_date
               FROM driver_load_daily
              WHERE activity_date >= ?
              ORDER BY activity_date DESC"
        );
        $stmt->bind_param('s', $fromDate);
        $stmt->execute();
        $res = $stmt->get_result();
        $seen = [];
        while ($row = $res->fetch_assoc()) {
            $weekStart = dlt_week_start_from_date($row['activity_date']);
            if (!isset($seen[$weekStart])) {
                $seen[$weekStart] = true;
                $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
                $options[] = [
                    'week_start' => $weekStart,
                    'week_end' => $weekEnd,
                    'label' => date('M j, Y', strtotime($weekStart)) . ' - ' . date('M j, Y', strtotime($weekEnd)),
                ];
            }
        }
        $stmt->close();

        usort($options, static function ($a, $b) {
            return strcmp($b['week_start'], $a['week_start']);
        });

        return $options;
    }
}

if (!function_exists('dlt_get_weekly_matrix')) {
    function dlt_get_weekly_matrix(mysqli $mysqli, string $weekStart, string $vendorCode = ''): array {
        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

        $sql = "
            SELECT d.driver_key, d.driver_name, d.vendor_name, d.activity_date, d.rate_amount, d.load_count
              FROM driver_load_daily d
              JOIN driver_load_vendor_sources v ON v.id = d.vendor_id
             WHERE d.activity_date BETWEEN ? AND ?
        ";

        $types = 'ss';
        $params = [$weekStart, $weekEnd];
        if ($vendorCode !== '') {
            $sql .= " AND v.vendor_code = ?";
            $types .= 's';
            $params[] = $vendorCode;
        }

        $sql .= " ORDER BY d.driver_name ASC, d.activity_date ASC";

        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[] = date('Y-m-d', strtotime($weekStart . ' +' . $i . ' day'));
        }

        $driverRows = [];
        $totals = [
            'daily_rates' => array_fill_keys($days, 0.0),
            'daily_loads' => array_fill_keys($days, 0),
            'weekly_rate' => 0.0,
            'weekly_loads' => 0,
        ];

        while ($row = $res->fetch_assoc()) {
            $key = $row['vendor_name'] . '|' . $row['driver_key'];
            if (!isset($driverRows[$key])) {
                $driverRows[$key] = [
                    'driver_name' => $row['driver_name'],
                    'vendor_name' => $row['vendor_name'],
                    'daily_rates' => array_fill_keys($days, 0.0),
                    'daily_loads' => array_fill_keys($days, 0),
                    'weekly_rate' => 0.0,
                    'weekly_loads' => 0,
                ];
            }

            $date = $row['activity_date'];
            if (!isset($driverRows[$key]['daily_rates'][$date])) {
                continue;
            }

            $rate = (float)$row['rate_amount'];
            $loads = (int)$row['load_count'];

            $driverRows[$key]['daily_rates'][$date] += $rate;
            $driverRows[$key]['daily_loads'][$date] += $loads;
            $driverRows[$key]['weekly_rate'] += $rate;
            $driverRows[$key]['weekly_loads'] += $loads;

            $totals['daily_rates'][$date] += $rate;
            $totals['daily_loads'][$date] += $loads;
            $totals['weekly_rate'] += $rate;
            $totals['weekly_loads'] += $loads;
        }

        $stmt->close();

        $driverRows = array_values(array_filter($driverRows, static function ($row) {
            return ((int)$row['weekly_loads'] > 0);
        }));

        usort($driverRows, static function ($a, $b) {
            return strcasecmp($a['driver_name'], $b['driver_name']);
        });

        return [
            'days' => $days,
            'rows' => $driverRows,
            'totals' => $totals,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
        ];
    }
}

if (!function_exists('dlt_activity_status')) {
    function dlt_activity_status(?string $lastActivityAt, ?string $todayYmd = null): array {
        if ($todayYmd === null) {
            $todayYmd = date('Y-m-d');
        }
        if (!$lastActivityAt) {
            return ['status' => 'red', 'days_since' => 999];
        }

        $lastDate = date('Y-m-d', strtotime($lastActivityAt));
        $daysSince = (int)floor((strtotime($todayYmd) - strtotime($lastDate)) / 86400);
        if ($daysSince <= 1) {
            return ['status' => 'green', 'days_since' => $daysSince];
        }
        if ($daysSince === 2) {
            return ['status' => 'yellow', 'days_since' => $daysSince];
        }
        return ['status' => 'red', 'days_since' => $daysSince];
    }
}

if (!function_exists('dlt_get_activity_monitor_rows')) {
    function dlt_get_activity_monitor_rows(mysqli $mysqli, int $rollingDays = 120, string $vendorCode = ''): array {
        $fromDate = date('Y-m-d', strtotime('-' . max(7, $rollingDays) . ' days'));

        $sql = "
            SELECT d.vendor_id, v.vendor_code, d.driver_key, d.driver_name, d.vendor_name, d.driver_contact_id,
                   d.activity_date, d.activity_datetime
              FROM driver_load_daily d
              JOIN driver_load_vendor_sources v ON v.id = d.vendor_id
             WHERE d.activity_date >= ?
        ";

        $types = 's';
        $params = [$fromDate];
        if ($vendorCode !== '') {
            $sql .= " AND v.vendor_code = ?";
            $types .= 's';
            $params[] = $vendorCode;
        }

        $sql .= " ORDER BY d.vendor_id, d.driver_key, COALESCE(d.activity_datetime, CONCAT(d.activity_date, ' 00:00:00')) DESC";

        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();

        $rows = [];
        $seen = [];
        while ($row = $res->fetch_assoc()) {
            $key = $row['vendor_id'] . '|' . $row['driver_key'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $lastActivityAt = $row['activity_datetime'] ?: ($row['activity_date'] . ' 00:00:00');
            $statusInfo = dlt_activity_status($lastActivityAt);

            $rows[] = [
                'vendor_id' => (int)$row['vendor_id'],
                'vendor_code' => $row['vendor_code'],
                'vendor_name' => $row['vendor_name'],
                'driver_key' => $row['driver_key'],
                'driver_name' => $row['driver_name'],
                'driver_contact_id' => $row['driver_contact_id'] ? (int)$row['driver_contact_id'] : null,
                'last_activity_at' => $lastActivityAt,
                'status' => $statusInfo['status'],
                'days_since' => $statusInfo['days_since'],
            ];
        }

        $stmt->close();

        usort($rows, static function ($a, $b) {
            $prio = ['red' => 0, 'yellow' => 1, 'green' => 2];
            $cmp = ($prio[$a['status']] ?? 9) <=> ($prio[$b['status']] ?? 9);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcasecmp($a['driver_name'], $b['driver_name']);
        });

        return $rows;
    }
}

if (!function_exists('dlt_send_sms_twilio')) {
    function dlt_send_sms_twilio(string $toPhone, string $message, ?string &$error = null): bool {
        $sid = getenv('TWILIO_ACCOUNT_SID') ?: (defined('LONESTAR_TWILIO_ACCOUNT_SID') ? LONESTAR_TWILIO_ACCOUNT_SID : '');
        $token = getenv('TWILIO_AUTH_TOKEN') ?: (defined('LONESTAR_TWILIO_AUTH_TOKEN') ? LONESTAR_TWILIO_AUTH_TOKEN : '');
        $from = getenv('TWILIO_FROM_NUMBER') ?: (defined('LONESTAR_TWILIO_FROM_NUMBER') ? LONESTAR_TWILIO_FROM_NUMBER : '');

        if ($sid === '' || $token === '' || $from === '') {
            $error = 'Missing Twilio environment variables.';
            return false;
        }

        if (!function_exists('curl_init')) {
            $error = 'cURL extension is not available.';
            return false;
        }

        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';
        $postFields = http_build_query([
            'From' => $from,
            'To' => $toPhone,
            'Body' => $message,
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $sid . ':' . $token);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $error = 'Twilio request failed (HTTP ' . $httpCode . ').';
            curl_close($ch);
            return false;
        }

        curl_close($ch);
        return true;
    }
}

if (!function_exists('dlt_send_out_of_compliance_alerts')) {
    function dlt_send_out_of_compliance_alerts(mysqli $mysqli, array $monitorRows): array {
        $stats = [
            'eligible' => 0,
            'sent_email' => 0,
            'sent_text' => 0,
            'skipped_already_sent' => 0,
            'skipped_missing_contact' => 0,
            'errors' => [],
        ];

        $contactIds = [];
        foreach ($monitorRows as $row) {
            if (($row['status'] === 'yellow' || $row['status'] === 'red') && !empty($row['driver_contact_id'])) {
                $contactIds[(int)$row['driver_contact_id']] = true;
            }
        }

        if (empty($contactIds)) {
            return $stats;
        }

        $contacts = [];
        $idList = array_keys($contactIds);
        $placeholders = implode(',', array_fill(0, count($idList), '?'));
        $types = str_repeat('i', count($idList));
        $sql = "SELECT id, first_name, last_name, email, phone FROM driver_contacts WHERE id IN ($placeholders)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param($types, ...$idList);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $contacts[(int)$row['id']] = $row;
        }
        $stmt->close();

        $today = date('Y-m-d');

        foreach ($monitorRows as $row) {
            $status = $row['status'];
            if ($status !== 'yellow' && $status !== 'red') {
                continue;
            }

            $contactId = $row['driver_contact_id'] ?? null;
            if (!$contactId || !isset($contacts[$contactId])) {
                $stats['skipped_missing_contact']++;
                continue;
            }

            $stats['eligible']++;
            $contact = $contacts[$contactId];
            $driverName = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
            if ($driverName === '') {
                $driverName = $row['driver_name'];
            }

            $hours = $status === 'yellow' ? 48 : 72;
            $message = "Lonestar Roadside Driver Check-In:\n"
                . "Our system shows no loads picked up in the last {$hours} hours.\n"
                . "Please contact dispatch if there is an issue.";

            $email = trim((string)($contact['email'] ?? ''));
            $phone = trim((string)($contact['phone'] ?? ''));

            $insLog = $mysqli->prepare(
                "INSERT IGNORE INTO driver_activity_alert_log
                 (driver_contact_id, vendor_id, alert_status, alert_date, last_activity_at, email_address, phone_number, message)
                 VALUES (?,?,?,?,?,?,?,?)"
            );
            if (!$insLog) {
                $stats['errors'][] = 'Alert log prepare failed: ' . $mysqli->error;
                continue;
            }

            $vendorId = (int)$row['vendor_id'];
            $lastActivityAt = $row['last_activity_at'];
            $insLog->bind_param(
                'iissssss',
                $contactId,
                $vendorId,
                $status,
                $today,
                $lastActivityAt,
                $email,
                $phone,
                $message
            );
            $insLog->execute();
            $wasInserted = $insLog->affected_rows > 0;
            $alertLogId = (int)$insLog->insert_id;
            $insLog->close();

            if (!$wasInserted) {
                $stats['skipped_already_sent']++;
                continue;
            }

            $emailedAt = null;
            $textedAt = null;

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $subject = 'Lonestar Roadside Driver Check-In';
                $body = "Hello {$driverName},\n\n{$message}\n\nLast load activity: "
                    . date('m/d/Y H:i', strtotime($lastActivityAt))
                    . "\n\n-Lonestar Dispatch";

                $okMail = @mail($email, $subject, $body, "From: no-reply@lonestarroadside.com\r\n");
                if ($okMail) {
                    $emailedAt = date('Y-m-d H:i:s');
                    $stats['sent_email']++;
                }
            }

            if ($phone !== '') {
                $smsError = null;
                $okSms = dlt_send_sms_twilio($phone, $message, $smsError);
                if ($okSms) {
                    $textedAt = date('Y-m-d H:i:s');
                    $stats['sent_text']++;
                } elseif ($smsError) {
                    $stats['errors'][] = "SMS not sent for {$driverName}: {$smsError}";
                }
            }

            $upd = $mysqli->prepare("UPDATE driver_activity_alert_log SET emailed_at=?, texted_at=? WHERE id=? LIMIT 1");
            if ($upd) {
                $upd->bind_param('ssi', $emailedAt, $textedAt, $alertLogId);
                $upd->execute();
                $upd->close();
            }
        }

        return $stats;
    }
}
