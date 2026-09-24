<?php
require_once __DIR__ . '/rtex_fsc.php';
require_once __DIR__ . '/trailer_assignment_history.php';
require_once __DIR__ . '/balance_history.php';

if (!function_exists('lonestar_payout_table_exists')) {
    function lonestar_payout_table_exists(mysqli $mysqli, string $table): bool {
        $table = $mysqli->real_escape_string($table);
        $res = $mysqli->query("SHOW TABLES LIKE '{$table}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('lonestar_payout_column_exists')) {
    function lonestar_payout_column_exists(mysqli $mysqli, string $table, string $column): bool {
        $table = $mysqli->real_escape_string($table);
        $column = $mysqli->real_escape_string($column);
        $res = $mysqli->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('lonestar_driver_fuel_balance_ensure_table')) {
    function lonestar_driver_fuel_balance_ensure_table(mysqli $mysqli): void {
        static $done = false;
        if ($done) {
            return;
        }
        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS driver_fuel_balances (
              driver_id INT NOT NULL PRIMARY KEY,
              balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              last_calculated_week_start DATE NULL,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $done = true;
    }
}

if (!function_exists('lonestar_driver_open_fuel_balance')) {
    function lonestar_driver_open_fuel_balance(mysqli $mysqli, int $driverId, string $asOfWeekStart = ''): float {
        if ($driverId <= 0) {
            return 0.0;
        }
        lonestar_driver_fuel_balance_ensure_table($mysqli);
        $sql = "SELECT COALESCE(balance,0) FROM driver_fuel_balances WHERE driver_id=?";
        if ($asOfWeekStart !== '') {
            $sql .= " AND (last_calculated_week_start IS NULL OR last_calculated_week_start < ?)";
        }
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return 0.0;
        }
        if ($asOfWeekStart !== '') {
            $stmt->bind_param('is', $driverId, $asOfWeekStart);
        } else {
            $stmt->bind_param('i', $driverId);
        }
        $stmt->execute();
        $stmt->bind_result($balance);
        $foundCurrentBalance = $stmt->fetch();
        $amount = $foundCurrentBalance ? (float)$balance : 0.0;
        $stmt->close();
        if ($foundCurrentBalance || $asOfWeekStart === '') {
            return round(max(0.0, $amount), 2);
        }

        lonestar_driver_fuel_balance_history_ensure_table($mysqli);
        $historyStmt = $mysqli->prepare(
            "SELECT ending_balance
               FROM driver_fuel_balance_history
              WHERE driver_id=?
                AND week_start < ?
              ORDER BY week_start DESC
              LIMIT 1"
        );
        if (!$historyStmt) {
            return 0.0;
        }
        $historyStmt->bind_param('is', $driverId, $asOfWeekStart);
        $historyStmt->execute();
        $historyStmt->bind_result($historyBalance);
        $amount = $historyStmt->fetch() ? (float)$historyBalance : 0.0;
        $historyStmt->close();
        return round(max(0.0, $amount), 2);
    }
}

if (!function_exists('lonestar_driver_save_fuel_balance')) {
    function lonestar_driver_save_fuel_balance(mysqli $mysqli, int $driverId, float $balance, string $weekStart): void {
        if ($driverId <= 0) {
            return;
        }
        lonestar_driver_fuel_balance_ensure_table($mysqli);
        $balance = round(max(0.0, $balance), 2);
        $stmt = $mysqli->prepare(
            "INSERT INTO driver_fuel_balances (driver_id, balance, last_calculated_week_start)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                balance = VALUES(balance),
                last_calculated_week_start = VALUES(last_calculated_week_start),
                updated_at = NOW()"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('ids', $driverId, $balance, $weekStart);
        $stmt->execute();
        $stmt->close();
        lonestar_driver_fuel_balance_history_save(
            $mysqli,
            $driverId,
            $weekStart,
            $balance,
            'statement',
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
        );
    }
}

if (!function_exists('lonestar_driver_misc_balance_ensure_table')) {
    function lonestar_driver_misc_balance_ensure_table(mysqli $mysqli): void {
        static $done = false;
        if ($done) {
            return;
        }
        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS driver_misc_adjustment_balances (
              driver_id INT NOT NULL,
              vendor_scope VARCHAR(30) NOT NULL,
              balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              last_calculated_week_start DATE NULL,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (driver_id, vendor_scope)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $done = true;
    }
}

if (!function_exists('lonestar_driver_open_misc_balance')) {
    function lonestar_driver_open_misc_balance(mysqli $mysqli, int $driverId, string $vendorScope, string $asOfWeekStart = ''): float {
        if ($driverId <= 0) {
            return 0.0;
        }
        lonestar_driver_misc_balance_ensure_table($mysqli);
        $scope = lonestar_payout_normalize_vendor_scope($vendorScope);
        $sql = "SELECT COALESCE(balance,0) FROM driver_misc_adjustment_balances WHERE driver_id=? AND vendor_scope=?";
        if ($asOfWeekStart !== '') {
            $sql .= " AND (last_calculated_week_start IS NULL OR last_calculated_week_start < ?)";
        }
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return 0.0;
        }
        if ($asOfWeekStart !== '') {
            $stmt->bind_param('iss', $driverId, $scope, $asOfWeekStart);
        } else {
            $stmt->bind_param('is', $driverId, $scope);
        }
        $stmt->execute();
        $stmt->bind_result($balance);
        $foundCurrentBalance = $stmt->fetch();
        $amount = $foundCurrentBalance ? (float)$balance : 0.0;
        $stmt->close();
        if ($foundCurrentBalance || $asOfWeekStart === '') {
            return round($amount, 2);
        }

        // A same-week statement regeneration must still use the prior week's
        // closing balance. The current snapshot has already advanced to this
        // week, so recover the opening from the immutable weekly ledger.
        lonestar_driver_misc_balance_history_ensure_table($mysqli);
        $historyStmt = $mysqli->prepare(
            "SELECT ending_balance
               FROM driver_misc_balance_history
              WHERE driver_id=?
                AND vendor_scope=?
                AND week_start < ?
              ORDER BY week_start DESC
              LIMIT 1"
        );
        if (!$historyStmt) {
            return 0.0;
        }
        $historyStmt->bind_param('iss', $driverId, $scope, $asOfWeekStart);
        $historyStmt->execute();
        $historyStmt->bind_result($historyBalance);
        $amount = $historyStmt->fetch() ? (float)$historyBalance : 0.0;
        $historyStmt->close();
        return round($amount, 2);
    }
}

if (!function_exists('lonestar_driver_save_misc_balance')) {
    function lonestar_driver_save_misc_balance(mysqli $mysqli, int $driverId, string $vendorScope, float $balance, string $weekStart): void {
        if ($driverId <= 0) {
            return;
        }
        lonestar_driver_misc_balance_ensure_table($mysqli);
        $scope = lonestar_payout_normalize_vendor_scope($vendorScope);
        $balance = round($balance, 2);
        $stmt = $mysqli->prepare(
            "INSERT INTO driver_misc_adjustment_balances (driver_id, vendor_scope, balance, last_calculated_week_start)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                balance = VALUES(balance),
                last_calculated_week_start = VALUES(last_calculated_week_start),
                updated_at = NOW()"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('isds', $driverId, $scope, $balance, $weekStart);
        $stmt->execute();
        $stmt->close();
        lonestar_driver_misc_balance_history_save(
            $mysqli,
            $driverId,
            $scope,
            $weekStart,
            $balance,
            'statement',
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
        );
    }
}

if (!function_exists('lonestar_driver_finalize_unpaid_balance')) {
    /**
     * Persist the part of a completed weekly statement that the week's earnings
     * could not cover. Fuel is finalized separately, so statementNet must be
     * calculated before any remaining-fuel display adjustment.
     */
    function lonestar_driver_finalize_unpaid_balance(
        mysqli $mysqli,
        int $driverId,
        string $vendorScope,
        string $weekStart,
        float $statementNet,
        bool $hasPayout
    ): float {
        if ($driverId <= 0 || $weekStart === '') {
            return 0.0;
        }
        $statementNet = round($statementNet, 2);
        $endingBalance = $statementNet < -0.005 ? $statementNet : 0.0;

        // Earnings consume the prior/current balance. If the result remains
        // negative, retain only that unpaid remainder. A deductions-only
        // statement also retains its debt.
        if ($hasPayout || $endingBalance < -0.005) {
            lonestar_driver_save_misc_balance(
                $mysqli,
                $driverId,
                $vendorScope,
                $endingBalance,
                $weekStart
            );
        }
        return round($endingBalance, 2);
    }
}

if (!function_exists('lonestar_misc_adjustments_ensure_table')) {
    function lonestar_misc_adjustments_ensure_table(mysqli $mysqli): void {
        static $done = false;
        if ($done) {
            return;
        }
        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS tss_misc_adjustments (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              payout_vendor VARCHAR(20) NOT NULL DEFAULT 'TSS',
              source_upload_date DATE NOT NULL,
              payout_week_start DATE NOT NULL,
              driver_contact_id INT NOT NULL,
              adjustment_type ENUM('misc_payment','misc_deduction') NOT NULL,
              amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              comments VARCHAR(255) NOT NULL,
              created_by INT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_tss_misc_adjustments_week_driver (payout_week_start, driver_contact_id),
              KEY idx_tss_misc_adjustments_source_date (source_upload_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $columns = [
            'payout_vendor' => "ALTER TABLE tss_misc_adjustments ADD COLUMN payout_vendor VARCHAR(20) NOT NULL DEFAULT 'TSS' AFTER id",
            'adjustment_date' => "ALTER TABLE tss_misc_adjustments ADD COLUMN adjustment_date DATE NULL AFTER source_upload_date",
            'updated_at' => "ALTER TABLE tss_misc_adjustments ADD COLUMN updated_at DATETIME NULL AFTER created_at",
        ];
        foreach ($columns as $column => $sql) {
            if (!lonestar_payout_column_exists($mysqli, 'tss_misc_adjustments', $column)) {
                $mysqli->query($sql);
            }
        }
        $mysqli->query("UPDATE tss_misc_adjustments SET adjustment_date = source_upload_date WHERE adjustment_date IS NULL");
        $idx = $mysqli->query("SHOW INDEX FROM tss_misc_adjustments WHERE Key_name = 'idx_tss_misc_adjustments_vendor_date_driver'");
        if (!$idx || $idx->num_rows === 0) {
            $mysqli->query("ALTER TABLE tss_misc_adjustments ADD KEY idx_tss_misc_adjustments_vendor_date_driver (payout_vendor, adjustment_date, driver_contact_id)");
        }
        if ($idx) {
            $idx->close();
        }
        $done = true;
    }
}

if (!function_exists('lonestar_auto_nextier_trailer_rental_deductions')) {
    function lonestar_auto_nextier_trailer_rental_deductions(mysqli $mysqli, string $weekStart, string $weekEnd): int {
        if ($weekStart === '' || $weekEnd === '') {
            return 0;
        }
        try {
            if (
                !lonestar_payout_table_exists($mysqli, 'driver_contacts')
                || !lonestar_payout_table_exists($mysqli, 'driver_payouts')
            ) {
                return 0;
            }
            lonestar_misc_adjustments_ensure_table($mysqli);
            lonestar_trailer_history_ensure_table($mysqli);

            $stmt = $mysqli->prepare(
                "SELECT dc.id,
                        COALESCE(NULLIF(CONCAT(dc.first_name, ' ', dc.last_name), ''), 'Driver') AS driver_name,
                        tah.trailer_number,
                        tah.assigned_date,
                        tah.removed_date,
                        COALESCE(NULLIF(tah.trailer_fee_mode, ''), ta.trailer_fee_mode) AS trailer_fee_mode,
                        COALESCE(tah.trailer_fee_value, ta.trailer_fee_value) AS trailer_fee_value
                   FROM trailer_assignment_history tah
                   JOIN driver_contacts dc
                     ON dc.id = tah.driver_contact_id
              LEFT JOIN trailer_assets ta
                     ON (
                        (tah.trailer_id IS NOT NULL AND ta.id = tah.trailer_id)
                        OR TRIM(COALESCE(ta.trailer_number, '')) = TRIM(COALESCE(tah.trailer_number, ''))
                     )
                  WHERE COALESCE(dc.is_disabled, 0) = 0
                    AND UPPER(TRIM(COALESCE(NULLIF(tah.vendor, ''), ta.vendor, ''))) = 'NEXTIER'
                    AND tah.assigned_date <= ?
                    AND COALESCE(tah.removed_date, ?) >= ?
                    AND NOT EXISTS (
                        SELECT 1
                          FROM driver_payouts dp
                         WHERE dp.driver_contact_id = dc.id
                           AND UPPER(TRIM(COALESCE(dp.vendor_name, ''))) = 'NEXTIER'
                           AND dp.payout_date BETWEEN ? AND ?
                    )
                  ORDER BY driver_name, tah.assigned_date"
            );
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('sssss', $weekEnd, $weekEnd, $weekStart, $weekStart, $weekEnd);
            $stmt->execute();
            $stmt->bind_result($rawDriverId, $rawDriverName, $rawTrailerNo, $rawAssignedDate, $rawRemovedDate, $rawFeeMode, $rawFeeValue);
            $stmt->store_result();

            $insertedOrUpdated = 0;
            while ($stmt->fetch()) {
                $driverId = (int)$rawDriverId;
                $trailerNo = trim((string)$rawTrailerNo);
                $assignedDate = trim((string)$rawAssignedDate);
                $removedDate = trim((string)$rawRemovedDate);
                $periodStart = max($weekStart, $assignedDate);
                $periodEnd = $removedDate !== '' ? min($weekEnd, $removedDate) : $weekEnd;
                $days = lonestar_nextier_trailer_fee_days($periodStart, $periodEnd, $assignedDate);
                if ($driverId <= 0 || $trailerNo === '' || $days <= 0) {
                    continue;
                }

                $dailyRate = ((string)$rawFeeMode === 'flat' && $rawFeeValue !== null)
                    ? max(0.0, (float)$rawFeeValue)
                    : 70.0;
                $amount = round($days * $dailyRate, 2);
                $comments = "Auto NexTier trailer rental deduction - {$trailerNo} - {$weekStart} to {$weekEnd}";

                $existingId = 0;
                $existing = $mysqli->prepare(
                    "SELECT id
                       FROM tss_misc_adjustments
                      WHERE payout_vendor = 'NEXTIER'
                        AND payout_week_start = ?
                        AND driver_contact_id = ?
                        AND adjustment_type = 'misc_deduction'
                        AND comments LIKE 'Auto NexTier trailer rental deduction%'
                      LIMIT 1"
                );
                if ($existing) {
                    $existing->bind_param('si', $weekStart, $driverId);
                    $existing->execute();
                    $existing->bind_result($existingId);
                    $existing->fetch();
                    $existing->close();
                }

                if ($existingId > 0) {
                    $update = $mysqli->prepare(
                        "UPDATE tss_misc_adjustments
                            SET source_upload_date = ?,
                                adjustment_date = ?,
                                amount = ?,
                                comments = ?,
                                updated_at = NOW()
                          WHERE id = ?
                          LIMIT 1"
                    );
                    if (!$update) {
                        continue;
                    }
                    $update->bind_param('ssdsi', $weekStart, $weekStart, $amount, $comments, $existingId);
                    $update->execute();
                    $update->close();
                    $insertedOrUpdated++;
                    continue;
                }

                $insert = $mysqli->prepare(
                    "INSERT INTO tss_misc_adjustments
                        (payout_vendor, source_upload_date, adjustment_date, payout_week_start, driver_contact_id, adjustment_type, amount, comments, created_by)
                     VALUES
                        ('NEXTIER', ?, ?, ?, ?, 'misc_deduction', ?, ?, NULL)"
                );
                if (!$insert) {
                    continue;
                }
                $insert->bind_param('sssids', $weekStart, $weekStart, $weekStart, $driverId, $amount, $comments);
                $insert->execute();
                $insert->close();
                $insertedOrUpdated++;
            }
            $stmt->close();
            return $insertedOrUpdated;
        } catch (Throwable $e) {
            error_log('NexTier trailer rental auto deduction failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('lonestar_driver_week_fuel_surcharge_total')) {
    function lonestar_driver_week_fuel_surcharge_total(mysqli $mysqli, int $driverId, string $weekStart, string $weekEnd): float {
        if (
            $driverId <= 0
            || !lonestar_payout_table_exists($mysqli, 'ls_detail_raw')
            || !lonestar_payout_column_exists($mysqli, 'ls_detail_raw', 'fuel_surcharge_rate')
        ) {
            return 0.0;
        }

        $milesExpr = "CAST(REPLACE(REPLACE(COALESCE(ldr.`Mileage`, '0'), ',', ''), '$', '') AS DECIMAL(12,2))";
        $tonsExpr = "CAST(REPLACE(REPLACE(COALESCE(ldr.`Net Weight (Tons)`, '0'), ',', ''), '$', '') AS DECIMAL(12,2))";
        $surchargeBaseExpr = $milesExpr;
        if (lonestar_payout_column_exists($mysqli, 'ls_detail_raw', 'fuel_surcharge_type')) {
            $surchargeBaseExpr = "CASE
                WHEN LOWER(TRIM(COALESCE(ldr.fuel_surcharge_type, 'mileage')))
                     IN ('ton', 'tons', 'tonnage', 'per ton', 'per-ton')
                THEN {$tonsExpr}
                ELSE {$milesExpr}
            END";
        }

        $fscAmountExpr = "({$surchargeBaseExpr}) * COALESCE(ldr.fuel_surcharge_rate, 0)";
        if (lonestar_payout_column_exists($mysqli, 'ls_detail_raw', 'fuel_surcharge_amount')) {
            $fscAmountExpr = "COALESCE(ldr.fuel_surcharge_amount, {$fscAmountExpr})";
        }

        $sql = "
            SELECT COALESCE(SUM(
                {$fscAmountExpr}
            ), 0)
              FROM ls_detail_raw ldr
              LEFT JOIN (
                SELECT upload_date, ticket_number, driver_contact_id, MIN(payout_date) AS payout_date
                  FROM driver_payouts
                 WHERE driver_contact_id IS NOT NULL
                 GROUP BY upload_date, ticket_number, driver_contact_id
              ) dp
                ON ldr.matched_contact_id IS NULL
               AND dp.upload_date = ldr.upload_date
               AND dp.ticket_number = ldr.`Truckload ID`
             WHERE COALESCE(ldr.matched_contact_id, dp.driver_contact_id) = ?
               AND COALESCE(ldr.fuel_surcharge_rate, 0) > 0
               AND COALESCE(
                    NULLIF(DATE(ldr.`Delivery Date`), '0000-00-00'),
                    STR_TO_DATE(ldr.`Delivery Date`, '%Y-%m-%d'),
                    STR_TO_DATE(SUBSTRING_INDEX(ldr.`Delivery Date`, ',', 1), '%m/%d/%Y'),
                    STR_TO_DATE(SUBSTRING_INDEX(ldr.`Delivery Date`, ',', 1), '%c/%e/%Y'),
                    STR_TO_DATE(ldr.`Delivery Date`, '%m/%d/%Y'),
                    STR_TO_DATE(ldr.`Delivery Date`, '%c/%e/%Y'),
                    dp.payout_date
               ) BETWEEN ? AND ?
        ";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return 0.0;
        }
        $stmt->bind_param('iss', $driverId, $weekStart, $weekEnd);
        $stmt->execute();
        $stmt->bind_result($amount);
        $total = $stmt->fetch() ? (float)$amount : 0.0;
        $stmt->close();
        return round($total, 2);
    }
}

if (!function_exists('lonestar_rtex_driver_week_hours_total')) {
    function lonestar_rtex_driver_week_hours_total(mysqli $mysqli, int $driverId, string $weekStart, string $weekEnd): float {
        if (
            $driverId <= 0
            || !lonestar_payout_table_exists($mysqli, 'rtex_payout_rows')
            || !lonestar_payout_column_exists($mysqli, 'rtex_payout_rows', 'hours')
            || !lonestar_payout_column_exists($mysqli, 'rtex_payout_rows', 'matched_contact_id')
        ) {
            return 0.0;
        }

        $stmt = $mysqli->prepare(
            "SELECT COALESCE(SUM(hours), 0)
               FROM rtex_payout_rows
              WHERE matched_contact_id = ?
                AND work_date BETWEEN ? AND ?"
        );
        if (!$stmt) {
            return 0.0;
        }
        $stmt->bind_param('iss', $driverId, $weekStart, $weekEnd);
        $stmt->execute();
        $stmt->bind_result($hours);
        $total = $stmt->fetch() ? (float)$hours : 0.0;
        $stmt->close();
        return round($total, 2);
    }
}

if (!function_exists('lonestar_payout_normalize_vendor_scope')) {
    function lonestar_payout_normalize_vendor_scope(string $vendorScope): string {
        $scope = strtolower(trim($vendorScope));
        return in_array($scope, ['tss', 'tss_company', 'detmar', 'nextier', 'rtex', 'nickelrock'], true) ? $scope : 'tss';
    }
}

if (!function_exists('lonestar_payout_vendor_order')) {
    function lonestar_payout_vendor_order(): array {
        return ['tss', 'detmar', 'nickelrock', 'nextier', 'rtex'];
    }
}

if (!function_exists('lonestar_payout_vendor_code')) {
    function lonestar_payout_vendor_code(string $vendorScope): string {
        $scope = lonestar_payout_normalize_vendor_scope($vendorScope);
        if ($scope === 'rtex') {
            return 'RTEX';
        }
        if ($scope === 'nextier') {
            return 'NEXTIER';
        }
        if ($scope === 'nickelrock') {
            return 'NICKELROCK';
        }
        if ($scope === 'detmar') {
            return 'DETMAR';
        }
        return 'TSS';
    }
}

if (!function_exists('lonestar_payout_vendor_sql_key')) {
    function lonestar_payout_vendor_sql_key(string $column = 'payout_vendor'): string {
        return "UPPER(REPLACE(REPLACE(COALESCE({$column}, 'TSS'), ' ', ''), '-', ''))";
    }
}

if (!function_exists('lonestar_vendor_broker_fee_defaults')) {
    function lonestar_vendor_broker_fee_defaults(): array {
        return [
            'tss' => ['label' => 'TSS', 'fee_mode' => 'percentage', 'fee_value' => 10.00],
            'tss_company' => ['label' => 'TSS Company Drivers', 'fee_mode' => 'percentage', 'fee_value' => 0.00],
            'detmar' => ['label' => 'Detmar', 'fee_mode' => 'percentage', 'fee_value' => 10.00],
            'nextier' => ['label' => 'NexTier', 'fee_mode' => 'percentage', 'fee_value' => 10.00],
            'rtex' => ['label' => 'RTEX', 'fee_mode' => 'flat', 'fee_value' => 10.00],
            'nickelrock' => ['label' => 'Nickel Rock', 'fee_mode' => 'percentage', 'fee_value' => 10.00],
        ];
    }
}

if (!function_exists('lonestar_vendor_broker_fees_ensure_table')) {
    function lonestar_vendor_broker_fees_ensure_table(mysqli $mysqli): void {
        static $done = false;
        if ($done) {
            return;
        }
        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS vendor_broker_fees (
              vendor_scope VARCHAR(30) NOT NULL PRIMARY KEY,
              vendor_label VARCHAR(80) NOT NULL,
              fee_mode ENUM('percentage','flat') NOT NULL DEFAULT 'percentage',
              fee_value DECIMAL(12,2) NOT NULL DEFAULT 10.00,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $stmt = $mysqli->prepare(
            "INSERT INTO vendor_broker_fees (vendor_scope, vendor_label, fee_mode, fee_value)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE vendor_label = VALUES(vendor_label)"
        );
        if ($stmt) {
            foreach (lonestar_vendor_broker_fee_defaults() as $scope => $settings) {
                $label = $settings['label'];
                $mode = $settings['fee_mode'];
                $value = (float)$settings['fee_value'];
                $stmt->bind_param('sssd', $scope, $label, $mode, $value);
                $stmt->execute();
            }
            $stmt->close();
        }
        $done = true;
    }
}

if (!function_exists('lonestar_vendor_broker_fee_settings')) {
    function lonestar_vendor_broker_fee_settings(mysqli $mysqli, string $vendorScope): array {
        lonestar_vendor_broker_fees_ensure_table($mysqli);
        $scope = lonestar_payout_normalize_vendor_scope($vendorScope);
        $defaults = lonestar_vendor_broker_fee_defaults();
        $settings = $defaults[$scope] ?? $defaults['tss'];
        $stmt = $mysqli->prepare("SELECT vendor_label, fee_mode, fee_value FROM vendor_broker_fees WHERE vendor_scope=?");
        if ($stmt) {
            $stmt->bind_param('s', $scope);
            $stmt->execute();
            $stmt->bind_result($label, $mode, $value);
            if ($stmt->fetch()) {
                $settings = [
                    'label' => (string)$label,
                    'fee_mode' => in_array((string)$mode, ['percentage', 'flat'], true) ? (string)$mode : $settings['fee_mode'],
                    'fee_value' => (float)$value,
                ];
            }
            $stmt->close();
        }
        $settings['vendor_scope'] = $scope;
        return $settings;
    }
}

if (!function_exists('lonestar_vendor_broker_fee_amount')) {
    function lonestar_vendor_broker_fee_amount(mysqli $mysqli, string $vendorScope, float $grossTotal, float $hoursTotal = 0.0): float {
        $settings = lonestar_vendor_broker_fee_settings($mysqli, $vendorScope);
        $value = max(0.0, (float)$settings['fee_value']);
        if (($settings['fee_mode'] ?? 'percentage') === 'flat') {
            $scope = lonestar_payout_normalize_vendor_scope($vendorScope);
            return round($scope === 'rtex' ? ($hoursTotal * $value) : $value, 2);
        }
        return round($grossTotal * ($value / 100.0), 2);
    }
}

if (!function_exists('lonestar_tss_driver_broker_fee_percentage')) {
    function lonestar_tss_driver_broker_fee_percentage(
        mysqli $mysqli,
        int $driverId,
        array $brokerFeeSettings
    ): ?float {
        if (($brokerFeeSettings['fee_mode'] ?? 'percentage') !== 'percentage') {
            return null;
        }

        $defaultPct = max(0.0, (float)($brokerFeeSettings['fee_value'] ?? 0));
        if ($driverId <= 0) {
            return $defaultPct;
        }

        $driverType = '';
        if ($stmt = $mysqli->prepare("SELECT COALESCE(driver_type, '') FROM driver_contacts WHERE id=?")) {
            $stmt->bind_param('i', $driverId);
            $stmt->execute();
            $stmt->bind_result($driverType);
            $stmt->fetch();
            $stmt->close();
        }

        if (strcasecmp(trim((string)$driverType), 'Company Driver') !== 0) {
            return $defaultPct;
        }

        $companySettings = lonestar_vendor_broker_fee_settings($mysqli, 'tss_company');
        return (($companySettings['fee_mode'] ?? 'percentage') === 'percentage')
            ? max(0.0, (float)($companySettings['fee_value'] ?? 0))
            : $defaultPct;
    }
}
if (!function_exists('lonestar_tss_driver_week_broker_fee_amount')) {
    function lonestar_tss_driver_week_broker_fee_amount(
        mysqli $mysqli,
        int $driverId,
        string $weekStart,
        string $weekEnd,
        float $grossTotal,
        array $brokerFeeSettings
    ): float {
        if ($driverId <= 0) {
            return lonestar_vendor_broker_fee_amount($mysqli, 'tss', $grossTotal);
        }

        $defaultPct = (($brokerFeeSettings['fee_mode'] ?? 'percentage') === 'percentage') ? max(0.0, (float)($brokerFeeSettings['fee_value'] ?? 0)) : null;
        if ($defaultPct === null) {
            return lonestar_vendor_broker_fee_amount($mysqli, 'tss', $grossTotal);
        }
        $driverPct = lonestar_tss_driver_broker_fee_percentage($mysqli, $driverId, $brokerFeeSettings) ?? $defaultPct;

        if (!lonestar_payout_table_exists($mysqli, 'ls_detail_raw') || !lonestar_payout_column_exists($mysqli, 'ls_detail_raw', 'matched_contact_id')) {
            return round($grossTotal * ($driverPct / 100.0), 2);
        }

        $hasOverride = lonestar_payout_column_exists($mysqli, 'ls_detail_raw', 'broker_fee_override_pct');

        $dateExpr = "COALESCE(
            NULLIF(DATE(ldr.`Delivery Date`), '0000-00-00'),
            STR_TO_DATE(ldr.`Delivery Date`, '%Y-%m-%d'),
            STR_TO_DATE(SUBSTRING_INDEX(ldr.`Delivery Date`, ',', 1), '%m/%d/%Y'),
            STR_TO_DATE(SUBSTRING_INDEX(ldr.`Delivery Date`, ',', 1), '%c/%e/%Y'),
            STR_TO_DATE(ldr.`Delivery Date`, '%m/%d/%Y'),
            STR_TO_DATE(ldr.`Delivery Date`, '%c/%e/%Y')
        )";
        $grossExpr = "CAST(REPLACE(REPLACE(COALESCE(ldr.`Calculated Freight Rate (Carrier)`, '0'), ',', ''), '$', '') AS DECIMAL(12,2))";
        $overrideSelect = $hasOverride ? 'ldr.broker_fee_override_pct' : 'NULL';
        $sql = "
            SELECT {$grossExpr} AS line_gross,
                   {$overrideSelect} AS override_pct,
                   COALESCE(dc.driver_type, '') AS driver_type
              FROM ls_detail_raw ldr
              LEFT JOIN driver_contacts dc ON dc.id = ldr.matched_contact_id
             WHERE ldr.matched_contact_id = ?
               AND {$dateExpr} BETWEEN ? AND ?
        ";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return round($grossTotal * ($driverPct / 100.0), 2);
        }
        $stmt->bind_param('iss', $driverId, $weekStart, $weekEnd);
        $stmt->execute();
        $res = $stmt->get_result();
        $feeTotal = 0.0;
        $rowGrossTotal = 0.0;
        while ($row = $res->fetch_assoc()) {
            $lineGross = round((float)($row['line_gross'] ?? 0), 2);
            $rowGrossTotal += $lineGross;
            if (($row['override_pct'] ?? null) !== null && ($row['override_pct'] ?? '') !== '') {
                $pct = max(0.0, (float)$row['override_pct']);
            } elseif (strcasecmp(trim((string)($row['driver_type'] ?? '')), 'Company Driver') === 0) {
                $pct = $driverPct;
            } else {
                $pct = $defaultPct;
            }
            $feeTotal += round($lineGross * ($pct / 100.0), 2);
        }
        $stmt->close();

        if (abs($rowGrossTotal) < 0.01) {
            return round($grossTotal * ($driverPct / 100.0), 2);
        }
        if (abs($grossTotal - $rowGrossTotal) >= 0.01) {
            $remainingGross = round($grossTotal - $rowGrossTotal, 2);
            $feeTotal += round($remainingGross * ($driverPct / 100.0), 2);
        }
        return round($feeTotal, 2);
    }
}

if (!function_exists('lonestar_payout_vendor_scope_from_name')) {
    function lonestar_payout_vendor_scope_from_name(string $vendorName): string {
        $vendor = strtoupper(trim($vendorName));
        if ($vendor === 'NEXTIER') {
            return 'nextier';
        }
        if ($vendor === 'RTEX') {
            return 'rtex';
        }
        if ($vendor === 'DETMAR') {
            return 'detmar';
        }
        if ($vendor === 'NICKEL ROCK' || $vendor === 'NICKELROCK') {
            return 'nickelrock';
        }
        return 'tss';
    }
}

if (!function_exists('lonestar_driver_week_first_payout_scope')) {
    function lonestar_driver_week_first_payout_scope(mysqli $mysqli, int $driverId, string $weekStart, string $weekEnd): string {
        if ($driverId <= 0 || !lonestar_payout_table_exists($mysqli, 'driver_payouts')) {
            return '';
        }
        $stmt = $mysqli->prepare(
            "SELECT DISTINCT vendor_name
               FROM driver_payouts
              WHERE driver_contact_id = ?
                AND payout_date BETWEEN ? AND ?"
        );
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param('iss', $driverId, $weekStart, $weekEnd);
        $stmt->execute();
        $stmt->bind_result($vendorName);
        $scopes = [];
        while ($stmt->fetch()) {
            $scope = lonestar_payout_vendor_scope_from_name((string)$vendorName);
            $scopes[$scope] = true;
        }
        $stmt->close();
        foreach (lonestar_payout_vendor_order() as $scope) {
            if (!empty($scopes[$scope])) {
                return $scope;
            }
        }
        return '';
    }
}

if (!function_exists('lonestar_driver_week_total_fuel')) {
    function lonestar_driver_week_total_fuel(mysqli $mysqli, int $driverId, string $weekStart, string $weekEnd): float {
        if ($driverId <= 0) {
            return 0.0;
        }
        static $fuelCache = [];
        $fuelKey = $driverId . '|' . $weekStart . '|' . $weekEnd;
        if (!array_key_exists($fuelKey, $fuelCache)) {
            $stmt = $mysqli->prepare("SELECT COALESCE(SUM(amount),0) FROM driver_gas_costs WHERE driver_id=? AND cost_date BETWEEN ? AND ?");
            $stmt->bind_param('iss', $driverId, $weekStart, $weekEnd);
            $stmt->execute();
            $stmt->bind_result($fuelAmt);
            $fuelCache[$fuelKey] = $stmt->fetch() ? (float)$fuelAmt : 0.0;
            $stmt->close();
        }
        return round((float)$fuelCache[$fuelKey], 2);
    }
}

if (!function_exists('lonestar_is_nextier_trailer_rental_adjustment')) {
    function lonestar_is_nextier_trailer_rental_adjustment(string $vendor, string $adjustmentType, string $comments): bool {
        if (strtoupper(trim($vendor)) !== 'NEXTIER') {
            return false;
        }
        if (strtolower(trim($adjustmentType)) !== 'misc_deduction') {
            return false;
        }
        return strpos(strtolower(trim($comments)), 'auto nextier trailer rental deduction') === 0;
    }
}

if (!function_exists('lonestar_driver_week_misc_adjustment_total')) {
    function lonestar_driver_week_misc_adjustment_total(mysqli $mysqli, int $driverId, string $weekStart, string $vendorScope): float {
        if ($driverId <= 0) {
            return 0.0;
        }
        $scope = lonestar_payout_normalize_vendor_scope($vendorScope);
        $scopesToInclude = [$scope];
        $priorBalance = 0.0;
        foreach ($scopesToInclude as $balanceScope) {
            $priorBalance += lonestar_driver_open_misc_balance($mysqli, $driverId, $balanceScope, $weekStart);
        }
        if (!lonestar_payout_table_exists($mysqli, 'tss_misc_adjustments')) {
            return round($priorBalance, 2);
        }
        $hasVendorColumn = lonestar_payout_column_exists($mysqli, 'tss_misc_adjustments', 'payout_vendor');
        if (!$hasVendorColumn && $scope !== 'tss') {
            return round($priorBalance, 2);
        }
        if (!$scopesToInclude) {
            return round($priorBalance, 2);
        }
        $includeAllVendors = $hasVendorColumn && count($scopesToInclude) > 1;
        $vendor = lonestar_payout_vendor_code($scope);
        $vendorWhere = $hasVendorColumn && !$includeAllVendors
            ? "AND " . lonestar_payout_vendor_sql_key('payout_vendor') . " = ?"
            : "";
        $trailerRentalWhere = $hasVendorColumn
            ? "AND NOT (
                " . lonestar_payout_vendor_sql_key('payout_vendor') . " = 'NEXTIER'
                AND adjustment_type = 'misc_deduction'
                AND LOWER(COALESCE(comments, '')) LIKE 'auto nextier trailer rental deduction%'
            )"
            : "";
        $stmt = $mysqli->prepare(
            "SELECT COALESCE(SUM(CASE WHEN adjustment_type='misc_deduction' THEN -ABS(amount) ELSE ABS(amount) END), 0)
               FROM tss_misc_adjustments
              WHERE driver_contact_id = ?
                AND payout_week_start = ?
                {$vendorWhere}
                {$trailerRentalWhere}"
        );
        if (!$stmt) {
            return 0.0;
        }
        if ($hasVendorColumn && !$includeAllVendors) {
            $stmt->bind_param('iss', $driverId, $weekStart, $vendor);
        } else {
            $stmt->bind_param('is', $driverId, $weekStart);
        }
        $stmt->execute();
        $stmt->bind_result($miscAmount);
        $total = $stmt->fetch() ? (float)$miscAmount : 0.0;
        $stmt->close();
        return round($total + $priorBalance, 2);
    }
}

if (!function_exists('lonestar_driver_vendor_week_gross')) {
    function lonestar_driver_vendor_week_gross(mysqli $mysqli, int $driverId, string $weekStart, string $weekEnd, string $vendorScope): float {
        if ($driverId <= 0) {
            return 0.0;
        }
        $scope = lonestar_payout_normalize_vendor_scope($vendorScope);
        if (
            $scope === 'nextier'
            && lonestar_payout_table_exists($mysqli, 'nextier_payout_rows')
            && lonestar_payout_column_exists($mysqli, 'nextier_payout_rows', 'matched_contact_id')
        ) {
            $stmt = $mysqli->prepare(
                "SELECT COALESCE(SUM(
                    CASE
                      WHEN COALESCE(rate,0) > 0 AND COALESCE(tons,0) > 0
                        THEN (COALESCE(rate,0) * COALESCE(tons,0)) + COALESCE(bonus,0)
                      ELSE COALESCE(line_haul,0) - COALESCE(fsc_total,0)
                    END
                ),0)
                   FROM nextier_payout_rows
                  WHERE matched_contact_id = ?
                    AND work_date BETWEEN ? AND ?"
            );
            if ($stmt) {
                $stmt->bind_param('iss', $driverId, $weekStart, $weekEnd);
                $stmt->execute();
                $stmt->bind_result($gross);
                $total = $stmt->fetch() ? (float)$gross : 0.0;
                $stmt->close();
                if ($total > 0) {
                    return round($total, 2);
                }
            }
        }

        $hasDriverId = lonestar_payout_column_exists($mysqli, 'driver_payouts', 'driver_id');
        $hasDriverContactId = lonestar_payout_column_exists($mysqli, 'driver_payouts', 'driver_contact_id');
        $driverWhere = [];
        if ($hasDriverId) {
            $driverWhere[] = 'driver_id = ?';
        }
        if ($hasDriverContactId) {
            $driverWhere[] = 'driver_contact_id = ?';
        }
        $driverWhere[] = "driver_name IN (SELECT TRIM(CONCAT(first_name,' ',last_name)) FROM driver_contacts WHERE id = ?)";
        if (!$driverWhere) {
            return 0.0;
        }
        $vendorWhere = "UPPER(vendor_name) = 'RTEX'";
        if ($scope === 'nextier') {
            $vendorWhere = "UPPER(vendor_name) = 'NEXTIER'";
        } elseif ($scope === 'detmar') {
            $vendorWhere = "UPPER(vendor_name) = 'DETMAR'";
        } elseif ($scope === 'nickelrock') {
            $vendorWhere = "UPPER(vendor_name) IN ('NICKEL ROCK', 'NICKELROCK')";
        } elseif ($scope === 'tss') {
            $vendorWhere = "UPPER(vendor_name) IN ('TSS', 'TSS MISC REVENUE', 'TSS SPLIT (PICKUP)', 'TSS SPLIT (DELIVERY)')";
        }
        $sql = "SELECT COALESCE(SUM(tss_pay),0)
                  FROM driver_payouts
                 WHERE payout_date BETWEEN ? AND ?
                   AND {$vendorWhere}
                   AND (" . implode(' OR ', $driverWhere) . ")";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return 0.0;
        }
        $types = 'ss' . str_repeat('i', count($driverWhere));
        $params = [$weekStart, $weekEnd];
        foreach ($driverWhere as $_) {
            $params[] = $driverId;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->bind_result($gross);
        $total = $stmt->fetch() ? (float)$gross : 0.0;
        $stmt->close();
        return round($total, 2);
    }
}

function lonestar_nextier_week_bonus_total(mysqli $db, int $driverId, string $start, string $end): float {
    if ($driverId <= 0 || !lonestar_payout_table_exists($db, 'nextier_payout_rows')) return 0.0;
    $stmt = $db->prepare('SELECT COALESCE(SUM(bonus),0) FROM nextier_payout_rows WHERE matched_contact_id=? AND work_date BETWEEN ? AND ?');
    $stmt->bind_param('iss', $driverId, $start, $end);
    $stmt->execute();
    $stmt->bind_result($amount);
    $total = $stmt->fetch() ? (float)$amount : 0.0;
    $stmt->close();
    return round($total, 2);
}

if (!function_exists('lonestar_driver_nextier_week_fuel_surcharge_total')) {
    function lonestar_driver_nextier_week_fuel_surcharge_total(mysqli $mysqli, int $driverId, string $weekStart, string $weekEnd): float {
        if (
            $driverId <= 0
            || !lonestar_payout_table_exists($mysqli, 'nextier_payout_rows')
            || !lonestar_payout_column_exists($mysqli, 'nextier_payout_rows', 'matched_contact_id')
        ) {
            return 0.0;
        }
        $stmt = $mysqli->prepare(
            "SELECT COALESCE(SUM(fsc_total),0)
               FROM nextier_payout_rows
              WHERE matched_contact_id = ?
                AND work_date BETWEEN ? AND ?"
        );
        if (!$stmt) {
            return 0.0;
        }
        $stmt->bind_param('iss', $driverId, $weekStart, $weekEnd);
        $stmt->execute();
        $stmt->bind_result($amount);
        $total = $stmt->fetch() ? (float)$amount : 0.0;
        $stmt->close();
        return round($total, 2);
    }
}

if (!function_exists('lonestar_nextier_trailer_fee_days')) {
    function lonestar_nextier_trailer_fee_days(string $weekStart, string $weekEnd, string $receivedDate = ''): int {
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
}

if (!function_exists('lonestar_driver_trailer_fee_estimate')) {
    function lonestar_driver_trailer_fee_estimate(mysqli $mysqli, int $driverId, float $grossTotal, string $vendorScope, string $weekStart = '', string $weekEnd = ''): float {
        $scope = lonestar_payout_normalize_vendor_scope($vendorScope);
        if ($driverId <= 0 || $scope === 'rtex' || $scope === 'nickelrock') {
            return 0.0;
        }
        if (
            $weekStart !== ''
            && $weekEnd !== ''
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekEnd)
        ) {
            try {
                lonestar_trailer_history_ensure_table($mysqli);
                $stmt = $mysqli->prepare(
                    "SELECT tah.trailer_number,
                            tah.assigned_date,
                            tah.removed_date,
                            COALESCE(NULLIF(tah.vendor, ''), ta.vendor) AS vendor,
                            COALESCE(NULLIF(tah.trailer_fee_mode, ''), ta.trailer_fee_mode) AS trailer_fee_mode,
                            COALESCE(tah.trailer_fee_value, ta.trailer_fee_value) AS trailer_fee_value
                       FROM trailer_assignment_history tah
                       LEFT JOIN trailer_assets ta
                              ON ta.id = tah.trailer_id
                              OR TRIM(COALESCE(ta.trailer_number, '')) = TRIM(COALESCE(tah.trailer_number, ''))
                      WHERE tah.driver_contact_id = ?
                        AND tah.assigned_date <= ?
                        AND COALESCE(tah.removed_date, ?) >= ?"
                );
                if ($stmt) {
                    $stmt->bind_param('isss', $driverId, $weekEnd, $weekEnd, $weekStart);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $historyTotal = 0.0;
                    $matchedHistoryRows = 0;
                    $sawHistoryRows = 0;
                    while ($row = $res->fetch_assoc()) {
                        $sawHistoryRows++;
                        $trailerScope = lonestar_payout_vendor_scope_from_name((string)($row['vendor'] ?? ''));
                        if ($trailerScope !== $scope) {
                            continue;
                        }
                        $mode = in_array((string)($row['trailer_fee_mode'] ?? ''), ['percentage', 'flat'], true)
                            ? (string)$row['trailer_fee_mode']
                            : '';
                        $value = $row['trailer_fee_value'] !== null
                            ? max(0.0, (float)$row['trailer_fee_value'])
                            : null;
                        if ($scope === 'nextier') {
                            $mode = 'flat';
                            $value = ($value !== null && $value > 0.0) ? $value : 70.0;
                        } elseif ($mode === '' || $value === null) {
                            continue;
                        }
                        $assignedDate = (string)($row['assigned_date'] ?? $weekStart);
                        $removedDate = (string)($row['removed_date'] ?? $weekEnd);
                        $periodStart = max($weekStart, $assignedDate);
                        $periodEnd = min($weekEnd, $removedDate);
                        if ($periodEnd < $periodStart) {
                            continue;
                        }
                        $matchedHistoryRows++;
                        if ($mode === 'flat') {
                            if ($scope === 'nextier') {
                                $days = lonestar_nextier_trailer_fee_days($periodStart, $periodEnd, $assignedDate);
                            } else {
                                try {
                                    $start = new DateTimeImmutable($periodStart);
                                    $end = new DateTimeImmutable($periodEnd);
                                    $days = max(0, ((int)$start->diff($end)->days) + 1);
                                } catch (Throwable $e) {
                                    $days = 0;
                                }
                            }
                            $historyTotal += round($value * $days, 2);
                        } else {
                            $periodGross = lonestar_driver_vendor_week_gross($mysqli, $driverId, $periodStart, $periodEnd, $scope);
                            $historyTotal += round($periodGross * ($value / 100.0), 2);
                        }
                    }
                    $stmt->close();
                    if ($matchedHistoryRows > 0) {
                        return round($historyTotal, 2);
                    }
                    if ($sawHistoryRows > 0) {
                        return 0.0;
                    }
                }
            } catch (Throwable $e) {
                // Fall back to current assignment calculation below.
            }
        }
        $mode = '';
        $value = null;
        $receivedDate = '';
        $hasTrailerAssetFees =
            lonestar_payout_table_exists($mysqli, 'trailer_assets')
            && lonestar_payout_column_exists($mysqli, 'trailer_assets', 'trailer_fee_mode')
            && lonestar_payout_column_exists($mysqli, 'trailer_assets', 'trailer_fee_value');
        if ($hasTrailerAssetFees) {
            $receivedSelect = lonestar_payout_column_exists($mysqli, 'driver_contacts', 'trailer_received_date')
                ? ', dc.trailer_received_date'
                : ", NULL AS trailer_received_date";
            $stmt = $mysqli->prepare(
                "SELECT ta.vendor, ta.trailer_fee_mode, ta.trailer_fee_value{$receivedSelect}
                   FROM driver_contacts dc
                   JOIN trailer_assets ta
                     ON TRIM(COALESCE(ta.trailer_number, '')) = TRIM(COALESCE(dc.trailer_no, ''))
                  WHERE dc.id = ?
                  LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('i', $driverId);
                $stmt->execute();
                $stmt->bind_result($rawVendor, $rawMode, $rawValue, $rawReceivedDate);
                if (
                    $stmt->fetch()
                    && $rawValue !== null
                    && lonestar_payout_vendor_scope_from_name((string)$rawVendor) === $scope
                ) {
                    $mode = in_array((string)$rawMode, ['percentage', 'flat'], true) ? (string)$rawMode : '';
                    $value = (float)$rawValue;
                    $receivedDate = $rawReceivedDate === null ? '' : (string)$rawReceivedDate;
                }
                $stmt->close();
            }
        }
        if ($scope === 'nextier' && $value === null && $hasTrailerAssetFees) {
            $stmt = $mysqli->prepare(
                "SELECT ta.trailer_fee_mode, ta.trailer_fee_value{$receivedSelect}
                   FROM driver_contacts dc
                   JOIN trailer_assets ta
                     ON TRIM(COALESCE(ta.trailer_number, '')) = TRIM(COALESCE(dc.trailer_no, ''))
                  WHERE dc.id = ?
                    AND UPPER(TRIM(COALESCE(ta.vendor, ''))) = 'NEXTIER'
                  LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('i', $driverId);
                $stmt->execute();
                $stmt->bind_result($rawMode, $rawValue, $rawReceivedDate);
                if ($stmt->fetch()) {
                    $mode = 'flat';
                    $value = ($rawValue !== null && (float)$rawValue > 0.0) ? (float)$rawValue : 70.0;
                    $receivedDate = $rawReceivedDate === null ? '' : (string)$rawReceivedDate;
                }
                $stmt->close();
            }
        }
        if ($value !== null) {
            $value = max(0.0, (float)$value);
            if ($mode === 'flat') {
                if ($scope === 'nextier') {
                    return round($value * lonestar_nextier_trailer_fee_days($weekStart, $weekEnd, $receivedDate), 2);
                }
                return round($value, 2);
            }
            return round($grossTotal * (($value > 1) ? ($value / 100.0) : $value), 2);
        }
        return 0.0;
    }
}

if (!function_exists('lonestar_driver_tss_trailer_percent_decimal')) {
    function lonestar_driver_tss_trailer_percent_decimal(mysqli $mysqli, int $driverId, string $periodStart = '', string $periodEnd = ''): float {
        if ($driverId <= 0) {
            return 0.0;
        }
        $hasPeriod = preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd);
        if (
            lonestar_payout_table_exists($mysqli, 'trailer_assignment_history')
            && lonestar_payout_table_exists($mysqli, 'trailer_assets')
            && lonestar_payout_column_exists($mysqli, 'trailer_assets', 'trailer_fee_mode')
            && lonestar_payout_column_exists($mysqli, 'trailer_assets', 'trailer_fee_value')
        ) {
            try {
                $dateWhere = $hasPeriod
                    ? "AND tah.assigned_date <= ?
                       AND COALESCE(tah.removed_date, ?) >= ?"
                    : "AND tah.removed_date IS NULL";
                $stmt = $mysqli->prepare(
                    "SELECT COALESCE(NULLIF(tah.trailer_fee_mode, ''), ta.trailer_fee_mode) AS trailer_fee_mode,
                            COALESCE(tah.trailer_fee_value, ta.trailer_fee_value) AS trailer_fee_value
                       FROM trailer_assignment_history tah
                  LEFT JOIN trailer_assets ta
                         ON (
                            (tah.trailer_id IS NOT NULL AND ta.id = tah.trailer_id)
                            OR TRIM(COALESCE(ta.trailer_number, '')) = TRIM(COALESCE(tah.trailer_number, ''))
                         )
                      WHERE tah.driver_contact_id = ?
                        AND UPPER(TRIM(COALESCE(NULLIF(tah.vendor, ''), ta.vendor, ''))) = 'TSS'
                        {$dateWhere}
                      ORDER BY tah.assigned_date DESC, tah.id DESC
                      LIMIT 1"
                );
                if ($stmt) {
                    if ($hasPeriod) {
                        $stmt->bind_param('isss', $driverId, $periodEnd, $periodEnd, $periodStart);
                    } else {
                        $stmt->bind_param('i', $driverId);
                    }
                    $stmt->execute();
                    $stmt->bind_result($historyMode, $historyValue);
                    if ($stmt->fetch()) {
                        $stmt->close();
                        if ($historyValue !== null && (string)$historyMode === 'percentage') {
                            $pct = max(0.0, (float)$historyValue);
                            return $pct > 1 ? ($pct / 100.0) : $pct;
                        }
                        return 0.07;
                    }
                    $stmt->close();
                }
            } catch (Throwable $e) {
                // Fall back to the legacy trailer field below.
            }
        }
        $trailerNo = '';
        $stmt = $mysqli->prepare("SELECT trailer_no FROM driver_contacts WHERE id=?");
        if ($stmt) {
            $stmt->bind_param('i', $driverId);
            $stmt->execute();
            $stmt->bind_result($rawTrailerNo);
            if ($stmt->fetch() && $rawTrailerNo !== null) {
                $trailerNo = trim((string)$rawTrailerNo);
            }
            $stmt->close();
        }
        if (in_array(strtolower($trailerNo), ['own', 'owned', 'n/a'], true)) {
            return 0.0;
        }
        if (
            $trailerNo !== ''
            && lonestar_payout_table_exists($mysqli, 'trailer_assets')
            && lonestar_payout_column_exists($mysqli, 'trailer_assets', 'trailer_fee_mode')
            && lonestar_payout_column_exists($mysqli, 'trailer_assets', 'trailer_fee_value')
        ) {
            $stmt = $mysqli->prepare(
                "SELECT trailer_fee_mode, trailer_fee_value
                   FROM trailer_assets
                  WHERE TRIM(COALESCE(trailer_number, '')) = TRIM(?)
                    AND UPPER(TRIM(COALESCE(vendor, ''))) = 'TSS'
                  LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('s', $trailerNo);
                $stmt->execute();
                $stmt->bind_result($mode, $value);
                if ($stmt->fetch() && $value !== null && (string)$mode === 'percentage') {
                    $pct = max(0.0, (float)$value);
                    $stmt->close();
                    return $pct > 1 ? ($pct / 100.0) : $pct;
                }
                $stmt->close();
            }
        }
        return 0.0;
    }
}

if (!function_exists('lonestar_driver_tss_week_trailer_fee_total')) {
    function lonestar_driver_tss_week_trailer_fee_total(mysqli $mysqli, int $driverId, string $weekStart, string $weekEnd): float {
        if ($driverId <= 0) {
            return 0.0;
        }
        // TSS trailer fees apply to every TSS load in the week unless the driver owns the trailer.
        // Assignment history is preserved, but assigned/removed dates do not prorate TSS fees.
        $hasDriverId = lonestar_payout_column_exists($mysqli, 'driver_payouts', 'driver_id');
        $hasDriverContactId = lonestar_payout_column_exists($mysqli, 'driver_payouts', 'driver_contact_id');
        $driverWhere = [];
        if ($hasDriverId) {
            $driverWhere[] = 'driver_id = ?';
        }
        if ($hasDriverContactId) {
            $driverWhere[] = 'driver_contact_id = ?';
        }
        $driverWhere[] = "driver_name IN (SELECT TRIM(CONCAT(first_name,' ',last_name)) FROM driver_contacts WHERE id = ?)";
        $sql = "SELECT vendor_name, tss_pay
                  FROM driver_payouts
                 WHERE payout_date BETWEEN ? AND ?
                   AND UPPER(vendor_name) IN ('TSS', 'TSS MISC REVENUE', 'TSS SPLIT (PICKUP)', 'TSS SPLIT (DELIVERY)')
                   AND (" . implode(' OR ', $driverWhere) . ")";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return 0.0;
        }
        $pct = lonestar_driver_tss_trailer_percent_decimal($mysqli, $driverId, $weekStart, $weekEnd);
        $types = 'ss' . str_repeat('i', count($driverWhere));
        $params = [$weekStart, $weekEnd];
        foreach ($driverWhere as $_) {
            $params[] = $driverId;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total = 0.0;
        if (method_exists($stmt, 'get_result')) {
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                if (strtoupper(trim((string)($row['vendor_name'] ?? ''))) === 'TSS MISC REVENUE') {
                    continue;
                }
                $total += round((float)($row['tss_pay'] ?? 0) * $pct, 2);
            }
        } else {
            $stmt->bind_result($vendorName, $pay);
            while ($stmt->fetch()) {
                if (strtoupper(trim((string)$vendorName)) === 'TSS MISC REVENUE') {
                    continue;
                }
                $total += round((float)$pay * $pct, 2);
            }
        }
        $stmt->close();
        return round($total, 2);
    }
}

if (!function_exists('lonestar_driver_vendor_pre_fuel_balance')) {
    function lonestar_driver_vendor_pre_fuel_balance(
        mysqli $mysqli,
        int $driverId,
        string $weekStart,
        string $weekEnd,
        string $vendorScope,
        float $payoutPct,
        float $insurance,
        ?float $grossOverride = null,
        ?float $trailerFeeOverride = null
    ): float {
        $scope = lonestar_payout_normalize_vendor_scope($vendorScope);
        $gross = $grossOverride ?? lonestar_driver_vendor_week_gross($mysqli, $driverId, $weekStart, $weekEnd, $scope);
        if ($trailerFeeOverride !== null) {
            $trailerFee = $trailerFeeOverride;
        } elseif ($scope === 'tss') {
            $trailerFee = lonestar_driver_tss_week_trailer_fee_total($mysqli, $driverId, $weekStart, $weekEnd);
        } else {
            $trailerFee = lonestar_driver_trailer_fee_estimate($mysqli, $driverId, $gross, $scope, $weekStart, $weekEnd);
        }
        if ($scope === 'tss') {
            $brokerSettings = lonestar_vendor_broker_fee_settings($mysqli, 'tss');
            $brokerAmt = lonestar_tss_driver_week_broker_fee_amount($mysqli, $driverId, $weekStart, $weekEnd, $gross, $brokerSettings);
        } else {
            $brokerAmt = lonestar_vendor_broker_fee_amount(
                $mysqli,
                $scope,
                $scope === 'nextier' ? max(0.0, $gross - lonestar_nextier_week_bonus_total($mysqli, $driverId, $weekStart, $weekEnd)) : $gross,
                $scope === 'rtex' ? lonestar_rtex_driver_week_hours_total($mysqli, $driverId, $weekStart, $weekEnd) : 0.0
            );
        }
        $miscAdjustmentTotal = lonestar_driver_week_misc_adjustment_total($mysqli, $driverId, $weekStart, $scope);
        $fuelSurchargeTotal = 0.0;
        if ($scope === 'tss') {
            $fuelSurchargeTotal = lonestar_driver_week_fuel_surcharge_total($mysqli, $driverId, $weekStart, $weekEnd);
        } elseif ($scope === 'nextier') {
            $fuelSurchargeTotal = lonestar_driver_nextier_week_fuel_surcharge_total($mysqli, $driverId, $weekStart, $weekEnd);
        } elseif ($scope === 'rtex') {
            $fuelSurchargeTotal = lonestar_driver_rtex_week_fuel_surcharge_total($mysqli, $driverId, $weekStart, $weekEnd);
        }
        return round($gross - $trailerFee - $brokerAmt - $insurance + $miscAdjustmentTotal + $fuelSurchargeTotal, 2);
    }
}

if (!function_exists('lonestar_driver_vendor_pre_insurance_balance')) {
    function lonestar_driver_vendor_pre_insurance_balance(
        mysqli $mysqli,
        int $driverId,
        string $weekStart,
        string $weekEnd,
        string $vendorScope,
        float $payoutPct,
        ?float $grossOverride = null,
        ?float $trailerFeeOverride = null
    ): float {
        $scope = lonestar_payout_normalize_vendor_scope($vendorScope);
        $gross = $grossOverride ?? lonestar_driver_vendor_week_gross($mysqli, $driverId, $weekStart, $weekEnd, $scope);
        if ($trailerFeeOverride !== null) {
            $trailerFee = $trailerFeeOverride;
        } elseif ($scope === 'tss') {
            $trailerFee = lonestar_driver_tss_week_trailer_fee_total($mysqli, $driverId, $weekStart, $weekEnd);
        } else {
            $trailerFee = lonestar_driver_trailer_fee_estimate($mysqli, $driverId, $gross, $scope, $weekStart, $weekEnd);
        }
        if ($scope === 'tss') {
            $brokerSettings = lonestar_vendor_broker_fee_settings($mysqli, 'tss');
            $brokerAmt = lonestar_tss_driver_week_broker_fee_amount($mysqli, $driverId, $weekStart, $weekEnd, $gross, $brokerSettings);
        } else {
            $brokerAmt = lonestar_vendor_broker_fee_amount(
                $mysqli,
                $scope,
                $scope === 'nextier' ? max(0.0, $gross - lonestar_nextier_week_bonus_total($mysqli, $driverId, $weekStart, $weekEnd)) : $gross,
                $scope === 'rtex' ? lonestar_rtex_driver_week_hours_total($mysqli, $driverId, $weekStart, $weekEnd) : 0.0
            );
        }
        $miscAdjustmentTotal = lonestar_driver_week_misc_adjustment_total($mysqli, $driverId, $weekStart, $scope);
        $fuelSurchargeTotal = 0.0;
        if ($scope === 'tss') {
            $fuelSurchargeTotal = lonestar_driver_week_fuel_surcharge_total($mysqli, $driverId, $weekStart, $weekEnd);
        } elseif ($scope === 'nextier') {
            $fuelSurchargeTotal = lonestar_driver_nextier_week_fuel_surcharge_total($mysqli, $driverId, $weekStart, $weekEnd);
        } elseif ($scope === 'rtex') {
            $fuelSurchargeTotal = lonestar_driver_rtex_week_fuel_surcharge_total($mysqli, $driverId, $weekStart, $weekEnd);
        }
        return round($gross - $trailerFee - $brokerAmt + $miscAdjustmentTotal + $fuelSurchargeTotal, 2);
    }
}

if (!function_exists('lonestar_driver_week_allocated_insurance')) {
    function lonestar_driver_week_allocated_insurance(
        mysqli $mysqli,
        int $driverId,
        string $weekStart,
        string $weekEnd,
        string $vendorScope,
        float $payoutPct,
        float $insuranceTotal,
        float $grossTotal,
        float $trailerFeeTotal
    ): array {
        $insuranceTotal = round(max(0.0, $insuranceTotal), 2);
        $scope = lonestar_payout_normalize_vendor_scope($vendorScope);
        if ($insuranceTotal <= 0.0) {
            return [
                'insurance' => 0.0,
                'total_week_insurance' => 0.0,
                'remaining_insurance_after' => 0.0,
                'insurance_allocation_mode' => 'none',
            ];
        }

        $vendorBalances = [];
        foreach (lonestar_payout_vendor_order() as $orderedScope) {
            $balance = lonestar_driver_vendor_pre_insurance_balance(
                $mysqli,
                $driverId,
                $weekStart,
                $weekEnd,
                $orderedScope,
                $payoutPct,
                $orderedScope === $scope ? $grossTotal : null,
                $orderedScope === $scope ? $trailerFeeTotal : null
            );
            if ($balance > 0.0) {
                $vendorBalances[$orderedScope] = round($balance, 2);
            }
        }

        foreach (lonestar_payout_vendor_order() as $orderedScope) {
            if (($vendorBalances[$orderedScope] ?? 0.0) >= $insuranceTotal) {
                $deduction = $orderedScope === $scope ? $insuranceTotal : 0.0;
                return [
                    'insurance' => $deduction,
                    'total_week_insurance' => $insuranceTotal,
                    'remaining_insurance_after' => $orderedScope === $scope ? 0.0 : $insuranceTotal,
                    'insurance_allocation_mode' => 'single_vendor',
                ];
            }
        }

        $remaining = $insuranceTotal;
        foreach (lonestar_payout_vendor_order() as $orderedScope) {
            $deduction = round(min($remaining, (float)($vendorBalances[$orderedScope] ?? 0.0)), 2);
            $isCurrentVendor = $orderedScope === $scope;
            if ($isCurrentVendor) {
                return [
                    'insurance' => $deduction,
                    'total_week_insurance' => $insuranceTotal,
                    'remaining_insurance_after' => round(max(0.0, $remaining - $deduction), 2),
                    'insurance_allocation_mode' => 'split',
                ];
            }
            $remaining = round(max(0.0, $remaining - $deduction), 2);
        }

        return [
            'insurance' => 0.0,
            'total_week_insurance' => $insuranceTotal,
            'remaining_insurance_after' => $insuranceTotal,
            'insurance_allocation_mode' => 'unallocated',
        ];
    }
}

if (!function_exists('lonestar_driver_week_allocated_fuel')) {
    function lonestar_driver_week_allocated_fuel(
        mysqli $mysqli,
        int $driverId,
        string $weekStart,
        string $weekEnd,
        string $vendorScope,
        float $payoutPct,
        float $insurance,
        float $grossTotal,
        float $trailerFeeTotal,
        ?float $insuranceTotal = null
    ): array {
        $currentWeekFuel = lonestar_driver_week_total_fuel($mysqli, $driverId, $weekStart, $weekEnd);
        $priorFuelBalance = lonestar_driver_open_fuel_balance($mysqli, $driverId, $weekStart);
        $totalFuel = round($currentWeekFuel + $priorFuelBalance, 2);
        $remaining = $totalFuel;
        $scope = lonestar_payout_normalize_vendor_scope($vendorScope);
        $weeklyInsuranceTotal = $insuranceTotal === null ? $insurance : round(max(0.0, $insuranceTotal), 2);
        $vendorOrder = lonestar_payout_vendor_order();
        if (!in_array($scope, $vendorOrder, true)) {
            $vendorOrder[] = $scope;
        }
        $allocations = [];
        $collectedBeforeCurrent = 0.0;
        // Build the full-week fuel allocation so later-uploaded vendor payouts can reduce any open fuel balance.
        foreach ($vendorOrder as $orderedScope) {
            $orderedInsurance = $insurance;
            if ($orderedScope !== $scope) {
                $orderedGross = lonestar_driver_vendor_week_gross($mysqli, $driverId, $weekStart, $weekEnd, $orderedScope);
                $orderedTrailerFee = $orderedScope === 'tss'
                    ? lonestar_driver_tss_week_trailer_fee_total($mysqli, $driverId, $weekStart, $weekEnd)
                    : lonestar_driver_trailer_fee_estimate($mysqli, $driverId, $orderedGross, $orderedScope, $weekStart, $weekEnd);
                $orderedInsuranceAllocation = lonestar_driver_week_allocated_insurance(
                    $mysqli,
                    $driverId,
                    $weekStart,
                    $weekEnd,
                    $orderedScope,
                    $payoutPct,
                    $weeklyInsuranceTotal,
                    $orderedGross,
                    $orderedTrailerFee
                );
                $orderedInsurance = (float)($orderedInsuranceAllocation['insurance'] ?? 0.0);
            }
            $balance = lonestar_driver_vendor_pre_fuel_balance(
                $mysqli,
                $driverId,
                $weekStart,
                $weekEnd,
                $orderedScope,
                $payoutPct,
                $orderedInsurance,
                $orderedScope === $scope ? $grossTotal : null,
                $orderedScope === $scope ? $trailerFeeTotal : null
            );
            $available = max(0.0, $balance);
            $isCurrentVendor = $orderedScope === $scope;
            if ($isCurrentVendor) {
                $collectedBeforeCurrent = round($totalFuel - $remaining, 2);
            }
            $deduction = round(min($remaining, $available), 2);
            $allocations[$orderedScope] = $deduction;
            $remaining = round(max(0.0, $remaining - $deduction), 2);
        }
        return [
            'fuel' => (float)($allocations[$scope] ?? 0.0),
            'total_fuel' => $totalFuel,
            'current_week_fuel' => $currentWeekFuel,
            'prior_fuel_balance' => $priorFuelBalance,
            'prior_collected_fuel' => $collectedBeforeCurrent,
            'remaining_fuel_after' => $remaining,
        ];
    }
}

if (!function_exists('lonestar_driver_week_net_breakdown')) {
    function lonestar_driver_week_net_breakdown(
        mysqli $mysqli,
        int $driverId,
        float $grossTotal,
        float $trailerFeeTotal,
        string $weekStart,
        string $weekEnd,
        string $vendorScope = 'tss', ?float $nextierBonusTotal = null
    ): array {
        $brokerFeeSettings = lonestar_vendor_broker_fee_settings($mysqli, $vendorScope);
        $payoutPct = ($brokerFeeSettings['fee_mode'] ?? 'percentage') === 'percentage'
            ? ((float)$brokerFeeSettings['fee_value'] / 100.0)
            : 0.0;
        $insuranceTotal = 0.0;
        $insurance = 0.0;
        $fuel = 0.0;

        if ($driverId > 0) {
            static $insuranceCache = [];

            if (!array_key_exists($driverId, $insuranceCache)) {
                $stmt = $mysqli->prepare("SELECT amount FROM driver_insurance_costs WHERE driver_id=?");
                $stmt->bind_param('i', $driverId);
                $stmt->execute();
                $stmt->bind_result($amount);
                $insuranceCache[$driverId] = $stmt->fetch() ? (float)$amount : 0.0;
                $stmt->close();
            }

            $insuranceTotal = $insuranceCache[$driverId];
        }

        $scope = lonestar_payout_normalize_vendor_scope($vendorScope);
        $brokerPct = $payoutPct;
        if ($scope === 'tss') {
            $tssDriverPct = lonestar_tss_driver_broker_fee_percentage($mysqli, $driverId, $brokerFeeSettings);
            if ($tssDriverPct !== null) {
                $brokerPct = $tssDriverPct / 100.0;
            }
        }
        $isRtex = $scope === 'rtex';
        $brokerHours = $isRtex ? lonestar_rtex_driver_week_hours_total($mysqli, $driverId, $weekStart, $weekEnd) : 0.0;
        $brokerRate = (($brokerFeeSettings['fee_mode'] ?? 'percentage') === 'flat') ? (float)$brokerFeeSettings['fee_value'] : 0.0;
        $brokerAmt = $scope === 'tss'
            ? lonestar_tss_driver_week_broker_fee_amount($mysqli, $driverId, $weekStart, $weekEnd, $grossTotal, $brokerFeeSettings)
            : lonestar_vendor_broker_fee_amount($mysqli, $scope,
                $scope === 'nextier' ? max(0.0, $grossTotal - ($nextierBonusTotal ?? lonestar_nextier_week_bonus_total($mysqli, $driverId, $weekStart, $weekEnd))) : $grossTotal,
                $brokerHours);
        $miscAdjustmentTotal = lonestar_driver_week_misc_adjustment_total($mysqli, $driverId, $weekStart, $scope);

        $fuelSurchargeTotal = $isRtex
            ? lonestar_driver_rtex_week_fuel_surcharge_total($mysqli, $driverId, $weekStart, $weekEnd)
            : ($scope === 'nextier'
                ? lonestar_driver_nextier_week_fuel_surcharge_total($mysqli, $driverId, $weekStart, $weekEnd)
                : lonestar_driver_week_fuel_surcharge_total($mysqli, $driverId, $weekStart, $weekEnd));
        $insuranceAllocation = lonestar_driver_week_allocated_insurance($mysqli, $driverId, $weekStart, $weekEnd, $scope, $payoutPct, $insuranceTotal, $grossTotal, $trailerFeeTotal);
        $insurance = (float)($insuranceAllocation['insurance'] ?? 0.0);
        $fuelAllocation = lonestar_driver_week_allocated_fuel($mysqli, $driverId, $weekStart, $weekEnd, $scope, $payoutPct, $insurance, $grossTotal, $trailerFeeTotal, $insuranceTotal);
        $fuel = (float)($fuelAllocation['fuel'] ?? 0.0);
        $netTotal = round($grossTotal - ($trailerFeeTotal + $brokerAmt + $insurance + $fuel) + $miscAdjustmentTotal + $fuelSurchargeTotal, 2);

        return [
            'payout_pct' => $payoutPct,
            'broker_pct' => $brokerPct,
            'broker_amt' => $brokerAmt,
            'broker_hours' => $brokerHours,
            'broker_rate' => $brokerRate,
            'insurance' => $insurance,
            'total_week_insurance' => (float)($insuranceAllocation['total_week_insurance'] ?? $insuranceTotal),
            'remaining_insurance_after' => (float)($insuranceAllocation['remaining_insurance_after'] ?? 0.0),
            'insurance_allocation_mode' => (string)($insuranceAllocation['insurance_allocation_mode'] ?? ''),
            'fuel' => $fuel,
            'total_week_fuel' => (float)($fuelAllocation['total_fuel'] ?? $fuel),
            'current_week_fuel' => (float)($fuelAllocation['current_week_fuel'] ?? 0.0),
            'prior_fuel_balance' => (float)($fuelAllocation['prior_fuel_balance'] ?? 0.0),
            'prior_collected_fuel' => (float)($fuelAllocation['prior_collected_fuel'] ?? 0.0),
            'remaining_fuel_after' => (float)($fuelAllocation['remaining_fuel_after'] ?? 0.0),
            'misc_adjustment_total' => $miscAdjustmentTotal,
            'fuel_surcharge_total' => $fuelSurchargeTotal,
            'net_total' => $netTotal,
        ];
    }
}

if (!function_exists('lonestar_owner_payout_profit_total')) {
    function lonestar_owner_payout_profit_total(
        float $vendorPayoutAmount,
        float $driverNetTotal,
        float $insuranceTotal,
        float $fuelTotal
    ): float {
        return round($vendorPayoutAmount - $driverNetTotal - $insuranceTotal - $fuelTotal, 2);
    }
}

if (!function_exists('lonestar_owner_payout_summary_ensure_table')) {
    function lonestar_owner_payout_summary_ensure_table(mysqli $mysqli): void {
        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS owner_payout_summary_snapshots (
                payout_week_start DATE NOT NULL,
                payout_vendor VARCHAR(30) NOT NULL,
                company_gross DECIMAL(14,2) NOT NULL DEFAULT 0,
                driver_net DECIMAL(14,2) NOT NULL DEFAULT 0,
                fuel DECIMAL(14,2) NOT NULL DEFAULT 0,
                broker_fee DECIMAL(14,2) NOT NULL DEFAULT 0,
                misc_revenue DECIMAL(14,2) NOT NULL DEFAULT 0,
                total_profit DECIMAL(14,2) NOT NULL DEFAULT 0,
                calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (payout_week_start, payout_vendor)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        if (!lonestar_payout_column_exists($mysqli, 'owner_payout_summary_snapshots', 'misc_revenue')) {
            $mysqli->query(
                "ALTER TABLE owner_payout_summary_snapshots
                 ADD COLUMN misc_revenue DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER broker_fee"
            );
        }
    }
}

if (!function_exists('lonestar_owner_payout_summary_save')) {
    function lonestar_owner_payout_summary_save(
        mysqli $mysqli,
        string $weekStart,
        string $vendorScope,
        float $companyGross,
        float $driverNet,
        float $fuel,
        float $brokerFee,
        float $miscRevenue,
        float $totalProfit
    ): void {
        lonestar_owner_payout_summary_ensure_table($mysqli);
        $scope = lonestar_payout_normalize_vendor_scope($vendorScope);
        $stmt = $mysqli->prepare(
            "INSERT INTO owner_payout_summary_snapshots
                (payout_week_start, payout_vendor, company_gross, driver_net, fuel, broker_fee, misc_revenue, total_profit, calculated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                company_gross=VALUES(company_gross),
                driver_net=VALUES(driver_net),
                fuel=VALUES(fuel),
                broker_fee=VALUES(broker_fee),
                misc_revenue=VALUES(misc_revenue),
                total_profit=VALUES(total_profit),
                calculated_at=NOW()"
        );
        $stmt->bind_param('ssdddddd', $weekStart, $scope, $companyGross, $driverNet, $fuel, $brokerFee, $miscRevenue, $totalProfit);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('lonestar_owner_payout_summary_rows')) {
    function lonestar_owner_payout_summary_rows(mysqli $mysqli, string $weekStart): array {
        lonestar_owner_payout_summary_ensure_table($mysqli);
        $stmt = $mysqli->prepare(
            "SELECT payout_vendor, company_gross, driver_net, fuel, broker_fee, misc_revenue, total_profit
               FROM owner_payout_summary_snapshots
              WHERE payout_week_start = ?"
        );
        $stmt->bind_param('s', $weekStart);
        $stmt->execute();
        $rows = [];
        $stmt->bind_result($scope, $companyGross, $driverNet, $fuel, $brokerFee, $miscRevenue, $totalProfit);
        while ($stmt->fetch()) {
            $rows[(string)$scope] = [
                'company_gross' => (float)$companyGross,
                'driver_net' => (float)$driverNet,
                'fuel' => (float)$fuel,
                'broker_fee' => (float)$brokerFee,
                'misc_revenue' => (float)$miscRevenue,
                'total_profit' => (float)$totalProfit,
            ];
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('lonestar_driver_week_net_total')) {
    function lonestar_driver_week_net_total(
        mysqli $mysqli,
        int $driverId,
        float $grossTotal,
        float $trailerFeeTotal,
        string $weekStart,
        string $weekEnd,
        string $vendorScope = 'tss'
    ): float {
        return (float)lonestar_driver_week_net_breakdown($mysqli, $driverId, $grossTotal, $trailerFeeTotal, $weekStart, $weekEnd, $vendorScope)['net_total'];
    }
}

if (!function_exists('lonestar_driver_other_open_balance_total')) {
    function lonestar_driver_other_open_balance_total(
        mysqli $mysqli,
        int $driverId,
        string $vendorScope
    ): float {
        if ($driverId <= 0 || !lonestar_payout_table_exists($mysqli, 'driver_misc_adjustment_balances')) {
            return 0.0;
        }
        $scope = lonestar_payout_normalize_vendor_scope($vendorScope);
        $stmt = $mysqli->prepare(
            "SELECT COALESCE(SUM(balance),0)
               FROM driver_misc_adjustment_balances
              WHERE driver_id = ?
                AND vendor_scope <> ?
                AND ABS(COALESCE(balance,0)) > 0.005"
        );
        if (!$stmt) {
            return 0.0;
        }
        $stmt->bind_param('is', $driverId, $scope);
        $stmt->execute();
        $stmt->bind_result($balance);
        $total = $stmt->fetch() ? (float)$balance : 0.0;
        $stmt->close();
        return round($total, 2);
    }
}

if (!function_exists('lonestar_driver_total_net_after_open_balances')) {
    function lonestar_driver_total_net_after_open_balances(
        float $statementNet,
        array $netBreakdown,
        float $otherOpenBalanceTotal = 0.0
    ): float {
        $remainingFuel = max(0.0, (float)($netBreakdown['remaining_fuel_after'] ?? 0.0));
        return round($statementNet - $remainingFuel + $otherOpenBalanceTotal, 2);
    }
}

if (!function_exists('lonestar_driver_week_has_report_activity')) {
    function lonestar_driver_week_has_report_activity(
        mysqli $mysqli,
        int $driverId,
        string $weekStart,
        string $weekEnd,
        string $vendorScope = 'tss'
    ): bool {
        if ($driverId <= 0 || $weekStart === '' || $weekEnd === '') {
            return false;
        }
        $scope = lonestar_payout_normalize_vendor_scope($vendorScope);
        if (abs(lonestar_driver_vendor_week_gross($mysqli, $driverId, $weekStart, $weekEnd, $scope)) > 0.009) {
            return true;
        }
        if (abs(lonestar_driver_week_misc_adjustment_total($mysqli, $driverId, $weekStart, $scope)) > 0.009) {
            return true;
        }
        if (abs(lonestar_driver_week_total_fuel($mysqli, $driverId, $weekStart, $weekEnd)) > 0.009) {
            return true;
        }
        if (abs(lonestar_driver_open_fuel_balance($mysqli, $driverId, $weekStart)) > 0.009) {
            return true;
        }
        if (abs(lonestar_driver_open_misc_balance($mysqli, $driverId, $scope, $weekStart)) > 0.009) {
            return true;
        }
        return false;
    }
}
