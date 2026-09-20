<?php
// public_html/reports.php (patched + combined matched/unmatched logic)
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php'; // $mysqli
require __DIR__ . '/includes/payout_net_helpers.php';
$showDisabled = isset($_REQUEST['show_disabled']) && $_REQUEST['show_disabled'] === '1';
const BUSINESS_SOURCE_TIMEZONE = 'America/Chicago';

// Helper: check if a table has a given column (runtime schema detection)
function table_has_column(mysqli $db, $table, $column){
    $table = $db->real_escape_string($table);
    $column= $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function table_exists(mysqli $db, $table){
    $table = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$table}'");
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
        return "{$column} = 'RTEX'";
    }
    if ($scope === 'nickelrock') {
        return "UPPER({$column}) IN ('NICKEL ROCK', 'NICKELROCK')";
    }
    if ($scope === 'nextier') {
        return "{$column} = 'NexTier'";
    }
    return "{$column} IN ('TSS', 'TSS Misc Revenue', 'TSS Split (Pickup)', 'TSS Split (Delivery)')";
}

$selectedPayoutVendor = normalize_payout_vendor_scope($_REQUEST['payout_vendor'] ?? 'tss');

function normalize_statement_basis($basis): string {
    return trim((string)$basis) === 'upload_date' ? 'upload_date' : 'work_date';
}

function statement_basis_sql_column(string $basis, string $alias = 'dp'): string {
    $column = normalize_statement_basis($basis) === 'upload_date' ? 'upload_date' : 'payout_date';
    return $alias !== '' ? "{$alias}.{$column}" : $column;
}

function statement_basis_date_where(string $basis, string $alias = 'dp'): string {
    $column = statement_basis_sql_column($basis, $alias);
    return "{$column} BETWEEN ? AND ?";
}

function statement_basis_date_param_types(string $basis): string {
    return 'ss';
}

function statement_basis_date_params(string $basis, string $weekStart, string $weekEnd): array {
    return [$weekStart, $weekEnd];
}

function statement_basis_label(string $basis): string {
    return normalize_statement_basis($basis) === 'upload_date'
        ? 'Off-cycle / Statement Upload Date'
        : 'Work Week';
}

function statement_row_work_date_display($workDate, string $weekStart, string $weekEnd, string $basis): string {
    $date = business_normalize_date($workDate);
    if ($date === '') {
        return (string)$workDate;
    }
    if (normalize_statement_basis($basis) === 'upload_date' && ($date < $weekStart || $date > $weekEnd)) {
        return $date . ' *';
    }
    return $date;
}

function offcycle_primary_work_week_start(mysqli $mysqli, string $vendorScope, string $uploadDate): string {
    static $cache = [];
    $uploadDate = business_normalize_date($uploadDate);
    if ($uploadDate === '') {
        return '';
    }
    $scope = normalize_payout_vendor_scope($vendorScope);
    $key = $scope . '|' . $uploadDate;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $vendorWhere = payout_vendor_sql_condition($scope, '');
    $stmt = $mysqli->prepare(
        "SELECT DATE_SUB(payout_date, INTERVAL (DAYOFWEEK(payout_date) - 1) DAY) AS week_start,
                COUNT(*) AS row_count
           FROM driver_payouts
          WHERE upload_date = ?
            AND {$vendorWhere}
          GROUP BY week_start
          ORDER BY row_count DESC, week_start DESC
          LIMIT 1"
    );
    if (!$stmt) {
        $cache[$key] = '';
        return '';
    }
    $stmt->bind_param('s', $uploadDate);
    $stmt->execute();
    $stmt->bind_result($weekStart, $rowCount);
    $cache[$key] = $stmt->fetch() ? (string)$weekStart : '';
    $stmt->close();
    return $cache[$key];
}

function payout_row_matches_statement_basis(mysqli $mysqli, array $row, string $vendorScope, string $statementBasis): bool {
    if (normalize_statement_basis($statementBasis) !== 'upload_date') {
        return true;
    }
    $uploadDate = business_normalize_date($row['upload_date'] ?? '');
    $workDate = business_normalize_date($row['payout_date'] ?? '');
    if ($uploadDate === '' || $workDate === '') {
        return false;
    }
    $primaryWeek = offcycle_primary_work_week_start($mysqli, $vendorScope, $uploadDate);
    if ($primaryWeek === '') {
        return false;
    }
    return business_week_bounds($workDate)['start'] !== $primaryWeek;
}

function business_tz(): DateTimeZone {
    return new DateTimeZone(BUSINESS_SOURCE_TIMEZONE);
}

function business_normalize_date($value): string {
    $v = trim((string)$value);
    if ($v === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v, $m)) {
        return $m[0];
    }
    if (preg_match('/^\d+(\.\d+)?$/', $v)) {
        $base = new DateTimeImmutable('1899-12-30 00:00:00', business_tz());
        $seconds = (int)round(((float)$v) * 86400);
        $dt = $base->modify('+' . $seconds . ' seconds');
        return $dt instanceof DateTimeImmutable ? $dt->format('Y-m-d') : '';
    }
    try {
        return (new DateTimeImmutable($v, business_tz()))->setTimezone(business_tz())->format('Y-m-d');
    } catch (Throwable $e) {
        return $v;
    }
}

function business_week_bounds(string $ymd): array {
    try {
        $dt = new DateTimeImmutable($ymd, business_tz());
    } catch (Throwable $e) {
        $dt = new DateTimeImmutable('now', business_tz());
    }
    $dow = (int)$dt->format('w');
    $start = $dt->modify('-' . $dow . ' days');
    $end = $start->modify('+6 days');
    return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')];
}

// ---------- Helpers for "download all" and single-driver workbook ----------
function sanitize_filename($s){
    $s = preg_replace('/[^\w\-. ]+/', '_', $s);
    $s = trim($s);
    return $s !== '' ? $s : 'file';
}

function driver_filename(mysqli $db, int $driverId): string {
    $label = 'Driver_' . $driverId;
    if ($res = $db->query("SELECT CONCAT(first_name, ' ', last_name) AS nm FROM driver_contacts WHERE id=".(int)$driverId)) {
        if ($row = $res->fetch_assoc()) {
            $label = $row['nm'] ?? $label;
        }
        $res->close();
    }
    $label = str_replace(' ', '', $label);
    return sanitize_filename($label);
}

function statement_cache_dir(string $vendorScope, string $weekEnd): string {
    $vendorScope = normalize_payout_vendor_scope($vendorScope);
    $weekEnd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekEnd) ? $weekEnd : date('Y-m-d');
    return __DIR__ . '/statement_cache/' . $vendorScope . '/' . $weekEnd;
}

function cache_statement_pdf(string $vendorScope, string $weekEnd, string $filename, string $pdf): string {
    $dir = statement_cache_dir($vendorScope, $weekEnd);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || $pdf === '') {
        return '';
    }
    $path = $dir . '/' . sanitize_filename($filename);
    return file_put_contents($path, $pdf) !== false ? $path : '';
}

function driver_report_meta(mysqli $db, int $driverId): array {
    $meta = ['truck_no' => '', 'owner_operator_name' => '', 'driver_name' => ''];
    if ($res = $db->query("SELECT first_name, last_name, owner_name, truck_no, alt_truck_no FROM driver_contacts WHERE id=".(int)$driverId." LIMIT 1")) {
        if ($row = $res->fetch_assoc()) {
            $driverName = trim((string)(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
            $owner = trim((string)($row['owner_name'] ?? ''));
            $truck = trim((string)($row['truck_no'] ?? ''));
            $altTruck = trim((string)($row['alt_truck_no'] ?? ''));
            $meta['driver_name'] = $driverName;
            $meta['owner_operator_name'] = $owner;
            $meta['truck_no'] = $truck !== '' ? $truck : $altTruck;
        }
        $res->close();
    }
    return $meta;
}

function driver_owns_trailer(mysqli $db, int $driverId): bool {
    $stmt = $db->prepare("SELECT trailer_no FROM driver_contacts WHERE id=?");
    $stmt->bind_param('i', $driverId);
    $stmt->execute();
    $stmt->bind_result($trailerNo);
    $stmt->fetch();
    $stmt->close();
    if ($trailerNo === null) {
        return false;
    }
    $val = strtolower(trim($trailerNo));
    return in_array($val, ['own', 'owned', 'n/a'], true);
}

function trailer_percent_decimal_for_driver(mysqli $db, int $driverId, $overrideValue = null, string $periodStart = '', string $periodEnd = ''): float {
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
    return lonestar_driver_tss_trailer_percent_decimal($db, $driverId, $periodStart, $periodEnd);
}

function trailer_percent_label(float $decimal): string {
    $pct = round($decimal * 100, 2);
    $formatted = rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
    return $formatted . '%';
}
function broker_percentage_label(float $configuredPct): string {
    $pct = round($configuredPct * 100, 2);
    $formatted = rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
    return 'Broker Percentage ' . $formatted . '%';
}

function trailer_fee_amount(float $gross, float $decimal): float {
    return round($gross * $decimal, 2);
}

function trailer_asset_fee_config_for_driver(mysqli $db, int $driverId): array {
    $out = ['mode' => '', 'value' => null, 'received_date' => ''];
    if ($driverId <= 0 || !table_exists($db, 'trailer_assets') || !table_has_column($db, 'trailer_assets', 'trailer_fee_mode') || !table_has_column($db, 'trailer_assets', 'trailer_fee_value')) {
        return $out;
    }
    $hasReceivedDate = table_has_column($db, 'driver_contacts', 'trailer_received_date');
    $receivedSelect = $hasReceivedDate ? ', dc.trailer_received_date' : ", NULL AS trailer_received_date";
    $stmt = $db->prepare("
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

function is_tss_misc_revenue_vendor($vendorName): bool {
    return strtoupper(trim((string)$vendorName)) === 'TSS MISC REVENUE';
}

function is_nextier_vendor($vendorName): bool {
    return strtoupper(trim((string)$vendorName)) === 'NEXTIER';
}

function is_nickelrock_vendor($vendorName): bool {
    $vendor = strtoupper(trim((string)$vendorName));
    return $vendor === 'NICKEL ROCK' || $vendor === 'NICKELROCK';
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

function trailer_fee_total_for_driver(mysqli $db, int $driverId, float $gross, string $weekStart = '', string $weekEnd = '', string $vendorScope = ''): float {
    return lonestar_driver_trailer_fee_estimate($db, $driverId, $gross, $vendorScope, $weekStart, $weekEnd);
}

function nextier_payout_row_meta(mysqli $db, $ticketNumber, $payoutDate): array {
    $out = [
        'detail_match' => false,
        'line_haul' => null,
        'fsc_total' => 0.0,
        'bonus' => 0.0,
        'bol_number' => '',
        'base_rate' => '',
        'base_rate_raw' => 0.0,
        'net_weight_tons' => '',
        'net_weight_tons_raw' => 0.0,
        'mileage' => '',
    ];
    if (!table_exists($db, 'nextier_payout_rows')) {
        return $out;
    }
    $ticket = trim((string)$ticketNumber);
    $date = business_normalize_date($payoutDate);
    if ($ticket === '' || $date === '') {
        return $out;
    }

    $formatMoneyLike = static function ($value): string {
        $raw = trim((string)$value);
        if ($raw === '') return '';
        $num = str_replace([',', '$'], '', $raw);
        if (!is_numeric($num)) return $raw;
        return '$' . number_format((float)$num, 2);
    };
    $formatMileage = static function ($value): string {
        $raw = trim((string)$value);
        if ($raw === '') return '';
        $num = str_replace([','], '', $raw);
        if (!is_numeric($num)) return $raw;
        return number_format((float)$num, 0, '.', '');
    };
    $formatNetWeightTons = static function ($value): string {
        $raw = trim((string)$value);
        if ($raw === '') return '';
        $num = str_replace([','], '', $raw);
        if (!is_numeric($num)) return $raw;
        return rtrim(rtrim(number_format((float)$num, 2, '.', ''), '0'), '.');
    };

    $stmt = $db->prepare("
        SELECT line_haul, fsc_total, bonus, bol_number, rate, tons, miles
          FROM nextier_payout_rows
         WHERE work_date = ?
           AND TRIM(CONCAT(load_id, CASE WHEN COALESCE(bol_number, '') <> '' THEN CONCAT('-', bol_number) ELSE '' END)) = ?
         ORDER BY upload_date DESC, id DESC
         LIMIT 1
    ");
    if (!$stmt) return $out;
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
                'bol_number' => trim((string)($row['bol_number'] ?? '')),
                'base_rate' => $formatMoneyLike($row['rate'] ?? ''),
                'base_rate_raw' => (float)($row['rate'] ?? 0),
                'net_weight_tons' => $formatNetWeightTons($row['tons'] ?? ''),
                'net_weight_tons_raw' => (float)($row['tons'] ?? 0),
                'mileage' => $formatMileage($row['miles'] ?? ''),
            ];
        }
    } else {
        $stmt->bind_result($lineHaul, $fscTotal, $bonus, $bolNumber, $rate, $tons, $miles);
        if ($stmt->fetch()) {
            $out = [
                'detail_match' => true,
                'line_haul' => (float)$lineHaul,
                'fsc_total' => (float)$fscTotal,
                'bonus' => (float)$bonus,
                'bol_number' => trim((string)$bolNumber),
                'base_rate' => $formatMoneyLike($rate),
                'base_rate_raw' => (float)$rate,
                'net_weight_tons' => $formatNetWeightTons($tons),
                'net_weight_tons_raw' => (float)$tons,
                'mileage' => $formatMileage($miles),
            ];
        }
    }
    $stmt->close();
    return $out;
}

function nextier_report_extras(array $meta): array {
    return [
        'bol_number' => trim((string)($meta['bol_number'] ?? '')),
        'base_rate' => (string)($meta['base_rate'] ?? ''),
        'net_weight_tons' => (string)($meta['net_weight_tons'] ?? ''),
        'mileage' => (string)($meta['mileage'] ?? ''),
        'detail_match' => true,
    ];
}

function nextier_report_row_pay(array $meta, $fallbackPay): float {
    if (!empty($meta['detail_match'])) {
        $rate = (float)($meta['base_rate_raw'] ?? 0);
        $tons = (float)($meta['net_weight_tons_raw'] ?? 0);
        $bonus = (float)($meta['bonus'] ?? 0);
        if ($rate > 0.0 && $tons > 0.0) {
            return round(($rate * $tons) + $bonus, 2);
        }
        return round((float)($meta['line_haul'] ?? 0) - (float)($meta['fsc_total'] ?? 0), 2);
    }
    return round((float)$fallbackPay, 2);
}

function money_format_display($value): string {
    $num = (float)$value;
    return '$' . number_format($num, 2);
}

function fuel_balance_summary_rows(array $netBreakdown): array {
    $rows = [];
    $priorFuel = (float)($netBreakdown['prior_fuel_balance'] ?? 0.0);
    $remainingFuel = (float)($netBreakdown['remaining_fuel_after'] ?? 0.0);
    if (abs($priorFuel) > 0.009) {
        $rows[] = ['Prior Fuel Balance', $priorFuel];
    }
    if (abs($remainingFuel) > 0.009) {
        $rows[] = ['Remaining Fuel Balance', $remainingFuel];
    }
    return $rows;
}

function driver_report_other_open_balance_total(mysqli $mysqli, int $driverId, string $weekStart, string $vendorScope): float {
    return lonestar_driver_other_open_balance_total($mysqli, $driverId, $vendorScope);
}

function total_net_after_open_balances(float $subtotal, array $netBreakdown, float $otherOpenBalanceTotal = 0.0): float {
    return lonestar_driver_total_net_after_open_balances($subtotal, $netBreakdown, $otherOpenBalanceTotal);
}

function driver_week_fuel_usage_rows(mysqli $mysqli, int $driverId, string $weekStart, string $weekEnd): array {
    if (!table_exists($mysqli, 'fuel_report_transactions')) {
        return [];
    }
    $rows = [];
    $hasEntrySource = table_has_column($mysqli, 'fuel_report_transactions', 'entry_source');
    $entrySourceSelect = $hasEntrySource ? "COALESCE(entry_source, 'upload')" : "'upload'";
    $dateExpr = table_has_column($mysqli, 'fuel_report_transactions', 'visit_date')
        ? 'COALESCE(visit_date, txn_date)'
        : 'txn_date';
    $stmt = $mysqli->prepare(
        "SELECT {$dateExpr} AS txn_date, card, description, qty, gross_amt, fees_amt, {$entrySourceSelect} AS entry_source
           FROM fuel_report_transactions
          WHERE driver_contact_id = ?
            AND {$dateExpr} BETWEEN ? AND ?
          ORDER BY {$dateExpr} ASC, id ASC"
    );
    if (!$stmt) return [];
    $stmt->bind_param('iss', $driverId, $weekStart, $weekEnd);
    $stmt->execute();
    if (method_exists($stmt, 'get_result')) {
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $entrySource = (string)($r['entry_source'] ?? 'upload');
            $description = (string)($r['description'] ?? '');
            if ($entrySource === 'manual' && stripos($description, 'manual') === false) {
                $description .= ($description !== '' ? ' ' : '') . '[Manual]';
            }
            $rows[] = [
                'txn_date' => (string)($r['txn_date'] ?? ''),
                'card' => (string)($r['card'] ?? ''),
                'description' => $description,
                'qty' => (float)($r['qty'] ?? 0),
                'ppg' => ((float)($r['qty'] ?? 0) > 0.0) ? ((float)($r['gross_amt'] ?? 0) / (float)($r['qty'] ?? 0)) : 0.0,
                'gross_amt' => (float)($r['gross_amt'] ?? 0),
                'fees_amt' => (float)($r['fees_amt'] ?? 0),
            ];
        }
    } else {
        $stmt->bind_result($txnDate, $card, $description, $qty, $grossAmt, $feesAmt, $entrySource);
        while ($stmt->fetch()) {
            $qtyNum = (float)$qty;
            $amtNum = (float)$grossAmt;
            $descText = (string)$description;
            if ((string)$entrySource === 'manual' && stripos($descText, 'manual') === false) {
                $descText .= ($descText !== '' ? ' ' : '') . '[Manual]';
            }
            $rows[] = [
                'txn_date' => (string)$txnDate,
                'card' => (string)$card,
                'description' => $descText,
                'qty' => $qtyNum,
                'ppg' => ($qtyNum > 0.0) ? ($amtNum / $qtyNum) : 0.0,
                'gross_amt' => $amtNum,
                'fees_amt' => (float)$feesAmt,
            ];
        }
    }
    $stmt->close();
    return $rows;
}

function driver_week_fuel_transaction_total(array $fuelUsageRows): float {
    $total = 0.0;
    foreach ($fuelUsageRows as $row) {
        $total += (float)($row['gross_amt'] ?? 0.0) + (float)($row['fees_amt'] ?? 0.0);
    }
    return round($total, 2);
}

function driver_statement_fuel_transaction_total(array $fuelUsageRows, array $netBreakdown): float {
    $weeklyTransactionTotal = driver_week_fuel_transaction_total($fuelUsageRows);
    $collectedByEarlierVendors = max(0.0, (float)($netBreakdown['prior_collected_fuel'] ?? 0.0));
    return round(max(0.0, $weeklyTransactionTotal - $collectedByEarlierVendors), 2);
}

function driver_week_misc_adjustments(mysqli $mysqli, int $driverId, string $weekStart, string $vendorScope = 'tss'): array {
    $scope = normalize_payout_vendor_scope($vendorScope);
    $rows = [];
    $priorBalance = function_exists('lonestar_driver_open_misc_balance')
        ? lonestar_driver_open_misc_balance($mysqli, $driverId, $scope, $weekStart)
        : 0.0;
    if (abs($priorBalance) > 0.005) {
        $rows[] = [
            'label' => $priorBalance < 0 ? 'Prior Unpaid Balance' : 'Prior Credit Balance',
            'comments' => '',
            'amount' => $priorBalance,
        ];
    }
    if (!table_exists($mysqli, 'tss_misc_adjustments')) {
        return $rows;
    }
    $vendor = function_exists('lonestar_payout_vendor_code') ? lonestar_payout_vendor_code($scope) : ($scope === 'rtex' ? 'RTEX' : ($scope === 'nextier' ? 'NEXTIER' : ($scope === 'nickelrock' ? 'NICKELROCK' : 'TSS')));
    $hasVendorColumn = table_has_column($mysqli, 'tss_misc_adjustments', 'payout_vendor');
    if (!$hasVendorColumn && $vendor !== 'TSS') {
        return $rows;
    }
    $vendorWhere = $hasVendorColumn ? "AND " . lonestar_payout_vendor_sql_key('payout_vendor') . " = ?" : "";
    $stmt = $mysqli->prepare(
        "SELECT adjustment_type, amount, comments
           FROM tss_misc_adjustments
          WHERE driver_contact_id = ?
            AND payout_week_start = ?
            {$vendorWhere}
          ORDER BY created_at ASC, id ASC"
    );
    if (!$stmt) return $rows;
    if ($hasVendorColumn) {
        $stmt->bind_param('iss', $driverId, $weekStart, $vendor);
    } else {
        $stmt->bind_param('is', $driverId, $weekStart);
    }
    $stmt->execute();
    if (method_exists($stmt, 'get_result')) {
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $type = (string)($r['adjustment_type'] ?? 'misc_payment');
            if (function_exists('lonestar_is_nextier_trailer_rental_adjustment') && lonestar_is_nextier_trailer_rental_adjustment($vendor, $type, (string)($r['comments'] ?? ''))) {
                continue;
            }
            $amount = (float)($r['amount'] ?? 0);
            $signedAmount = ($type === 'misc_deduction') ? -1 * abs($amount) : abs($amount);
            $label = $type === 'misc_deduction' ? 'Misc Deduction' : 'Misc Payment';
            $rows[] = [
                'label' => $label,
                'comments' => (string)($r['comments'] ?? ''),
                'amount' => $signedAmount,
            ];
        }
    } else {
        $stmt->bind_result($type, $amount, $comments);
        while ($stmt->fetch()) {
            if (function_exists('lonestar_is_nextier_trailer_rental_adjustment') && lonestar_is_nextier_trailer_rental_adjustment($vendor, (string)$type, (string)$comments)) {
                continue;
            }
            $signedAmount = ((string)$type === 'misc_deduction') ? -1 * abs((float)$amount) : abs((float)$amount);
            $rows[] = [
                'label' => ((string)$type === 'misc_deduction') ? 'Misc Deduction' : 'Misc Payment',
                'comments' => (string)$comments,
                'amount' => $signedAmount,
            ];
        }
    }
    $stmt->close();
    return $rows;
}

function payout_vendor_label(mysqli $mysqli, $vendorName, $ticketNumber, $payoutDate, $payAmount): string {
    $vendor = trim((string)$vendorName);
    $ticket = trim((string)$ticketNumber);
    $date = trim((string)$payoutDate);
    $amt = (float)$payAmount;

    if ($vendor !== 'TSS Split (Pickup)' && $vendor !== 'TSS Split (Delivery)') {
        return $vendor;
    }
    if ($ticket === '' || $date === '') {
        return $vendor;
    }

    static $splitTotalCache = [];
    $cacheKey = $ticket . '|' . $date;
    if (!array_key_exists($cacheKey, $splitTotalCache)) {
        $sum = 0.0;
        $stmt = $mysqli->prepare(
            "SELECT COALESCE(SUM(CAST(tss_pay AS DECIMAL(12,2))),0) AS split_total
               FROM driver_payouts
              WHERE payout_date = ?
                AND ticket_number = ?
                AND vendor_name IN ('TSS Split (Pickup)', 'TSS Split (Delivery)')"
        );
        if ($stmt) {
            $stmt->bind_param('ss', $date, $ticket);
            $stmt->execute();
            if (method_exists($stmt, 'get_result')) {
                $row = $stmt->get_result()->fetch_assoc();
                $sum = (float)($row['split_total'] ?? 0);
            } else {
                $stmt->bind_result($splitTotal);
                if ($stmt->fetch()) $sum = (float)$splitTotal;
            }
            $stmt->close();
        }
        $splitTotalCache[$cacheKey] = $sum;
    }

    $total = (float)$splitTotalCache[$cacheKey];
    if ($total <= 0.0) {
        return $vendor;
    }

    $pct = round(($amt / $total) * 100, 2);
    $pctText = rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
    return $vendor . ' (' . $pctText . '%)';
}

function payout_row_trailer_meta(mysqli $mysqli, int $driverId, array $extras, $grossPay, $vendorName = '', string $payoutDate = ''): array {
    if (is_tss_misc_revenue_vendor($vendorName) || is_nextier_vendor($vendorName) || is_nickelrock_vendor($vendorName)) {
        return [
            'pct_decimal' => 0.0,
            'pct_label' => '0%',
            'fee_amount' => 0.0,
        ];
    }
    $payoutDateNorm = business_normalize_date($payoutDate);
    $pctDecimal = trailer_percent_decimal_for_driver($mysqli, $driverId, $extras['trailer_pct_override'] ?? null, $payoutDateNorm, $payoutDateNorm);
    $pctLabel = trailer_percent_label($pctDecimal);
    $feeAmount = trailer_fee_amount((float)$grossPay, $pctDecimal);
    return [
        'pct_decimal' => $pctDecimal,
        'pct_label' => $pctLabel,
        'fee_amount' => $feeAmount,
    ];
}

function payout_vendor_label_with_trailer(mysqli $mysqli, int $driverId, $vendorName, $ticketNumber, $payoutDate, $payAmount, array $extras): string {
    $vendorLabel = payout_vendor_label($mysqli, $vendorName, $ticketNumber, $payoutDate, $payAmount);
    if (is_nextier_vendor($vendorName) || is_nickelrock_vendor($vendorName)) {
        return $vendorLabel;
    }
    $meta = payout_row_trailer_meta($mysqli, $driverId, $extras, $payAmount, $vendorName, business_normalize_date($payoutDate));
    return $vendorLabel . ' [Trailer ' . $meta['pct_label'] . ']';
}

function xlsx_col(int $index): string {
    $col = '';
    $index += 1;
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $col = chr(65 + $mod) . $col;
        $index = (int)(($index - 1) / 26);
    }
    return $col;
}

function build_xlsx(array $sheets): string {
    $shared = [];
    $sharedIndex = [];
    $sheetXml = [];

    foreach ($sheets as $sIdx => $sheet) {
        $rows = $sheet['rows'] ?? [];
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<sheetData>';
        foreach ($rows as $rIdx => $row) {
            $rowNum = $rIdx + 1;
            $xml .= '<row r="' . $rowNum . '">';
            foreach ($row as $cIdx => $value) {
                $col = xlsx_col($cIdx) . $rowNum;
                if ($value === null || $value === '') {
                    continue;
                }
                if (is_numeric($value)) {
                    $xml .= '<c r="' . $col . '"><v>' . $value . '</v></c>';
                } else {
                    $key = (string)$value;
                    if (!array_key_exists($key, $sharedIndex)) {
                        $sharedIndex[$key] = count($shared);
                        $shared[] = $key;
                    }
                    $xml .= '<c r="' . $col . '" t="s"><v>' . $sharedIndex[$key] . '</v></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        $sheetXml[] = $xml;
    }

    $sharedXml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $sharedXml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared) . '" uniqueCount="' . count($shared) . '">';
    foreach ($shared as $text) {
        $sharedXml .= '<si><t>' . htmlspecialchars($text, ENT_QUOTES) . '</t></si>';
    }
    $sharedXml .= '</sst>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $workbookXml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $workbookXml .= '<sheets>';
    foreach ($sheets as $i => $sheet) {
        $name = htmlspecialchars($sheet['name'], ENT_QUOTES);
        $workbookXml .= '<sheet name="' . $name . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
    }
    $workbookXml .= '</sheets></workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $workbookRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($sheets as $i => $_sheet) {
        $workbookRels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
    }
    $workbookRels .= '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
    $workbookRels .= '</Relationships>';

    $rels = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $rels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
    $rels .= '</Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $contentTypes .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    $contentTypes .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
    $contentTypes .= '<Default Extension="xml" ContentType="application/xml"/>';
    foreach ($sheets as $i => $_sheet) {
        $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $contentTypes .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $contentTypes .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
    $contentTypes .= '</Types>';

    $zipPath = tempnam(sys_get_temp_dir(), 'xlsx_');
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/sharedStrings.xml', $sharedXml);
    foreach ($sheetXml as $i => $xml) {
        $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $xml);
    }
    $zip->close();
    $data = file_get_contents($zipPath);
    @unlink($zipPath);
    return $data !== false ? $data : '';
}

function build_csv(array $rows): string {
    $fh = fopen('php://temp', 'r+');
    if ($fh === false) {
        return '';
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            $row = [$row];
        }
        fputcsv($fh, $row, ',', '"', '\\');
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv !== false ? $csv : '';
}

function payout_row_extras_from_detail(mysqli $mysqli, $ticketNumber, $payoutDate): array {
    static $cache = [];
    static $dateTicketMapCache = [];
    static $lsDetailExists = null;
    static $hasBolCol = null;
    static $hasBaseRateCol = null;
    static $hasNetWeightTonsCol = null;
    static $hasMileageCol = null;
    static $hasTrailerPctOverrideCol = null;

    $ticketNumber = trim((string)$ticketNumber);
    $payoutDate = trim((string)$payoutDate);
    if ($ticketNumber === '') {
        return ['bol_number' => '', 'base_rate' => '', 'net_weight_tons' => '', 'mileage' => '', 'trailer_pct_override' => '', 'detail_match' => false];
    }
    $normalizeDate = static function ($value): string {
        return business_normalize_date($value);
    };
    $normalizeTicket = static function ($value): string {
        $v = trim((string)$value);
        if ($v === '') return '';
        $v = preg_replace('/\s+/', '', $v);
        $v = preg_replace('/\.0+$/', '', $v); // Excel-style numeric text: 12345.0
        return $v;
    };
    $digitsOnly = static function ($value): string {
        return preg_replace('/\D+/', '', (string)$value);
    };
    $formatMoneyLike = static function ($value): string {
        $raw = trim((string)$value);
        if ($raw === '') return '';
        $num = str_replace([',', '$'], '', $raw);
        if (!is_numeric($num)) return $raw;
        return '$' . number_format((float)$num, 2);
    };
    $formatMileage = static function ($value): string {
        $raw = trim((string)$value);
        if ($raw === '') return '';
        $num = str_replace([','], '', $raw);
        if (!is_numeric($num)) return $raw;
        return number_format((float)$num, 0, '.', '');
    };
    $formatNetWeightTons = static function ($value): string {
        $raw = trim((string)$value);
        if ($raw === '') return '';
        $num = str_replace([','], '', $raw);
        if (!is_numeric($num)) return $raw;
        return rtrim(rtrim(number_format((float)$num, 2, '.', ''), '0'), '.');
    };
    $payoutDateNorm = $normalizeDate($payoutDate);

    if ($lsDetailExists === null) {
        $lsDetailExists = table_exists($mysqli, 'ls_detail_raw');
        $hasBolCol = $lsDetailExists ? table_has_column($mysqli, 'ls_detail_raw', 'BOL') : false;
        $hasBaseRateCol = $lsDetailExists ? table_has_column($mysqli, 'ls_detail_raw', 'Base Freight Rate (Carrier)') : false;
        $hasNetWeightTonsCol = $lsDetailExists ? table_has_column($mysqli, 'ls_detail_raw', 'Net Weight (Tons)') : false;
        $hasMileageCol = $lsDetailExists ? table_has_column($mysqli, 'ls_detail_raw', 'Mileage') : false;
        $hasTrailerPctOverrideCol = $lsDetailExists ? table_has_column($mysqli, 'ls_detail_raw', 'tss_trailer_pct_override') : false;
    }
    if (!$lsDetailExists || (!$hasBolCol && !$hasBaseRateCol && !$hasNetWeightTonsCol && !$hasMileageCol && !$hasTrailerPctOverrideCol)) {
        return ['bol_number' => '', 'base_rate' => '', 'net_weight_tons' => '', 'mileage' => '', 'trailer_pct_override' => '', 'detail_match' => false];
    }

    $key = $ticketNumber . '|' . $payoutDateNorm;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $out = ['bol_number' => '', 'base_rate' => '', 'net_weight_tons' => '', 'mileage' => '', 'trailer_pct_override' => '', 'detail_match' => false];

    if ($payoutDateNorm !== '') {
        if (!isset($dateTicketMapCache[$payoutDateNorm])) {
            $dateTicketMapCache[$payoutDateNorm] = [];
            $selectBol = $hasBolCol ? "COALESCE(`BOL`, '')" : "''";
            $selectBase = $hasBaseRateCol ? "COALESCE(`Base Freight Rate (Carrier)`, '')" : "''";
            $selectNetTons = $hasNetWeightTonsCol ? "COALESCE(`Net Weight (Tons)`, '')" : "''";
            $selectMileage = $hasMileageCol ? "COALESCE(`Mileage`, '')" : "''";
            $selectTrailerPct = $hasTrailerPctOverrideCol ? "COALESCE(`tss_trailer_pct_override`, '')" : "''";
            $sql = "SELECT `Truckload ID` AS ticket_no, `Delivery Date` AS delivery_date, {$selectBol} AS bol_number, {$selectBase} AS base_rate, {$selectNetTons} AS net_weight_tons, {$selectMileage} AS mileage, {$selectTrailerPct} AS trailer_pct_override
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
                        $rowDateNorm = $normalizeDate($row['delivery_date'] ?? '');
                        if ($rowDateNorm !== $payoutDateNorm) {
                            continue;
                        }
                        $normTicket = $normalizeTicket($row['ticket_no'] ?? '');
                        $digitsTicket = $digitsOnly($row['ticket_no'] ?? '');
                        if ($normTicket !== '' && !isset($dateTicketMapCache[$payoutDateNorm][$normTicket])) {
                            $dateTicketMapCache[$payoutDateNorm][$normTicket] = [
                                'bol_number' => trim((string)($row['bol_number'] ?? '')),
                                'base_rate' => $formatMoneyLike($row['base_rate'] ?? ''),
                                'net_weight_tons' => $formatNetWeightTons($row['net_weight_tons'] ?? ''),
                                'mileage' => $formatMileage($row['mileage'] ?? ''),
                                'trailer_pct_override' => trim((string)($row['trailer_pct_override'] ?? '')),
                                'detail_match' => true,
                            ];
                        }
                        if ($digitsTicket !== '' && !isset($dateTicketMapCache[$payoutDateNorm]['#d:' . $digitsTicket])) {
                            $dateTicketMapCache[$payoutDateNorm]['#d:' . $digitsTicket] = [
                                'bol_number' => trim((string)($row['bol_number'] ?? '')),
                                'base_rate' => $formatMoneyLike($row['base_rate'] ?? ''),
                                'net_weight_tons' => $formatNetWeightTons($row['net_weight_tons'] ?? ''),
                                'mileage' => $formatMileage($row['mileage'] ?? ''),
                                'trailer_pct_override' => trim((string)($row['trailer_pct_override'] ?? '')),
                                'detail_match' => true,
                            ];
                        }
                    }
                } else {
                    $stmt->bind_result($ticketNo, $deliveryDate, $bolNumber, $baseRate, $netWeightTons, $mileage, $trailerPctOverride);
                    while ($stmt->fetch()) {
                        $rowDateNorm = $normalizeDate($deliveryDate);
                        if ($rowDateNorm !== $payoutDateNorm) {
                            continue;
                        }
                        $normTicket = $normalizeTicket($ticketNo);
                        $digitsTicket = $digitsOnly($ticketNo);
                        if ($normTicket !== '' && !isset($dateTicketMapCache[$payoutDateNorm][$normTicket])) {
                            $dateTicketMapCache[$payoutDateNorm][$normTicket] = [
                                'bol_number' => trim((string)($bolNumber ?? '')),
                                'base_rate' => $formatMoneyLike($baseRate ?? ''),
                                'net_weight_tons' => $formatNetWeightTons($netWeightTons ?? ''),
                                'mileage' => $formatMileage($mileage ?? ''),
                                'trailer_pct_override' => trim((string)($trailerPctOverride ?? '')),
                                'detail_match' => true,
                            ];
                        }
                        if ($digitsTicket !== '' && !isset($dateTicketMapCache[$payoutDateNorm]['#d:' . $digitsTicket])) {
                            $dateTicketMapCache[$payoutDateNorm]['#d:' . $digitsTicket] = [
                                'bol_number' => trim((string)($bolNumber ?? '')),
                                'base_rate' => $formatMoneyLike($baseRate ?? ''),
                                'net_weight_tons' => $formatNetWeightTons($netWeightTons ?? ''),
                                'mileage' => $formatMileage($mileage ?? ''),
                                'trailer_pct_override' => trim((string)($trailerPctOverride ?? '')),
                                'detail_match' => true,
                            ];
                        }
                    }
                }
                $stmt->close();
            }
        }

        $normWanted = $normalizeTicket($ticketNumber);
        $digitsWanted = $digitsOnly($ticketNumber);
        if ($normWanted !== '' && isset($dateTicketMapCache[$payoutDateNorm][$normWanted])) {
            $out = $dateTicketMapCache[$payoutDateNorm][$normWanted];
        } elseif ($digitsWanted !== '' && isset($dateTicketMapCache[$payoutDateNorm]['#d:' . $digitsWanted])) {
            $out = $dateTicketMapCache[$payoutDateNorm]['#d:' . $digitsWanted];
        }
    }

    // Fallback: ticket-only lookup (still prefers latest upload) when date formatting is inconsistent.
    if ($out['bol_number'] === '' && $out['base_rate'] === '' && $out['net_weight_tons'] === '' && $out['mileage'] === '') {
        $selectBol = $hasBolCol ? "COALESCE(`BOL`, '')" : "''";
        $selectBase = $hasBaseRateCol ? "COALESCE(`Base Freight Rate (Carrier)`, '')" : "''";
        $selectNetTons = $hasNetWeightTonsCol ? "COALESCE(`Net Weight (Tons)`, '')" : "''";
        $selectMileage = $hasMileageCol ? "COALESCE(`Mileage`, '')" : "''";
        $selectTrailerPct = $hasTrailerPctOverrideCol ? "COALESCE(`tss_trailer_pct_override`, '')" : "''";
        $sql = "SELECT `Truckload ID` AS ticket_no, {$selectBol} AS bol_number, {$selectBase} AS base_rate, {$selectNetTons} AS net_weight_tons, {$selectMileage} AS mileage, {$selectTrailerPct} AS trailer_pct_override
                  FROM ls_detail_raw
                 WHERE `Truckload ID` = ?
                    OR REPLACE(`Truckload ID`, ' ', '') = ?
                 ORDER BY upload_date DESC
                 LIMIT 25";
        $ticketCompact = preg_replace('/\s+/', '', $ticketNumber);
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param('ss', $ticketNumber, $ticketCompact);
            $stmt->execute();
            if (method_exists($stmt, 'get_result')) {
                $res = $stmt->get_result();
                $normWanted = $normalizeTicket($ticketNumber);
                $digitsWanted = $digitsOnly($ticketNumber);
                while ($row = $res->fetch_assoc()) {
                    $normTicket = $normalizeTicket($row['ticket_no'] ?? '');
                    $digitsTicket = $digitsOnly($row['ticket_no'] ?? '');
                    if (($normWanted !== '' && $normTicket === $normWanted) || ($digitsWanted !== '' && $digitsTicket === $digitsWanted)) {
                        $out = [
                            'bol_number' => trim((string)($row['bol_number'] ?? '')),
                            'base_rate' => $formatMoneyLike($row['base_rate'] ?? ''),
                            'net_weight_tons' => $formatNetWeightTons($row['net_weight_tons'] ?? ''),
                            'mileage' => $formatMileage($row['mileage'] ?? ''),
                            'trailer_pct_override' => trim((string)($row['trailer_pct_override'] ?? '')),
                            'detail_match' => true,
                        ];
                        break;
                    }
                }
            } else {
                $stmt->bind_result($ticketNo, $bolNumber, $baseRate, $netWeightTons, $mileage, $trailerPctOverride);
                $normWanted = $normalizeTicket($ticketNumber);
                $digitsWanted = $digitsOnly($ticketNumber);
                while ($stmt->fetch()) {
                    $normTicket = $normalizeTicket($ticketNo);
                    $digitsTicket = $digitsOnly($ticketNo);
                    if (($normWanted !== '' && $normTicket === $normWanted) || ($digitsWanted !== '' && $digitsTicket === $digitsWanted)) {
                        $out = [
                            'bol_number' => trim((string)($bolNumber ?? '')),
                            'base_rate' => $formatMoneyLike($baseRate ?? ''),
                            'net_weight_tons' => $formatNetWeightTons($netWeightTons ?? ''),
                            'mileage' => $formatMileage($mileage ?? ''),
                            'trailer_pct_override' => trim((string)($trailerPctOverride ?? '')),
                            'detail_match' => true,
                        ];
                        break;
                    }
                }
            }
            $stmt->close();
        }
    }

    $cache[$key] = $out;
    return $out;
}

function rtex_payout_row_meta(mysqli $mysqli, $ticketNumber, $payoutDate): array {
    $default = ['rate' => '', 'hours' => '', 'rate_raw' => 0.0, 'hours_raw' => 0.0];
    if (!table_exists($mysqli, 'rtex_payout_rows')) {
        return $default;
    }

    $ticket = trim((string)$ticketNumber);
    $date = business_normalize_date($payoutDate);
    if ($ticket === '' || $date === '') {
        return $default;
    }

    static $cache = [];
    $key = $ticket . '|' . $date;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $mysqli->prepare(
        "SELECT rate, hours
           FROM rtex_payout_rows
          WHERE ticket_number = ?
            AND work_date = ?
          ORDER BY upload_date DESC, id DESC
          LIMIT 1"
    );
    if (!$stmt) {
        $cache[$key] = $default;
        return $default;
    }
    $stmt->bind_param('ss', $ticket, $date);
    $stmt->execute();
    $stmt->bind_result($rate, $hours);
    if ($stmt->fetch()) {
        $rateRaw = (float)$rate;
        $hoursRaw = (float)$hours;
        $default = [
            'rate' => money_format_display($rateRaw),
            'hours' => rtrim(rtrim(number_format($hoursRaw, 2, '.', ''), '0'), '.'),
            'rate_raw' => $rateRaw,
            'hours_raw' => $hoursRaw,
        ];
    }
    $stmt->close();
    $cache[$key] = $default;
    return $default;
}

function rtex_driver_report_values(array $rtexMeta, $grossPay): array {
    $hours = (float)($rtexMeta['hours_raw'] ?? 0);
    $rate = (float)($rtexMeta['rate_raw'] ?? 0);
    $adjustedRate = $rate > 0 ? max(0, $rate - 10.0) : 0.0;
    $adjustedPay = round((float)$grossPay - ($hours * 10.0), 2);
    return [
        'rate' => $adjustedRate > 0 ? money_format_display($adjustedRate) : '',
        'hours' => rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.'),
        'pay' => $adjustedPay,
        'pay_display' => money_format_display($adjustedPay),
    ];
}

function nickelrock_payout_row_meta(mysqli $mysqli, $ticketNumber, $payoutDate): array {
    $out = [
        'detail_match' => false,
        'provider_name' => '',
        'rate' => '',
        'tons' => '',
        'truck' => '',
    ];
    if (!table_exists($mysqli, 'nickelrock_payout_rows')) {
        return $out;
    }
    $ticket = trim((string)$ticketNumber);
    $date = business_normalize_date((string)$payoutDate);
    if ($ticket === '' || $date === '') {
        return $out;
    }
    $stmt = $mysqli->prepare("
        SELECT provider_name, rate, tons, truck_raw
          FROM nickelrock_payout_rows
         WHERE work_date = ?
           AND TRIM(ticket_number) = ?
         ORDER BY upload_date DESC, id DESC
         LIMIT 1
    ");
    if (!$stmt) return $out;
    $stmt->bind_param('ss', $date, $ticket);
    $stmt->execute();
    if (method_exists($stmt, 'get_result')) {
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $out = [
                'detail_match' => true,
                'provider_name' => trim((string)($row['provider_name'] ?? '')),
                'rate' => '$' . number_format((float)($row['rate'] ?? 0), 2),
                'tons' => rtrim(rtrim(number_format((float)($row['tons'] ?? 0), 2, '.', ''), '0'), '.'),
                'truck' => trim((string)($row['truck_raw'] ?? '')),
            ];
        }
    } else {
        $stmt->bind_result($providerName, $rate, $tons, $truckRaw);
        if ($stmt->fetch()) {
            $out = [
                'detail_match' => true,
                'provider_name' => trim((string)$providerName),
                'rate' => '$' . number_format((float)$rate, 2),
                'tons' => rtrim(rtrim(number_format((float)$tons, 2, '.', ''), '0'), '.'),
                'truck' => trim((string)$truckRaw),
            ];
        }
    }
    $stmt->close();
    return $out;
}

function nickelrock_report_extras(array $meta): array {
    return [
        'bol_number' => '',
        'base_rate' => (string)($meta['rate'] ?? ''),
        'net_weight_tons' => (string)($meta['tons'] ?? ''),
        'mileage' => '',
        'detail_match' => true,
    ];
}

function pdf_escape_text(string $text): string {
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace('(', '\\(', $text);
    $text = str_replace(')', '\\)', $text);
    return $text;
}

function build_simple_pdf(array $rows, string $title = '', ?string $logoPath = null): string {
    $lines = [];
    if ($title !== '') {
        $lines[] = $title;
        $lines[] = '';
    }
    foreach ($rows as $row) {
        $lines[] = implode(' | ', $row);
    }

    $pages = [];
    $pageLines = [];
    $maxLines = 48;
    foreach ($lines as $line) {
        if (count($pageLines) >= $maxLines) {
            $pages[] = $pageLines;
            $pageLines = [];
        }
        $pageLines[] = $line;
    }
    if ($pageLines) {
        $pages[] = $pageLines;
    }

    $objects = [];
    $addObject = function (int $id, string $content) use (&$objects): void {
        $objects[$id] = $content;
    };

    $logoObjId = null;
    $logoWidth = 0;
    $logoHeight = 0;
    if ($logoPath && is_file($logoPath)) {
        $info = @getimagesize($logoPath);
        if ($info && isset($info['mime']) && $info['mime'] === 'image/jpeg') {
            $logoWidth = (int)$info[0];
            $logoHeight = (int)$info[1];
            $jpg = @file_get_contents($logoPath);
            if ($jpg !== false) {
                $len = strlen($jpg);
                $logoObjId = 4;
                $addObject(
                    $logoObjId,
                    "4 0 obj\n<< /Type /XObject /Subtype /Image /Width {$logoWidth} /Height {$logoHeight} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length {$len} >>\nstream\n{$jpg}\nendstream\nendobj"
                );
            }
        } elseif ($info && isset($info['mime']) && $info['mime'] === 'image/png') {
            $png = @file_get_contents($logoPath);
            if ($png !== false && substr($png, 0, 8) === "\x89PNG\r\n\x1a\n") {
                $offset = 8;
                $idat = '';
                $colorType = null;
                $bitDepth = null;
                while ($offset + 8 <= strlen($png)) {
                    $length = unpack('N', substr($png, $offset, 4))[1];
                    $type = substr($png, $offset + 4, 4);
                    $data = substr($png, $offset + 8, $length);
                    if ($type === 'IHDR') {
                        $logoWidth = unpack('N', substr($data, 0, 4))[1];
                        $logoHeight = unpack('N', substr($data, 4, 4))[1];
                        $bitDepth = ord($data[8]);
                        $colorType = ord($data[9]);
                    } elseif ($type === 'IDAT') {
                        $idat .= $data;
                    } elseif ($type === 'IEND') {
                        break;
                    }
                    $offset += 12 + $length;
                }
                if ($logoWidth > 0 && $logoHeight > 0 && $bitDepth === 8 && $colorType === 2 && $idat !== '') {
                    $len = strlen($idat);
                    $logoObjId = 4;
                    $addObject(
                        $logoObjId,
                        "4 0 obj\n<< /Type /XObject /Subtype /Image /Width {$logoWidth} /Height {$logoHeight} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 3 /BitsPerComponent 8 /Columns {$logoWidth} >> /Length {$len} >>\nstream\n{$idat}\nendstream\nendobj"
                    );
                }
            }
        }
    }

    $catalogId = 1;
    $fontId = 3;
    $addObject($catalogId, "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj");
    $addObject($fontId, "3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj");

    $kids = [];
    $pageObjId = 5;
    $contentObjId = 6;
    $pageCount = count($pages);
    for ($i = 0; $i < $pageCount; $i++) {
        $kids[] = $pageObjId . " 0 R";
        $resources = "/Resources << /Font << /F1 {$fontId} 0 R >> /ExtGState << /GS1 << /ca 0.1 /CA 0.1 >> >>";
        if ($logoObjId) {
            $resources .= " /XObject << /Im1 {$logoObjId} 0 R >>";
        }
        $resources .= " >>";
        $addObject($pageObjId, $pageObjId . " 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] {$resources} /Contents {$contentObjId} 0 R >>\nendobj");

        $stream = '';
        if ($logoObjId) {
            $targetW = 70;
            $scale = $logoWidth > 0 ? ($targetW / $logoWidth) : 1;
            $targetH = $logoHeight * $scale;
            // Watermark background
            $wmW = 420;
            $wmScale = $logoWidth > 0 ? ($wmW / $logoWidth) : 1;
            $wmH = $logoHeight * $wmScale;
            $wmX = 96;
            $wmY = 280;
            $stream .= "q /GS1 gs {$wmW} 0 0 {$wmH} {$wmX} {$wmY} cm /Im1 Do Q\n";
            // Header logo + text (slightly lower to avoid clipping)
            $stream .= "q {$targetW} 0 0 {$targetH} 40 725 cm /Im1 Do Q\n";
            $stream .= "BT /F1 12 Tf 120 745 Td (LONESTAR ROADSIDE LLC) Tj\n0 -14 Td (Your Freight Logistics Partner) Tj ET\n";
            $stream .= "q 0.6 w 0 0 0 RG 40 715 m 572 715 l S Q\n";
        } else {
            $stream .= "BT /F1 12 Tf 40 745 Td (LONESTAR ROADSIDE LLC) Tj\n0 -14 Td (Your Freight Logistics Partner) Tj ET\n";
            $stream .= "q 0.6 w 0 0 0 RG 40 715 m 572 715 l S Q\n";
        }

        $stream .= "BT /F1 10 Tf 40 690 Td\n";
        foreach ($pages[$i] as $line) {
            $trimmed = substr($line, 0, 220);
            $stream .= "(" . pdf_escape_text($trimmed) . ") Tj\n0 -14 Td\n";
        }
        $stream .= "ET";
        $streamLen = strlen($stream);
        $addObject($contentObjId, $contentObjId . " 0 obj\n<< /Length {$streamLen} >>\nstream\n{$stream}\nendstream\nendobj");
        $pageObjId += 2;
        $contentObjId += 2;
    }

    $addObject(2, "2 0 obj\n<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . $pageCount . " >>\nendobj");

    $maxId = $objects ? max(array_keys($objects)) : 0;
    $pdf = "%PDF-1.4\n";
    $offsets = array_fill(0, $maxId + 1, 0);
    for ($id = 1; $id <= $maxId; $id++) {
        if (!isset($objects[$id])) {
            continue;
        }
        $offsets[$id] = strlen($pdf);
        $pdf .= $objects[$id] . "\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($id = 1; $id <= $maxId; $id++) {
        $off = $offsets[$id] ?? 0;
        $pdf .= str_pad((string)$off, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root {$catalogId} 0 R >>\n";
    $pdf .= "startxref\n{$xrefOffset}\n%%EOF";
    return $pdf;
}

function build_payout_structured_pdf(
    string $title,
    array $driverMeta,
    array $tableRows,
    array $summaryRows,
    array $fuelUsageRows = [],
    ?string $logoPath = null,
    ?array $customColDefs = null
): string {
    $objects = [];
    $addObject = function (int $id, string $content) use (&$objects): void {
        $objects[$id] = $content;
    };

    $logoObjId = null;
    $logoWidth = 0;
    $logoHeight = 0;
    if ($logoPath && is_file($logoPath)) {
        $info = @getimagesize($logoPath);
        if ($info && isset($info['mime']) && $info['mime'] === 'image/jpeg') {
            $logoWidth = (int)$info[0];
            $logoHeight = (int)$info[1];
            $jpg = @file_get_contents($logoPath);
            if ($jpg !== false) {
                $len = strlen($jpg);
                $logoObjId = 4;
                $addObject(
                    $logoObjId,
                    "4 0 obj\n<< /Type /XObject /Subtype /Image /Width {$logoWidth} /Height {$logoHeight} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length {$len} >>\nstream\n{$jpg}\nendstream\nendobj"
                );
            }
        } elseif ($info && isset($info['mime']) && $info['mime'] === 'image/png') {
            $png = @file_get_contents($logoPath);
            if ($png !== false && substr($png, 0, 8) === "\x89PNG\r\n\x1a\n") {
                $offset = 8;
                $idat = '';
                $colorType = null;
                $bitDepth = null;
                while ($offset + 8 <= strlen($png)) {
                    $length = unpack('N', substr($png, $offset, 4))[1];
                    $type = substr($png, $offset + 4, 4);
                    $data = substr($png, $offset + 8, $length);
                    if ($type === 'IHDR') {
                        $logoWidth = unpack('N', substr($data, 0, 4))[1];
                        $logoHeight = unpack('N', substr($data, 4, 4))[1];
                        $bitDepth = ord($data[8]);
                        $colorType = ord($data[9]);
                    } elseif ($type === 'IDAT') {
                        $idat .= $data;
                    } elseif ($type === 'IEND') {
                        break;
                    }
                    $offset += 12 + $length;
                }
                if ($logoWidth > 0 && $logoHeight > 0 && $bitDepth === 8 && $colorType === 2 && $idat !== '') {
                    $len = strlen($idat);
                    $logoObjId = 4;
                    $addObject(
                        $logoObjId,
                        "4 0 obj\n<< /Type /XObject /Subtype /Image /Width {$logoWidth} /Height {$logoHeight} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 3 /BitsPerComponent 8 /Columns {$logoWidth} >> /Length {$len} >>\nstream\n{$idat}\nendstream\nendobj"
                    );
                }
            }
        }
    }

    $fitText = static function ($text, float $width, float $fontSize): string {
        $txt = trim((string)$text);
        if ($txt === '') return '';
        $maxChars = max(1, (int)floor(($width - 4) / max(1.0, $fontSize * 0.52)));
        if (strlen($txt) <= $maxChars) return $txt;
        return substr($txt, 0, max(1, $maxChars - 3)) . '...';
    };
    $textWidth = static function ($text, float $fontSize): float {
        return strlen((string)$text) * ($fontSize * 0.5);
    };

    $colDefs = $customColDefs ?: [
        ['key' => 'date',            'label' => 'Date',      'w' => 54, 'align' => 'left'],
        ['key' => 'ticket',          'label' => 'Ticket #',  'w' => 58, 'align' => 'left'],
        ['key' => 'bol_number',      'label' => 'BOL #',     'w' => 56, 'align' => 'left'],
        ['key' => 'vendor',          'label' => 'Client',    'w' => 150, 'align' => 'left'],
        ['key' => 'base_rate',       'label' => 'Base Rate', 'w' => 60, 'align' => 'right'],
        ['key' => 'net_weight_tons', 'label' => 'Net Wt T',  'w' => 52, 'align' => 'right'],
        ['key' => 'mileage',         'label' => 'Mileage',   'w' => 42, 'align' => 'right'],
        ['key' => 'pay',             'label' => 'Pay',       'w' => 44, 'align' => 'right'],
    ];
    $tableX = 40;
    $rowH = 16;
    $headerFont = 8;
    $bodyFont = 7.5;
    $bottomMargin = 70;

    $streams = [];
    $pageNo = 1;
    $rowIndex = 0;
    $rowCount = count($tableRows);
    $summaryRendered = false;
    $fuelRendered = empty($fuelUsageRows);

    $startPage = function (int $pageNo, bool $includeMeta) use ($logoObjId, $logoWidth, $logoHeight, $title, $driverMeta): array {
        $stream = '';
        if ($logoObjId) {
            $targetW = 70;
            $scale = $logoWidth > 0 ? ($targetW / $logoWidth) : 1;
            $targetH = $logoHeight * $scale;
            $wmW = 420;
            $wmScale = $logoWidth > 0 ? ($wmW / $logoWidth) : 1;
            $wmH = $logoHeight * $wmScale;
            $wmX = 96;
            $wmY = 280;
            $stream .= "q /GS1 gs {$wmW} 0 0 {$wmH} {$wmX} {$wmY} cm /Im1 Do Q\n";
            $stream .= "q {$targetW} 0 0 {$targetH} 40 725 cm /Im1 Do Q\n";
            $stream .= "BT /F1 12 Tf 120 745 Td (LONESTAR ROADSIDE LLC) Tj\n0 -14 Td (Your Freight Logistics Partner) Tj ET\n";
            $stream .= "q 0.6 w 0 0 0 RG 40 715 m 572 715 l S Q\n";
        } else {
            $stream .= "BT /F1 12 Tf 40 745 Td (LONESTAR ROADSIDE LLC) Tj\n0 -14 Td (Your Freight Logistics Partner) Tj ET\n";
            $stream .= "q 0.6 w 0 0 0 RG 40 715 m 572 715 l S Q\n";
        }

        $titleText = $pageNo > 1 ? ($title . ' (cont.)') : $title;
        $stream .= "BT /F1 11 Tf 40 695 Td (" . pdf_escape_text($titleText) . ") Tj ET\n";
        $y = 672;
        if ($includeMeta) {
            $owner = (string)($driverMeta['owner_operator_name'] ?? '');
            $truck = (string)($driverMeta['truck_no'] ?? '');
            $driver = (string)($driverMeta['driver_name'] ?? '');
            $stream .= "BT /F1 9 Tf 40 {$y} Td (Driver Name: " . pdf_escape_text($driver) . ") Tj ET\n";
            $y -= 14;
            $stream .= "BT /F1 9 Tf 40 {$y} Td (OwnerOp Name: " . pdf_escape_text($owner) . ") Tj ET\n";
            $y -= 14;
            $stream .= "BT /F1 9 Tf 40 {$y} Td (Truck No.: " . pdf_escape_text($truck) . ") Tj ET\n";
            $y -= 18;
        } else {
            $y -= 4;
        }
        return ['stream' => $stream, 'y' => $y];
    };

    $drawTableHeader = function (string &$stream, float $y) use ($tableX, $colDefs, $rowH, $headerFont, $fitText, $textWidth): void {
        $totalW = 0;
        foreach ($colDefs as $c) $totalW += $c['w'];
        $stream .= "q 0.93 g {$tableX} " . ($y - $rowH) . " {$totalW} {$rowH} re f Q\n";
        $stream .= "q 0.4 w 0 0 0 RG {$tableX} " . ($y - $rowH) . " {$totalW} {$rowH} re S Q\n";
        $x = $tableX;
        foreach ($colDefs as $c) {
            $w = $c['w'];
            $label = $fitText($c['label'], $w, $headerFont);
            $tw = $textWidth($label, $headerFont);
            $tx = $x + 2;
            if (($c['align'] ?? 'left') === 'right') {
                $tx = max($x + 2, $x + $w - $tw - 2);
            }
            $stream .= "q 0.4 w 0 0 0 RG {$x} " . ($y - $rowH) . " {$w} {$rowH} re S Q\n";
            $stream .= "BT /F1 {$headerFont} Tf {$tx} " . ($y - 11) . " Td (" . pdf_escape_text($label) . ") Tj ET\n";
            $x += $w;
        }
    };

    $drawTableRow = function (string &$stream, float $y, array $row) use ($tableX, $colDefs, $rowH, $bodyFont, $fitText, $textWidth): void {
        $x = $tableX;
        foreach ($colDefs as $c) {
            $w = $c['w'];
            $val = (string)($row[$c['key']] ?? '');
            $txt = $fitText($val, $w, $bodyFont);
            $tw = $textWidth($txt, $bodyFont);
            $tx = $x + 2;
            if (($c['align'] ?? 'left') === 'right') {
                $tx = max($x + 2, $x + $w - $tw - 2);
            }
            $stream .= "q 0.3 w 0 0 0 RG {$x} " . ($y - $rowH) . " {$w} {$rowH} re S Q\n";
            $stream .= "BT /F1 {$bodyFont} Tf {$tx} " . ($y - 11) . " Td (" . pdf_escape_text($txt) . ") Tj ET\n";
            $x += $w;
        }
    };

    while (true) {
        $page = $startPage($pageNo, $pageNo === 1);
        $stream = $page['stream'];
        $y = $page['y'];

        $drawTableHeader($stream, $y);
        $y -= $rowH;

        while ($rowIndex < $rowCount && ($y - $rowH) >= $bottomMargin) {
            $drawTableRow($stream, $y, $tableRows[$rowIndex]);
            $y -= $rowH;
            $rowIndex++;
        }

        if ($rowIndex >= $rowCount && !$fuelRendered) {
            $fuelRowsNeeded = max(1, count($fuelUsageRows)) + 1; // include totals row
            $fuelHeight = ($fuelRowsNeeded + 2) * $rowH + 8;
            if (($y - $fuelHeight) < $bottomMargin && $pageNo > 0) {
                $streams[] = $stream;
                $pageNo++;
                continue;
            }

            $y -= 10;
            $fx = 40;
            $fuelCols = [
                ['key' => 'txn_date', 'label' => 'Txn Date', 'w' => 72, 'align' => 'left'],
                ['key' => 'card', 'label' => 'Card', 'w' => 60, 'align' => 'left'],
                ['key' => 'description', 'label' => 'Description', 'w' => 210, 'align' => 'left'],
                ['key' => 'qty', 'label' => 'Qty', 'w' => 52, 'align' => 'right'],
                ['key' => 'ppg', 'label' => 'PPG', 'w' => 50, 'align' => 'right'],
                ['key' => 'gross_amt', 'label' => 'Amt', 'w' => 56, 'align' => 'right'],
                ['key' => 'fees_amt', 'label' => 'Fees Amt', 'w' => 56, 'align' => 'right'],
            ];
            $fuelW = 0;
            foreach ($fuelCols as $c) $fuelW += $c['w'];

            $stream .= "q 0.93 g {$fx} " . ($y - $rowH) . " {$fuelW} {$rowH} re f Q\n";
            $stream .= "q 0.4 w 0 0 0 RG {$fx} " . ($y - $rowH) . " {$fuelW} {$rowH} re S Q\n";
            $stream .= "BT /F1 8 Tf " . ($fx + 2) . " " . ($y - 11) . " Td (Fuel Card Usage) Tj ET\n";
            $y -= $rowH;

            $stream .= "q 0.93 g {$fx} " . ($y - $rowH) . " {$fuelW} {$rowH} re f Q\n";
            $cx = $fx;
            foreach ($fuelCols as $c) {
                $w = $c['w'];
                $label = $fitText($c['label'], $w, 8);
                $tw = $textWidth($label, 8);
                $tx = $cx + 2;
                if (($c['align'] ?? 'left') === 'right') {
                    $tx = max($cx + 2, $cx + $w - $tw - 2);
                }
                $stream .= "q 0.3 w 0 0 0 RG {$cx} " . ($y - $rowH) . " {$w} {$rowH} re S Q\n";
                $stream .= "BT /F1 8 Tf {$tx} " . ($y - 11) . " Td (" . pdf_escape_text($label) . ") Tj ET\n";
                $cx += $w;
            }
            $y -= $rowH;

            $fuelGrossTotal = 0.0;
            $fuelFeesTotal = 0.0;
            foreach ($fuelUsageRows as $fr) {
                $fuelGrossTotal += (float)($fr['gross_amt'] ?? 0);
                $fuelFeesTotal += (float)($fr['fees_amt'] ?? 0);
                $vals = [
                    'txn_date' => (string)($fr['txn_date'] ?? ''),
                    'card' => (string)($fr['card'] ?? ''),
                    'description' => (string)($fr['description'] ?? ''),
                    'qty' => number_format((float)($fr['qty'] ?? 0), 3, '.', ''),
                    'ppg' => number_format((float)($fr['ppg'] ?? 0), 3, '.', ''),
                    'gross_amt' => money_format_display($fr['gross_amt'] ?? 0),
                    'fees_amt' => money_format_display($fr['fees_amt'] ?? 0),
                ];
                $cx = $fx;
                foreach ($fuelCols as $c) {
                    $w = $c['w'];
                    $txt = $fitText((string)($vals[$c['key']] ?? ''), $w, 8);
                    $tw = $textWidth($txt, 8);
                    $tx = $cx + 2;
                    if (($c['align'] ?? 'left') === 'right') {
                        $tx = max($cx + 2, $cx + $w - $tw - 2);
                    }
                    $stream .= "q 0.3 w 0 0 0 RG {$cx} " . ($y - $rowH) . " {$w} {$rowH} re S Q\n";
                    $stream .= "BT /F1 8 Tf {$tx} " . ($y - 11) . " Td (" . pdf_escape_text($txt) . ") Tj ET\n";
                    $cx += $w;
                }
                $y -= $rowH;
            }

            $totals = [
                'txn_date' => 'Total',
                'card' => '',
                'description' => '',
                'qty' => '',
                'ppg' => '',
                'gross_amt' => money_format_display($fuelGrossTotal),
                'fees_amt' => money_format_display($fuelFeesTotal),
            ];
            $cx = $fx;
            foreach ($fuelCols as $c) {
                $w = $c['w'];
                $txt = $fitText((string)($totals[$c['key']] ?? ''), $w, 8);
                $tw = $textWidth($txt, 8);
                $tx = $cx + 2;
                if (($c['align'] ?? 'left') === 'right') {
                    $tx = max($cx + 2, $cx + $w - $tw - 2);
                }
                $stream .= "q 0.3 w 0 0 0 RG {$cx} " . ($y - $rowH) . " {$w} {$rowH} re S Q\n";
                $stream .= "BT /F1 8 Tf {$tx} " . ($y - 11) . " Td (" . pdf_escape_text($txt) . ") Tj ET\n";
                $cx += $w;
            }
            $y -= $rowH;
            $fuelRendered = true;
        }

        if ($rowIndex >= $rowCount && $fuelRendered && !$summaryRendered) {
            $summaryRowsNeeded = max(1, count($summaryRows));
            $summaryHeight = ($summaryRowsNeeded + 1) * $rowH + 8;
            if (($y - $summaryHeight) < $bottomMargin && $pageNo > 0) {
                $streams[] = $stream;
                $pageNo++;
                continue;
            }

            $y -= 10;
            $sx = 40;
            $sw1 = 220;
            $sw2 = 110;
            $sx2 = $sx + $sw1;
            $stream .= "q 0.93 g {$sx} " . ($y - $rowH) . " " . ($sw1 + $sw2) . " {$rowH} re f Q\n";
            $stream .= "q 0.4 w 0 0 0 RG {$sx} " . ($y - $rowH) . " {$sw1} {$rowH} re S {$sx2} " . ($y - $rowH) . " {$sw2} {$rowH} re S Q\n";
            $stream .= "BT /F1 8 Tf " . ($sx + 2) . " " . ($y - 11) . " Td (Summary) Tj ET\n";
            $y -= $rowH;
            foreach ($summaryRows as $sr) {
                $label = (string)($sr[0] ?? '');
                $value = (string)($sr[1] ?? '');
                $labelTxt = $fitText($label, $sw1, 8);
                $valueTxt = $fitText($value, $sw2, 8);
                $vTw = $textWidth($valueTxt, 8);
                $vTx = max($sx + $sw1 + 2, $sx + $sw1 + $sw2 - $vTw - 2);
                $stream .= "q 0.3 w 0 0 0 RG {$sx} " . ($y - $rowH) . " {$sw1} {$rowH} re S {$sx2} " . ($y - $rowH) . " {$sw2} {$rowH} re S Q\n";
                $stream .= "BT /F1 8 Tf " . ($sx + 2) . " " . ($y - 11) . " Td (" . pdf_escape_text($labelTxt) . ") Tj ET\n";
                $stream .= "BT /F1 8 Tf {$vTx} " . ($y - 11) . " Td (" . pdf_escape_text($valueTxt) . ") Tj ET\n";
                $y -= $rowH;
            }
            $summaryRendered = true;
        }

        $streams[] = $stream;

        if ($rowIndex >= $rowCount && $summaryRendered && $fuelRendered) {
            break;
        }
        $pageNo++;
    }

    if (!$streams) {
        return '';
    }

    $catalogId = 1;
    $fontId = 3;
    $addObject($catalogId, "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj");
    $addObject($fontId, "3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj");

    $kids = [];
    $pageObjId = 5;
    $contentObjId = 6;
    foreach ($streams as $stream) {
        $kids[] = $pageObjId . " 0 R";
        $resources = "/Resources << /Font << /F1 {$fontId} 0 R >> /ExtGState << /GS1 << /ca 0.1 /CA 0.1 >> >>";
        if ($logoObjId) {
            $resources .= " /XObject << /Im1 {$logoObjId} 0 R >>";
        }
        $resources .= " >>";
        $addObject($pageObjId, $pageObjId . " 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] {$resources} /Contents {$contentObjId} 0 R >>\nendobj");
        $streamLen = strlen($stream);
        $addObject($contentObjId, $contentObjId . " 0 obj\n<< /Length {$streamLen} >>\nstream\n{$stream}\nendstream\nendobj");
        $pageObjId += 2;
        $contentObjId += 2;
    }
    $addObject(2, "2 0 obj\n<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . count($streams) . " >>\nendobj");

    $maxId = $objects ? max(array_keys($objects)) : 0;
    $pdf = "%PDF-1.4\n";
    $offsets = array_fill(0, $maxId + 1, 0);
    for ($id = 1; $id <= $maxId; $id++) {
        if (!isset($objects[$id])) continue;
        $offsets[$id] = strlen($pdf);
        $pdf .= $objects[$id] . "\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($id = 1; $id <= $maxId; $id++) {
        $off = $offsets[$id] ?? 0;
        $pdf .= str_pad((string)$off, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root {$catalogId} 0 R >>\n";
    $pdf .= "startxref\n{$xrefOffset}\n%%EOF";
    return $pdf;
}

/**
 * Builds the SpreadsheetML payout workbook for a given driver+year and returns:
 * [ $downloadFilename, $xmlContent ]
 */
function build_payout_workbook(mysqli $mysqli, int $driverId, int $year, string $vendorScope = 'tss'){
    $vendorScope = normalize_payout_vendor_scope($vendorScope);
    // Resolve label for filename
    $driverLabel = 'Driver_' . $driverId;
    if ($res = $mysqli->query("SELECT CONCAT(first_name,' ',last_name) AS nm FROM driver_contacts WHERE id=".(int)$driverId)) {
        if ($row = $res->fetch_assoc()) $driverLabel = $row['nm'];
        $res->close();
    }
    $driverMeta = driver_report_meta($mysqli, $driverId);

    // Payout percentage (normalize 10 => 10%)
    $payoutPct = 0.10;
    if ($stmt = $mysqli->prepare("SELECT payout_percentage FROM driver_contacts WHERE id=?")) {
        $stmt->bind_param('i', $driverId);
        $stmt->execute();
        $stmt->bind_result($pctRaw);
        if ($stmt->fetch()) $payoutPct = ($pctRaw > 1) ? ($pctRaw/100.0) : (float)$pctRaw;
        $stmt->close();
    }

    // Do we have driver_id in driver_payouts?
    $hasDriverId = table_has_column($mysqli, 'driver_payouts', 'driver_id');
    $hasDriverContactId = table_has_column($mysqli, 'driver_payouts', 'driver_contact_id');

    // Weeks for the year
    if ($hasDriverId && $hasDriverContactId) {
        $wq = $mysqli->prepare(
            "SELECT YEARWEEK(dp.payout_date,0) AS yw, MIN(dp.payout_date) AS sd, MAX(dp.payout_date) AS ed
             FROM driver_payouts dp
             WHERE YEAR(dp.payout_date)=?
               AND (
                    dp.driver_id = ?
                    OR dp.driver_contact_id = ?
                    OR (
                      dp.driver_id IS NULL
                      AND dp.driver_contact_id IS NULL
                      AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)
                    )
               )
             GROUP BY yw ORDER BY sd"
        );
        $wq->bind_param('iiii', $year, $driverId, $driverId, $driverId);
    } elseif ($hasDriverId) {
        $wq = $mysqli->prepare(
            "SELECT YEARWEEK(dp.payout_date,0) AS yw, MIN(dp.payout_date) AS sd, MAX(dp.payout_date) AS ed
             FROM driver_payouts dp
             WHERE YEAR(dp.payout_date)=?
               AND (dp.driver_id = ? OR (dp.driver_id IS NULL AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)))
             GROUP BY yw ORDER BY sd"
        );
        $wq->bind_param('iii', $year, $driverId, $driverId);
    } elseif ($hasDriverContactId) {
        $wq = $mysqli->prepare(
            "SELECT YEARWEEK(dp.payout_date,0) AS yw, MIN(dp.payout_date) AS sd, MAX(dp.payout_date) AS ed
             FROM driver_payouts dp
             WHERE YEAR(dp.payout_date)=?
               AND (
                    dp.driver_contact_id = ?
                    OR (
                      dp.driver_contact_id IS NULL
                      AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)
                    )
               )
             GROUP BY yw ORDER BY sd"
        );
        $wq->bind_param('iii', $year, $driverId, $driverId);
    } else {
        $wq = $mysqli->prepare(
            "SELECT YEARWEEK(dp.payout_date,0) AS yw, MIN(dp.payout_date) AS sd, MAX(dp.payout_date) AS ed
             FROM driver_payouts dp
             WHERE YEAR(dp.payout_date)=?
               AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)
             GROUP BY yw ORDER BY sd"
        );
        $wq->bind_param('ii', $year, $driverId);
    }
    $wq->execute();
    $weeksArr = [];
    if (method_exists($wq,'get_result')) {
        $res = $wq->get_result();
        while($w = $res->fetch_assoc()) $weeksArr[] = $w;
    } else {
        $wq->bind_result($yw,$sd,$ed);
        while($wq->fetch()) $weeksArr[] = ['yw'=>$yw,'sd'=>$sd,'ed'=>$ed];
    }
    $wq->close();
    $weekKeys = [];
    foreach ($weeksArr as $w) {
        $bounds = business_week_bounds((string)($w['sd'] ?? ''));
        if ($bounds['start'] !== '') {
            $weekKeys[$bounds['start']] = true;
        }
    }
    $addWorkbookWeek = static function (string $dateValue) use (&$weeksArr, &$weekKeys, $mysqli): void {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
            return;
        }
        $bounds = business_week_bounds($dateValue);
        if (isset($weekKeys[$bounds['start']])) {
            return;
        }
        $weekKeys[$bounds['start']] = true;
        $stmt = $mysqli->prepare("SELECT YEARWEEK(?,0)");
        $yw = 0;
        if ($stmt) {
            $stmt->bind_param('s', $bounds['start']);
            $stmt->execute();
            $stmt->bind_result($ywRaw);
            if ($stmt->fetch()) {
                $yw = (int)$ywRaw;
            }
            $stmt->close();
        }
        $weeksArr[] = ['yw' => $yw, 'sd' => $bounds['start'], 'ed' => $bounds['end']];
    };
    if (table_exists($mysqli, 'tss_misc_adjustments')) {
        $vendor = function_exists('lonestar_payout_vendor_code') ? lonestar_payout_vendor_code($vendorScope) : ($vendorScope === 'rtex' ? 'RTEX' : ($vendorScope === 'nextier' ? 'NEXTIER' : ($vendorScope === 'nickelrock' ? 'NICKELROCK' : 'TSS')));
        if (table_has_column($mysqli, 'tss_misc_adjustments', 'payout_vendor')) {
            $stmt = $mysqli->prepare("SELECT DISTINCT payout_week_start FROM tss_misc_adjustments WHERE driver_contact_id=? AND " . lonestar_payout_vendor_sql_key('payout_vendor') . "=? AND YEAR(payout_week_start)=?");
            if ($stmt) {
                $stmt->bind_param('isi', $driverId, $vendor, $year);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($res && ($row = $res->fetch_assoc())) {
                    $addWorkbookWeek((string)($row['payout_week_start'] ?? ''));
                }
                $stmt->close();
            }
        } else {
            $stmt = $mysqli->prepare("SELECT DISTINCT payout_week_start FROM tss_misc_adjustments WHERE driver_contact_id=? AND YEAR(payout_week_start)=?");
            if ($stmt) {
                $stmt->bind_param('ii', $driverId, $year);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($res && ($row = $res->fetch_assoc())) {
                    $addWorkbookWeek((string)($row['payout_week_start'] ?? ''));
                }
                $stmt->close();
            }
        }
    }
    if (table_exists($mysqli, 'driver_gas_costs')) {
        $stmt = $mysqli->prepare("SELECT DISTINCT cost_date FROM driver_gas_costs WHERE driver_id=? AND YEAR(cost_date)=?");
        if ($stmt) {
            $stmt->bind_param('ii', $driverId, $year);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $addWorkbookWeek((string)($row['cost_date'] ?? ''));
            }
            $stmt->close();
        }
    }
    if (table_exists($mysqli, 'driver_fuel_balances')) {
        $stmt = $mysqli->prepare("SELECT DISTINCT last_calculated_week_start FROM driver_fuel_balances WHERE driver_id=? AND YEAR(last_calculated_week_start)=? AND ABS(balance) > 0.009");
        if ($stmt) {
            $stmt->bind_param('ii', $driverId, $year);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $addWorkbookWeek((string)($row['last_calculated_week_start'] ?? ''));
            }
            $stmt->close();
        }
    }
    usort($weeksArr, static fn($a, $b) => strcmp((string)($a['sd'] ?? ''), (string)($b['sd'] ?? '')));

    $sheets = [];
    foreach ($weeksArr as $w) {
        $bounds = business_week_bounds((string)$w['sd']);
        $label     = substr($bounds['start'], 5, 5) . ' to ' . substr($bounds['end'], 5, 5);
        $startDate = $bounds['start'];
        $endDate   = $bounds['end'];

        // Headers
        $headers = ($vendorScope === 'rtex')
            ? ['Date','Ticket #','Client','Base Rate','Hours','Pay']
            : ['Date','Ticket #','BOL #','Client','Base Rate','Net Weight (Tons)','Mileage','Pay'];
        $rows = [];
        $rows[] = ['Driver Name', $driverMeta['driver_name']];
        $rows[] = ['OwnerOp Name', $driverMeta['owner_operator_name']];
        $rows[] = ['Truck No.', $driverMeta['truck_no']];
        $rows[] = [];
        $rows[] = $headers;

        // Detail query
        if ($hasDriverId && $hasDriverContactId) {
            $dq = $mysqli->prepare(
                "SELECT dp.payout_date, dp.ticket_number, dp.driver_name, dp.vendor_name, dp.tss_pay
                 FROM driver_payouts dp
                 WHERE YEAR(dp.payout_date)=?
                   AND YEARWEEK(dp.payout_date,0)=?
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
            $dq->bind_param('iiiii', $year, $w['yw'], $driverId, $driverId, $driverId);
        } elseif ($hasDriverId) {
            $dq = $mysqli->prepare(
                "SELECT dp.payout_date, dp.ticket_number, dp.driver_name, dp.vendor_name, dp.tss_pay
                 FROM driver_payouts dp
                 WHERE YEAR(dp.payout_date)=?
                   AND YEARWEEK(dp.payout_date,0)=?
                   AND (dp.driver_id = ? OR (dp.driver_id IS NULL AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)))
                 ORDER BY (CASE WHEN dp.vendor_name='TSS Misc Revenue' THEN 1 ELSE 0 END), dp.payout_date, dp.ticket_number"
            );
            $dq->bind_param('iiii', $year, $w['yw'], $driverId, $driverId);
        } elseif ($hasDriverContactId) {
            $dq = $mysqli->prepare(
                "SELECT dp.payout_date, dp.ticket_number, dp.driver_name, dp.vendor_name, dp.tss_pay
                 FROM driver_payouts dp
                 WHERE YEAR(dp.payout_date)=?
                   AND YEARWEEK(dp.payout_date,0)=?
                   AND (
                        dp.driver_contact_id = ?
                        OR (
                          dp.driver_contact_id IS NULL
                          AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)
                        )
                   )
                 ORDER BY (CASE WHEN dp.vendor_name='TSS Misc Revenue' THEN 1 ELSE 0 END), dp.payout_date, dp.ticket_number"
            );
            $dq->bind_param('iiii', $year, $w['yw'], $driverId, $driverId);
        } else {
            $dq = $mysqli->prepare(
                "SELECT dp.payout_date, dp.ticket_number, dp.driver_name, dp.vendor_name, dp.tss_pay
                 FROM driver_payouts dp
                 WHERE YEAR(dp.payout_date)=?
                   AND YEARWEEK(dp.payout_date,0)=?
                   AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)
                 ORDER BY (CASE WHEN dp.vendor_name='TSS Misc Revenue' THEN 1 ELSE 0 END), dp.payout_date, dp.ticket_number"
            );
            $dq->bind_param('iii', $year, $w['yw'], $driverId);
        }
        $dq->execute();

        $dataRows = [];
        $totalLoadsFromDetail = 0;
        if (method_exists($dq,'get_result')) {
            $dr = $dq->get_result();
            while ($row = $dr->fetch_assoc()) {
                if (!payout_vendor_matches_scope($row['vendor_name'] ?? '', $vendorScope)) continue;
                $extras = ($vendorScope === 'rtex')
                    ? ['bol_number' => '', 'base_rate' => '', 'net_weight_tons' => '', 'mileage' => '', 'detail_match' => true]
                    : payout_row_extras_from_detail($mysqli, $row['ticket_number'] ?? '', $row['payout_date'] ?? '');
                $rtexMeta = ($vendorScope === 'rtex') ? rtex_payout_row_meta($mysqli, $row['ticket_number'] ?? '', $row['payout_date'] ?? '') : ['rate' => '', 'hours' => ''];
                $rtexValues = ($vendorScope === 'rtex') ? rtex_driver_report_values($rtexMeta, $row['tss_pay'] ?? 0) : null;
                $nextierMeta = ($vendorScope === 'nextier') ? nextier_payout_row_meta($mysqli, $row['ticket_number'] ?? '', $row['payout_date'] ?? '') : ['fsc_total' => 0.0, 'detail_match' => false];
                if ($vendorScope === 'nextier') $extras = nextier_report_extras($nextierMeta);
                if ($vendorScope === 'nickelrock') $extras = nickelrock_report_extras(nickelrock_payout_row_meta($mysqli, $row['ticket_number'] ?? '', $row['payout_date'] ?? ''));
                $trailerMeta = ($vendorScope === 'rtex') ? ['fee_amount' => 0.0] : payout_row_trailer_meta($mysqli, $driverId, $extras, $row['tss_pay'] ?? 0, $row['vendor_name'] ?? '', (string)($row['payout_date'] ?? ''));
                $row['trailer_fee'] = $trailerMeta['fee_amount'];
                if ($vendorScope === 'rtex') $row['tss_pay'] = $rtexValues['pay'];
                if ($vendorScope === 'nextier') $row['tss_pay'] = nextier_report_row_pay($nextierMeta, $row['tss_pay'] ?? 0);
                $row['nextier_fsc'] = (float)($nextierMeta['fsc_total'] ?? 0);
                $dataRows[] = $row;
                if (!empty($extras['detail_match'])) $totalLoadsFromDetail++;
                $rows[] = ($vendorScope === 'rtex')
                    ? [
                        $row['payout_date'],
                        $row['ticket_number'],
                        $row['vendor_name'] ?? '',
                        $rtexValues['rate'],
                        $rtexValues['hours'],
                        $rtexValues['pay_display'],
                    ]
                    : [
                        $row['payout_date'],
                        $row['ticket_number'],
                        $extras['bol_number'],
                        payout_vendor_label_with_trailer($mysqli, $driverId, $row['vendor_name'] ?? '', $row['ticket_number'] ?? '', $row['payout_date'] ?? '', $row['tss_pay'] ?? 0, $extras),
                        $extras['base_rate'],
                        $extras['net_weight_tons'],
                        $extras['mileage'],
                        money_format_display($row['tss_pay']),
                    ];
            }
        } else {
            $dq->bind_result($payout_date,$ticket_number,$driver_name,$vendor_name,$tss_pay);
            while ($dq->fetch()) {
                if (!payout_vendor_matches_scope($vendor_name, $vendorScope)) continue;
                $extras = ($vendorScope === 'rtex')
                    ? ['bol_number' => '', 'base_rate' => '', 'net_weight_tons' => '', 'mileage' => '', 'detail_match' => true]
                    : payout_row_extras_from_detail($mysqli, $ticket_number, $payout_date);
                $rtexMeta = ($vendorScope === 'rtex') ? rtex_payout_row_meta($mysqli, $ticket_number, $payout_date) : ['rate' => '', 'hours' => ''];
                $rtexValues = ($vendorScope === 'rtex') ? rtex_driver_report_values($rtexMeta, $tss_pay) : null;
                $nextierMeta = ($vendorScope === 'nextier') ? nextier_payout_row_meta($mysqli, $ticket_number, $payout_date) : ['fsc_total' => 0.0, 'detail_match' => false];
                if ($vendorScope === 'nextier') $extras = nextier_report_extras($nextierMeta);
                if ($vendorScope === 'nickelrock') $extras = nickelrock_report_extras(nickelrock_payout_row_meta($mysqli, $ticket_number, $payout_date));
                $trailerMeta = ($vendorScope === 'rtex') ? ['fee_amount' => 0.0] : payout_row_trailer_meta($mysqli, $driverId, $extras, $tss_pay, $vendor_name, (string)$payout_date);
                $rowPay = ($vendorScope === 'rtex') ? $rtexValues['pay'] : (($vendorScope === 'nextier') ? nextier_report_row_pay($nextierMeta, $tss_pay) : $tss_pay);
                $dataRows[] = compact('payout_date','ticket_number','driver_name','vendor_name') + ['tss_pay' => $rowPay, 'trailer_fee' => $trailerMeta['fee_amount'], 'nextier_fsc' => (float)($nextierMeta['fsc_total'] ?? 0)];
                if (!empty($extras['detail_match'])) $totalLoadsFromDetail++;
                $rows[] = ($vendorScope === 'rtex')
                    ? [
                        $payout_date,
                        $ticket_number,
                        $vendor_name,
                        $rtexValues['rate'],
                        $rtexValues['hours'],
                        $rtexValues['pay_display'],
                    ]
                    : [
                        $payout_date,
                        $ticket_number,
                        $extras['bol_number'],
                        payout_vendor_label_with_trailer($mysqli, $driverId, $vendor_name, $ticket_number, $payout_date, $tss_pay, $extras),
                        $extras['base_rate'],
                        $extras['net_weight_tons'],
                        $extras['mileage'],
                        money_format_display($rowPay),
                    ];
            }
        }
        $dq->close();

        if (empty($dataRows) && !lonestar_driver_week_has_report_activity($mysqli, $driverId, $startDate, $endDate, $vendorScope)) {
            continue;
        }

        // Summary calculations
        $totalLoads = $totalLoadsFromDetail;
        $totalGross = array_sum(array_map(fn($r)=> (float)$r['tss_pay'], $dataRows));
        $nextierFuelSurchargeTotal = ($vendorScope === 'nextier')
            ? round(array_sum(array_map(fn($r)=> (float)($r['nextier_fsc'] ?? 0), $dataRows)), 2)
            : 0.0;
        $tss7 = ($vendorScope === 'nextier')
            ? trailer_fee_total_for_driver($mysqli, $driverId, (float)$totalGross, $startDate, $endDate, $vendorScope)
            : round(array_sum(array_map(fn($r)=> (float)($r['trailer_fee'] ?? 0), $dataRows)), 2);
        $netBreakdown = lonestar_driver_week_net_breakdown($mysqli, $driverId, (float)$totalGross, (float)$tss7, $startDate, $endDate, $vendorScope);
        $payoutPct = (float)$netBreakdown['payout_pct'];
        $brokerPct = (float)($netBreakdown['broker_pct'] ?? $payoutPct);
        $brokerAmt = (float)$netBreakdown['broker_amt'];
        $miscAdjustments = driver_week_misc_adjustments($mysqli, $driverId, $startDate, $vendorScope);
        $miscAdjustmentTotal = (float)$netBreakdown['misc_adjustment_total'];
        $insurance = (float)$netBreakdown['insurance'];
        $fuel = (float)$netBreakdown['fuel'];
        $fuelBalanceRows = fuel_balance_summary_rows($netBreakdown);
        $subtotal = (float)$netBreakdown['net_total'];
        if ($vendorScope === 'nextier') {
            $subtotal = round($subtotal - (float)($netBreakdown['fuel_surcharge_total'] ?? 0) + $nextierFuelSurchargeTotal, 2);
        }
        if ($vendorScope === 'rtex') {
            $tss7 = 0.0;
            $subtotal = round($totalGross - $insurance - $fuel + $miscAdjustmentTotal, 2);
        }
        lonestar_driver_finalize_unpaid_balance(
            $mysqli,
            $driverId,
            $vendorScope,
            $startDate,
            $subtotal,
            !empty($dataRows)
        );
        $otherOpenBalanceTotal = driver_report_other_open_balance_total($mysqli, $driverId, $startDate, $vendorScope);
        $otherOpenBalanceRows = [];
        if (abs($otherOpenBalanceTotal) > 0.009) {
            $otherOpenBalanceRows[] = ['Other Open Balances', $otherOpenBalanceTotal];
        }
        $subtotal = total_net_after_open_balances($subtotal, $netBreakdown, $otherOpenBalanceTotal);

        if (!empty($miscAdjustments)) {
            foreach ($miscAdjustments as $adj) {
                $label = (string)($adj['label'] ?? '');
                $comments = trim((string)($adj['comments'] ?? ''));
                $vendorText = $comments !== '' ? ($label . ': ' . $comments) : $label;
                $rows[] = ($vendorScope === 'rtex')
                    ? [
                        $startDate,
                        '',
                        $vendorText,
                        '',
                        '',
                        money_format_display($adj['amount'] ?? 0),
                    ]
                    : [
                        $startDate,
                        '',
                        '',
                        $vendorText,
                        '',
                        '',
                        '',
                        money_format_display($adj['amount'] ?? 0),
                    ];
            }
        }

        // Fuel card usage (above summary)
        $fuelUsageRows = driver_week_fuel_usage_rows($mysqli, $driverId, $startDate, $endDate);
        $statementFuelTransactionTotal = driver_statement_fuel_transaction_total($fuelUsageRows, $netBreakdown);
        if (!empty($fuelUsageRows)) {
            $fuelGrossTotal = 0.0;
            $fuelFeesTotal = 0.0;
            $rows[] = [];
            $rows[] = ['Fuel Card Usage', '', '', '', '', '', ''];
            $rows[] = ['Txn Date', 'Card', 'Description', 'Qty', 'PPG', 'Amt', 'Fees Amt'];
            foreach ($fuelUsageRows as $fr) {
                $fuelGrossTotal += (float)($fr['gross_amt'] ?? 0);
                $fuelFeesTotal += (float)($fr['fees_amt'] ?? 0);
                $rows[] = [
                    (string)($fr['txn_date'] ?? ''),
                    (string)($fr['card'] ?? ''),
                    (string)($fr['description'] ?? ''),
                    number_format((float)($fr['qty'] ?? 0), 3, '.', ''),
                    number_format((float)($fr['ppg'] ?? 0), 3, '.', ''),
                    money_format_display($fr['gross_amt'] ?? 0),
                    money_format_display($fr['fees_amt'] ?? 0),
                ];
            }
            $rows[] = ['Total', '', '', '', '', money_format_display($fuelGrossTotal), money_format_display($fuelFeesTotal)];
        }

        // Spacer + summary
        $rows[] = [];
        $rows[] = [];
        $sums = ($vendorScope === 'rtex')
            ? [
                ['Total Days Worked',   $totalLoads],
                ['Total Gross',   $totalGross],
                ['Insurance',     $insurance],
                ['Fuel',          $statementFuelTransactionTotal],
                ['Misc Adjustments', $miscAdjustmentTotal],
            ]
            : [
                ['Total Loads',   $totalLoads],
                ['Total Gross',   $totalGross],
                ['Trailer Fee', $tss7],
                [broker_percentage_label($brokerPct), $brokerAmt],
                ['Insurance',     $insurance],
                ['Fuel',          $statementFuelTransactionTotal],
                ['Misc Adjustments', $miscAdjustmentTotal],
                $vendorScope === 'nextier' ? ['Fuel Surcharge', $nextierFuelSurchargeTotal] : null,
            ];
        $sums = array_merge(array_values(array_filter($sums)), $fuelBalanceRows, $otherOpenBalanceRows, [['Total Net', $subtotal]]);
        foreach (array_values(array_filter($sums)) as $s) {
            if ($s[0] === 'Total Loads' || $s[0] === 'Total Days Worked') {
                $rows[] = [$s[0], $s[1]];
            } else {
                $rows[] = [$s[0], money_format_display($s[1])];
            }
        }
        $sheets[] = ['name' => $label, 'rows' => $rows];
    }
    if (empty($sheets)) {
        $sheets[] = [
            'name' => payout_vendor_scope_label($vendorScope),
            'rows' => [[payout_vendor_scope_label($vendorScope) . ' payout rows were not found for this driver/year.']],
        ];
    }
    $xlsx = build_xlsx($sheets);
    $dlName = sanitize_filename($driverLabel) . '_' . $vendorScope . "_payout_{$year}.xlsx";
    return [$dlName, $xlsx];
}

function build_payout_pdf(mysqli $mysqli, int $driverId, int $year, string $weekStart, string $weekEnd, string $vendorScope = 'tss', string $statementBasis = 'work_date'): string {
    $vendorScope = normalize_payout_vendor_scope($vendorScope);
    $statementBasis = normalize_statement_basis($statementBasis);
    $dateWhere = statement_basis_date_where($statementBasis, 'dp');
    $dateTypes = statement_basis_date_param_types($statementBasis);
    $dateParams = statement_basis_date_params($statementBasis, $weekStart, $weekEnd);
    $hasDriverId = table_has_column($mysqli, 'driver_payouts', 'driver_id');
    $hasDriverContactId = table_has_column($mysqli, 'driver_payouts', 'driver_contact_id');
    $driverMeta = driver_report_meta($mysqli, $driverId);
    $rows = [];
    $rows[] = ['Driver Name', $driverMeta['driver_name']];
    $rows[] = ['OwnerOp Name', $driverMeta['owner_operator_name']];
    $rows[] = ['Truck No.', $driverMeta['truck_no']];
    if ($statementBasis === 'upload_date') {
        $rows[] = ['Statement Basis', 'Uploaded this statement week; work date outside this statement week.'];
    }
    $rows[] = [];
    $rows[] = ($vendorScope === 'rtex')
        ? ['Date','Ticket #','Client','Base Rate','Hours','Pay']
        : ['Date','Ticket #','BOL #','Client','Base Rate','Net Weight (Tons)','Mileage','Pay'];
    $dataRows = [];
    $totalLoadsFromDetail = 0;
    $tableRows = [];

    if ($hasDriverId && $hasDriverContactId) {
        $dq = $mysqli->prepare(
            "SELECT dp.payout_date, dp.upload_date, dp.ticket_number, dp.driver_name, dp.vendor_name, dp.tss_pay
             FROM driver_payouts dp
             WHERE {$dateWhere}
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
        $dq->bind_param($dateTypes . 'iii', ...array_merge($dateParams, [$driverId, $driverId, $driverId]));
    } elseif ($hasDriverId) {
        $dq = $mysqli->prepare(
            "SELECT dp.payout_date, dp.upload_date, dp.ticket_number, dp.driver_name, dp.vendor_name, dp.tss_pay
             FROM driver_payouts dp
             WHERE {$dateWhere}
               AND (dp.driver_id = ? OR (dp.driver_id IS NULL AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)))
             ORDER BY (CASE WHEN dp.vendor_name='TSS Misc Revenue' THEN 1 ELSE 0 END), dp.payout_date, dp.ticket_number"
        );
        $dq->bind_param($dateTypes . 'ii', ...array_merge($dateParams, [$driverId, $driverId]));
    } elseif ($hasDriverContactId) {
        $dq = $mysqli->prepare(
            "SELECT dp.payout_date, dp.upload_date, dp.ticket_number, dp.driver_name, dp.vendor_name, dp.tss_pay
             FROM driver_payouts dp
             WHERE {$dateWhere}
               AND (
                    dp.driver_contact_id = ?
                    OR (
                      dp.driver_contact_id IS NULL
                      AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)
                    )
               )
             ORDER BY (CASE WHEN dp.vendor_name='TSS Misc Revenue' THEN 1 ELSE 0 END), dp.payout_date, dp.ticket_number"
        );
        $dq->bind_param($dateTypes . 'ii', ...array_merge($dateParams, [$driverId, $driverId]));
    } else {
        $dq = $mysqli->prepare(
            "SELECT dp.payout_date, dp.upload_date, dp.ticket_number, dp.driver_name, dp.vendor_name, dp.tss_pay
             FROM driver_payouts dp
             WHERE {$dateWhere}
               AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)
             ORDER BY (CASE WHEN dp.vendor_name='TSS Misc Revenue' THEN 1 ELSE 0 END), dp.payout_date, dp.ticket_number"
        );
        $dq->bind_param($dateTypes . 'i', ...array_merge($dateParams, [$driverId]));
    }
    $dq->execute();
    if (method_exists($dq,'get_result')) {
        $dr = $dq->get_result();
        while ($row = $dr->fetch_assoc()) {
            if (!payout_vendor_matches_scope($row['vendor_name'] ?? '', $vendorScope)) continue;
            if (!payout_row_matches_statement_basis($mysqli, $row, $vendorScope, $statementBasis)) continue;
            $extras = ($vendorScope === 'rtex' || $vendorScope === 'nextier')
                ? ['bol_number' => '', 'base_rate' => '', 'net_weight_tons' => '', 'mileage' => '', 'detail_match' => true]
                : payout_row_extras_from_detail($mysqli, $row['ticket_number'] ?? '', $row['payout_date'] ?? '');
            $rtexMeta = ($vendorScope === 'rtex') ? rtex_payout_row_meta($mysqli, $row['ticket_number'] ?? '', $row['payout_date'] ?? '') : ['rate' => '', 'hours' => ''];
            $rtexValues = ($vendorScope === 'rtex') ? rtex_driver_report_values($rtexMeta, $row['tss_pay'] ?? 0) : null;
            $nextierMeta = ($vendorScope === 'nextier') ? nextier_payout_row_meta($mysqli, $row['ticket_number'] ?? '', $row['payout_date'] ?? '') : ['fsc_total' => 0.0, 'detail_match' => false];
            if ($vendorScope === 'nextier') $extras = nextier_report_extras($nextierMeta);
            if ($vendorScope === 'nickelrock') $extras = nickelrock_report_extras(nickelrock_payout_row_meta($mysqli, $row['ticket_number'] ?? '', $row['payout_date'] ?? ''));
            $trailerMeta = ($vendorScope === 'rtex') ? ['fee_amount' => 0.0] : payout_row_trailer_meta($mysqli, $driverId, $extras, $row['tss_pay'] ?? 0, $row['vendor_name'] ?? '', (string)($row['payout_date'] ?? ''));
            $row['trailer_fee'] = $trailerMeta['fee_amount'];
            if ($vendorScope === 'rtex') $row['tss_pay'] = $rtexValues['pay'];
            if ($vendorScope === 'nextier') $row['tss_pay'] = nextier_report_row_pay($nextierMeta, $row['tss_pay'] ?? 0);
            $row['nextier_fsc'] = (float)($nextierMeta['fsc_total'] ?? 0);
            $dataRows[] = $row;
            if (!empty($extras['detail_match'])) $totalLoadsFromDetail++;
            $displayRow = ($vendorScope === 'rtex')
                ? [
                    statement_row_work_date_display($row['payout_date'] ?? '', $weekStart, $weekEnd, $statementBasis),
                    $row['ticket_number'] ?? '',
                    $row['vendor_name'] ?? '',
                    $rtexValues['rate'],
                    $rtexValues['hours'],
                    $rtexValues['pay_display'],
                ]
                : [
                    statement_row_work_date_display($row['payout_date'] ?? '', $weekStart, $weekEnd, $statementBasis),
                    $row['ticket_number'] ?? '',
                    $extras['bol_number'],
                    payout_vendor_label_with_trailer($mysqli, $driverId, $row['vendor_name'] ?? '', $row['ticket_number'] ?? '', $row['payout_date'] ?? '', $row['tss_pay'] ?? 0, $extras),
                    $extras['base_rate'],
                    $extras['net_weight_tons'],
                    $extras['mileage'],
                    money_format_display($row['tss_pay'] ?? 0),
                ];
            $rows[] = $displayRow;
            $tableRows[] = ($vendorScope === 'rtex')
                ? [
                    'date' => (string)($displayRow[0] ?? ''),
                    'ticket' => (string)($displayRow[1] ?? ''),
                    'vendor' => (string)($displayRow[2] ?? ''),
                    'base_rate' => (string)($displayRow[3] ?? ''),
                    'hours' => (string)($displayRow[4] ?? ''),
                    'pay' => (string)($displayRow[5] ?? ''),
                ]
                : [
                    'date' => (string)($displayRow[0] ?? ''),
                    'ticket' => (string)($displayRow[1] ?? ''),
                    'bol_number' => (string)($displayRow[2] ?? ''),
                    'vendor' => (string)($displayRow[3] ?? ''),
                    'base_rate' => (string)($displayRow[4] ?? ''),
                    'net_weight_tons' => (string)($displayRow[5] ?? ''),
                    'mileage' => (string)($displayRow[6] ?? ''),
                    'pay' => (string)($displayRow[7] ?? ''),
                ];
        }
    } else {
        $dq->bind_result($payout_date,$upload_date,$ticket_number,$driver_name,$vendor_name,$tss_pay);
        while ($dq->fetch()) {
            if (!payout_vendor_matches_scope($vendor_name, $vendorScope)) continue;
            if (!payout_row_matches_statement_basis($mysqli, ['payout_date' => $payout_date, 'upload_date' => $upload_date], $vendorScope, $statementBasis)) continue;
            $extras = ($vendorScope === 'rtex' || $vendorScope === 'nextier')
                ? ['bol_number' => '', 'base_rate' => '', 'net_weight_tons' => '', 'mileage' => '', 'detail_match' => true]
                : payout_row_extras_from_detail($mysqli, $ticket_number, $payout_date);
            $rtexMeta = ($vendorScope === 'rtex') ? rtex_payout_row_meta($mysqli, $ticket_number, $payout_date) : ['rate' => '', 'hours' => ''];
            $rtexValues = ($vendorScope === 'rtex') ? rtex_driver_report_values($rtexMeta, $tss_pay) : null;
            $nextierMeta = ($vendorScope === 'nextier') ? nextier_payout_row_meta($mysqli, $ticket_number, $payout_date) : ['fsc_total' => 0.0, 'detail_match' => false];
            if ($vendorScope === 'nextier') $extras = nextier_report_extras($nextierMeta);
            if ($vendorScope === 'nickelrock') $extras = nickelrock_report_extras(nickelrock_payout_row_meta($mysqli, $ticket_number, $payout_date));
            $trailerMeta = ($vendorScope === 'rtex') ? ['fee_amount' => 0.0] : payout_row_trailer_meta($mysqli, $driverId, $extras, $tss_pay, $vendor_name, (string)$payout_date);
            $rowPay = ($vendorScope === 'rtex') ? $rtexValues['pay'] : (($vendorScope === 'nextier') ? nextier_report_row_pay($nextierMeta, $tss_pay) : $tss_pay);
            $dataRows[] = compact('payout_date','upload_date','ticket_number','driver_name','vendor_name') + ['tss_pay' => $rowPay, 'trailer_fee' => $trailerMeta['fee_amount'], 'nextier_fsc' => (float)($nextierMeta['fsc_total'] ?? 0)];
            if (!empty($extras['detail_match'])) $totalLoadsFromDetail++;
            $displayRow = ($vendorScope === 'rtex')
                ? [
                    statement_row_work_date_display($payout_date, $weekStart, $weekEnd, $statementBasis),
                    $ticket_number,
                    $vendor_name,
                    $rtexValues['rate'],
                    $rtexValues['hours'],
                    $rtexValues['pay_display'],
                ]
                : [
                    statement_row_work_date_display($payout_date, $weekStart, $weekEnd, $statementBasis),
                    $ticket_number,
                    $extras['bol_number'],
                    payout_vendor_label_with_trailer($mysqli, $driverId, $vendor_name, $ticket_number, $payout_date, $tss_pay, $extras),
                    $extras['base_rate'],
                    $extras['net_weight_tons'],
                    $extras['mileage'],
                    money_format_display($rowPay)
                ];
            $rows[] = $displayRow;
            $tableRows[] = ($vendorScope === 'rtex')
                ? [
                    'date' => (string)($displayRow[0] ?? ''),
                    'ticket' => (string)($displayRow[1] ?? ''),
                    'vendor' => (string)($displayRow[2] ?? ''),
                    'base_rate' => (string)($displayRow[3] ?? ''),
                    'hours' => (string)($displayRow[4] ?? ''),
                    'pay' => (string)($displayRow[5] ?? ''),
                ]
                : [
                    'date' => (string)($displayRow[0] ?? ''),
                    'ticket' => (string)($displayRow[1] ?? ''),
                    'bol_number' => (string)($displayRow[2] ?? ''),
                    'vendor' => (string)($displayRow[3] ?? ''),
                    'base_rate' => (string)($displayRow[4] ?? ''),
                    'net_weight_tons' => (string)($displayRow[5] ?? ''),
                    'mileage' => (string)($displayRow[6] ?? ''),
                    'pay' => (string)($displayRow[7] ?? ''),
                ];
        }
    }
    $dq->close();

    // Summary calculations
    $totalLoads = $totalLoadsFromDetail;
    $totalGross = array_sum(array_map(fn($r)=> (float)$r['tss_pay'], $dataRows));
    $nextierFuelSurchargeTotal = ($vendorScope === 'nextier')
        ? round(array_sum(array_map(fn($r)=> (float)($r['nextier_fsc'] ?? 0), $dataRows)), 2)
        : 0.0;
    $tss7 = ($vendorScope === 'nextier')
        ? trailer_fee_total_for_driver($mysqli, $driverId, (float)$totalGross, $weekStart, $weekEnd, $vendorScope)
        : round(array_sum(array_map(fn($r)=> (float)($r['trailer_fee'] ?? 0), $dataRows)), 2);
    $netBreakdown = lonestar_driver_week_net_breakdown($mysqli, $driverId, (float)$totalGross, (float)$tss7, $weekStart, $weekEnd, $vendorScope);
    $payoutPct = (float)$netBreakdown['payout_pct'];
    $brokerPct = (float)($netBreakdown['broker_pct'] ?? $payoutPct);
    $brokerAmt = (float)$netBreakdown['broker_amt'];
    $insurance = (float)$netBreakdown['insurance'];
    $fuel = (float)$netBreakdown['fuel'];
    $miscAdjustments = driver_week_misc_adjustments($mysqli, $driverId, $weekStart, $vendorScope);
    $miscAdjustmentTotal = (float)$netBreakdown['misc_adjustment_total'];
    $fuelBalanceRows = fuel_balance_summary_rows($netBreakdown);
    $fuelSurchargeTotal = (float)($netBreakdown['fuel_surcharge_total'] ?? 0);
    $subtotal = (float)$netBreakdown['net_total'];
    if ($vendorScope === 'nextier') {
        $fuelSurchargeTotal = $nextierFuelSurchargeTotal;
        $subtotal = round($subtotal - (float)($netBreakdown['fuel_surcharge_total'] ?? 0) + $fuelSurchargeTotal, 2);
    }
    if ($vendorScope === 'rtex') {
        $fuelSurchargeTotal = 0.0;
        $tss7 = 0.0;
        $subtotal = round($totalGross - $insurance - $fuel + $miscAdjustmentTotal, 2);
    }
    if ($statementBasis === 'work_date') {
        lonestar_driver_finalize_unpaid_balance(
            $mysqli,
            $driverId,
            $vendorScope,
            $weekStart,
            $subtotal,
            !empty($dataRows)
        );
    }
    $otherOpenBalanceTotal = driver_report_other_open_balance_total($mysqli, $driverId, $weekStart, $vendorScope);
    $otherOpenBalanceRows = [];
    if (abs($otherOpenBalanceTotal) > 0.009) {
        $otherOpenBalanceRows[] = ['Other Open Balances', $otherOpenBalanceTotal];
    }
    $subtotal = total_net_after_open_balances($subtotal, $netBreakdown, $otherOpenBalanceTotal);
    foreach ($miscAdjustments as $adj) {
        $label = (string)($adj['label'] ?? '');
        $comments = trim((string)($adj['comments'] ?? ''));
        $vendorText = $comments !== '' ? ($label . ': ' . $comments) : $label;
        $displayRow = [
            $weekStart,
            '',
            '',
            $vendorText,
            '',
            '',
            '',
            money_format_display($adj['amount'] ?? 0),
        ];
        if ($vendorScope === 'rtex') {
            $displayRow = [
                $weekStart,
                '',
                $vendorText,
                '',
                '',
                money_format_display($adj['amount'] ?? 0),
            ];
        }
        $rows[] = $displayRow;
        $tableRows[] = [
            'date' => (string)($displayRow[0] ?? ''),
            'ticket' => (string)($displayRow[1] ?? ''),
            'bol_number' => $vendorScope === 'rtex' ? '' : (string)($displayRow[2] ?? ''),
            'vendor' => $vendorScope === 'rtex' ? (string)($displayRow[2] ?? '') : (string)($displayRow[3] ?? ''),
            'base_rate' => $vendorScope === 'rtex' ? (string)($displayRow[3] ?? '') : (string)($displayRow[4] ?? ''),
            'net_weight_tons' => $vendorScope === 'rtex' ? '' : (string)($displayRow[5] ?? ''),
            'mileage' => $vendorScope === 'rtex' ? '' : (string)($displayRow[6] ?? ''),
            'hours' => $vendorScope === 'rtex' ? (string)($displayRow[4] ?? '') : '',
            'pay' => $vendorScope === 'rtex' ? (string)($displayRow[5] ?? '') : (string)($displayRow[7] ?? ''),
        ];
    }

    $fuelUsageRows = driver_week_fuel_usage_rows($mysqli, $driverId, $weekStart, $weekEnd);
    $statementFuelTransactionTotal = driver_statement_fuel_transaction_total($fuelUsageRows, $netBreakdown);
    if (!empty($fuelUsageRows)) {
        $rows[] = [];
        $rows[] = ['Fuel Card Usage', '', '', '', '', '', ''];
        $rows[] = ['Txn Date', 'Card', 'Description', 'Qty', 'PPG', 'Amt', 'Fees Amt'];
        $fuelGrossTotal = 0.0;
        $fuelFeesTotal = 0.0;
        foreach ($fuelUsageRows as $fr) {
            $fuelGrossTotal += (float)($fr['gross_amt'] ?? 0);
            $fuelFeesTotal += (float)($fr['fees_amt'] ?? 0);
            $rows[] = [
                (string)($fr['txn_date'] ?? ''),
                (string)($fr['card'] ?? ''),
                (string)($fr['description'] ?? ''),
                number_format((float)($fr['qty'] ?? 0), 3, '.', ''),
                number_format((float)($fr['ppg'] ?? 0), 3, '.', ''),
                money_format_display($fr['gross_amt'] ?? 0),
                money_format_display($fr['fees_amt'] ?? 0),
            ];
        }
        $rows[] = ['Total', '', '', '', '', money_format_display($fuelGrossTotal), money_format_display($fuelFeesTotal)];
    }

    $rows[] = [];
    $rows[] = [];
    $rows[] = [$vendorScope === 'rtex' ? 'Total Days Worked' : 'Total Loads', $totalLoads];
    $rows[] = ['Total Gross', money_format_display($totalGross)];
    if ($vendorScope === 'tss' || $vendorScope === 'nextier') {
        $rows[] = ['Trailer Fee', money_format_display($tss7)];
    }
    if ($vendorScope === 'tss' || $vendorScope === 'nextier') {
        $rows[] = [broker_percentage_label($brokerPct), money_format_display($brokerAmt)];
    }
    $rows[] = ['Insurance', money_format_display($insurance)];
    $rows[] = ['Fuel', money_format_display($statementFuelTransactionTotal)];
    foreach ($fuelBalanceRows as $fuelBalanceRow) {
        $rows[] = [$fuelBalanceRow[0], money_format_display($fuelBalanceRow[1])];
    }
    foreach ($otherOpenBalanceRows as $openBalanceRow) {
        $rows[] = [$openBalanceRow[0], money_format_display($openBalanceRow[1])];
    }
    if ($vendorScope === 'tss' || $vendorScope === 'nextier') {
        $rows[] = ['Misc Adjustments', money_format_display($miscAdjustmentTotal)];
        if ($vendorScope === 'tss' || $vendorScope === 'nextier') {
            $rows[] = ['Fuel Surcharge', money_format_display($fuelSurchargeTotal)];
        }
    } elseif (abs($miscAdjustmentTotal) > 0.009) {
        $rows[] = ['Misc Adjustments', money_format_display($miscAdjustmentTotal)];
    }
    $rows[] = ['Total Net', money_format_display($subtotal)];

    $title = ($statementBasis === 'upload_date' ? 'Off-Cycle ' : '') . payout_vendor_scope_label($vendorScope) . ' Payout Report ' . $weekStart . ' to ' . $weekEnd;
    $summaryRows = ($vendorScope === 'rtex')
        ? [
            ['Total Days Worked', (string)$totalLoads],
            ['Total Gross', money_format_display($totalGross)],
            ['Insurance', money_format_display($insurance)],
            ['Fuel', money_format_display($statementFuelTransactionTotal)],
            ['Misc Adjustments', money_format_display($miscAdjustmentTotal)],
        ]
        : [
            ['Total Loads', (string)$totalLoads],
            ['Total Gross', money_format_display($totalGross)],
            ['Trailer Fee', money_format_display($tss7)],
            [broker_percentage_label($brokerPct), money_format_display($brokerAmt)],
            ['Insurance', money_format_display($insurance)],
            ['Fuel', money_format_display($statementFuelTransactionTotal)],
            ['Misc Adjustments', money_format_display($miscAdjustmentTotal)],
            ($vendorScope === 'tss' || $vendorScope === 'nextier') ? ['Fuel Surcharge', money_format_display($fuelSurchargeTotal)] : null,
        ];
    foreach ($fuelBalanceRows as $fuelBalanceRow) {
        $summaryRows[] = [$fuelBalanceRow[0], money_format_display($fuelBalanceRow[1])];
    }
    foreach ($otherOpenBalanceRows as $openBalanceRow) {
        $summaryRows[] = [$openBalanceRow[0], money_format_display($openBalanceRow[1])];
    }
    if ($statementBasis === 'upload_date') {
        $summaryRows[] = ['Statement Basis', 'Uploaded this statement week; work date outside this statement week'];
    }
    $summaryRows[] = ['Total Net', money_format_display($subtotal)];
    $summaryRows = array_values(array_filter($summaryRows));
    $rtexColDefs = [
        ['key' => 'date',      'label' => 'Date',      'w' => 70, 'align' => 'left'],
        ['key' => 'ticket',    'label' => 'Ticket #',  'w' => 82, 'align' => 'left'],
        ['key' => 'vendor',    'label' => 'Client',    'w' => 120, 'align' => 'left'],
        ['key' => 'base_rate', 'label' => 'Base Rate', 'w' => 80, 'align' => 'right'],
        ['key' => 'hours',     'label' => 'Hours',     'w' => 70, 'align' => 'right'],
        ['key' => 'pay',       'label' => 'Pay',       'w' => 80, 'align' => 'right'],
    ];
    $pdf = build_payout_structured_pdf($title, $driverMeta, $tableRows, $summaryRows, $fuelUsageRows, __DIR__ . '/img/logo.jpg', $vendorScope === 'rtex' ? $rtexColDefs : null);
    return $pdf !== '' ? $pdf : build_simple_pdf($rows, $title, __DIR__ . '/img/logo.jpg');
}

function build_payout_csv(mysqli $mysqli, int $driverId, int $year, string $weekStart, string $weekEnd, string $vendorScope = 'tss', string $statementBasis = 'work_date'): string {
    $vendorScope = normalize_payout_vendor_scope($vendorScope);
    $statementBasis = normalize_statement_basis($statementBasis);
    $dateWhere = statement_basis_date_where($statementBasis, 'dp');
    $dateTypes = statement_basis_date_param_types($statementBasis);
    $dateParams = statement_basis_date_params($statementBasis, $weekStart, $weekEnd);
    $hasDriverId = table_has_column($mysqli, 'driver_payouts', 'driver_id');
    $hasDriverContactId = table_has_column($mysqli, 'driver_payouts', 'driver_contact_id');
    $driverMeta = driver_report_meta($mysqli, $driverId);
    $rows = [];
    $rows[] = [($statementBasis === 'upload_date' ? 'Off-Cycle ' : '') . payout_vendor_scope_label($vendorScope) . ' Payout Report', $weekStart . ' to ' . $weekEnd];
    $rows[] = ['Year', $year];
    if ($statementBasis === 'upload_date') {
        $rows[] = ['Statement Basis', statement_basis_label($statementBasis)];
        $rows[] = ['Note', 'Includes only rows uploaded this statement week with work dates outside this statement week'];
    }
    $rows[] = [];
    $rows[] = ['Driver Name', $driverMeta['driver_name']];
    $rows[] = ['OwnerOp Name', $driverMeta['owner_operator_name']];
    $rows[] = ['Truck No.', $driverMeta['truck_no']];
    $rows[] = [];
    $rows[] = ($vendorScope === 'rtex')
        ? ['Date','Ticket #','Client','Base Rate','Hours','Pay']
        : ['Date','Ticket #','BOL #','Client','Base Rate','Net Weight (Tons)','Mileage','Pay'];
    $dataRows = [];
    $totalLoadsFromDetail = 0;

    if ($hasDriverId && $hasDriverContactId) {
        $dq = $mysqli->prepare(
            "SELECT dp.payout_date, dp.upload_date, dp.ticket_number, dp.driver_name, dp.vendor_name, dp.tss_pay
             FROM driver_payouts dp
             WHERE {$dateWhere}
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
        $dq->bind_param($dateTypes . 'iii', ...array_merge($dateParams, [$driverId, $driverId, $driverId]));
    } elseif ($hasDriverId) {
        $dq = $mysqli->prepare(
            "SELECT dp.payout_date, dp.upload_date, dp.ticket_number, dp.driver_name, dp.vendor_name, dp.tss_pay
             FROM driver_payouts dp
             WHERE {$dateWhere}
               AND (dp.driver_id = ? OR (dp.driver_id IS NULL AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)))
             ORDER BY (CASE WHEN dp.vendor_name='TSS Misc Revenue' THEN 1 ELSE 0 END), dp.payout_date, dp.ticket_number"
        );
        $dq->bind_param($dateTypes . 'ii', ...array_merge($dateParams, [$driverId, $driverId]));
    } elseif ($hasDriverContactId) {
        $dq = $mysqli->prepare(
            "SELECT dp.payout_date, dp.upload_date, dp.ticket_number, dp.driver_name, dp.vendor_name, dp.tss_pay
             FROM driver_payouts dp
             WHERE {$dateWhere}
               AND (
                    dp.driver_contact_id = ?
                    OR (
                      dp.driver_contact_id IS NULL
                      AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)
                    )
               )
             ORDER BY (CASE WHEN dp.vendor_name='TSS Misc Revenue' THEN 1 ELSE 0 END), dp.payout_date, dp.ticket_number"
        );
        $dq->bind_param($dateTypes . 'ii', ...array_merge($dateParams, [$driverId, $driverId]));
    } else {
        $dq = $mysqli->prepare(
            "SELECT dp.payout_date, dp.upload_date, dp.ticket_number, dp.driver_name, dp.vendor_name, dp.tss_pay
             FROM driver_payouts dp
             WHERE {$dateWhere}
               AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)
             ORDER BY (CASE WHEN dp.vendor_name='TSS Misc Revenue' THEN 1 ELSE 0 END), dp.payout_date, dp.ticket_number"
        );
        $dq->bind_param($dateTypes . 'i', ...array_merge($dateParams, [$driverId]));
    }
    $dq->execute();
    if (method_exists($dq,'get_result')) {
        $dr = $dq->get_result();
        while ($row = $dr->fetch_assoc()) {
            if (!payout_vendor_matches_scope($row['vendor_name'] ?? '', $vendorScope)) continue;
            if (!payout_row_matches_statement_basis($mysqli, $row, $vendorScope, $statementBasis)) continue;
            $extras = ($vendorScope === 'rtex' || $vendorScope === 'nextier')
                ? ['bol_number' => '', 'base_rate' => '', 'net_weight_tons' => '', 'mileage' => '', 'detail_match' => true]
                : payout_row_extras_from_detail($mysqli, $row['ticket_number'] ?? '', $row['payout_date'] ?? '');
            $rtexMeta = ($vendorScope === 'rtex') ? rtex_payout_row_meta($mysqli, $row['ticket_number'] ?? '', $row['payout_date'] ?? '') : ['rate' => '', 'hours' => ''];
            $rtexValues = ($vendorScope === 'rtex') ? rtex_driver_report_values($rtexMeta, $row['tss_pay'] ?? 0) : null;
            $nextierMeta = ($vendorScope === 'nextier') ? nextier_payout_row_meta($mysqli, $row['ticket_number'] ?? '', $row['payout_date'] ?? '') : ['fsc_total' => 0.0, 'detail_match' => false];
            if ($vendorScope === 'nextier') $extras = nextier_report_extras($nextierMeta);
            if ($vendorScope === 'nickelrock') $extras = nickelrock_report_extras(nickelrock_payout_row_meta($mysqli, $row['ticket_number'] ?? '', $row['payout_date'] ?? ''));
            $trailerMeta = ($vendorScope === 'rtex') ? ['fee_amount' => 0.0] : payout_row_trailer_meta($mysqli, $driverId, $extras, $row['tss_pay'] ?? 0, $row['vendor_name'] ?? '', (string)($row['payout_date'] ?? ''));
            $row['trailer_fee'] = $trailerMeta['fee_amount'];
            if ($vendorScope === 'rtex') $row['tss_pay'] = $rtexValues['pay'];
            if ($vendorScope === 'nextier') $row['tss_pay'] = nextier_report_row_pay($nextierMeta, $row['tss_pay'] ?? 0);
            $row['nextier_fsc'] = (float)($nextierMeta['fsc_total'] ?? 0);
            $dataRows[] = $row;
            if (!empty($extras['detail_match'])) $totalLoadsFromDetail++;
            $rows[] = ($vendorScope === 'rtex')
                ? [
                    statement_row_work_date_display($row['payout_date'] ?? '', $weekStart, $weekEnd, $statementBasis),
                    $row['ticket_number'] ?? '',
                    $row['vendor_name'] ?? '',
                    $rtexValues['rate'],
                    $rtexValues['hours'],
                    $rtexValues['pay_display'],
                ]
                : [
                    statement_row_work_date_display($row['payout_date'] ?? '', $weekStart, $weekEnd, $statementBasis),
                    $row['ticket_number'] ?? '',
                    $extras['bol_number'],
                    payout_vendor_label_with_trailer($mysqli, $driverId, $row['vendor_name'] ?? '', $row['ticket_number'] ?? '', $row['payout_date'] ?? '', $row['tss_pay'] ?? 0, $extras),
                    $extras['base_rate'],
                    $extras['net_weight_tons'],
                    $extras['mileage'],
                    money_format_display($row['tss_pay'] ?? 0),
                ];
        }
    } else {
        $dq->bind_result($payout_date,$upload_date,$ticket_number,$driver_name,$vendor_name,$tss_pay);
        while ($dq->fetch()) {
            if (!payout_vendor_matches_scope($vendor_name, $vendorScope)) continue;
            if (!payout_row_matches_statement_basis($mysqli, ['payout_date' => $payout_date, 'upload_date' => $upload_date], $vendorScope, $statementBasis)) continue;
            $extras = ($vendorScope === 'rtex' || $vendorScope === 'nextier')
                ? ['bol_number' => '', 'base_rate' => '', 'net_weight_tons' => '', 'mileage' => '', 'detail_match' => true]
                : payout_row_extras_from_detail($mysqli, $ticket_number, $payout_date);
            $rtexMeta = ($vendorScope === 'rtex') ? rtex_payout_row_meta($mysqli, $ticket_number, $payout_date) : ['rate' => '', 'hours' => ''];
            $rtexValues = ($vendorScope === 'rtex') ? rtex_driver_report_values($rtexMeta, $tss_pay) : null;
            $nextierMeta = ($vendorScope === 'nextier') ? nextier_payout_row_meta($mysqli, $ticket_number, $payout_date) : ['fsc_total' => 0.0, 'detail_match' => false];
            if ($vendorScope === 'nextier') $extras = nextier_report_extras($nextierMeta);
            if ($vendorScope === 'nickelrock') $extras = nickelrock_report_extras(nickelrock_payout_row_meta($mysqli, $ticket_number, $payout_date));
            $trailerMeta = ($vendorScope === 'rtex') ? ['fee_amount' => 0.0] : payout_row_trailer_meta($mysqli, $driverId, $extras, $tss_pay, $vendor_name, (string)$payout_date);
            $rowPay = ($vendorScope === 'rtex') ? $rtexValues['pay'] : (($vendorScope === 'nextier') ? nextier_report_row_pay($nextierMeta, $tss_pay) : $tss_pay);
            $dataRows[] = compact('payout_date','upload_date','ticket_number','driver_name','vendor_name') + ['tss_pay' => $rowPay, 'trailer_fee' => $trailerMeta['fee_amount'], 'nextier_fsc' => (float)($nextierMeta['fsc_total'] ?? 0)];
            if (!empty($extras['detail_match'])) $totalLoadsFromDetail++;
            $rows[] = ($vendorScope === 'rtex')
                ? [
                    statement_row_work_date_display($payout_date, $weekStart, $weekEnd, $statementBasis),
                    $ticket_number,
                    $vendor_name,
                    $rtexValues['rate'],
                    $rtexValues['hours'],
                    $rtexValues['pay_display'],
                ]
                : [
                    statement_row_work_date_display($payout_date, $weekStart, $weekEnd, $statementBasis),
                    $ticket_number,
                    $extras['bol_number'],
                    payout_vendor_label_with_trailer($mysqli, $driverId, $vendor_name, $ticket_number, $payout_date, $tss_pay, $extras),
                    $extras['base_rate'],
                    $extras['net_weight_tons'],
                    $extras['mileage'],
                    money_format_display($rowPay)
                ];
        }
    }
    $dq->close();

    $totalLoads = $totalLoadsFromDetail;
    $totalGross = array_sum(array_map(fn($r)=> (float)$r['tss_pay'], $dataRows));
    $nextierFuelSurchargeTotal = ($vendorScope === 'nextier')
        ? round(array_sum(array_map(fn($r)=> (float)($r['nextier_fsc'] ?? 0), $dataRows)), 2)
        : 0.0;
    $tss7 = ($vendorScope === 'nextier')
        ? trailer_fee_total_for_driver($mysqli, $driverId, (float)$totalGross, $weekStart, $weekEnd, $vendorScope)
        : round(array_sum(array_map(fn($r)=> (float)($r['trailer_fee'] ?? 0), $dataRows)), 2);
    $netBreakdown = lonestar_driver_week_net_breakdown($mysqli, $driverId, (float)$totalGross, (float)$tss7, $weekStart, $weekEnd, $vendorScope);
    $payoutPct = (float)$netBreakdown['payout_pct'];
    $brokerPct = (float)($netBreakdown['broker_pct'] ?? $payoutPct);
    $brokerAmt = (float)$netBreakdown['broker_amt'];
    $insurance = (float)$netBreakdown['insurance'];
    $fuel = (float)$netBreakdown['fuel'];
    $miscAdjustments = driver_week_misc_adjustments($mysqli, $driverId, $weekStart, $vendorScope);
    $miscAdjustmentTotal = (float)$netBreakdown['misc_adjustment_total'];
    $fuelBalanceRows = fuel_balance_summary_rows($netBreakdown);
    $fuelSurchargeTotal = (float)($netBreakdown['fuel_surcharge_total'] ?? 0);
    $subtotal = (float)$netBreakdown['net_total'];
    if ($vendorScope === 'nextier') {
        $fuelSurchargeTotal = $nextierFuelSurchargeTotal;
        $subtotal = round($subtotal - (float)($netBreakdown['fuel_surcharge_total'] ?? 0) + $fuelSurchargeTotal, 2);
    }
    if ($vendorScope === 'rtex') {
        $fuelSurchargeTotal = 0.0;
        $tss7 = 0.0;
        $subtotal = round($totalGross - $insurance - $fuel + $miscAdjustmentTotal, 2);
    }
    if ($statementBasis === 'work_date') {
        lonestar_driver_finalize_unpaid_balance(
            $mysqli,
            $driverId,
            $vendorScope,
            $weekStart,
            $subtotal,
            !empty($dataRows)
        );
    }
    $otherOpenBalanceTotal = driver_report_other_open_balance_total($mysqli, $driverId, $weekStart, $vendorScope);
    $otherOpenBalanceRows = [];
    if (abs($otherOpenBalanceTotal) > 0.009) {
        $otherOpenBalanceRows[] = ['Other Open Balances', $otherOpenBalanceTotal];
    }
    $subtotal = total_net_after_open_balances($subtotal, $netBreakdown, $otherOpenBalanceTotal);
    foreach ($miscAdjustments as $adj) {
        $label = (string)($adj['label'] ?? '');
        $comments = trim((string)($adj['comments'] ?? ''));
        $vendorText = $comments !== '' ? ($label . ': ' . $comments) : $label;
        $rows[] = ($vendorScope === 'rtex')
            ? [
                $weekStart,
                '',
                $vendorText,
                '',
                '',
                money_format_display($adj['amount'] ?? 0),
            ]
            : [
                $weekStart,
                '',
                '',
                $vendorText,
                '',
                '',
                '',
                money_format_display($adj['amount'] ?? 0),
            ];
    }

    $fuelUsageRows = driver_week_fuel_usage_rows($mysqli, $driverId, $weekStart, $weekEnd);
    $statementFuelTransactionTotal = driver_statement_fuel_transaction_total($fuelUsageRows, $netBreakdown);
    if (!empty($fuelUsageRows)) {
        $rows[] = [];
        $rows[] = ['Fuel Card Usage', '', '', '', '', '', ''];
        $rows[] = ['Txn Date', 'Card', 'Description', 'Qty', 'PPG', 'Amt', 'Fees Amt'];
        $fuelGrossTotal = 0.0;
        $fuelFeesTotal = 0.0;
        foreach ($fuelUsageRows as $fr) {
            $fuelGrossTotal += (float)($fr['gross_amt'] ?? 0);
            $fuelFeesTotal += (float)($fr['fees_amt'] ?? 0);
            $rows[] = [
                (string)($fr['txn_date'] ?? ''),
                (string)($fr['card'] ?? ''),
                (string)($fr['description'] ?? ''),
                number_format((float)($fr['qty'] ?? 0), 3, '.', ''),
                number_format((float)($fr['ppg'] ?? 0), 3, '.', ''),
                money_format_display($fr['gross_amt'] ?? 0),
                money_format_display($fr['fees_amt'] ?? 0),
            ];
        }
        $rows[] = ['Total', '', '', '', '', money_format_display($fuelGrossTotal), money_format_display($fuelFeesTotal)];
    }

    $rows[] = [];
    $rows[] = ['Metric', 'Value'];
    $rows[] = [$vendorScope === 'rtex' ? 'Total Days Worked' : 'Total Loads', $totalLoads];
    $rows[] = ['Total Gross', money_format_display($totalGross)];
    if ($vendorScope === 'tss' || $vendorScope === 'nextier') {
        $rows[] = ['Trailer Fee', money_format_display($tss7)];
    }
    if ($vendorScope === 'tss' || $vendorScope === 'nextier') {
        $rows[] = [broker_percentage_label($brokerPct), money_format_display($brokerAmt)];
    }
    $rows[] = ['Insurance', money_format_display($insurance)];
    $rows[] = ['Fuel', money_format_display($statementFuelTransactionTotal)];
    foreach ($fuelBalanceRows as $fuelBalanceRow) {
        $rows[] = [$fuelBalanceRow[0], money_format_display($fuelBalanceRow[1])];
    }
    foreach ($otherOpenBalanceRows as $openBalanceRow) {
        $rows[] = [$openBalanceRow[0], money_format_display($openBalanceRow[1])];
    }
    if ($vendorScope === 'tss' || $vendorScope === 'nextier') {
        $rows[] = ['Misc Adjustments', money_format_display($miscAdjustmentTotal)];
        if ($vendorScope === 'tss' || $vendorScope === 'nextier') {
            $rows[] = ['Fuel Surcharge', money_format_display($fuelSurchargeTotal)];
        }
    } elseif (abs($miscAdjustmentTotal) > 0.009) {
        $rows[] = ['Misc Adjustments', money_format_display($miscAdjustmentTotal)];
    }
    $rows[] = ['Total Net', money_format_display($subtotal)];

    return build_csv($rows);
}

// --- Build alias view for canonical driver names (incl. unresolved matches) ---
$unionAliases = '';
if (table_exists($mysqli, 'ls_unresolved_matches')) {
    // Adjust column names if your table uses different ones:
    // raw_name: the original driver name/string from the upload
    // matched_contact_id: driver_contacts.id chosen in unresolved UI
    $unionAliases = "
        UNION ALL
        SELECT lm.matched_contact_id AS driver_id,
               TRIM(CONCAT(lm.pickup_first_name,' ',lm.pickup_last_name))     AS name,
               1                      AS is_alias
          FROM ls_unresolved_matches lm
          JOIN driver_contacts dc ON dc.id = lm.matched_contact_id
         WHERE lm.matched_contact_id IS NOT NULL
           " . ($showDisabled ? "" : "AND dc.is_disabled = 0") . "
           AND TRIM(CONCAT(lm.pickup_first_name,' ',lm.pickup_last_name)) <> ''
    ";
}

$disabledFilterSql = $showDisabled ? '' : 'WHERE dc.is_disabled = 0';
$mysqli->query("
    CREATE OR REPLACE VIEW v_driver_identity_names AS
    SELECT dc.id AS driver_id,
           TRIM(CONCAT(dc.first_name,' ',dc.last_name)) AS name,
           0 AS is_alias
      FROM driver_contacts dc
      $disabledFilterSql
    $unionAliases
");

function driver_has_vendor_payout(
    mysqli $mysqli,
    int $driverId,
    string $vendorScope,
    ?int $year = null,
    ?string $weekStart = null,
    ?string $weekEnd = null,
    string $statementBasis = 'work_date'
): bool {
    if ($driverId <= 0) {
        return false;
    }

    $hasDriverId = table_has_column($mysqli, 'driver_payouts', 'driver_id');
    $hasDriverContactId = table_has_column($mysqli, 'driver_payouts', 'driver_contact_id');
    $dateColumn = statement_basis_sql_column($statementBasis, 'dp');
    $where = [payout_vendor_sql_condition($vendorScope, 'dp')];
    $types = '';
    $params = [];

    if ($year !== null) {
        $where[] = "YEAR({$dateColumn}) = ?";
        $types .= 'i';
        $params[] = $year;
    }
    if ($weekStart !== null && $weekEnd !== null) {
        $where[] = statement_basis_date_where($statementBasis, 'dp');
        $types .= statement_basis_date_param_types($statementBasis);
        array_push($params, ...statement_basis_date_params($statementBasis, $weekStart, $weekEnd));
    }

    if ($hasDriverId && $hasDriverContactId) {
        $where[] = "(
            dp.driver_id = ?
            OR dp.driver_contact_id = ?
            OR (
                dp.driver_id IS NULL
                AND dp.driver_contact_id IS NULL
                AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)
            )
        )";
        $types .= 'iii';
        array_push($params, $driverId, $driverId, $driverId);
    } elseif ($hasDriverId) {
        $where[] = "(dp.driver_id = ? OR (dp.driver_id IS NULL AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)))";
        $types .= 'ii';
        array_push($params, $driverId, $driverId);
    } elseif ($hasDriverContactId) {
        $where[] = "(dp.driver_contact_id = ? OR (dp.driver_contact_id IS NULL AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)))";
        $types .= 'ii';
        array_push($params, $driverId, $driverId);
    } else {
        $where[] = "dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)";
        $types .= 'i';
        $params[] = $driverId;
    }

    $isOffcycleWeekCheck = normalize_statement_basis($statementBasis) === 'upload_date' && $weekStart !== null && $weekEnd !== null;
    $select = $isOffcycleWeekCheck ? 'dp.payout_date, dp.upload_date' : '1';
    $sql = 'SELECT ' . $select . ' FROM driver_payouts dp WHERE ' . implode(' AND ', $where) . ($isOffcycleWeekCheck ? '' : ' LIMIT 1');
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return false;
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    if ($isOffcycleWeekCheck) {
        if (method_exists($stmt, 'get_result')) {
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                if (payout_row_matches_statement_basis($mysqli, $row, $vendorScope, $statementBasis)) {
                    $stmt->close();
                    return true;
                }
            }
        } else {
            $stmt->bind_result($payoutDate, $uploadDate);
            while ($stmt->fetch()) {
                if (payout_row_matches_statement_basis($mysqli, ['payout_date' => $payoutDate, 'upload_date' => $uploadDate], $vendorScope, $statementBasis)) {
                    $stmt->close();
                    return true;
                }
            }
        }
        $stmt->close();
        return false;
    } else {
        $stmt->store_result();
        $hasRows = $stmt->num_rows > 0;
        $stmt->close();
        if ($hasRows) {
            return true;
        }
    }
    if ($weekStart !== null && $weekEnd !== null && normalize_statement_basis($statementBasis) === 'work_date') {
        return lonestar_driver_week_has_report_activity($mysqli, $driverId, $weekStart, $weekEnd, $vendorScope);
    }
    if ($year !== null) {
        if (table_exists($mysqli, 'tss_misc_adjustments')) {
            $vendor = function_exists('lonestar_payout_vendor_code') ? lonestar_payout_vendor_code($vendorScope) : ($vendorScope === 'rtex' ? 'RTEX' : ($vendorScope === 'nextier' ? 'NEXTIER' : ($vendorScope === 'nickelrock' ? 'NICKELROCK' : 'TSS')));
            if (table_has_column($mysqli, 'tss_misc_adjustments', 'payout_vendor')) {
                $stmt = $mysqli->prepare("SELECT 1 FROM tss_misc_adjustments WHERE driver_contact_id=? AND " . lonestar_payout_vendor_sql_key('payout_vendor') . "=? AND YEAR(payout_week_start)=? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('isi', $driverId, $vendor, $year);
                    $stmt->execute();
                    $stmt->store_result();
                    $found = $stmt->num_rows > 0;
                    $stmt->close();
                    if ($found) return true;
                }
            } else {
                $stmt = $mysqli->prepare("SELECT 1 FROM tss_misc_adjustments WHERE driver_contact_id=? AND YEAR(payout_week_start)=? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('ii', $driverId, $year);
                    $stmt->execute();
                    $stmt->store_result();
                    $found = $stmt->num_rows > 0;
                    $stmt->close();
                    if ($found) return true;
                }
            }
        }
        if (table_exists($mysqli, 'driver_gas_costs')) {
            $stmt = $mysqli->prepare("SELECT 1 FROM driver_gas_costs WHERE driver_id=? AND YEAR(cost_date)=? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ii', $driverId, $year);
                $stmt->execute();
                $stmt->store_result();
                $found = $stmt->num_rows > 0;
                $stmt->close();
                if ($found) return true;
            }
        }
        if (table_exists($mysqli, 'driver_fuel_balances')) {
            $stmt = $mysqli->prepare("SELECT 1 FROM driver_fuel_balances WHERE driver_id=? AND YEAR(last_calculated_week_start)=? AND ABS(balance) > 0.009 LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ii', $driverId, $year);
                $stmt->execute();
                $stmt->store_result();
                $found = $stmt->num_rows > 0;
                $stmt->close();
                if ($found) return true;
            }
        }
    }
    return false;
}

function driver_statement_filter_metadata(mysqli $mysqli, int $driverId, string $vendorScope): array {
    $empty = [
        'work_years' => [],
        'work_weeks' => [],
        'upload_years' => [],
        'upload_weeks' => [],
    ];
    if ($driverId <= 0) {
        return $empty;
    }

    $hasDriverId = table_has_column($mysqli, 'driver_payouts', 'driver_id');
    $hasDriverContactId = table_has_column($mysqli, 'driver_payouts', 'driver_contact_id');
    $where = [payout_vendor_sql_condition($vendorScope, 'dp')];
    $types = '';
    $params = [];

    if ($hasDriverId && $hasDriverContactId) {
        $where[] = "(
            dp.driver_id = ?
            OR dp.driver_contact_id = ?
            OR (
                dp.driver_id IS NULL
                AND dp.driver_contact_id IS NULL
                AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)
            )
        )";
        $types .= 'iii';
        array_push($params, $driverId, $driverId, $driverId);
    } elseif ($hasDriverId) {
        $where[] = "(dp.driver_id = ? OR (dp.driver_id IS NULL AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)))";
        $types .= 'ii';
        array_push($params, $driverId, $driverId);
    } elseif ($hasDriverContactId) {
        $where[] = "(dp.driver_contact_id = ? OR (dp.driver_contact_id IS NULL AND dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)))";
        $types .= 'ii';
        array_push($params, $driverId, $driverId);
    } else {
        $where[] = "dp.driver_name IN (SELECT name FROM v_driver_identity_names WHERE driver_id = ?)";
        $types .= 'i';
        $params[] = $driverId;
    }

    $sql = 'SELECT dp.payout_date, dp.upload_date FROM driver_payouts dp WHERE ' . implode(' AND ', $where);
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return $empty;
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();

    $meta = $empty;
    $rows = [];
    $addDate = static function (array &$years, array &$weeks, $dateValue): void {
        $date = business_normalize_date($dateValue);
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return;
        }
        $bounds = business_week_bounds($date);
        $years[(string)(int)substr($date, 0, 4)] = true;
        $weeks[$bounds['start'] . '|' . $bounds['end']] = true;
    };

    if (method_exists($stmt, 'get_result')) {
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    } else {
        $stmt->bind_result($payoutDate, $uploadDate);
        while ($stmt->fetch()) {
            $rows[] = ['payout_date' => $payoutDate, 'upload_date' => $uploadDate];
        }
    }
    $stmt->close();

    foreach ($rows as $row) {
        $addDate($meta['work_years'], $meta['work_weeks'], $row['payout_date'] ?? '');
        if (payout_row_matches_statement_basis($mysqli, $row, $vendorScope, 'upload_date')) {
            $addDate($meta['upload_years'], $meta['upload_weeks'], $row['upload_date'] ?? '');
        }
    }

    foreach ($meta as $key => $values) {
        $meta[$key] = array_keys($values);
        rsort($meta[$key]);
    }
    return $meta;
}

// --- Dropdown data (canonical driver only) ---
$drivers = [];
$dq = $mysqli->query(
    "SELECT vin.driver_id,
           MAX(CASE WHEN vin.is_alias=0 THEN vin.name END) AS legal_name
      FROM v_driver_identity_names vin
     GROUP BY vin.driver_id
     ORDER BY legal_name ASC"
);
while($row = $dq->fetch_assoc()){
    $drivers[] = $row; // ['driver_id','legal_name']
}

$years = [];
$payoutVendorWhereSql = payout_vendor_sql_condition($selectedPayoutVendor, '');
$ry = $mysqli->query("SELECT DISTINCT YEAR(payout_date) AS yr FROM driver_payouts WHERE {$payoutVendorWhereSql} ORDER BY yr DESC");
while($y = $ry->fetch_assoc()) $years[] = $y['yr'];
$ryUpload = $mysqli->query("SELECT DISTINCT YEAR(upload_date) AS yr FROM driver_payouts WHERE {$payoutVendorWhereSql} ORDER BY yr DESC");
while($ryUpload && ($y = $ryUpload->fetch_assoc())) $years[] = $y['yr'];
if (table_exists($mysqli, 'tss_misc_adjustments')) {
    if (table_has_column($mysqli, 'tss_misc_adjustments', 'payout_vendor')) {
        $vendor = function_exists('lonestar_payout_vendor_code') ? lonestar_payout_vendor_code($selectedPayoutVendor) : ($selectedPayoutVendor === 'rtex' ? 'RTEX' : ($selectedPayoutVendor === 'nextier' ? 'NEXTIER' : ($selectedPayoutVendor === 'nickelrock' ? 'NICKELROCK' : 'TSS')));
        $stmt = $mysqli->prepare("SELECT DISTINCT YEAR(payout_week_start) AS yr FROM tss_misc_adjustments WHERE " . lonestar_payout_vendor_sql_key('payout_vendor') . " = ?");
        if ($stmt) {
            $stmt->bind_param('s', $vendor);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                if (!empty($row['yr'])) $years[] = (int)$row['yr'];
            }
            $stmt->close();
        }
    } else {
        $res = $mysqli->query("SELECT DISTINCT YEAR(payout_week_start) AS yr FROM tss_misc_adjustments");
        while ($res && ($row = $res->fetch_assoc())) {
            if (!empty($row['yr'])) $years[] = (int)$row['yr'];
        }
    }
}
if (table_exists($mysqli, 'driver_gas_costs')) {
    $res = $mysqli->query("SELECT DISTINCT YEAR(cost_date) AS yr FROM driver_gas_costs");
    while ($res && ($row = $res->fetch_assoc())) {
        if (!empty($row['yr'])) $years[] = (int)$row['yr'];
    }
}
if (table_exists($mysqli, 'driver_fuel_balances')) {
    $res = $mysqli->query("SELECT DISTINCT YEAR(last_calculated_week_start) AS yr FROM driver_fuel_balances");
    while ($res && ($row = $res->fetch_assoc())) {
        if (!empty($row['yr'])) $years[] = (int)$row['yr'];
    }
}
$years[] = (int)date('Y');
$years = array_values(array_unique(array_filter(array_map('intval', $years))));
rsort($years);

$weekOptions = [];
$weekOptionMap = [];
$addWeekOption = static function (string $dateValue) use (&$weekOptions, &$weekOptionMap): void {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
        return;
    }
    $bounds = business_week_bounds($dateValue);
    $key = $bounds['start'] . '|' . $bounds['end'];
    if (isset($weekOptionMap[$key])) {
        return;
    }
    $weekOptionMap[$key] = true;
    $weekOptions[] = [
        'yw' => '',
        'sd' => $bounds['start'],
        'ed' => $bounds['end'],
        'week_start' => $bounds['start'],
        'week_end' => $bounds['end'],
    ];
};
$weekRes = $mysqli->query(
    "SELECT YEARWEEK(payout_date,0) AS yw,
            MIN(payout_date) AS sd,
            MAX(payout_date) AS ed
       FROM driver_payouts
      WHERE {$payoutVendorWhereSql}
   GROUP BY yw
   ORDER BY sd DESC"
);
while ($w = $weekRes->fetch_assoc()) {
    $addWeekOption((string)$w['sd']);
}
$uploadWeekRes = $mysqli->query(
    "SELECT YEARWEEK(upload_date,0) AS yw,
            MIN(upload_date) AS sd,
            MAX(upload_date) AS ed
       FROM driver_payouts
      WHERE {$payoutVendorWhereSql}
   GROUP BY yw
   ORDER BY sd DESC"
);
while ($uploadWeekRes && ($w = $uploadWeekRes->fetch_assoc())) {
    $addWeekOption((string)$w['sd']);
}
if (table_exists($mysqli, 'tss_misc_adjustments')) {
    $miscWeekSql = "SELECT DISTINCT payout_week_start FROM tss_misc_adjustments";
    if (table_has_column($mysqli, 'tss_misc_adjustments', 'payout_vendor')) {
        $vendor = function_exists('lonestar_payout_vendor_code') ? lonestar_payout_vendor_code($selectedPayoutVendor) : ($selectedPayoutVendor === 'rtex' ? 'RTEX' : ($selectedPayoutVendor === 'nextier' ? 'NEXTIER' : ($selectedPayoutVendor === 'nickelrock' ? 'NICKELROCK' : 'TSS')));
        $stmt = $mysqli->prepare($miscWeekSql . " WHERE " . lonestar_payout_vendor_sql_key('payout_vendor') . " = ? ORDER BY payout_week_start DESC");
        if ($stmt) {
            $stmt->bind_param('s', $vendor);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $addWeekOption((string)($row['payout_week_start'] ?? ''));
            }
            $stmt->close();
        }
    } else {
        $res = $mysqli->query($miscWeekSql . " ORDER BY payout_week_start DESC");
        while ($res && ($row = $res->fetch_assoc())) {
            $addWeekOption((string)($row['payout_week_start'] ?? ''));
        }
    }
}
if (table_exists($mysqli, 'driver_gas_costs')) {
    $res = $mysqli->query("SELECT DISTINCT cost_date FROM driver_gas_costs ORDER BY cost_date DESC");
    while ($res && ($row = $res->fetch_assoc())) {
        $addWeekOption((string)($row['cost_date'] ?? ''));
    }
}
if (table_exists($mysqli, 'driver_fuel_balances')) {
    $res = $mysqli->query("SELECT DISTINCT last_calculated_week_start FROM driver_fuel_balances ORDER BY last_calculated_week_start DESC");
    while ($res && ($row = $res->fetch_assoc())) {
        $addWeekOption((string)($row['last_calculated_week_start'] ?? ''));
    }
}
usort($weekOptions, static fn($a, $b) => strcmp((string)$b['week_start'], (string)$a['week_start']));

// Detail report dates (unchanged)
$uploadDates = [];
$ru = $mysqli->query("SELECT DISTINCT upload_date FROM ls_detail_raw ORDER BY upload_date DESC");
while($ud = $ru->fetch_assoc()) $uploadDates[] = $ud['upload_date'];

$miscUploadDates = [];
if (table_exists($mysqli, 'tss_payout_rows')) {
    $rm = $mysqli->query("SELECT DISTINCT upload_date FROM tss_payout_rows WHERE row_type='misc' AND matched_contact_id IS NULL ORDER BY upload_date DESC");
    while ($rm && ($md = $rm->fetch_assoc())) $miscUploadDates[] = $md['upload_date'];
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $type = $_POST['report_type'];

    // DETAIL CSV
    if($type==='detail'){
        $from = $mysqli->real_escape_string($_POST['from_date']);
        $to   = $mysqli->real_escape_string($_POST['to_date']);
        $format = $_POST['export_format'] ?? 'xlsx';
        $detailWhere = '';
        if (!$showDisabled && table_has_column($mysqli, 'ls_detail_raw', 'matched_contact_id')) {
            $detailWhere = " AND (matched_contact_id IS NULL OR matched_contact_id IN (SELECT id FROM driver_contacts WHERE is_disabled = 0))";
        }
        $sql  = "SELECT *, upload_date FROM ls_detail_raw "
              . "WHERE `Delivery Date` BETWEEN '$from' AND '$to' "
              . $detailWhere . " "
              . "ORDER BY `Delivery Date`";
        $res  = $mysqli->query($sql);
        $rows = [];
        $first = $res->fetch_assoc();
        if ($first) {
            $rows[] = array_keys($first);
            $rows[] = array_values($first);
        }
        while($row = $res->fetch_assoc()) {
            $rows[] = array_values($row);
        }
        if ($format === 'pdf') {
            $pdf = build_simple_pdf($rows, 'Detail Report ' . $from . ' to ' . $to, __DIR__ . '/img/logo.jpg');
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename=detail_report_' . $from . '-' . $to .'.pdf');
            echo $pdf;
        } else {
            $xlsx = build_xlsx([['name' => 'Detail Report', 'rows' => $rows]]);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename=detail_report_' . $from . '-' . $to .'.xlsx');
            echo $xlsx;
        }
        exit;
    }

    // CONTACTS CSV
    if($type==='contacts'){
        $format = $_POST['export_format'] ?? 'xlsx';
        $rows = [];
        $rows[] = ['First Name','Last Name','Email','Phone','Created'];
        $contactsSql = "SELECT first_name,last_name,email,phone,created_at FROM driver_contacts";
        if (!$showDisabled) {
            $contactsSql .= " WHERE is_disabled = 0";
        }
        $resC = $mysqli->query($contactsSql);
        while($c = $resC->fetch_assoc()) {
            $rows[] = array_values($c);
        }
        if ($format === 'pdf') {
            $pdf = build_simple_pdf($rows, 'Contacts Report', __DIR__ . '/img/logo.jpg');
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename=contacts_report_' . date("Ymd") . '.pdf');
            echo $pdf;
        } else {
            $xlsx = build_xlsx([['name' => 'Contacts', 'rows' => $rows]]);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename=contacts_report_' . date("Ymd") . '.xlsx');
            echo $xlsx;
        }
        exit;
    }

    // UNASSIGNED MISC REVENUE (from TSS payout rows)
    if ($type === 'unassigned_misc') {
        if (!table_exists($mysqli, 'tss_payout_rows')) {
            http_response_code(400);
            echo "tss_payout_rows table is required.";
            exit;
        }

        $format = $_POST['export_format'] ?? 'xlsx';
        $uploadDate = trim($_POST['upload_date'] ?? '');
        $fromDate = trim($_POST['from_date'] ?? '');
        $toDate = trim($_POST['to_date'] ?? '');

        $sql = "
            SELECT upload_date, as_of_date, work_date, truck_raw, truck_digits,
                   misc_category, misc_description, extracted_ticket_number, pay_amount, source_line_no
              FROM tss_payout_rows
             WHERE row_type='misc'
               AND matched_contact_id IS NULL
               AND pay_amount <> 0
               AND LOWER(COALESCE(misc_category, '')) NOT LIKE '%trailer usage%'
               AND LOWER(COALESCE(misc_description, '')) NOT LIKE '%trailer usage%'
        ";
        $types = '';
        $params = [];
        if ($uploadDate !== '') {
            $sql .= " AND upload_date = ?";
            $types .= 's';
            $params[] = $uploadDate;
        }
        if ($fromDate !== '') {
            $sql .= " AND COALESCE(work_date, as_of_date, upload_date) >= ?";
            $types .= 's';
            $params[] = $fromDate;
        }
        if ($toDate !== '') {
            $sql .= " AND COALESCE(work_date, as_of_date, upload_date) <= ?";
            $types .= 's';
            $params[] = $toDate;
        }
        $sql .= " ORDER BY upload_date DESC, truck_digits ASC, COALESCE(work_date, as_of_date, upload_date) ASC, source_line_no ASC";

        $rows = [];
        $rows[] = ['Upload Date','As Of Date','Work Date','Truck Raw','Truck Digits','Category','Description','Extracted Ticket','Amount','Line #'];
        $count = 0;
        $total = 0.0;

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            http_response_code(500);
            echo "Query prepare failed: " . htmlspecialchars($mysqli->error);
            exit;
        }
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $amt = (float)($r['pay_amount'] ?? 0);
            $total += $amt;
            $count++;
            $rows[] = [
                $r['upload_date'] ?? '',
                $r['as_of_date'] ?? '',
                $r['work_date'] ?? '',
                $r['truck_raw'] ?? '',
                $r['truck_digits'] ?? '',
                $r['misc_category'] ?? '',
                $r['misc_description'] ?? '',
                $r['extracted_ticket_number'] ?? '',
                money_format_display($amt),
                $r['source_line_no'] ?? '',
            ];
        }
        $stmt->close();

        $rows[] = [];
        $rows[] = ['Total Unassigned Rows', $count];
        $rows[] = ['Total Unassigned Amount', money_format_display($total)];

        if ($format === 'pdf') {
            $title = 'Unassigned Misc Revenue';
            if ($uploadDate !== '') $title .= ' · Upload ' . $uploadDate;
            if ($fromDate !== '' || $toDate !== '') $title .= ' · Range ' . ($fromDate ?: '...') . ' to ' . ($toDate ?: '...');
            $pdf = build_simple_pdf($rows, $title, __DIR__ . '/img/logo.jpg');
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename=unassigned_misc_revenue_' . date('Ymd_His') . '.pdf');
            echo $pdf;
        } else {
            $xlsx = build_xlsx([['name' => 'Unassigned Misc', 'rows' => $rows]]);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename=unassigned_misc_revenue_' . date('Ymd_His') . '.xlsx');
            echo $xlsx;
        }
        exit;
    }

    // PAYOUT XLS (single driver)
    if ($type === 'payout') {
        $driverId = isset($_POST['driver_id']) ? (int)$_POST['driver_id'] : 0;
        $year     = intval($_POST['year']);
        $format   = $_POST['export_format'] ?? 'xlsx';
        $weekPick = $_POST['week_range'] ?? '';
        $payoutVendor = normalize_payout_vendor_scope($_POST['payout_vendor'] ?? $selectedPayoutVendor);
        $statementBasis = normalize_statement_basis($_POST['statement_basis'] ?? 'work_date');
        if ($driverId <= 0) { http_response_code(400); echo "Select a driver."; exit; }
        if (!$showDisabled) {
            $stmt = $mysqli->prepare("SELECT is_disabled FROM driver_contacts WHERE id=?");
            $stmt->bind_param('i', $driverId);
            $stmt->execute();
            $stmt->bind_result($isDisabled);
            $stmt->fetch();
            $stmt->close();
            if (!empty($isDisabled)) {
                http_response_code(400);
                echo "Selected driver is disabled. Enable 'Show disabled drivers' to include.";
                exit;
            }
        }

        if ($format === 'pdf' || $format === 'csv') {
            if ($weekPick === '' || strpos($weekPick, '|') === false) {
                http_response_code(400);
                echo "Select a week for PDF/CSV export.";
                exit;
            }
            [$weekStart, $weekEnd] = explode('|', $weekPick, 2);
            if (!driver_has_vendor_payout($mysqli, $driverId, $payoutVendor, $year, $weekStart, $weekEnd, $statementBasis)) {
                http_response_code(400);
                echo "No " . htmlspecialchars(payout_vendor_scope_label($payoutVendor)) . " payout data found for this driver/" . htmlspecialchars(statement_basis_label($statementBasis)) . ".";
                exit;
            }
            if ($format === 'pdf') {
                $pdf = build_payout_pdf($mysqli, $driverId, $year, $weekStart, $weekEnd, $payoutVendor, $statementBasis);
                $dl = driver_filename($mysqli, $driverId) . ($statementBasis === 'upload_date' ? '_offcycle' : '') . '_' . $payoutVendor . '_' . $weekEnd . '.pdf';
                if ($statementBasis === 'work_date') {
                    cache_statement_pdf($payoutVendor, $weekEnd, $dl, $pdf);
                }
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="'.$dl.'"');
                echo $pdf;
            } else {
                $csv = build_payout_csv($mysqli, $driverId, $year, $weekStart, $weekEnd, $payoutVendor, $statementBasis);
                $dl = driver_filename($mysqli, $driverId) . ($statementBasis === 'upload_date' ? '_offcycle' : '') . '_' . $payoutVendor . '_' . $weekEnd . '.csv';
                header('Content-Type: text/csv; charset=UTF-8');
                header('Content-Disposition: attachment; filename="'.$dl.'"');
                echo $csv;
            }
        } else {
            if (!driver_has_vendor_payout($mysqli, $driverId, $payoutVendor, $year)) {
                http_response_code(400);
                echo "No " . htmlspecialchars(payout_vendor_scope_label($payoutVendor)) . " payout data found for this driver/year.";
                exit;
            }
            list($filename, $content) = build_payout_workbook($mysqli, $driverId, $year, $payoutVendor);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="'.$filename.'"');
            echo $content;
        }
        exit;
    }

    // PAYOUT XLS (ALL drivers -> ZIP), also saves copies on server under /exports
    if ($type === 'payout_all') {
        set_time_limit(0);
        $year = intval($_POST['year']);
        $format = $_POST['export_format'] ?? 'xlsx';
        $weekPick = $_POST['week_range'] ?? '';
        $payoutVendor = normalize_payout_vendor_scope($_POST['payout_vendor'] ?? $selectedPayoutVendor);
        $statementBasis = normalize_statement_basis($_POST['statement_basis'] ?? 'work_date');
        if (($format === 'pdf' || $format === 'csv') && ($weekPick === '' || strpos($weekPick, '|') === false)) {
            http_response_code(400);
            echo "Select a week for PDF/CSV export.";
            exit;
        }

        // Ensure export directory exists (server-local)
        $exportBase = __DIR__ . '/exports';
        if (!is_dir($exportBase)) { @mkdir($exportBase, 0775, true); }

        $batchDir = $exportBase . '/payouts_' . $year . '_' . date('Ymd_His');
        if (!@mkdir($batchDir, 0775, true)) {
            http_response_code(500);
            echo "Failed to create export directory: " . htmlspecialchars($batchDir);
            exit;
        }

        $savedFiles = [];
        foreach ($drivers as $d) {
            $driverId = (int)$d['driver_id'];
            if ($driverId <= 0) continue;

            if ($format === 'pdf' || $format === 'csv') {
                if ($weekPick === '' || strpos($weekPick, '|') === false) {
                    continue;
                }
                [$weekStart, $weekEnd] = explode('|', $weekPick, 2);
                if (!driver_has_vendor_payout($mysqli, $driverId, $payoutVendor, $year, $weekStart, $weekEnd, $statementBasis)) {
                    continue;
                }
                if ($format === 'pdf') {
                    $pdf = build_payout_pdf($mysqli, $driverId, $year, $weekStart, $weekEnd, $payoutVendor, $statementBasis);
                    $filename = driver_filename($mysqli, $driverId) . ($statementBasis === 'upload_date' ? '_offcycle' : '') . '_' . $payoutVendor . '_' . $weekEnd . '.pdf';
                    $filepath = $batchDir . '/' . $filename;
                    file_put_contents($filepath, $pdf);
                    if ($statementBasis === 'work_date') {
                        cache_statement_pdf($payoutVendor, $weekEnd, $filename, $pdf);
                    }
                } else {
                    $csv = build_payout_csv($mysqli, $driverId, $year, $weekStart, $weekEnd, $payoutVendor, $statementBasis);
                    $filename = driver_filename($mysqli, $driverId) . ($statementBasis === 'upload_date' ? '_offcycle' : '') . '_' . $payoutVendor . '_' . $weekEnd . '.csv';
                    $filepath = $batchDir . '/' . $filename;
                    file_put_contents($filepath, $csv);
                }
                $savedFiles[] = $filepath;
            } else {
                if (!driver_has_vendor_payout($mysqli, $driverId, $payoutVendor, $year)) {
                    continue;
                }
                list($filename, $content) = build_payout_workbook($mysqli, $driverId, $year, $payoutVendor);
                $filepath = $batchDir . '/' . sanitize_filename($filename);
                file_put_contents($filepath, $content);
                $savedFiles[] = $filepath;
            }
        }

        if (empty($savedFiles)) {
            http_response_code(400);
            $rangeText = ($format === 'pdf' || $format === 'csv') ? 'selected week' : 'selected year';
            echo "No " . htmlspecialchars(payout_vendor_scope_label($payoutVendor)) . " payout data found for the {$rangeText} using " . htmlspecialchars(statement_basis_label($statementBasis)) . ".";
            @rmdir($batchDir);
            exit;
        }

        // Create ZIP
        if (!class_exists('ZipArchive')) {
            http_response_code(500);
            echo "PHP ZipArchive extension is not enabled on the server.";
            exit;
        }

        $zipPath = $batchDir . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            http_response_code(500);
            echo "Could not create ZIP.";
            exit;
        }
        foreach ($savedFiles as $fp) {
            $zip->addFile($fp, basename($fp));
        }
        $zip->close();

        // Stream ZIP to browser
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="payout_reports_' . ($statementBasis === 'upload_date' ? 'offcycle_' : '') . $payoutVendor . '_' . $year . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        // Note: we intentionally keep files on server in $batchDir for your records.
        exit;
    }
}

$driverStatementFilters = [];
foreach ($drivers as $d) {
    $driverId = (int)$d['driver_id'];
    $meta = driver_statement_filter_metadata($mysqli, $driverId, $selectedPayoutVendor);
    foreach ($years as $yearValue) {
        $yearValue = (int)$yearValue;
        if ($yearValue > 0 && !in_array((string)$yearValue, $meta['work_years'], true) && driver_has_vendor_payout($mysqli, $driverId, $selectedPayoutVendor, $yearValue, null, null, 'work_date')) {
            $meta['work_years'][] = (string)$yearValue;
        }
    }
    foreach ($weekOptions as $weekOption) {
        $weekStart = (string)($weekOption['week_start'] ?? '');
        $weekEnd = (string)($weekOption['week_end'] ?? '');
        if ($weekStart === '' || $weekEnd === '') {
            continue;
        }
        $weekKey = $weekStart . '|' . $weekEnd;
        if (!in_array($weekKey, $meta['work_weeks'], true) && driver_has_vendor_payout($mysqli, $driverId, $selectedPayoutVendor, null, $weekStart, $weekEnd, 'work_date')) {
            $meta['work_weeks'][] = $weekKey;
            $weekYear = (string)(int)substr($weekStart, 0, 4);
            if (!in_array($weekYear, $meta['work_years'], true)) {
                $meta['work_years'][] = $weekYear;
            }
        }
    }
    rsort($meta['work_years']);
    rsort($meta['work_weeks']);
    $driverStatementFilters[$driverId] = $meta;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Reports</title>
  <!-- Bootstrap CSS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <style>
    body { margin:0; font-family:sans-serif; }
    .page-shell { display:flex; min-height: calc(100vh - var(--banner-h)); }
    .sidebar { width:250px; background:#333; color:#fff; height:calc(100vh - var(--banner-h)); position:fixed; top:var(--banner-h); left:0; overflow:auto; transition:transform .3s ease; z-index:1000; transform: translateX(0); }
    .sidebar.collapsed { transform: translateX(-250px); }
    .sidebar a { display:block; color:#fff; padding:15px; text-decoration:none; }
    .sidebar a:hover { background:#444; }
    .main { margin-left:250px; padding:20px; flex:1; min-width:0; }
    .sidebar.collapsed + .main { margin-left:0; }
    @media (max-width:768px){ .sidebar { transform: translateX(-250px); } .sidebar.open { transform: translateX(0); } .main { margin:0; } }
    form { margin-bottom:2em; border:1px solid #ccc; padding:15px; border-radius:5px; }
    label, select, button { display:block; width:100%; margin:8px 0; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
    <h1>Reports</h1>
    <form method="get" class="mb-4" style="border:none; padding:0;">
      <div class="d-flex flex-wrap align-items-end" style="gap:12px;">
        <label class="mb-0" style="width:auto; min-width:180px;">Payout Client
          <select name="payout_vendor" class="form-control form-control-sm payout-vendor-filter">
            <?php foreach (payout_vendor_scope_options() as $value => $label): ?>
              <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>" <?= $selectedPayoutVendor === $value ? 'selected' : '' ?>>
                <?= htmlspecialchars($label, ENT_QUOTES) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="form-check form-check-inline mb-0 d-flex align-items-center">
          <input class="form-check-input mt-0" type="checkbox" id="showDisabledDrivers" name="show_disabled" value="1" <?= $showDisabled ? 'checked' : '' ?>>
          <label class="form-check-label ms-1" for="showDisabledDrivers">Show disabled drivers</label>
        </div>
        <button type="submit" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center" style="width:auto;">Apply</button>
      </div>
    </form>
    <!-- Detail Report -->
    <form method="post">
      <input type="hidden" name="report_type" value="detail">
      <input type="hidden" name="show_disabled" value="<?= $showDisabled ? '1' : '0' ?>">
      <h2>Detail Report</h2>
      <label>From Date:<br>
        <input type="date" name="from_date" required>
      </label>
      <label>To Date:<br>
        <input type="date" name="to_date" required>
      </label>
      <label>Format
        <select name="export_format" class="form-control">
          <option value="xlsx">XLSX</option>
          <option value="pdf">PDF</option>
        </select>
      </label>
      <button type="submit" class="btn btn-primary">Download Detail</button>
    </form>

    <!-- Payout Report (Single Driver) -->
    <form method="post">
      <input type="hidden" name="report_type" value="payout">
      <input type="hidden" name="show_disabled" value="<?= $showDisabled ? '1' : '0' ?>">
      <h2>Payout Report</h2>
      <label>Driver
        <select name="driver_id" class="form-control payout-driver" required>
          <option value="">-- Select Driver --</option>
          <?php foreach($drivers as $d): ?>
            <?php
              $driverId = (int)$d['driver_id'];
              $filterMeta = $driverStatementFilters[$driverId] ?? ['work_years' => [], 'work_weeks' => [], 'upload_years' => [], 'upload_weeks' => []];
            ?>
            <option
              value="<?= $driverId ?>"
              data-work-years="<?= htmlspecialchars(implode(',', $filterMeta['work_years']), ENT_QUOTES) ?>"
              data-work-weeks="<?= htmlspecialchars(implode(',', $filterMeta['work_weeks']), ENT_QUOTES) ?>"
              data-upload-years="<?= htmlspecialchars(implode(',', $filterMeta['upload_years']), ENT_QUOTES) ?>"
              data-upload-weeks="<?= htmlspecialchars(implode(',', $filterMeta['upload_weeks']), ENT_QUOTES) ?>"
            ><?= htmlspecialchars($d['legal_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Year
        <select name="year" class="form-control payout-year">
          <?php foreach($years as $y): ?>
            <option><?= (int)$y ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Client
        <select name="payout_vendor" class="form-control payout-vendor-filter">
          <?php foreach (payout_vendor_scope_options() as $value => $label): ?>
            <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>" <?= $selectedPayoutVendor === $value ? 'selected' : '' ?>>
              <?= htmlspecialchars($label, ENT_QUOTES) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Statement Mode
        <select name="statement_basis" class="form-control payout-basis">
          <option value="work_date">Work Week</option>
          <option value="upload_date">Off-cycle / Statement Upload Date</option>
        </select>
      </label>
      <label>Week (required for PDF/CSV)
        <select name="week_range" class="form-control payout-week" disabled>
          <option value="">-- Select Week --</option>
          <?php foreach($weekOptions as $w): ?>
            <option value="<?= htmlspecialchars($w['week_start'] . '|' . $w['week_end'], ENT_QUOTES) ?>">
              <?= htmlspecialchars(substr((string)$w['week_start'], 5, 5) . ' to ' . substr((string)$w['week_end'], 5, 5), ENT_QUOTES) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Format
        <select name="export_format" class="form-control payout-format">
          <option value="xlsx">XLSX</option>
          <option value="pdf">PDF</option>
          <option value="csv">CSV</option>
        </select>
      </label>
      <button type="submit" class="btn btn-success">Download Payout</button>
    </form>

    <!-- Payout Reports: ALL Drivers (ZIP) -->
    <form method="post">
      <input type="hidden" name="report_type" value="payout_all">
      <input type="hidden" name="show_disabled" value="<?= $showDisabled ? '1' : '0' ?>">
      <h3>All Drivers — Payout Reports (ZIP)</h3>
      <label>Year
        <select name="year" class="form-control">
          <?php foreach($years as $y): ?>
            <option><?= (int)$y ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Client
        <select name="payout_vendor" class="form-control payout-vendor-filter">
          <?php foreach (payout_vendor_scope_options() as $value => $label): ?>
            <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>" <?= $selectedPayoutVendor === $value ? 'selected' : '' ?>>
              <?= htmlspecialchars($label, ENT_QUOTES) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Statement Mode
        <select name="statement_basis" class="form-control">
          <option value="work_date">Work Week</option>
          <option value="upload_date">Off-cycle / Statement Upload Date</option>
        </select>
      </label>
      <label>Week (required for PDF/CSV)
        <select name="week_range" class="form-control payout-week" disabled>
          <option value="">-- Select Week --</option>
          <?php foreach($weekOptions as $w): ?>
            <option value="<?= htmlspecialchars($w['week_start'] . '|' . $w['week_end'], ENT_QUOTES) ?>">
              <?= htmlspecialchars(substr((string)$w['week_start'], 5, 5) . ' to ' . substr((string)$w['week_end'], 5, 5), ENT_QUOTES) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Format
        <select name="export_format" class="form-control payout-format">
          <option value="xlsx">XLSX</option>
          <option value="pdf">PDF</option>
          <option value="csv">CSV</option>
        </select>
      </label>
      <button type="submit" class="btn btn-info">Download ALL Payouts (ZIP)</button>
      <small class="text-muted d-block mt-2">
        A copy is also saved on the server in <code>/exports</code>.
      </small>
    </form>

    <!-- Contacts Report -->
    <form method="post">
      <input type="hidden" name="report_type" value="contacts">
      <input type="hidden" name="show_disabled" value="<?= $showDisabled ? '1' : '0' ?>">
      <h2>Contacts Report</h2>
      <label>Format
        <select name="export_format" class="form-control">
          <option value="xlsx">XLSX</option>
          <option value="pdf">PDF</option>
        </select>
      </label>
      <button type="submit" class="btn btn-secondary">Download Contacts</button>
    </form>

    <!-- Unassigned Misc Revenue -->
    <form method="post">
      <input type="hidden" name="report_type" value="unassigned_misc">
      <input type="hidden" name="show_disabled" value="<?= $showDisabled ? '1' : '0' ?>">
      <h2>Unassigned Misc Revenue</h2>
      <p class="text-muted mb-2">Shows TSS miscellaneous payout rows that are not matched to a driver yet.</p>
      <label>Upload Date (optional)
        <select name="upload_date" class="form-control">
          <option value="">-- All Upload Dates --</option>
          <?php foreach($miscUploadDates as $d): ?>
            <option value="<?= htmlspecialchars($d, ENT_QUOTES) ?>"><?= htmlspecialchars($d) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>From Date (optional)
        <input type="date" name="from_date" class="form-control">
      </label>
      <label>To Date (optional)
        <input type="date" name="to_date" class="form-control">
      </label>
      <label>Format
        <select name="export_format" class="form-control">
          <option value="xlsx">XLSX</option>
          <option value="pdf">PDF</option>
        </select>
      </label>
      <button type="submit" class="btn btn-warning">Download Unassigned Misc</button>
    </form>

  </div>
  </div>
  <script>
    document.querySelectorAll('.payout-vendor-filter').forEach((select) => {
      select.addEventListener('change', () => {
        const url = new URL(window.location.href);
        const showDisabled = document.querySelector('#showDisabledDrivers');
        url.searchParams.set('payout_vendor', select.value);
        if (showDisabled && showDisabled.checked) {
          url.searchParams.set('show_disabled', '1');
        } else {
          url.searchParams.delete('show_disabled');
        }
        window.location.href = url.toString();
      });
    });

    document.querySelectorAll('form').forEach((form) => {
      const format = form.querySelector('.payout-format');
      const week = form.querySelector('.payout-week');
      const driver = form.querySelector('.payout-driver');
      const year = form.querySelector('.payout-year');
      const basis = form.querySelector('.payout-basis');
      if (!format || !week) return;
      const optionList = (option, key) => (option.dataset[key] || '').split(',').filter(Boolean);
      const driverMatchesFilters = (option) => {
        if (!driver || !option.value) return true;
        const needsWeek = format.value === 'pdf' || format.value === 'csv';
        const offcycleMode = basis && basis.value === 'upload_date';
        const years = optionList(option, offcycleMode && needsWeek ? 'uploadYears' : 'workYears');
        const weeks = optionList(option, offcycleMode ? 'uploadWeeks' : 'workWeeks');
        if (needsWeek && week.value) {
          return weeks.includes(week.value) && (!year || years.includes(year.value));
        }
        return !year || years.includes(year.value);
      };
      const sync = () => {
        const needsWeek = format.value === 'pdf' || format.value === 'csv';
        week.disabled = !needsWeek;
        week.required = needsWeek;
        if (!needsWeek) {
          week.value = '';
        }
        if (driver) {
          let selectedStillAvailable = false;
          Array.from(driver.options).forEach((option) => {
            const show = driverMatchesFilters(option);
            option.hidden = !show;
            option.disabled = !show;
            if (show && option.selected && option.value) {
              selectedStillAvailable = true;
            }
          });
          if (driver.value && !selectedStillAvailable) {
            driver.value = '';
          }
        }
      };
      format.addEventListener('change', sync);
      week.addEventListener('change', sync);
      if (year) year.addEventListener('change', sync);
      if (basis) basis.addEventListener('change', sync);
      sync();
    });
  </script>
</body>
</html>
