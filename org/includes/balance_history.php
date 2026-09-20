<?php

if (!function_exists('lonestar_driver_fuel_balance_history_ensure_table')) {
    function lonestar_driver_fuel_balance_history_ensure_table(mysqli $mysqli): void {
        static $done = false;
        if ($done) {
            return;
        }
        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS driver_fuel_balance_history (
              driver_id INT NOT NULL,
              week_start DATE NOT NULL,
              ending_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              source VARCHAR(30) NOT NULL DEFAULT 'statement',
              updated_by INT NULL,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (driver_id, week_start),
              KEY idx_fuel_balance_history_week (week_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $done = true;
    }
}

if (!function_exists('lonestar_driver_fuel_balance_history_save')) {
    function lonestar_driver_fuel_balance_history_save(
        mysqli $mysqli,
        int $driverId,
        string $weekStart,
        float $endingBalance,
        string $source = 'statement',
        ?int $updatedBy = null
    ): void {
        if ($driverId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
            return;
        }
        lonestar_driver_fuel_balance_history_ensure_table($mysqli);
        $endingBalance = round(max(0.0, $endingBalance), 2);
        $source = substr(trim($source) !== '' ? trim($source) : 'statement', 0, 30);
        $stmt = $mysqli->prepare(
            "INSERT INTO driver_fuel_balance_history
                (driver_id, week_start, ending_balance, source, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                ending_balance = VALUES(ending_balance),
                source = VALUES(source),
                updated_by = VALUES(updated_by),
                updated_at = NOW()"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('isdsi', $driverId, $weekStart, $endingBalance, $source, $updatedBy);
        $stmt->execute();
        $stmt->close();
    }
}
if (!function_exists('lonestar_driver_misc_balance_history_ensure_table')) {
    function lonestar_driver_misc_balance_history_ensure_table(mysqli $mysqli): void {
        static $done = false;
        if ($done) {
            return;
        }
        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS driver_misc_balance_history (
              driver_id INT NOT NULL,
              vendor_scope VARCHAR(30) NOT NULL,
              week_start DATE NOT NULL,
              ending_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              source VARCHAR(30) NOT NULL DEFAULT 'statement',
              updated_by INT NULL,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (driver_id, vendor_scope, week_start),
              KEY idx_misc_balance_history_week (week_start),
              KEY idx_misc_balance_history_driver_week (driver_id, week_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $done = true;
    }
}

if (!function_exists('lonestar_driver_misc_balance_history_save')) {
    function lonestar_driver_misc_balance_history_save(
        mysqli $mysqli,
        int $driverId,
        string $vendorScope,
        string $weekStart,
        float $endingBalance,
        string $source = 'statement',
        ?int $updatedBy = null
    ): void {
        if ($driverId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
            return;
        }
        lonestar_driver_misc_balance_history_ensure_table($mysqli);
        $scope = function_exists('lonestar_payout_normalize_vendor_scope')
            ? lonestar_payout_normalize_vendor_scope($vendorScope)
            : strtolower(trim($vendorScope));
        $endingBalance = round($endingBalance, 2);
        $source = substr(trim($source) !== '' ? trim($source) : 'statement', 0, 30);
        $stmt = $mysqli->prepare(
            "INSERT INTO driver_misc_balance_history
                (driver_id, vendor_scope, week_start, ending_balance, source, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                ending_balance = VALUES(ending_balance),
                source = VALUES(source),
                updated_by = VALUES(updated_by),
                updated_at = NOW()"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('issdsi', $driverId, $scope, $weekStart, $endingBalance, $source, $updatedBy);
        $stmt->execute();
        $stmt->close();
    }
}

