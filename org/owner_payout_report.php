<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/rtex_fsc.php';
require __DIR__ . '/includes/payout_net_helpers.php';

$currentUser = $_SESSION['username'] ?? '';
$currentUserType = $_SESSION['user_type'] ?? '';
if ($currentUser === 'admin' && $currentUserType === '') {
    $currentUserType = 'admin';
}
if (!in_array($currentUserType, ['admin', 'owner'], true)) {
    header('Location: dashboard.php');
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');

$phpMailerBase = __DIR__ . '/includes/PHPMailer/src';
if (file_exists($phpMailerBase . '/PHPMailer.php')) {
    require_once $phpMailerBase . '/Exception.php';
    require_once $phpMailerBase . '/PHPMailer.php';
    require_once $phpMailerBase . '/SMTP.php';
}

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES);
}

function money($v): string {
    return '$' . number_format((float)$v, 2);
}

function owner_report_percent_decimal($value, float $defaultPercent): float {
    $raw = trim((string)$value);
    if ($raw === '') {
        $raw = (string)$defaultPercent;
    }
    $raw = str_replace(',', '', rtrim($raw, '%'));
    if (!is_numeric($raw)) {
        $raw = (string)$defaultPercent;
    }
    $pct = max(0.0, (float)$raw);
    return $pct > 1 ? ($pct / 100.0) : $pct;
}

function owner_report_sanitize_filename($s): string {
    $s = preg_replace('/[^\w\-. ]+/', '_', (string)$s);
    $s = trim((string)$s);
    return $s !== '' ? $s : 'file';
}

function owner_report_driver_filename(mysqli $mysqli, int $driverId): string {
    $label = 'Driver_' . $driverId;
    $stmt = $mysqli->prepare("SELECT CONCAT(first_name, ' ', last_name) AS nm FROM driver_contacts WHERE id=? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $driverId);
        $stmt->execute();
        $stmt->bind_result($driverName);
        if ($stmt->fetch() && trim((string)$driverName) !== '') {
            $label = trim((string)$driverName);
        }
        $stmt->close();
    }
    return owner_report_sanitize_filename(str_replace(' ', '', $label));
}

function owner_report_driver_filename_variants(mysqli $mysqli, int $driverId): array {
    $variants = [owner_report_driver_filename($mysqli, $driverId)];
    try {
        ensure_driver_identity_view($mysqli);
        $stmt = $mysqli->prepare("SELECT DISTINCT name FROM v_driver_identity_names WHERE driver_id=?");
        if ($stmt) {
            $stmt->bind_param('i', $driverId);
            $stmt->execute();
            if (method_exists($stmt, 'get_result')) {
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $name = trim((string)($row['name'] ?? ''));
                    if ($name !== '') {
                        $variants[] = owner_report_sanitize_filename(str_replace(' ', '', $name));
                    }
                }
            } else {
                $stmt->bind_result($name);
                while ($stmt->fetch()) {
                    $name = trim((string)$name);
                    if ($name !== '') {
                        $variants[] = owner_report_sanitize_filename(str_replace(' ', '', $name));
                    }
                }
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        // Fall back to the current driver contact name.
    }
    return array_values(array_unique(array_filter($variants)));
}

function owner_report_statement_base_dir(): string {
    return 'G:\\My Drive\\11_Lonestar\\Files\\Weekly_Statements';
}

function owner_report_statement_cache_dir(string $vendorScope, string $weekEnd): string {
    $vendorScope = normalize_payout_vendor_scope($vendorScope);
    $weekEnd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekEnd) ? $weekEnd : date('Y-m-d');
    return __DIR__ . '/statement_cache/' . $vendorScope . '/' . $weekEnd;
}

function owner_report_statement_files_in_dir(string $dir, string $driverBase, string $vendorSuffix, string $weekEnd): array {
    if (!is_dir($dir)) {
        return [];
    }
    $matches = [];
    $prefix = strtolower($driverBase);
    $suffixes = array_values(array_filter([
        $vendorSuffix !== '' ? '_' . strtolower($vendorSuffix) . '_' . $weekEnd . '.pdf' : '',
        '_' . $weekEnd . '.pdf',
    ]));
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $name = strtolower($file->getFilename());
            if (substr($name, -4) !== '.pdf' || strpos($name, $prefix) !== 0) {
                continue;
            }
            foreach ($suffixes as $suffix) {
                if (substr($name, -strlen($suffix)) === $suffix) {
                    $matches[] = $file->getPathname();
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        return [];
    }
    return array_values(array_unique($matches));
}

function owner_report_statement_files(mysqli $mysqli, int $driverId, string $vendorScope, string $weekEnd): array {
    $year = substr($weekEnd, 0, 4);
    $driverBases = owner_report_driver_filename_variants($mysqli, $driverId);
    $scope = normalize_payout_vendor_scope($vendorScope);
    $baseDir = owner_report_statement_base_dir();
    $paths = [];
    $searchDirs = [];
    $searchDirs[] = owner_report_statement_cache_dir($scope, $weekEnd);
    if ($scope === 'nextier') {
        $searchDirs[] = $baseDir . "\\NexTier";
        foreach ($driverBases as $driverBase) {
            $paths[] = $baseDir . "\\NexTier\\payout_reports_nextier_{$year}\\{$driverBase}_nextier_{$weekEnd}.pdf";
        }
    } elseif ($scope === 'rtex') {
        $folderDate = (new DateTimeImmutable($weekEnd))->format('ymd');
        $searchDirs[] = $baseDir . "\\RTEX\\{$folderDate}";
        foreach ($driverBases as $driverBase) {
            $paths[] = $baseDir . "\\RTEX\\{$folderDate}\\payout_reports_rtex_{$year}\\{$driverBase}_rtex_{$weekEnd}.pdf";
        }
    } elseif ($scope === 'nickelrock') {
        $folderDate = (new DateTimeImmutable($weekEnd))->format('ymd');
        $searchDirs[] = $baseDir . "\\NickelRock\\{$folderDate}";
        foreach ($driverBases as $driverBase) {
            $paths[] = $baseDir . "\\NickelRock\\{$folderDate}\\payout_reports_nickelrock_{$year}\\{$driverBase}_nickelrock_{$weekEnd}.pdf";
        }
    } else {
        $folderDate = (new DateTimeImmutable($weekEnd))->format('Y_m_d');
        $searchDirs[] = $baseDir . "\\TSS\\{$folderDate}";
        foreach ($driverBases as $driverBase) {
            $paths[] = $baseDir . "\\TSS\\{$folderDate}\\payout_reports_tss_{$year}\\{$driverBase}_tss_{$weekEnd}.pdf";
            $paths[] = $baseDir . "\\TSS\\{$folderDate}\\payout_reports_{$year}\\{$driverBase}_{$weekEnd}.pdf";
            $paths[] = $baseDir . "\\TSS\\{$folderDate}\\payout_reports_" . str_replace('-', '', $weekEnd) . "\\{$driverBase}_{$weekEnd}.pdf";
        }
    }
    $found = [];
    foreach ($paths as $path) {
        if (is_file($path)) {
            $found[] = $path;
        }
    }
    $vendorSuffix = $scope === 'tss' ? 'tss' : $scope;
    foreach ($searchDirs as $dir) {
        foreach ($driverBases as $driverBase) {
            foreach (owner_report_statement_files_in_dir($dir, $driverBase, $vendorSuffix, $weekEnd) as $path) {
                $found[] = $path;
            }
        }
    }
    return array_values(array_unique($found));
}

function owner_report_statement_file(mysqli $mysqli, int $driverId, string $vendorScope, string $weekEnd): string {
    $files = owner_report_statement_files($mysqli, $driverId, $vendorScope, $weekEnd);
    return $files[0] ?? '';
}

function owner_report_smtp_config(): array {
    return [
        'host' => getenv('OWNER_STATEMENTS_SMTP_HOST') ?: 'smtp.ionos.com',
        'port' => (int)(getenv('OWNER_STATEMENTS_SMTP_PORT') ?: 587),
        'username' => getenv('OWNER_STATEMENTS_SMTP_USER') ?: 'accounting@lonestarroadsidetx.com',
        'password' => getenv('OWNER_STATEMENTS_SMTP_PASS') ?: '',
        'from' => getenv('OWNER_STATEMENTS_FROM') ?: 'accounting@lonestarroadsidetx.com',
        'from_name' => getenv('OWNER_STATEMENTS_FROM_NAME') ?: 'Lone Star Roadside Accounting',
    ];
}

function owner_report_send_statement_email(string $to, string $ownerName, string $vendorLabel, string $weekStart, string $weekEnd, array $attachments): string {
    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        return 'PHPMailer is not installed.';
    }
    $cfg = owner_report_smtp_config();
    if ($cfg['username'] === '' || $cfg['password'] === '') {
        return 'Missing SMTP credentials. Set OWNER_STATEMENTS_SMTP_USER and OWNER_STATEMENTS_SMTP_PASS for accounting@lonestarroadsidetx.com.';
    }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return 'Missing or invalid owner email address.';
    }
    if (empty($attachments)) {
        return 'No statement PDFs were found to attach.';
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $cfg['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $cfg['username'];
        $mail->Password = $cfg['password'];
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $cfg['port'];
        $mail->setFrom($cfg['from'], $cfg['from_name']);
        $mail->addAddress($to, $ownerName !== '' ? $ownerName : $to);
        $mail->addBCC('hmarrero@lonestarroadsidetx.com');
        $mail->addBCC('jchalwell@lonestarroadsidetx.com');
        $mail->isHTML(true);
        $mail->Subject = "{$vendorLabel} Settlement Statements {$weekStart} to {$weekEnd}";
        $safeOwner = h($ownerName !== '' ? $ownerName : 'there');
        $mail->Body = "
            <p>Hello {$safeOwner},</p>
            <p>Please see the attached {$vendorLabel} settlement statement(s) for {$weekStart} to {$weekEnd}.</p>
            <p>Thank you,<br>Lone Star Roadside Accounting</p>
        ";
        $mail->AltBody = "Hello " . ($ownerName !== '' ? $ownerName : 'there') . ",\n\nPlease see the attached {$vendorLabel} settlement statement(s) for {$weekStart} to {$weekEnd}.\n\nThank you,\nLone Star Roadside Accounting";
        foreach ($attachments as $path) {
            $mail->addAttachment($path, basename($path));
        }
        $mail->send();
        return '';
    } catch (Throwable $e) {
        return $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
    }
}

function sunday_week_start(string $date): string {
    $dt = new DateTimeImmutable($date);
    $dow = (int)$dt->format('w');
    return $dt->modify("-{$dow} days")->format('Y-m-d');
}

function week_end_from_start(string $weekStart): string {
    return (new DateTimeImmutable($weekStart))->modify('+6 days')->format('Y-m-d');
}

function table_has_column(mysqli $mysqli, string $table, string $column): bool {
    $table = $mysqli->real_escape_string($table);
    $column = $mysqli->real_escape_string($column);
    $res = $mysqli->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function table_exists(mysqli $mysqli, string $table): bool {
    $table = $mysqli->real_escape_string($table);
    $res = $mysqli->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}

function payout_vendor_scope_options(): array {
    return [
        'tss' => 'TSS',
        'nextier' => 'NexTier',
        'rtex' => 'RTEX',
        'nickelrock' => 'Nickel Rock',
    ];
}

function normalize_payout_vendor_scope($scope): string {
    $scope = strtolower(trim((string)$scope));
    return array_key_exists($scope, payout_vendor_scope_options()) ? $scope : 'tss';
}

function payout_vendor_scope_label(string $scope): string {
    $options = payout_vendor_scope_options();
    return $options[normalize_payout_vendor_scope($scope)] ?? 'TSS';
}

function payout_vendor_matches_scope($vendorName, string $scope): bool {
    $vendor = strtoupper(trim((string)$vendorName));
    $scope = normalize_payout_vendor_scope($scope);
    if ($scope === 'rtex') {
        return $vendor === 'RTEX';
    }
    if ($scope === 'nickelrock') {
        return $vendor === 'NICKEL ROCK' || $vendor === 'NICKELROCK';
    }
    if ($scope === 'nextier') {
        return $vendor === 'NEXTIER';
    }
    return $vendor === 'TSS'
        || $vendor === 'TSS MISC REVENUE'
        || strpos($vendor, 'TSS SPLIT') === 0;
}

function payout_vendor_sql_condition(string $scope, string $alias = 'dp'): string {
    $column = $alias !== '' ? "{$alias}.vendor_name" : "vendor_name";
    $scope = normalize_payout_vendor_scope($scope);
    if ($scope === 'rtex') {
        return "UPPER({$column}) = 'RTEX'";
    }
    if ($scope === 'nickelrock') {
        return "UPPER({$column}) IN ('NICKEL ROCK', 'NICKELROCK')";
    }
    if ($scope === 'nextier') {
        return "UPPER({$column}) = 'NEXTIER'";
    }
    return "{$column} IN ('TSS', 'TSS Misc Revenue', 'TSS Split (Pickup)', 'TSS Split (Delivery)')";
}

function is_tss_misc_revenue_vendor($vendorName): bool {
    return strtoupper(trim((string)$vendorName)) === 'TSS MISC REVENUE';
}

function owner_payout_inputs_has_vendor(mysqli $mysqli): bool {
    static $hasVendor = null;
    if ($hasVendor === null) {
        try {
            $hasVendor = table_has_column($mysqli, 'owner_payout_inputs', 'payout_vendor');
        } catch (Throwable $e) {
            $hasVendor = false;
        }
    }
    return $hasVendor;
}

function ensure_owner_payout_inputs_table(mysqli $mysqli): void {
    try {
        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS owner_payout_inputs (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              payout_week_start DATE NOT NULL,
              payout_vendor VARCHAR(30) NOT NULL DEFAULT 'tss',
              vendor_payout_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              updated_by INT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_owner_payout_week_vendor (payout_week_start, payout_vendor)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        if (!table_has_column($mysqli, 'owner_payout_inputs', 'payout_vendor')) {
            $mysqli->query("ALTER TABLE owner_payout_inputs ADD COLUMN payout_vendor VARCHAR(30) NOT NULL DEFAULT 'tss' AFTER payout_week_start");
        }
        if (!table_has_column($mysqli, 'owner_payout_inputs', 'vendor_payout_amount')) {
            $mysqli->query("ALTER TABLE owner_payout_inputs ADD COLUMN vendor_payout_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER payout_vendor");
        }
        if (!table_has_column($mysqli, 'owner_payout_inputs', 'updated_by')) {
            $mysqli->query("ALTER TABLE owner_payout_inputs ADD COLUMN updated_by INT NULL AFTER vendor_payout_amount");
        }
        if (!table_has_column($mysqli, 'owner_payout_inputs', 'created_at')) {
            $mysqli->query("ALTER TABLE owner_payout_inputs ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER updated_by");
        }
        if (!table_has_column($mysqli, 'owner_payout_inputs', 'updated_at')) {
            $mysqli->query("ALTER TABLE owner_payout_inputs ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        }

        $uniqueKeys = [];
        if ($rs = $mysqli->query("SHOW INDEX FROM owner_payout_inputs WHERE Non_unique = 0")) {
            while ($idx = $rs->fetch_assoc()) {
                $keyName = (string)($idx['Key_name'] ?? '');
                $seq = (int)($idx['Seq_in_index'] ?? 0);
                $col = (string)($idx['Column_name'] ?? '');
                if ($keyName === '' || $keyName === 'PRIMARY' || $col === '') continue;
                $uniqueKeys[$keyName][$seq] = $col;
            }
            $rs->close();
        }
        foreach ($uniqueKeys as $keyName => $colsBySeq) {
            ksort($colsBySeq);
            $cols = array_values($colsBySeq);
            if ($cols === ['payout_week_start']) {
                $safeKey = str_replace('`', '``', $keyName);
                $mysqli->query("ALTER TABLE owner_payout_inputs DROP INDEX `{$safeKey}`");
            }
        }

        $hasWeekVendorIndex = false;
        if ($rs = $mysqli->query("SHOW INDEX FROM owner_payout_inputs WHERE Key_name = 'idx_owner_payout_week_vendor'")) {
            $hasWeekVendorIndex = $rs->num_rows > 0;
            $rs->close();
        }
        if (!$hasWeekVendorIndex) {
            $mysqli->query("ALTER TABLE owner_payout_inputs ADD KEY idx_owner_payout_week_vendor (payout_week_start, payout_vendor)");
        }
    } catch (Throwable $e) {
        error_log('owner_payout_inputs ensure failed: ' . $e->getMessage());
    }
}

function business_normalize_date(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $m)) {
        return $m[0];
    }
    if (preg_match('/^\d+(\.\d+)?$/', $value)) {
        $base = new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('America/Chicago'));
        $seconds = (int)round(((float)$value) * 86400);
        return $base->modify('+' . $seconds . ' seconds')->format('Y-m-d');
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('America/Chicago')))->format('Y-m-d');
    } catch (Throwable $e) {
        return $value;
    }
}

function trailer_percent_decimal_for_driver(mysqli $mysqli, int $driverId, $overrideValue = null, string $periodStart = '', string $periodEnd = ''): float {
    $override = null;
    if ($overrideValue !== null && $overrideValue !== '') {
        $raw = preg_replace('/[^0-9\.\-]/', '', (string)$overrideValue);
        if ($raw !== '' && $raw !== '-' && $raw !== '.' && $raw !== '-.') {
            $override = (float)$raw;
        }
    }
    if ($override !== null) {
        return $override > 1 ? ($override / 100.0) : $override;
    }
    return lonestar_driver_tss_trailer_percent_decimal($mysqli, $driverId, $periodStart, $periodEnd);
}

function trailer_asset_fee_config_for_driver(mysqli $mysqli, int $driverId): array {
    $out = ['mode' => '', 'value' => null, 'received_date' => ''];
    if ($driverId <= 0 || !table_exists($mysqli, 'trailer_assets') || !table_has_column($mysqli, 'trailer_assets', 'trailer_fee_mode') || !table_has_column($mysqli, 'trailer_assets', 'trailer_fee_value')) {
        return $out;
    }
    $hasReceivedDate = table_has_column($mysqli, 'driver_contacts', 'trailer_received_date');
    $receivedSelect = $hasReceivedDate ? ', dc.trailer_received_date' : ", NULL AS trailer_received_date";
    $stmt = $mysqli->prepare("
        SELECT ta.trailer_fee_mode, ta.trailer_fee_value{$receivedSelect}
          FROM driver_contacts dc
          JOIN trailer_assets ta
            ON TRIM(COALESCE(ta.trailer_number, '')) = TRIM(COALESCE(dc.trailer_no, ''))
         WHERE dc.id = ?
         LIMIT 1
    ");
    if (!$stmt) return $out;
    $stmt->bind_param('i', $driverId);
    $stmt->execute();
    $stmt->bind_result($mode, $value, $receivedDate);
    if ($stmt->fetch()) {
        $out['mode'] = in_array((string)$mode, ['percentage', 'flat'], true) ? (string)$mode : '';
        $out['value'] = $value === null ? null : (float)$value;
        $out['received_date'] = $receivedDate === null ? '' : (string)$receivedDate;
    }
    $stmt->close();
    return $out;
}

function nextier_trailer_fee_days(string $weekStart, string $weekEnd, string $receivedDate = ''): int {
    if ($weekStart === '' || $weekEnd === '') {
        return 7;
    }
    if (trim($receivedDate) === '') {
        return 7;
    }
    try {
        $start = new DateTimeImmutable($weekStart);
        $end = new DateTimeImmutable($weekEnd);
        $received = new DateTimeImmutable($receivedDate);
    } catch (Throwable $e) {
        return 7;
    }
    if ($received > $end) {
        return 0;
    }
    $effectiveStart = $received >= $start ? $received->modify('+1 day') : $start;
    if ($effectiveStart > $end) {
        return 0;
    }
    return max(0, ((int)$effectiveStart->diff($end)->days) + 1);
}

function trailer_fee_total_for_driver(mysqli $mysqli, int $driverId, float $grossTotal, string $weekStart = '', string $weekEnd = '', string $vendorScope = ''): float {
    return lonestar_driver_trailer_fee_estimate($mysqli, $driverId, $grossTotal, $vendorScope, $weekStart, $weekEnd);
}

function payout_row_trailer_pct_override(mysqli $mysqli, string $ticketNumber, string $payoutDate): string {
    static $lsDetailExists = null;
    static $hasTrailerPctOverrideCol = null;
    static $cache = [];
    static $dateTicketMapCache = [];

    $ticketNumber = trim($ticketNumber);
    $payoutDateNorm = business_normalize_date($payoutDate);
    if ($ticketNumber === '') {
        return '';
    }

    if ($lsDetailExists === null) {
        $lsDetailExists = table_exists($mysqli, 'ls_detail_raw');
        $hasTrailerPctOverrideCol = $lsDetailExists ? table_has_column($mysqli, 'ls_detail_raw', 'tss_trailer_pct_override') : false;
    }
    if (!$lsDetailExists || !$hasTrailerPctOverrideCol) {
        return '';
    }

    $cacheKey = $ticketNumber . '|' . $payoutDateNorm;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $normalizeTicket = static function ($value): string {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/\s+/', '', $value);
        return preg_replace('/\.0+$/', '', $value);
    };
    $digitsOnly = static function ($value): string {
        return preg_replace('/\D+/', '', (string)$value);
    };

    $override = '';

    if ($payoutDateNorm !== '') {
        if (!isset($dateTicketMapCache[$payoutDateNorm])) {
            $dateTicketMapCache[$payoutDateNorm] = [];
            $sql = "SELECT `Truckload ID` AS ticket_no, `Delivery Date` AS delivery_date, COALESCE(`tss_trailer_pct_override`, '') AS trailer_pct_override
                      FROM ls_detail_raw
                     WHERE `Delivery Date` = ?
                        OR `Delivery Date` LIKE CONCAT(?, ' %')
                        OR `Delivery Date` LIKE CONCAT(?, 'T%')
                     ORDER BY upload_date DESC";
            if ($stmt = $mysqli->prepare($sql)) {
                $stmt->bind_param('sss', $payoutDateNorm, $payoutDateNorm, $payoutDateNorm);
                $stmt->execute();
                if (method_exists($stmt, 'get_result')) {
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $rowDateNorm = business_normalize_date((string)($row['delivery_date'] ?? ''));
                        if ($rowDateNorm !== $payoutDateNorm) {
                            continue;
                        }
                        $normTicket = $normalizeTicket($row['ticket_no'] ?? '');
                        $digitsTicket = $digitsOnly($row['ticket_no'] ?? '');
                        $mappedOverride = trim((string)($row['trailer_pct_override'] ?? ''));
                        if ($normTicket !== '' && !isset($dateTicketMapCache[$payoutDateNorm][$normTicket])) {
                            $dateTicketMapCache[$payoutDateNorm][$normTicket] = $mappedOverride;
                        }
                        if ($digitsTicket !== '' && !isset($dateTicketMapCache[$payoutDateNorm]['#d:' . $digitsTicket])) {
                            $dateTicketMapCache[$payoutDateNorm]['#d:' . $digitsTicket] = $mappedOverride;
                        }
                    }
                } else {
                    $stmt->bind_result($rowTicketNo, $rowDeliveryDate, $rowOverride);
                    while ($stmt->fetch()) {
                        $rowDateNorm = business_normalize_date((string)$rowDeliveryDate);
                        if ($rowDateNorm !== $payoutDateNorm) {
                            continue;
                        }
                        $normTicket = $normalizeTicket((string)$rowTicketNo);
                        $digitsTicket = $digitsOnly((string)$rowTicketNo);
                        $mappedOverride = trim((string)$rowOverride);
                        if ($normTicket !== '' && !isset($dateTicketMapCache[$payoutDateNorm][$normTicket])) {
                            $dateTicketMapCache[$payoutDateNorm][$normTicket] = $mappedOverride;
                        }
                        if ($digitsTicket !== '' && !isset($dateTicketMapCache[$payoutDateNorm]['#d:' . $digitsTicket])) {
                            $dateTicketMapCache[$payoutDateNorm]['#d:' . $digitsTicket] = $mappedOverride;
                        }
                    }
                }
                $stmt->close();
            }
        }

        $wantedNorm = $normalizeTicket($ticketNumber);
        $wantedDigits = $digitsOnly($ticketNumber);
        if ($wantedNorm !== '' && isset($dateTicketMapCache[$payoutDateNorm][$wantedNorm])) {
            $override = $dateTicketMapCache[$payoutDateNorm][$wantedNorm];
        } elseif ($wantedDigits !== '' && isset($dateTicketMapCache[$payoutDateNorm]['#d:' . $wantedDigits])) {
            $override = $dateTicketMapCache[$payoutDateNorm]['#d:' . $wantedDigits];
        }
    }

    if ($override === '') {
        $sql = "SELECT `Truckload ID` AS ticket_no, COALESCE(`tss_trailer_pct_override`, '') AS trailer_pct_override
                  FROM ls_detail_raw
                 WHERE `Truckload ID` = ?
                    OR REPLACE(`Truckload ID`, ' ', '') = ?
                 ORDER BY upload_date DESC
                 LIMIT 25";
        $ticketCompact = preg_replace('/\s+/', '', $ticketNumber);
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param('ss', $ticketNumber, $ticketCompact);
            $stmt->execute();
            $wantedNorm = $normalizeTicket($ticketNumber);
            $wantedDigits = $digitsOnly($ticketNumber);
            if (method_exists($stmt, 'get_result')) {
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $rowNorm = $normalizeTicket($row['ticket_no'] ?? '');
                    $rowDigits = $digitsOnly($row['ticket_no'] ?? '');
                    if (($wantedNorm !== '' && $rowNorm === $wantedNorm) || ($wantedDigits !== '' && $rowDigits === $wantedDigits)) {
                        $override = trim((string)($row['trailer_pct_override'] ?? ''));
                        break;
                    }
                }
            } else {
                $stmt->bind_result($rowTicketNo, $rowOverride);
                while ($stmt->fetch()) {
                    $rowNorm = $normalizeTicket((string)$rowTicketNo);
                    $rowDigits = $digitsOnly((string)$rowTicketNo);
                    if (($wantedNorm !== '' && $rowNorm === $wantedNorm) || ($wantedDigits !== '' && $rowDigits === $wantedDigits)) {
                        $override = trim((string)$rowOverride);
                        break;
                    }
                }
            }
            $stmt->close();
        }
    }

    $cache[$cacheKey] = $override;
    return $override;
}

function driver_trailer_fee_amount(mysqli $mysqli, int $driverId, float $grossTotal, string $ticketNumber = '', string $payoutDate = '', $vendorName = ''): float {
    if (is_tss_misc_revenue_vendor($vendorName)) {
        return 0.0;
    }
    $pctDecimal = trailer_percent_decimal_for_driver(
        $mysqli,
        $driverId,
        payout_row_trailer_pct_override($mysqli, $ticketNumber, $payoutDate),
        business_normalize_date($payoutDate),
        business_normalize_date($payoutDate)
    );
    return round($grossTotal * $pctDecimal, 2);
}

function nextier_payout_row_meta(mysqli $mysqli, $ticketNumber, $payoutDate): array {
    $out = [
        'detail_match' => false,
        'line_haul' => null,
        'fsc_total' => 0.0,
        'bonus' => 0.0,
        'rate' => 0.0,
        'tons' => 0.0,
    ];
    if (!table_exists($mysqli, 'nextier_payout_rows')) {
        return $out;
    }
    $ticket = trim((string)$ticketNumber);
    $date = business_normalize_date((string)$payoutDate);
    if ($ticket === '' || $date === '') {
        return $out;
    }

    $stmt = $mysqli->prepare("
        SELECT line_haul, fsc_total, bonus, rate, tons
          FROM nextier_payout_rows
         WHERE work_date = ?
           AND TRIM(CONCAT(load_id, CASE WHEN COALESCE(bol_number, '') <> '' THEN CONCAT('-', bol_number) ELSE '' END)) = ?
         ORDER BY upload_date DESC, id DESC
         LIMIT 1
    ");
    if (!$stmt) {
        return $out;
    }
    $stmt->bind_param('ss', $date, $ticket);
    $stmt->execute();
    if (method_exists($stmt, 'get_result')) {
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $out = [
                'detail_match' => true,
                'line_haul' => (float)($row['line_haul'] ?? 0),
                'fsc_total' => (float)($row['fsc_total'] ?? 0),
                'bonus' => (float)($row['bonus'] ?? 0),
                'rate' => (float)($row['rate'] ?? 0),
                'tons' => (float)($row['tons'] ?? 0),
            ];
        }
    } else {
        $stmt->bind_result($lineHaul, $fscTotal, $bonus, $rate, $tons);
        if ($stmt->fetch()) {
            $out = [
                'detail_match' => true,
                'line_haul' => (float)$lineHaul,
                'fsc_total' => (float)$fscTotal,
                'bonus' => (float)$bonus,
                'rate' => (float)$rate,
                'tons' => (float)$tons,
            ];
        }
    }
    $stmt->close();
    return $out;
}

function nextier_report_row_pay(array $meta, $fallbackPay): float {
    if (!empty($meta['detail_match'])) {
        $rate = (float)($meta['rate'] ?? 0);
        $tons = (float)($meta['tons'] ?? 0);
        $bonus = (float)($meta['bonus'] ?? 0);
        if ($rate > 0.0 && $tons > 0.0) {
            return round(($rate * $tons) + $bonus, 2);
        }
        return round((float)($meta['line_haul'] ?? 0) - (float)($meta['fsc_total'] ?? 0), 2);
    }
    return round((float)$fallbackPay, 2);
}

function driver_week_net_total(mysqli $mysqli, int $driverId, float $grossTotal, float $trailerFeeTotal, string $weekStart, string $weekEnd): float {
    return lonestar_driver_week_net_total($mysqli, $driverId, $grossTotal, $trailerFeeTotal, $weekStart, $weekEnd);
}

function ensure_driver_identity_view(mysqli $mysqli): void {
    static $done = false;
    if ($done) {
        return;
    }

    $unionAliases = '';
    if (table_exists($mysqli, 'ls_unresolved_matches')) {
        $unionAliases = "
            UNION ALL
            SELECT lm.matched_contact_id AS driver_id,
                   TRIM(CONCAT(lm.pickup_first_name,' ',lm.pickup_last_name)) AS name,
                   1 AS is_alias
              FROM ls_unresolved_matches lm
              JOIN driver_contacts dc ON dc.id = lm.matched_contact_id
             WHERE lm.matched_contact_id IS NOT NULL";
    }

    $mysqli->query(
        "CREATE OR REPLACE VIEW v_driver_identity_names AS
        SELECT dc.id AS driver_id,
               TRIM(CONCAT(dc.first_name,' ',dc.last_name)) AS name,
               0 AS is_alias
          FROM driver_contacts dc
        {$unionAliases}"
    );
    $done = true;
}

function mysql_yearweek_for_date(mysqli $mysqli, string $date): int {
    static $cache = [];
    if (isset($cache[$date])) {
        return $cache[$date];
    }
    $stmt = $mysqli->prepare("SELECT YEARWEEK(?,0)");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $stmt->bind_result($yearWeek);
    $cache[$date] = $stmt->fetch() ? (int)$yearWeek : 0;
    $stmt->close();
    return $cache[$date];
}

function owner_report_driver_candidates(mysqli $mysqli): array {
    ensure_driver_identity_view($mysqli);
    $drivers = [];
    $res = $mysqli->query(
        "SELECT vin.driver_id,
                MAX(CASE WHEN vin.is_alias=0 THEN vin.name END) AS legal_name,
                dc.owner_name,
                dc.owner_dot_number,
                dc.email AS owner_email
           FROM v_driver_identity_names vin
           JOIN driver_contacts dc ON dc.id = vin.driver_id
          GROUP BY vin.driver_id
          ORDER BY legal_name ASC"
    );
    while ($res && ($row = $res->fetch_assoc())) {
        $drivers[] = [
            'driver_id' => (int)($row['driver_id'] ?? 0),
            'legal_name' => trim((string)($row['legal_name'] ?? '')),
            'owner_op' => trim((string)($row['owner_name'] ?? '')),
            'owner_email' => trim((string)($row['owner_email'] ?? '')),
            'dot_number' => trim((string)($row['owner_dot_number'] ?? '')),
        ];
    }
    if ($res) {
        $res->close();
    }
    return $drivers;
}

function owner_report_driver_week_rows(mysqli $mysqli, int $driverId, string $weekStart, string $vendorScope = 'tss'): array {
    $vendorScope = normalize_payout_vendor_scope($vendorScope);
    ensure_driver_identity_view($mysqli);
    static $hasDriverId = null;
    static $hasDriverContactId = null;

    if ($hasDriverId === null) {
        $hasDriverId = table_has_column($mysqli, 'driver_payouts', 'driver_id');
        $hasDriverContactId = table_has_column($mysqli, 'driver_payouts', 'driver_contact_id');
    }

    $year = (int)substr($weekStart, 0, 4);
    $yearWeek = mysql_yearweek_for_date($mysqli, $weekStart);
    $vendorWhere = payout_vendor_sql_condition($vendorScope, 'dp');
    $rows = [];

    if ($hasDriverId && $hasDriverContactId) {
        $stmt = $mysqli->prepare(
            "SELECT dp.payout_date, dp.ticket_number, dp.driver_name, dp.vendor_name, dp.tss_pay
               FROM driver_payouts dp
              WHERE YEAR(dp.payout_date)=?
                AND YEARWEEK(dp.payout_date,0)=?
                AND {$vendorWhere}
                AND (
                     dp.driver_id = ?
                     OR dp.driver_contact_id = ?
                     OR (
                       dp.driver_id IS NULL
                       AND dp.driver_contact_id IS NULL
                       AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)
                     )
                )
              ORDER BY (CASE WHEN dp.vendor_name='TSS Misc Revenue' THEN 1 ELSE 0 END), dp.payout_date, dp.ticket_number"
        );
        $stmt->bind_param('iiiii', $year, $yearWeek, $driverId, $driverId, $driverId);
    } elseif ($hasDriverId) {
        $stmt = $mysqli->prepare(
            "SELECT dp.payout_date, dp.ticket_number, dp.driver_name, dp.vendor_name, dp.tss_pay
               FROM driver_payouts dp
              WHERE YEAR(dp.payout_date)=?
                AND YEARWEEK(dp.payout_date,0)=?
                AND {$vendorWhere}
                AND (dp.driver_id = ? OR (dp.driver_id IS NULL AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)))
              ORDER BY (CASE WHEN dp.vendor_name='TSS Misc Revenue' THEN 1 ELSE 0 END), dp.payout_date, dp.ticket_number"
        );
        $stmt->bind_param('iiii', $year, $yearWeek, $driverId, $driverId);
    } elseif ($hasDriverContactId) {
        $stmt = $mysqli->prepare(
            "SELECT dp.payout_date, dp.ticket_number, dp.driver_name, dp.vendor_name, dp.tss_pay
               FROM driver_payouts dp
              WHERE YEAR(dp.payout_date)=?
                AND YEARWEEK(dp.payout_date,0)=?
                AND {$vendorWhere}
                AND (
                     dp.driver_contact_id = ?
                     OR (
                       dp.driver_contact_id IS NULL
                       AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)
                     )
                )
              ORDER BY (CASE WHEN dp.vendor_name='TSS Misc Revenue' THEN 1 ELSE 0 END), dp.payout_date, dp.ticket_number"
        );
        $stmt->bind_param('iiii', $year, $yearWeek, $driverId, $driverId);
    } else {
        $stmt = $mysqli->prepare(
            "SELECT dp.payout_date, dp.ticket_number, dp.driver_name, dp.vendor_name, dp.tss_pay
               FROM driver_payouts dp
              WHERE YEAR(dp.payout_date)=?
                AND YEARWEEK(dp.payout_date,0)=?
                AND {$vendorWhere}
                AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)
              ORDER BY (CASE WHEN dp.vendor_name='TSS Misc Revenue' THEN 1 ELSE 0 END), dp.payout_date, dp.ticket_number"
        );
        $stmt->bind_param('iii', $year, $yearWeek, $driverId);
    }

    $stmt->execute();
    if (method_exists($stmt, 'get_result')) {
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if (!payout_vendor_matches_scope($row['vendor_name'] ?? '', $vendorScope)) continue;
            $gross = (float)($row['tss_pay'] ?? 0);
            $nextierMeta = $vendorScope === 'nextier'
                ? nextier_payout_row_meta($mysqli, $row['ticket_number'] ?? '', $row['payout_date'] ?? '')
                : ['fsc_total' => 0.0, 'detail_match' => false];
            if ($vendorScope === 'nextier') {
                $gross = nextier_report_row_pay($nextierMeta, $gross);
            }
            $rows[] = [
                'payout_date' => (string)($row['payout_date'] ?? ''),
                'ticket_number' => (string)($row['ticket_number'] ?? ''),
                'driver_name' => (string)($row['driver_name'] ?? ''),
                'vendor_name' => (string)($row['vendor_name'] ?? ''),
                'tss_pay' => $gross,
                'trailer_fee' => in_array($vendorScope, ['rtex', 'nextier', 'nickelrock'], true) ? 0.0 : driver_trailer_fee_amount($mysqli, $driverId, $gross, (string)($row['ticket_number'] ?? ''), (string)($row['payout_date'] ?? ''), $row['vendor_name'] ?? ''),
                'nextier_bonus' => (float)($nextierMeta['bonus'] ?? 0), 'nextier_fsc' => (float)($nextierMeta['fsc_total'] ?? 0),
            ];
        }
    } else {
        $stmt->bind_result($payoutDate, $ticketNumber, $driverName, $vendorName, $tssPay);
        while ($stmt->fetch()) {
            if (!payout_vendor_matches_scope($vendorName, $vendorScope)) continue;
            $gross = (float)$tssPay;
            $nextierMeta = $vendorScope === 'nextier'
                ? nextier_payout_row_meta($mysqli, $ticketNumber, $payoutDate)
                : ['fsc_total' => 0.0, 'detail_match' => false];
            if ($vendorScope === 'nextier') {
                $gross = nextier_report_row_pay($nextierMeta, $gross);
            }
            $rows[] = [
                'payout_date' => (string)$payoutDate,
                'ticket_number' => (string)$ticketNumber,
                'driver_name' => (string)$driverName,
                'vendor_name' => (string)$vendorName,
                'tss_pay' => $gross,
                'trailer_fee' => in_array($vendorScope, ['rtex', 'nextier', 'nickelrock'], true) ? 0.0 : driver_trailer_fee_amount($mysqli, $driverId, $gross, (string)$ticketNumber, (string)$payoutDate, $vendorName),
                'nextier_bonus' => (float)($nextierMeta['bonus'] ?? 0), 'nextier_fsc' => (float)($nextierMeta['fsc_total'] ?? 0),
            ];
        }
    }
    $stmt->close();

    return $rows;
}

$weekOptions = [];
$qWeeks = $mysqli->query(
    "SELECT DISTINCT DATE_SUB(payout_date, INTERVAL (DAYOFWEEK(payout_date) - 1) DAY) AS week_start
       FROM driver_payouts
      ORDER BY week_start DESC"
);
while ($r = $qWeeks->fetch_assoc()) {
    $ws = (string)($r['week_start'] ?? '');
    if ($ws !== '') {
        $weekOptions[] = $ws;
    }
}
$qWeeks->close();

ensure_owner_payout_inputs_table($mysqli);

if (!$weekOptions) {
    $weekOptions[] = sunday_week_start(date('Y-m-d'));
}

$selectedPayoutVendor = normalize_payout_vendor_scope($_REQUEST['payout_vendor'] ?? 'tss');
$showNonPayableDrivers = (string)($_REQUEST['show_nonpayable'] ?? '') === '1';
$nickelRockBrokerageSettings = lonestar_vendor_broker_fee_settings($mysqli, 'nickelrock');
$nickelRockBrokerageRateInput = ($nickelRockBrokerageSettings['fee_mode'] ?? 'percentage') === 'percentage'
    ? number_format((float)$nickelRockBrokerageSettings['fee_value'], 2, '.', '')
    : number_format(0, 2, '.', '');

$requestedWeek = trim((string)($_GET['week_start'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedWeek)) {
    $requestedWeek = $weekOptions[0];
}
if (!in_array($requestedWeek, $weekOptions, true)) {
    $requestedWeek = $weekOptions[0];
}

$weekStart = $requestedWeek;
$weekEnd = week_end_from_start($weekStart);
$vendorPayoutAmount = 0.0;
$vendorPayoutInput = '';
$saveMessage = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['action'] ?? '') === 'save_vendor_payout')) {
    $postedWeekStart = trim((string)($_POST['week_start'] ?? $weekStart));
    $selectedPayoutVendor = normalize_payout_vendor_scope($_POST['payout_vendor'] ?? $selectedPayoutVendor);
    $postedAmountRaw = trim((string)($_POST['vendor_payout_amount'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $postedWeekStart)) {
        $weekStart = $postedWeekStart;
        $weekEnd = week_end_from_start($weekStart);
    }
    $vendorPayoutInput = $postedAmountRaw;
    if ($postedAmountRaw === '' || !is_numeric(str_replace(',', '', $postedAmountRaw))) {
        $saveMessage = 'Enter a valid Client Payout Amount.';
    } else {
        $vendorPayoutAmount = round((float)str_replace(',', '', $postedAmountRaw), 2);
        $updatedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        try {
            ensure_owner_payout_inputs_table($mysqli);
            if (owner_payout_inputs_has_vendor($mysqli)) {
                $existingId = 0;
                $find = $mysqli->prepare(
                    "SELECT id FROM owner_payout_inputs WHERE payout_week_start = ? AND payout_vendor = ? ORDER BY id DESC LIMIT 1"
                );
                $find->bind_param('ss', $weekStart, $selectedPayoutVendor);
                $find->execute();
                $find->bind_result($existingIdRaw);
                if ($find->fetch()) {
                    $existingId = (int)$existingIdRaw;
                }
                $find->close();

                if ($existingId > 0) {
                    $stmt = $mysqli->prepare(
                        "UPDATE owner_payout_inputs
                            SET vendor_payout_amount = ?, updated_by = ?
                          WHERE id = ?
                          LIMIT 1"
                    );
                    $stmt->bind_param('dii', $vendorPayoutAmount, $updatedBy, $existingId);
                } else {
                    $stmt = $mysqli->prepare(
                        "INSERT INTO owner_payout_inputs (payout_week_start, payout_vendor, vendor_payout_amount, updated_by)
                         VALUES (?, ?, ?, ?)"
                    );
                    $stmt->bind_param('ssdi', $weekStart, $selectedPayoutVendor, $vendorPayoutAmount, $updatedBy);
                }
            } else {
                $stmt = $mysqli->prepare(
                    "INSERT INTO owner_payout_inputs (payout_week_start, vendor_payout_amount, updated_by)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                       vendor_payout_amount = VALUES(vendor_payout_amount),
                       updated_by = VALUES(updated_by)"
                );
                $stmt->bind_param('sdi', $weekStart, $vendorPayoutAmount, $updatedBy);
            }
            $stmt->execute();
            $stmt->close();
            $saveMessage = 'Client Payout Amount saved.';
        } catch (Throwable $e) {
            error_log('owner payout save failed: ' . $e->getMessage());
            $saveMessage = 'Unable to save Client Payout Amount. Please try again.';
        }
    }
}

lonestar_auto_nextier_trailer_rental_deductions($mysqli, $weekStart, $weekEnd);

if (owner_payout_inputs_has_vendor($mysqli)) {
    $stmt = $mysqli->prepare(
        "SELECT vendor_payout_amount
           FROM owner_payout_inputs
          WHERE payout_week_start = ?
            AND payout_vendor = ?
          ORDER BY id DESC
          LIMIT 1"
    );
    $stmt->bind_param('ss', $weekStart, $selectedPayoutVendor);
} else {
    $stmt = $mysqli->prepare(
        "SELECT vendor_payout_amount
           FROM owner_payout_inputs
          WHERE payout_week_start = ?
          LIMIT 1"
    );
    $stmt->bind_param('s', $weekStart);
}
$stmt->execute();
$stmt->bind_result($vendorPayoutAmountRaw);
if ($stmt->fetch()) {
    $vendorPayoutAmount = (float)$vendorPayoutAmountRaw;
}
$stmt->close();
if ($vendorPayoutInput === '') {
    $vendorPayoutInput = number_format($vendorPayoutAmount, 2, '.', '');
}

$payableDriverRows = [];
$nonPayableDriverRows = [];
$showsBrokerageColumn = in_array($selectedPayoutVendor, ['tss', 'rtex', 'nextier', 'nickelrock'], true);
$grossDisplayTotal = 0.0;
$grossRevenueTotal = 0.0;
$netTotal = 0.0;
$brokerageFeeTotal = 0.0;
$fuelSurchargeTotal = 0.0;
$insuranceTotal = 0.0;
$fuelTotal = 0.0;
$miscRevenueTotal = 0.0;
$showSummaryBrokerageRow = $showsBrokerageColumn && !in_array($selectedPayoutVendor, ['tss', 'nextier'], true);
$showSummaryFuelSurchargeRow = false;

foreach (owner_report_driver_candidates($mysqli) as $driver) {
    $driverId = (int)($driver['driver_id'] ?? 0);
    if ($driverId <= 0) {
        continue;
    }

    $dataRows = owner_report_driver_week_rows($mysqli, $driverId, $weekStart, $selectedPayoutVendor);
    // This is a client-specific payroll register. Open balances and shared fuel
    // affect net pay only after the driver has loads for the selected client.
    if (!$dataRows) {
        continue;
    }
    $totalGross = round(array_sum(array_map(static fn($r) => (float)($r['tss_pay'] ?? 0), $dataRows)), 2);
    $trailerFeeTotal = round(array_sum(array_map(static fn($r) => (float)($r['trailer_fee'] ?? 0), $dataRows)), 2);
    if ($selectedPayoutVendor === 'nextier') {
        $trailerFeeTotal = trailer_fee_total_for_driver($mysqli, $driverId, $totalGross, $weekStart, $weekEnd, $selectedPayoutVendor);
    }
    $grossAfterTrailer = round($totalGross - $trailerFeeTotal, 2);
    $netBreakdown = lonestar_driver_week_net_breakdown($mysqli, $driverId, $totalGross, $trailerFeeTotal, $weekStart, $weekEnd, $selectedPayoutVendor, array_sum(array_column($dataRows, 'nextier_bonus')));
    $brokerageFee = (float)($netBreakdown['broker_amt'] ?? 0);
    $insurance = (float)($netBreakdown['insurance'] ?? 0);
    $net = (float)$netBreakdown['net_total'];
    $driverFuelSurchargeTotal = 0.0;
    if ($selectedPayoutVendor === 'nextier') {
        $driverFuelSurchargeTotal = round(array_sum(array_map(static fn($r) => (float)($r['nextier_fsc'] ?? 0), $dataRows)), 2);
        $net = round($net - (float)($netBreakdown['fuel_surcharge_total'] ?? 0) + $driverFuelSurchargeTotal, 2);
    } elseif ($selectedPayoutVendor === 'rtex') {
        $rtexDetails = ['broker_fee'=>(float)$netBreakdown['broker_amt'], 'total'=>(float)$netBreakdown['fuel_surcharge_total']];
        $brokerageFee = (float)$rtexDetails['broker_fee'];
        $driverFuelSurchargeTotal = (float)$rtexDetails['total'];
        $net = (float)$netBreakdown['net_total'];
    }
    $otherOpenBalanceTotal = lonestar_driver_other_open_balance_total($mysqli, $driverId, $selectedPayoutVendor);
    $net = lonestar_driver_total_net_after_open_balances($net, $netBreakdown, $otherOpenBalanceTotal);

    $driverRow = [
        'driver_id' => $driverId,
        'driver_name' => (string)($driver['legal_name'] ?? ''),
        'owner_op' => (string)($driver['owner_op'] ?? ''),
        'owner_email' => (string)($driver['owner_email'] ?? ''),
        'dot_number' => (string)($driver['dot_number'] ?? ''),
        'gross_total' => $grossAfterTrailer,
        'brokerage_fee' => $brokerageFee,
        'insurance' => $insurance,
        'fuel_surcharge' => $driverFuelSurchargeTotal,
        'net_total' => $net,
    ];
    if ($net <= 0.0) {
        $nonPayableDriverRows[] = $driverRow;
        continue;
    }
    $payableDriverRows[] = $driverRow;
    $grossDisplayTotal += $grossAfterTrailer;
    $grossRevenueTotal += $totalGross;
    $brokerageFeeTotal += $brokerageFee;
    $fuelSurchargeTotal += $driverFuelSurchargeTotal;
    $insuranceTotal += $insurance;
    $fuelTotal += (float)($netBreakdown['fuel'] ?? 0);
    $miscRevenueTotal += (float)($netBreakdown['misc_adjustment_total'] ?? 0);
    $netTotal += $net;
}

$driverRows = $showNonPayableDrivers
    ? array_merge($payableDriverRows, $nonPayableDriverRows)
    : $payableDriverRows;

if ($selectedPayoutVendor === 'nickelrock') {
    $vendorPayoutAmount = round($grossRevenueTotal, 2);
    $vendorPayoutInput = number_format($vendorPayoutAmount, 2, '.', '');
}

$dotRows = [];
$dotTotals = [];
foreach ($payableDriverRows as $row) {
    $dot = $row['dot_number'];
    if ($dot === '') continue;
    if (!isset($dotTotals[$dot])) {
        $dotTotals[$dot] = [
        'owner_op' => $row['owner_op'],
        'owner_email' => $row['owner_email'],
        'driver_ids' => [],
        'brokerage_fee' => 0.0,
        'net_total' => 0.0,
    ];
    }
    $dotTotals[$dot]['driver_ids'][] = (int)($row['driver_id'] ?? 0);
    $dotTotals[$dot]['brokerage_fee'] += (float)($row['brokerage_fee'] ?? 0);
    $dotTotals[$dot]['net_total'] += $row['net_total'];
}
foreach ($dotTotals as $dot => $data) {
    $dotRows[] = [
        'dot_number' => $dot,
        'owner_op' => $data['owner_op'],
        'owner_email' => $data['owner_email'],
        'driver_ids' => array_values(array_unique(array_filter(array_map('intval', $data['driver_ids'] ?? [])))),
        'brokerage_fee' => round((float)($data['brokerage_fee'] ?? 0), 2),
        'net_total' => round($data['net_total'], 2),
    ];
}

$statementSendMessage = '';
$statementSendMessageType = 'success';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['action'] ?? '') === 'send_owner_statements')) {
    $selectedPayoutVendor = normalize_payout_vendor_scope($_POST['payout_vendor'] ?? $selectedPayoutVendor);
    $weekStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['week_start'] ?? '')) ? (string)$_POST['week_start'] : $weekStart;
    $weekEnd = week_end_from_start($weekStart);
    $sendDots = [];
    if (trim((string)($_POST['send_dot'] ?? '')) !== '') {
        $sendDots[] = trim((string)$_POST['send_dot']);
    } else {
        $sendDots = array_map('trim', $_POST['dot_numbers'] ?? []);
    }
    $sendDots = array_values(array_unique(array_filter($sendDots, static fn($v) => $v !== '')));
    if (!$sendDots) {
        $statementSendMessage = 'Select at least one DOT row to send statements.';
        $statementSendMessageType = 'danger';
    } else {
        $sentCount = 0;
        $errorsForSend = [];
        $dotRowsByNumber = [];
        foreach ($dotRows as $dotRow) {
            $dotRowsByNumber[(string)$dotRow['dot_number']] = $dotRow;
        }
        foreach ($sendDots as $dotNumber) {
            if (!isset($dotRowsByNumber[$dotNumber])) {
                $errorsForSend[] = "DOT {$dotNumber}: row not found.";
                continue;
            }
            $dotRow = $dotRowsByNumber[$dotNumber];
            $attachments = [];
            foreach ($dotRow['driver_ids'] ?? [] as $driverIdForAttachment) {
                $attachments = array_merge(
                    $attachments,
                    owner_report_statement_files($mysqli, (int)$driverIdForAttachment, $selectedPayoutVendor, $weekEnd)
                );
            }
            $attachments = array_values(array_unique($attachments));
            $error = owner_report_send_statement_email(
                trim((string)($dotRow['owner_email'] ?? '')),
                trim((string)($dotRow['owner_op'] ?? '')),
                payout_vendor_scope_label($selectedPayoutVendor),
                $weekStart,
                $weekEnd,
                $attachments
            );
            if ($error === '') {
                $sentCount++;
            } else {
                $errorsForSend[] = "DOT {$dotNumber}: {$error}";
            }
        }
        if ($errorsForSend) {
            $statementSendMessageType = $sentCount > 0 ? 'warning' : 'danger';
            $statementSendMessage = "{$sentCount} statement email" . ($sentCount === 1 ? '' : 's') . " sent. " . implode(' ', $errorsForSend);
        } else {
            $statementSendMessage = "{$sentCount} statement email" . ($sentCount === 1 ? '' : 's') . " sent.";
        }
    }
}

$insuranceTotal = round($insuranceTotal, 2);
$fuelTotal = round($fuelTotal, 2);
$miscRevenueTotal = round($miscRevenueTotal, 2);
$nickelRockManagementFee = $selectedPayoutVendor === 'nickelrock'
    ? round($vendorPayoutAmount * 0.05, 2)
    : 0.0;

$lsWeeklyRevenue = round($grossDisplayTotal, 2);
$profitPayoutAmount = round($vendorPayoutAmount - $nickelRockManagementFee, 2);
$totalProfit = lonestar_owner_payout_profit_total($profitPayoutAmount, $netTotal, $insuranceTotal, $fuelTotal);
lonestar_owner_payout_summary_save(
    $mysqli,
    $weekStart,
    $selectedPayoutVendor,
    $vendorPayoutAmount,
    $netTotal,
    $fuelTotal,
    $brokerageFeeTotal,
    $miscRevenueTotal,
    $totalProfit
);
$companyProfit = round($totalProfit * 0.52, 2);
$employeeSplit = round($totalProfit * 0.08, 2);
$partner1Split = round($totalProfit * 0.20, 2);
$partner2Split = round($totalProfit * 0.20, 2);

if (($_GET['export_dot'] ?? '') === 'csv') {
    $filename = 'owner_payout_report_by_dot_' . $selectedPayoutVendor . '_' . $weekStart . '_to_' . $weekEnd . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fputcsv($out, [payout_vendor_scope_label($selectedPayoutVendor) . ' Owner Payout Report by DOT']);
        fputcsv($out, ['Week', $weekStart . ' to ' . $weekEnd]);
        fputcsv($out, ['Client', payout_vendor_scope_label($selectedPayoutVendor)]);
        if ($selectedPayoutVendor === 'nickelrock') {
            fputcsv($out, ['Brokerage Fee %', $nickelRockBrokerageRateInput]);
        }
        fputcsv($out, []);

        fputcsv($out, $showsBrokerageColumn
            ? ['DOT Number', 'Owner OP', 'Owner Email', 'LS Brokerage Fee', 'Net Total']
            : ['DOT Number', 'Owner OP', 'Owner Email', 'Net Total']
        );
        foreach ($dotRows as $r) {
            $csvRow = [
                $r['dot_number'],
                $r['owner_op'],
                $r['owner_email'],
            ];
            if ($showsBrokerageColumn) {
                $csvRow[] = number_format((float)($r['brokerage_fee'] ?? 0), 2, '.', '');
            }
            $csvRow[] = number_format((float)$r['net_total'], 2, '.', '');
            fputcsv($out, $csvRow);
        }
        fclose($out);
    }
    exit;
}

if (($_GET['export'] ?? '') === 'csv') {
    $filename = 'owner_payout_report_' . $selectedPayoutVendor . '_' . $weekStart . '_to_' . $weekEnd . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fputcsv($out, [payout_vendor_scope_label($selectedPayoutVendor) . ' Owner Payout Report']);
        fputcsv($out, ['Week', $weekStart . ' to ' . $weekEnd]);
        fputcsv($out, ['Client', payout_vendor_scope_label($selectedPayoutVendor)]);
        if ($selectedPayoutVendor === 'nickelrock') {
            fputcsv($out, ['Brokerage Fee %', $nickelRockBrokerageRateInput]);
        }
        fputcsv($out, []);

        fputcsv($out, $showsBrokerageColumn
            ? ['Driver Name', 'LS Brokerage Fee', 'Net Total']
            : ['Driver Name', 'Net Total']
        );
        foreach ($payableDriverRows as $r) {
            $csvRow = [$r['driver_name']];
            if ($showsBrokerageColumn) {
                $csvRow[] = number_format((float)($r['brokerage_fee'] ?? 0), 2, '.', '');
            }
            $csvRow[] = number_format((float)$r['net_total'], 2, '.', '');
            fputcsv($out, $csvRow);
        }
        $totalCsvRow = ['Total'];
        if ($showsBrokerageColumn) {
            $totalCsvRow[] = number_format($brokerageFeeTotal, 2, '.', '');
        }
        $totalCsvRow[] = number_format($netTotal, 2, '.', '');
        fputcsv($out, $totalCsvRow);
        fputcsv($out, []);

        fputcsv($out, ['Category', 'Amount']);
        fputcsv($out, ['Client Payout Amount', number_format($vendorPayoutAmount, 2, '.', '')]);
        if ($selectedPayoutVendor === 'nickelrock') {
            fputcsv($out, ["Nickel Rock Management Fee (5%)", number_format($nickelRockManagementFee, 2, '.', '')]);
        }
        fputcsv($out, ["Driver's Net Total", number_format($netTotal, 2, '.', '')]);
        if ($showSummaryBrokerageRow) {
            fputcsv($out, ['LS Brokerage Fee', number_format($brokerageFeeTotal, 2, '.', '')]);
        }
        if ($showSummaryFuelSurchargeRow) {
            fputcsv($out, ['Fuel Surcharge', number_format($fuelSurchargeTotal, 2, '.', '')]);
        }
        fputcsv($out, ['Insurance Costs', number_format($insuranceTotal, 2, '.', '')]);
        fputcsv($out, ['Fuel Costs', number_format($fuelTotal, 2, '.', '')]);
        fputcsv($out, ['Total Profit', number_format($totalProfit, 2, '.', '')]);
        fputcsv($out, ['Company Profit (52%)', number_format($companyProfit, 2, '.', '')]);
        fputcsv($out, ['Employee Split (8%)', number_format($employeeSplit, 2, '.', '')]);
        fputcsv($out, ['Partner 1 Split (20%)', number_format($partner1Split, 2, '.', '')]);
        fputcsv($out, ['Partner 2 Split (20%)', number_format($partner2Split, 2, '.', '')]);
        fclose($out);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payout Reports</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    body { margin:0; font-family:sans-serif; }
    .page-shell { display:flex; min-height: calc(100vh - var(--banner-h)); }
    .sidebar { width:250px; background:#333; color:#fff; height:calc(100vh - var(--banner-h)); position:fixed; top:var(--banner-h); overflow:auto; transition:transform .3s ease; }
    .sidebar.collapsed { transform: translateX(-250px); }
    .sidebar a { display:block; color:#fff; padding:15px; text-decoration:none; }
    .sidebar a:hover { background:#444; }
    .main { margin-left:250px; padding:20px; flex:1; min-width:0; }
    .sidebar.collapsed + .main { margin-left:0; }
    .report-card {
      border: 3px solid #222;
      border-radius: 12px;
      padding: 16px;
      background: #f9fafb;
      box-shadow: 0 8px 18px rgba(0,0,0,0.08);
    }
    .muted-summary-row > td {
      background: #e5e7eb;
      color: #4b5563;
    }
    .partner-summary-row > td {
      background: #dcfce7;
      color: #166534;
    }
    .negative-summary-row > td {
      color: #b91c1c;
    }
    .vendor-tabs {
      display: flex;
      flex-wrap: wrap;
      gap: 0;
      align-items: flex-end;
      border-bottom: 1px solid #9ca3af;
      margin: 0 0 18px;
    }
    .vendor-tab {
      background: #e5e7eb;
      border: 1px solid #9ca3af;
      border-bottom: 0;
      border-radius: 8px 8px 0 0;
      color: #111827;
      font-weight: 600;
      margin: 0 0 -1px -1px;
      padding: 9px 18px;
      text-decoration: none;
      transition: background-color .12s ease, color .12s ease;
    }
    .vendor-tab:first-child { margin-left: 0; }
    .vendor-tab:hover {
      background: #f3f4f6;
      color: #111827;
    }
    .vendor-tab.active {
      background: #ffffff;
      border-color: #9ca3af;
      border-bottom: 1px solid #ffffff;
      position: relative;
      z-index: 1;
    }
    @media(max-width:768px) {
      .sidebar { transform:translateX(-250px); }
      .sidebar.open { transform: translateX(0); }
      .main { margin:0; }
      .vendor-tab {
        flex: 1 1 50%;
        margin-top: -1px;
        text-align: center;
      }
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main">
      <h1>Payout Reports</h1>

      <?php if ($saveMessage !== ''): ?>
        <div class="alert <?= strpos($saveMessage, 'saved') !== false ? 'alert-success' : 'alert-danger' ?> mb-3">
          <?= h($saveMessage) ?>
        </div>
      <?php endif; ?>
      <?php if ($statementSendMessage !== ''): ?>
        <div class="alert alert-<?= h($statementSendMessageType) ?> mb-3">
          <?= h($statementSendMessage) ?>
        </div>
      <?php endif; ?>

      <nav class="vendor-tabs" aria-label="Payout client">
        <?php foreach (payout_vendor_scope_options() as $value => $label): ?>
          <?php
            $isActiveVendorTab = $selectedPayoutVendor === $value;
            $vendorTabUrl = '?week_start=' . rawurlencode($weekStart) . '&payout_vendor=' . rawurlencode($value)
              . ($showNonPayableDrivers ? '&show_nonpayable=1' : '');
          ?>
          <a
            href="<?= h($vendorTabUrl) ?>"
            class="vendor-tab vendor-tab-<?= h($value) ?><?= $isActiveVendorTab ? ' active' : '' ?>"
            <?= $isActiveVendorTab ? 'aria-current="page"' : '' ?>
          ><?= h($label) ?></a>
        <?php endforeach; ?>
      </nav>

      <form method="post" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="action" value="save_vendor_payout">
        <input type="hidden" name="payout_vendor" value="<?= h($selectedPayoutVendor) ?>">
        <input type="hidden" name="show_nonpayable" value="<?= $showNonPayableDrivers ? '1' : '0' ?>">
        <div class="col-12 col-md-4">
          <label for="week_start" class="form-label">Week (Sunday to Saturday)</label>
          <select id="week_start" name="week_start" class="form-select" onchange="window.location='?week_start=' + encodeURIComponent(this.value) + '&payout_vendor=<?= h($selectedPayoutVendor) ?><?= $showNonPayableDrivers ? '&show_nonpayable=1' : '' ?>'">
            <?php foreach ($weekOptions as $ws): ?>
              <?php $we = week_end_from_start($ws); ?>
              <option value="<?= h($ws) ?>" <?= $ws === $weekStart ? 'selected' : '' ?>>
                <?= h($ws) ?> to <?= h($we) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-3">
          <label for="vendor_payout_amount" class="form-label">Client Payout Amount</label>
          <input
            type="number"
            step="0.01"
            min="0"
            id="vendor_payout_amount"
            name="vendor_payout_amount"
            value="<?= h($vendorPayoutInput) ?>"
            class="form-control"
            <?= $selectedPayoutVendor === 'nickelrock' ? 'readonly' : '' ?>
          >
        </div>
        <div class="col-12 col-md-auto">
          <button type="submit" class="btn btn-primary">Save Amount</button>
        </div>
      </form>

      <form method="get" class="mb-3">
        <input type="hidden" name="week_start" value="<?= h($weekStart) ?>">
        <input type="hidden" name="payout_vendor" value="<?= h($selectedPayoutVendor) ?>">
        <div class="form-check form-switch">
          <input
            class="form-check-input"
            type="checkbox"
            role="switch"
            id="show_nonpayable"
            name="show_nonpayable"
            value="1"
            <?= $showNonPayableDrivers ? 'checked' : '' ?>
            onchange="this.form.submit()"
          >
          <label class="form-check-label" for="show_nonpayable">Show zero and negative drivers</label>
        </div>
      </form>

      <form method="get" class="mb-3">
        <input type="hidden" name="week_start" value="<?= h($weekStart) ?>">
        <input type="hidden" name="payout_vendor" value="<?= h($selectedPayoutVendor) ?>">
        <input type="hidden" name="show_nonpayable" value="<?= $showNonPayableDrivers ? '1' : '0' ?>">
        <div class="col-12 col-md-auto">
          <button type="submit" name="export" value="csv" class="btn btn-outline-primary">Export CSV</button>
        </div>
      </form>

      <form method="get" class="mb-3">
        <input type="hidden" name="week_start" value="<?= h($weekStart) ?>">
        <input type="hidden" name="payout_vendor" value="<?= h($selectedPayoutVendor) ?>">
        <input type="hidden" name="show_nonpayable" value="<?= $showNonPayableDrivers ? '1' : '0' ?>">
        <div class="col-12 col-md-auto">
          <button type="submit" name="export_dot" value="csv" class="btn btn-outline-secondary">Export DOT CSV</button>
        </div>
      </form>

      <div class="report-card">
        <h5 class="mb-3"><?= h(payout_vendor_scope_label($selectedPayoutVendor)) ?> Week: <?= h($weekStart) ?> to <?= h($weekEnd) ?></h5>

        <div class="table-responsive mb-4">
          <table class="table table-bordered table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>Driver Name</th>
                <th>Owner OP</th>
                <th>DOT Number</th>
                <?php if ($showsBrokerageColumn): ?>
                  <th class="text-end">LS Brokerage Fee</th>
                <?php endif; ?>
                <th class="text-end">Net Total</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$driverRows): ?>
                <tr>
                  <td colspan="<?= $showsBrokerageColumn ? '5' : '4' ?>" class="text-muted">No payout data found for this week.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($driverRows as $r): ?>
                  <tr<?= (float)$r['net_total'] <= 0.0 ? ' class="table-secondary"' : '' ?>>
                    <td><?= h($r['driver_name']) ?></td>
                    <td><?= h($r['owner_op']) ?></td>
                    <td><?= h($r['dot_number']) ?></td>
                    <?php if ($showsBrokerageColumn): ?>
                      <td class="text-end"><?= money($r['brokerage_fee'] ?? 0) ?></td>
                    <?php endif; ?>
                    <td class="text-end"><?= money($r['net_total']) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
            <tfoot>
              <tr class="fw-bold">
                <td>Total</td>
                <td></td>
                <td></td>
                <?php if ($showsBrokerageColumn): ?>
                  <td class="text-end"><?= money($brokerageFeeTotal) ?></td>
                <?php endif; ?>
                <td class="text-end"><?= money($netTotal) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>

        <h5 class="mb-3">By DOT Number</h5>
        <form id="dotStatementSendForm" method="post" class="mb-2">
          <input type="hidden" name="action" value="send_owner_statements">
          <input type="hidden" name="week_start" value="<?= h($weekStart) ?>">
          <input type="hidden" name="payout_vendor" value="<?= h($selectedPayoutVendor) ?>">
          <input type="hidden" name="show_nonpayable" value="<?= $showNonPayableDrivers ? '1' : '0' ?>">
          <button
            type="submit"
            class="btn btn-sm btn-outline-primary"
            onclick="return confirm('Send statements for all selected DOT rows?');"
          >Send Selected Statements</button>
        </form>
        <div class="table-responsive mb-4">
          <table class="table table-bordered table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th><input form="dotStatementSendForm" type="checkbox" id="selectAllDotStatements" aria-label="Select all DOT rows"></th>
                <th>DOT Number</th>
                <th>Owner OP</th>
                <th>Email</th>
                <?php if ($showsBrokerageColumn): ?>
                  <th class="text-end">LS Brokerage Fee</th>
                <?php endif; ?>
                <th class="text-end">Net Total</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$dotRows): ?>
                <tr>
                  <td colspan="<?= $showsBrokerageColumn ? '7' : '6' ?>" class="text-muted">No DOT data found for this week.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($dotRows as $r): ?>
                  <tr>
                    <td>
                      <input
                        form="dotStatementSendForm"
                        type="checkbox"
                        name="dot_numbers[]"
                        value="<?= h($r['dot_number']) ?>"
                        class="dot-statement-checkbox"
                        aria-label="Select DOT <?= h($r['dot_number']) ?>"
                      >
                    </td>
                    <td><?= h($r['dot_number']) ?></td>
                    <td><?= h($r['owner_op']) ?></td>
                    <td>
                      <?php if ($r['owner_email']): ?>
                        <a href="mailto:<?= h($r['owner_email']) ?>"><?= h($r['owner_email']) ?></a>
                      <?php else: ?>
                        &ndash;
                      <?php endif; ?>
                    </td>
                    <?php if ($showsBrokerageColumn): ?>
                      <td class="text-end"><?= money($r['brokerage_fee'] ?? 0) ?></td>
                    <?php endif; ?>
                    <td class="text-end"><?= money($r['net_total']) ?></td>
                    <td class="text-end">
                      <button
                        form="dotStatementSendForm"
                        type="submit"
                        name="send_dot"
                        value="<?= h($r['dot_number']) ?>"
                        class="btn btn-sm btn-outline-primary"
                        onclick="return confirm(<?= h(json_encode('Send statements for DOT ' . (string)$r['dot_number'] . '?')) ?>);"
                      >Send Statements</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
            <tfoot>
              <tr class="fw-bold">
                <td></td>
                <td>Total</td>
                <td></td>
                <td></td>
                <?php if ($showsBrokerageColumn): ?>
                  <td class="text-end"><?= money(array_sum(array_column($dotRows, 'brokerage_fee'))) ?></td>
                <?php endif; ?>
                <td class="text-end"><?= money(array_sum(array_column($dotRows, 'net_total'))) ?></td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>

        <div class="table-responsive">
          <table class="table table-bordered table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>Category</th>
                <th class="text-end">Amount</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Client Payout Amount</td>
                <td class="text-end"><?= money($vendorPayoutAmount) ?></td>
              </tr>
              <?php if ($selectedPayoutVendor === 'nickelrock'): ?>
                <tr class="negative-summary-row">
                  <td>Nickel Rock Management Fee (5%)</td>
                  <td class="text-end"><?= money($nickelRockManagementFee) ?></td>
                </tr>
              <?php endif; ?>
              <tr class="negative-summary-row">
                <td>Driver's Net Total</td>
                <td class="text-end"><?= money($netTotal) ?></td>
              </tr>
              <?php if ($showSummaryBrokerageRow): ?>
                <tr>
                  <td>LS Brokerage Fee</td>
                  <td class="text-end"><?= money($brokerageFeeTotal) ?></td>
                </tr>
              <?php endif; ?>
              <?php if ($showSummaryFuelSurchargeRow): ?>
                <tr>
                  <td>Fuel Surcharge</td>
                  <td class="text-end"><?= money($fuelSurchargeTotal) ?></td>
                </tr>
              <?php endif; ?>
              <tr class="negative-summary-row">
                <td>Insurance Costs</td>
                <td class="text-end"><?= money($insuranceTotal) ?></td>
              </tr>
              <tr class="negative-summary-row">
                <td>Fuel Costs</td>
                <td class="text-end"><?= money($fuelTotal) ?></td>
              </tr>
              <tr class="fw-bold">
                <td>Total Profit</td>
                <td class="text-end"><?= money($totalProfit) ?></td>
              </tr>
              <tr>
                <td>Company Profit (52%)</td>
                <td class="text-end"><?= money($companyProfit) ?></td>
              </tr>
              <tr class="partner-summary-row">
                <td>Employee Split (8%)</td>
                <td class="text-end"><?= money($employeeSplit) ?></td>
              </tr>
              <tr class="partner-summary-row">
                <td>Partner 1 Split (20%)</td>
                <td class="text-end"><?= money($partner1Split) ?></td>
              </tr>
              <tr class="partner-summary-row">
                <td>Partner 2 Split (20%)</td>
                <td class="text-end"><?= money($partner2Split) ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <script>
    document.getElementById('selectAllDotStatements')?.addEventListener('change', function () {
      document.querySelectorAll('.dot-statement-checkbox').forEach((checkbox) => {
        checkbox.checked = this.checked;
      });
    });
  </script>
</body>
</html>
