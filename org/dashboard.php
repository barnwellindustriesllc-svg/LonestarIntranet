<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/payout_net_helpers.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['dashboard_data'] ?? '') !== '1') {
    require __DIR__ . '/includes/dashboard_shell.php';
    exit;
}
// Long dashboard calculations must not lock other pages in this login session.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES);
}

function money(float $value): string {
    return '$' . number_format($value, 2);
}

function dashboard_vendor_case(string $column = 'vendor_name'): string {
    return "CASE
        WHEN UPPER(TRIM(COALESCE({$column}, ''))) = 'NEXTIER' THEN 'NexTier'
        WHEN UPPER(TRIM(COALESCE({$column}, ''))) = 'RTEX' THEN 'RTEX'
        WHEN UPPER(TRIM(COALESCE({$column}, ''))) IN ('NICKEL ROCK', 'NICKELROCK') THEN 'Nickel Rock'
        WHEN UPPER(TRIM(COALESCE({$column}, ''))) = 'TSS'
          OR UPPER(TRIM(COALESCE({$column}, ''))) = 'TSS MISC REVENUE'
          OR UPPER(TRIM(COALESCE({$column}, ''))) LIKE 'TSS SPLIT%' THEN 'TSS'
        WHEN TRIM(COALESCE({$column}, '')) = '' THEN 'Unknown'
        ELSE TRIM({$column})
    END";
}

function dashboard_vendor_filter(string $column = 'vendor_name'): string {
    return "UPPER(TRIM(COALESCE({$column}, ''))) NOT LIKE '%BRINGING%TRAILER%WTX%NE%'";
}

function dashboard_vendor_scope_from_name(string $vendorName): string {
    $vendor = strtoupper(trim($vendorName));
    if ($vendor === 'NEXTIER') return 'nextier';
    if ($vendor === 'RTEX') return 'rtex';
    if ($vendor === 'DETMAR') return 'detmar';
    if ($vendor === 'NICKEL ROCK' || $vendor === 'NICKELROCK') return 'nickelrock';
    return 'tss';
}

function dashboard_vendor_label_from_scope(string $scope): string {
    $scope = strtolower(trim($scope));
    if ($scope === 'nextier') return 'NexTier';
    if ($scope === 'rtex') return 'RTEX';
    if ($scope === 'detmar') return 'Detmar';
    if ($scope === 'nickelrock') return 'Nickel Rock';
    return 'TSS';
}

function dashboard_table_exists(mysqli $mysqli, string $table): bool {
    $table = $mysqli->real_escape_string($table);
    $res = $mysqli->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}

function dashboard_column_exists(mysqli $mysqli, string $table, string $column): bool {
    $table = $mysqli->real_escape_string($table);
    $column = $mysqli->real_escape_string($column);
    $res = $mysqli->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function dashboard_week_start(string $date): string {
    return (new DateTimeImmutable($date))->modify('-' . (int)(new DateTimeImmutable($date))->format('w') . ' days')->format('Y-m-d');
}

function dashboard_week_end(string $weekStart): string {
    return (new DateTimeImmutable($weekStart))->modify('+6 days')->format('Y-m-d');
}

function dashboard_balance_clear_token(string $type, int $driverId, string $scope, string $weekStart): string {
    return hash('sha256', implode('|', ['balance-clear', $type, $driverId, $scope, $weekStart, session_id()]));
}

function dashboard_balance_tracker_clears_ensure_table(mysqli $mysqli): void {
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS dashboard_balance_tracker_clears (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          balance_type VARCHAR(20) NOT NULL,
          driver_id INT NOT NULL,
          vendor_scope VARCHAR(30) NOT NULL DEFAULT 'tss',
          week_start DATE NOT NULL,
          cleared_by INT NULL,
          cleared_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_dashboard_balance_clear (balance_type, driver_id, vendor_scope, week_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function dashboard_owner_payout_amounts(mysqli $mysqli, string $weekStart): array {
    try {
        if (
            !dashboard_table_exists($mysqli, 'owner_payout_inputs')
            || !dashboard_column_exists($mysqli, 'owner_payout_inputs', 'payout_week_start')
            || !dashboard_column_exists($mysqli, 'owner_payout_inputs', 'vendor_payout_amount')
        ) {
            return [];
        }
        $hasVendorColumn = dashboard_column_exists($mysqli, 'owner_payout_inputs', 'payout_vendor');
        $hasIdColumn = dashboard_column_exists($mysqli, 'owner_payout_inputs', 'id');
        $orderSql = $hasIdColumn ? 'id ASC' : 'payout_week_start ASC';
        $amounts = [];
        if ($hasVendorColumn) {
            $stmt = $mysqli->prepare(
                "SELECT payout_vendor, vendor_payout_amount
                   FROM owner_payout_inputs
                  WHERE payout_week_start = ?
                  ORDER BY {$orderSql}"
            );
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('s', $weekStart);
            $stmt->execute();
            $stmt->bind_result($vendorScope, $amount);
            while ($stmt->fetch()) {
                $scope = dashboard_vendor_scope_from_name((string)$vendorScope);
                $amounts[$scope] = round((float)$amount, 2);
            }
            $stmt->close();
            return $amounts;
        }

        $stmt = $mysqli->prepare(
            "SELECT vendor_payout_amount
               FROM owner_payout_inputs
              WHERE payout_week_start = ?
              ORDER BY {$orderSql}
              LIMIT 1"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('s', $weekStart);
        $stmt->execute();
        $stmt->bind_result($amount);
        if ($stmt->fetch()) {
            $amounts['tss'] = round((float)$amount, 2);
        }
        $stmt->close();
        return $amounts;
    } catch (Throwable $e) {
        error_log('dashboard owner payout amounts failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Reconstruct missing weekly profitability snapshots from the saved client
 * payout inputs. Nickel Rock snapshots are refreshed from load revenue because
 * that client's payout amount is derived rather than manually entered.
 */
function dashboard_backfill_annual_profitability(mysqli $mysqli, int $year): void {
    if (
        !dashboard_table_exists($mysqli, 'owner_payout_inputs')
        || !dashboard_column_exists($mysqli, 'owner_payout_inputs', 'payout_week_start')
        || !dashboard_column_exists($mysqli, 'owner_payout_inputs', 'vendor_payout_amount')
    ) {
        return;
    }

    lonestar_owner_payout_summary_ensure_table($mysqli);
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS dashboard_profitability_backfills (
            backfill_key VARCHAR(100) NOT NULL PRIMARY KEY,
            completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // Versioned so existing Nickel Rock snapshots are refreshed whenever its
    // profitability rules change. v3 deducts Nickel Rock's 5% management fee.
    $nickelRockBackfillKey = 'nickelrock-derived-revenue-v3-management-fee-' . $year;
    $refreshNickelRockExisting = true;
    $stmt = $mysqli->prepare("SELECT 1 FROM dashboard_profitability_backfills WHERE backfill_key=? LIMIT 1");
    $stmt->bind_param('s', $nickelRockBackfillKey);
    $stmt->execute();
    $stmt->store_result();
    $refreshNickelRockExisting = $stmt->num_rows === 0;
    $stmt->close();
    $hasVendorColumn = dashboard_column_exists($mysqli, 'owner_payout_inputs', 'payout_vendor');
    $hasInputIdColumn = dashboard_column_exists($mysqli, 'owner_payout_inputs', 'id');
    $inputOrder = $hasInputIdColumn ? 'payout_week_start, id' : 'payout_week_start';
    $existing = [];
    $stmt = $mysqli->prepare(
        "SELECT payout_week_start, payout_vendor
           FROM owner_payout_summary_snapshots
          WHERE YEAR(payout_week_start) = ?"
    );
    $stmt->bind_param('i', $year);
    $stmt->execute();
    $stmt->bind_result($existingWeek, $existingScope);
    while ($stmt->fetch()) {
        $existing[(string)$existingWeek . '|' . lonestar_payout_normalize_vendor_scope((string)$existingScope)] = true;
    }
    $stmt->close();

    $inputSql = $hasVendorColumn
        ? "SELECT payout_week_start, payout_vendor, vendor_payout_amount
             FROM owner_payout_inputs
            WHERE YEAR(payout_week_start) = ?
            ORDER BY {$inputOrder}"
        : "SELECT payout_week_start, 'tss' AS payout_vendor, vendor_payout_amount
             FROM owner_payout_inputs
            WHERE YEAR(payout_week_start) = ?
            ORDER BY {$inputOrder}";
    $stmt = $mysqli->prepare($inputSql);
    $stmt->bind_param('i', $year);
    $stmt->execute();
    $stmt->bind_result($inputWeek, $inputScope, $inputGross);
    $inputs = [];
    while ($stmt->fetch()) {
        $scope = lonestar_payout_normalize_vendor_scope((string)$inputScope);
        $inputs[(string)$inputWeek . '|' . $scope] = [
            'week_start' => (string)$inputWeek,
            'scope' => $scope,
            'company_gross' => round((float)$inputGross, 2),
        ];
    }
    $stmt->close();

    // Nickel Rock's Owner Payout Report derives Client Payout Amount from the
    // week's actual payout rows. Include every Nickel Rock week even when no
    // owner_payout_inputs row was saved for it.
    $stmt = $mysqli->prepare(
        "SELECT DISTINCT DATE_SUB(payout_date, INTERVAL (DAYOFWEEK(payout_date) - 1) DAY) AS week_start
           FROM driver_payouts
          WHERE YEAR(DATE_SUB(payout_date, INTERVAL (DAYOFWEEK(payout_date) - 1) DAY)) = ?
            AND UPPER(TRIM(COALESCE(vendor_name, ''))) IN ('NICKEL ROCK', 'NICKELROCK')
          ORDER BY week_start"
    );
    $stmt->bind_param('i', $year);
    $stmt->execute();
    $stmt->bind_result($nickelRockWeek);
    while ($stmt->fetch()) {
        $week = (string)$nickelRockWeek;
        $inputs[$week . '|nickelrock'] = [
            'week_start' => $week,
            'scope' => 'nickelrock',
            'company_gross' => 0.0,
        ];
    }
    $stmt->close();

    $vendorCase = dashboard_vendor_case('dp.vendor_name');
    $vendorFilter = dashboard_vendor_filter('dp.vendor_name');
    foreach ($inputs as $key => $input) {
        if (isset($existing[$key]) && ($input['scope'] !== 'nickelrock' || !$refreshNickelRockExisting)) {
            continue;
        }
        $weekStart = $input['week_start'];
        $weekEnd = dashboard_week_end($weekStart);
        $scope = $input['scope'];
        $weekStartSql = $mysqli->real_escape_string($weekStart);
        $weekEndSql = $mysqli->real_escape_string($weekEnd);
        $rows = [];
        $res = $mysqli->query(
            "SELECT {$vendorCase} AS client_label,
                    dp.driver_contact_id,
                    COALESCE(SUM(dp.tss_pay),0) AS gross_total
               FROM driver_payouts dp
              WHERE dp.payout_date BETWEEN '{$weekStartSql}' AND '{$weekEndSql}'
                AND dp.driver_contact_id IS NOT NULL
                AND dp.driver_contact_id <> 0
                AND {$vendorFilter}
              GROUP BY client_label, dp.driver_contact_id"
        );
        while ($row = $res->fetch_assoc()) {
            if (dashboard_vendor_scope_from_name((string)$row['client_label']) === $scope) {
                $rows[] = $row;
            }
        }
        $res->close();

        $driverNet = 0.0;
        $insurance = 0.0;
        $fuel = 0.0;
        $brokerFee = 0.0;
        $miscRevenue = 0.0;
        $derivedCompanyGross = 0.0;
        foreach ($rows as $row) {
            $driverId = (int)$row['driver_contact_id'];
            $gross = round((float)$row['gross_total'], 2);
            if ($scope === 'nextier') {
                $gross = lonestar_driver_vendor_week_gross($mysqli, $driverId, $weekStart, $weekEnd, 'nextier');
            }
            if ($scope === 'nickelrock') {
                $derivedCompanyGross += $gross;
            }
            $trailerFee = $scope === 'tss'
                ? lonestar_driver_tss_week_trailer_fee_total($mysqli, $driverId, $weekStart, $weekEnd)
                : lonestar_driver_trailer_fee_estimate($mysqli, $driverId, $gross, $scope, $weekStart, $weekEnd);
            $breakdown = lonestar_driver_week_net_breakdown($mysqli, $driverId, $gross, $trailerFee, $weekStart, $weekEnd, $scope);
            $net = (float)($breakdown['net_total'] ?? 0);
            if ($scope === 'rtex') {
                $net = round(
                    $gross
                    - (float)($breakdown['broker_amt'] ?? 0)
                    - (float)($breakdown['insurance'] ?? 0)
                    - (float)($breakdown['fuel'] ?? 0)
                    + (float)($breakdown['misc_adjustment_total'] ?? 0)
            + (float)($breakdown['fuel_surcharge_total'] ?? 0),
                    2
                );
            }
            $driverNet += $net;
            $insurance += (float)($breakdown['insurance'] ?? 0);
            $fuel += (float)($breakdown['fuel'] ?? 0);
            $brokerFee += (float)($breakdown['broker_amt'] ?? 0);
            $miscRevenue += (float)($breakdown['misc_adjustment_total'] ?? 0);
        }

        $companyGross = $scope === 'nickelrock'
            ? round($derivedCompanyGross, 2)
            : (float)$input['company_gross'];
        $profitGross = $scope === 'nickelrock'
            ? round($companyGross * 0.95, 2)
            : $companyGross;
        // Fuel and insurance are passed separately because they have already
        // reduced driver net and must not be counted as company profit.
        $totalProfit = lonestar_owner_payout_profit_total($profitGross, $driverNet, $insurance, $fuel);
        lonestar_owner_payout_summary_save(
            $mysqli,
            $weekStart,
            $scope,
            $companyGross,
            round($driverNet, 2),
            round($fuel, 2),
            round($brokerFee, 2),
            round($miscRevenue, 2),
            $totalProfit
        );
    }
    if ($refreshNickelRockExisting) {
        $stmt = $mysqli->prepare(
            "INSERT INTO dashboard_profitability_backfills (backfill_key, completed_at)
             VALUES (?, NOW())
             ON DUPLICATE KEY UPDATE completed_at=VALUES(completed_at)"
        );
        $stmt->bind_param('s', $nickelRockBackfillKey);
        $stmt->execute();
        $stmt->close();
    }
}

$driverCount = 0;
$activeDriverCount = 0;
$driversWorkedTodayCount = 0;
$driversWorkedThisWeekCount = 0;
$inactiveDriversWithFuelCount = 0;
$inactiveDriversWithoutFuelCount = 0;
$ownerCount = 0;
$missingDotCount = 0;
$driverTypeCounts = [];
$topOwners = [];
$topDrivers = [];
$weeklyTotals = [];
$weeklyVendorRows = [];
$vendorTotals = [];
$weeklyVendorTotals = [];
$vendorProfitRows = [];
$vendorProfitSummary = [];
$fuelExposureRows = [];
$trailerRevenueRows = [];
$driverProfitRows = [];
$ownerSummaryRows = [];
$adjustmentRows = [];
$outstandingBalanceRows = [];
$outstandingBalanceTotal = 0.0;
$balanceTrackerTotal = 0.0;
$fuelSurchargeRows = [];
$weeklyFuelUsageRows = [];
$vendorAnnualProfitabilityRows = [];
$needsAttention = [];
$totalPayoutAmount = 0.0;
$unmatchedPayoutAmount = 0.0;
$recent30DayPayoutAmount = 0.0;
$recent30DayPayoutCount = 0;
$inactiveDriversWithFuel = [];
$dashboardWeekStart = dashboard_week_start(date('Y-m-d'));
$dashboardWeekEnd = dashboard_week_end($dashboardWeekStart);
$balanceTrackerMessage = '';
$balanceTrackerMessageType = 'success';

if ((string)($_GET['refresh_balances'] ?? '') === '1') {
    $balanceTrackerMessage = 'Balance Tracker refreshed from current system data.';
}

$vendorCase = dashboard_vendor_case('dp.vendor_name');
$vendorFilter = dashboard_vendor_filter('dp.vendor_name');
dashboard_balance_tracker_clears_ensure_table($mysqli);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['action'] ?? '') === 'clear_balance_tracker')) {
    $clearType = trim((string)($_POST['balance_type'] ?? ''));
    $clearDriverId = (int)($_POST['driver_id'] ?? 0);
    $clearScope = lonestar_payout_normalize_vendor_scope((string)($_POST['vendor_scope'] ?? 'tss'));
    $clearWeekStart = trim((string)($_POST['week_start'] ?? $dashboardWeekStart));
    $clearToken = (string)($_POST['clear_token'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $clearWeekStart)) {
        $clearWeekStart = $dashboardWeekStart;
    }
    if (
        $clearDriverId <= 0
        || !in_array($clearType, ['fuel', 'misc'], true)
        || !hash_equals(dashboard_balance_clear_token($clearType, $clearDriverId, $clearScope, $clearWeekStart), $clearToken)
    ) {
        $balanceTrackerMessage = 'Unable to clear that balance. Please refresh and try again.';
        $balanceTrackerMessageType = 'danger';
    } else {
        $clearedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $stmtClear = $mysqli->prepare(
            "INSERT INTO dashboard_balance_tracker_clears (balance_type, driver_id, vendor_scope, week_start, cleared_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE cleared_by = VALUES(cleared_by), cleared_at = NOW()"
        );
        if ($stmtClear) {
            $stmtClear->bind_param('sissi', $clearType, $clearDriverId, $clearScope, $clearWeekStart, $clearedBy);
            $stmtClear->execute();
            $stmtClear->close();
        }
        if ($clearType === 'fuel') {
            lonestar_driver_save_fuel_balance($mysqli, $clearDriverId, 0.0, $clearWeekStart);
            $balanceTrackerMessage = 'Fuel balance cleared.';
        } else {
            lonestar_driver_save_misc_balance($mysqli, $clearDriverId, $clearScope, 0.0, $clearWeekStart);
            $balanceTrackerMessage = dashboard_vendor_label_from_scope($clearScope) . ' misc balance cleared.';
        }
    }
}

$balanceTrackerClears = [];

$res = $mysqli->query("SELECT COUNT(*) AS cnt FROM driver_contacts");
if ($row = $res->fetch_assoc()) {
    $driverCount = (int)$row['cnt'];
}
$res->close();

$res = $mysqli->query("SELECT COUNT(*) AS cnt FROM driver_contacts WHERE COALESCE(is_disabled,0) = 0");
if ($row = $res->fetch_assoc()) {
    $activeDriverCount = (int)$row['cnt'];
}
$res->close();

$res = $mysqli->query(
    "SELECT COUNT(DISTINCT dld.driver_contact_id) AS cnt
       FROM (
             SELECT driver_contact_id
               FROM driver_load_daily
              WHERE activity_date = CURDATE()
                AND driver_contact_id IS NOT NULL
             UNION
             SELECT driver_contact_id
               FROM driver_payouts
              WHERE payout_date = CURDATE()
                AND driver_contact_id IS NOT NULL
                AND driver_contact_id <> 0
            ) dld
       JOIN driver_contacts dc ON dld.driver_contact_id = dc.id
      WHERE COALESCE(dc.is_disabled, 0) = 0"
);
if ($row = $res->fetch_assoc()) {
    $driversWorkedTodayCount = (int)$row['cnt'];
}
$res->close();

$res = $mysqli->query(
    "SELECT COUNT(DISTINCT dld.driver_contact_id) AS cnt
       FROM (
             SELECT driver_contact_id
               FROM driver_load_daily
              WHERE activity_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                AND activity_date <= CURDATE()
                AND driver_contact_id IS NOT NULL
             UNION
             SELECT driver_contact_id
               FROM driver_payouts
              WHERE payout_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                AND payout_date <= CURDATE()
                AND driver_contact_id IS NOT NULL
                AND driver_contact_id <> 0
            ) dld
       JOIN driver_contacts dc ON dld.driver_contact_id = dc.id
      WHERE COALESCE(dc.is_disabled, 0) = 0"
);
if ($row = $res->fetch_assoc()) {
    $driversWorkedThisWeekCount = (int)$row['cnt'];
}
$res->close();

$res = $mysqli->query(
    "SELECT dc.id, CONCAT(dc.first_name, ' ', dc.last_name) AS driver_name, 
            CASE WHEN EXISTS (SELECT 1 FROM fuel_cards fc WHERE fc.assigned_driver_id = dc.id AND COALESCE(fc.is_active, 1) = 1) THEN 1 ELSE 0 END AS has_active_fuel
       FROM driver_contacts dc
      WHERE COALESCE(dc.is_disabled, 0) = 0
        AND NOT EXISTS (
          SELECT 1 FROM driver_load_daily dld
           WHERE dld.driver_contact_id = dc.id
             AND dld.activity_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
        )
        AND NOT EXISTS (
          SELECT 1 FROM driver_payouts dp
           WHERE dp.driver_contact_id = dc.id
             AND dp.payout_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
        )
      ORDER BY has_active_fuel DESC, driver_name"
);
while ($row = $res->fetch_assoc()) {
    $driverId = (int)$row['id'];
    $hasActiveFuel = (int)$row['has_active_fuel'] === 1;
    if ($hasActiveFuel) {
        $inactiveDriversWithFuelCount++;
        $inactiveDriversWithFuel[] = [
            'id' => $driverId,
            'driver_name' => (string)$row['driver_name'],
            'has_active_fuel' => true,
        ];
    } else {
        $inactiveDriversWithoutFuelCount++;
    }
}
$res->close();

$res = $mysqli->query("SELECT COUNT(DISTINCT NULLIF(owner_name, '')) AS cnt FROM driver_contacts");
if ($row = $res->fetch_assoc()) {
    $ownerCount = (int)$row['cnt'];
}
$res->close();

$res = $mysqli->query("SELECT COUNT(*) AS cnt FROM driver_contacts WHERE COALESCE(owner_name, '') <> '' AND COALESCE(owner_dot_number, '') = ''");
if ($row = $res->fetch_assoc()) {
    $missingDotCount = (int)$row['cnt'];
}
$res->close();

$res = $mysqli->query(
    "SELECT COALESCE(driver_type, 'Unknown') AS driver_type, COUNT(*) AS cnt
       FROM driver_contacts
      WHERE COALESCE(is_disabled,0) = 0
      GROUP BY driver_type
      ORDER BY cnt DESC"
);
while ($row = $res->fetch_assoc()) {
    $driverTypeCounts[] = [
        'label' => (string)$row['driver_type'],
        'count' => (int)$row['cnt'],
    ];
}
$res->close();

$res = $mysqli->query(
    "SELECT COALESCE(NULLIF(dc.owner_name, ''), dp.driver_name) AS owner_name,
            COALESCE(SUM(dp.tss_pay),0) AS total,
            COUNT(*) AS payouts
       FROM driver_payouts dp
       LEFT JOIN driver_contacts dc ON dp.driver_contact_id = dc.id
      GROUP BY owner_name
      ORDER BY total DESC
      LIMIT 5"
);
while ($row = $res->fetch_assoc()) {
    $topOwners[] = [
        'owner_name' => trim((string)$row['owner_name']),
        'total' => (float)$row['total'],
        'payouts' => (int)$row['payouts'],
    ];
}
$res->close();

$res = $mysqli->query(
    "SELECT COALESCE(NULLIF(CONCAT(dc.first_name, ' ', dc.last_name), ''), dp.driver_name) AS driver_name,
            COALESCE(SUM(dp.tss_pay),0) AS total
       FROM driver_payouts dp
       LEFT JOIN driver_contacts dc ON dp.driver_contact_id = dc.id
      GROUP BY driver_name
      ORDER BY total DESC
      LIMIT 5"
);
while ($row = $res->fetch_assoc()) {
    $topDrivers[] = [
        'driver_name' => trim((string)$row['driver_name']),
        'total' => (float)$row['total'],
    ];
}
$res->close();

$res = $mysqli->query("SELECT COALESCE(SUM(tss_pay),0) AS total FROM driver_payouts");
if ($row = $res->fetch_assoc()) {
    $totalPayoutAmount = (float)$row['total'];
}
$res->close();

$res = $mysqli->query("SELECT COALESCE(SUM(tss_pay),0) AS total FROM driver_payouts WHERE driver_contact_id IS NULL OR driver_contact_id = 0");
if ($row = $res->fetch_assoc()) {
    $unmatchedPayoutAmount = (float)$row['total'];
}
$res->close();

$res = $mysqli->query("SELECT COALESCE(SUM(tss_pay),0) AS total, COUNT(*) AS cnt FROM driver_payouts WHERE payout_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
if ($row = $res->fetch_assoc()) {
    $recent30DayPayoutAmount = (float)$row['total'];
    $recent30DayPayoutCount = (int)$row['cnt'];
}
$res->close();

$res = $mysqli->query(
    "SELECT vendor_label,
            COALESCE(SUM(driver_total),0) AS total,
            COALESCE(SUM(loads),0) AS payouts,
            COUNT(DISTINCT NULLIF(driver_contact_id, 0)) AS drivers,
            COALESCE(AVG(CASE WHEN driver_contact_id IS NOT NULL AND driver_contact_id <> 0 AND loads > 0 THEN driver_total / loads ELSE NULL END),0) AS avg_driver_load
       FROM (
            SELECT {$vendorCase} AS vendor_label,
                   dp.driver_contact_id,
                   COALESCE(SUM(dp.tss_pay),0) AS driver_total,
                   COUNT(*) AS loads
              FROM driver_payouts dp
             WHERE {$vendorFilter}
               AND YEAR(dp.payout_date) = 2026
             GROUP BY vendor_label, dp.driver_contact_id
       ) vendor_driver_totals
      GROUP BY vendor_label
      ORDER BY total DESC"
);
while ($row = $res->fetch_assoc()) {
    $loads = (int)$row['payouts'];
    $drivers = (int)$row['drivers'];
    $total = (float)$row['total'];
    $vendorTotals[] = [
        'vendor_label' => (string)$row['vendor_label'],
        'total' => $total,
        'payouts' => $loads,
        'loads' => $loads,
        'avg_driver_load' => round((float)$row['avg_driver_load'], 2),
        'drivers' => $drivers,
    ];
}
$res->close();

$res = $mysqli->query(
    "SELECT DATE_SUB(dp.payout_date, INTERVAL (DAYOFWEEK(dp.payout_date)-1) DAY) AS week_start,
            COALESCE(SUM(dp.tss_pay),0) AS total
       FROM driver_payouts dp
      WHERE dp.payout_date >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
        AND {$vendorFilter}
      GROUP BY week_start
      ORDER BY week_start"
);
while ($row = $res->fetch_assoc()) {
    $weeklyTotals[] = [
        'week_start' => (string)$row['week_start'],
        'total' => (float)$row['total'],
    ];
}
$res->close();

$res = $mysqli->query(
    "SELECT DATE_SUB(dp.payout_date, INTERVAL (DAYOFWEEK(dp.payout_date)-1) DAY) AS week_start,
            {$vendorCase} AS vendor_label,
            COALESCE(SUM(dp.tss_pay),0) AS total
       FROM driver_payouts dp
      WHERE dp.payout_date >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
        AND {$vendorFilter}
      GROUP BY week_start, vendor_label
      ORDER BY week_start, vendor_label"
);
while ($row = $res->fetch_assoc()) {
    $weeklyVendorRows[] = [
        'week_start' => (string)$row['week_start'],
        'vendor_label' => (string)$row['vendor_label'],
        'total' => (float)$row['total'],
    ];
}
$res->close();

$activityDatesSql = "SELECT payout_date AS activity_date FROM driver_payouts";
if (dashboard_table_exists($mysqli, 'tss_misc_adjustments')) {
    // Apply posted adjustments even when the driver has stopped receiving payouts.
    // Future-dated adjustments must not advance the live dashboard prematurely.
    $activityDatesSql .= " UNION ALL SELECT adjustment_date AS activity_date FROM tss_misc_adjustments WHERE adjustment_date <= CURDATE()";
}
$res = $mysqli->query("SELECT MAX(activity_date) AS latest_date FROM ({$activityDatesSql}) dashboard_activity");
if ($row = $res->fetch_assoc()) {
    $latestPayoutDate = trim((string)($row['latest_date'] ?? ''));
    if ($latestPayoutDate !== '') {
        $dashboardWeekStart = dashboard_week_start($latestPayoutDate);
        $dashboardWeekEnd = dashboard_week_end($dashboardWeekStart);
    }
}
$res->close();

$res = $mysqli->query(
    "SELECT vendor_label,
            COALESCE(SUM(driver_total),0) AS total,
            COALESCE(SUM(loads),0) AS payouts,
            COUNT(DISTINCT NULLIF(driver_contact_id, 0)) AS drivers,
            COALESCE(AVG(CASE WHEN driver_contact_id IS NOT NULL AND driver_contact_id <> 0 AND loads > 0 THEN driver_total / loads ELSE NULL END),0) AS avg_driver_load
       FROM (
            SELECT {$vendorCase} AS vendor_label,
                   dp.driver_contact_id,
                   COALESCE(SUM(dp.tss_pay),0) AS driver_total,
                   COUNT(*) AS loads
              FROM driver_payouts dp
             WHERE {$vendorFilter}
               AND dp.payout_date BETWEEN '{$mysqli->real_escape_string($dashboardWeekStart)}' AND '{$mysqli->real_escape_string($dashboardWeekEnd)}'
             GROUP BY vendor_label, dp.driver_contact_id
       ) vendor_driver_totals
      GROUP BY vendor_label
      ORDER BY total DESC"
);
while ($row = $res->fetch_assoc()) {
    $loads = (int)$row['payouts'];
    $weeklyVendorTotals[] = [
        'vendor_label' => (string)$row['vendor_label'],
        'total' => (float)$row['total'],
        'payouts' => $loads,
        'loads' => $loads,
        'avg_driver_load' => round((float)$row['avg_driver_load'], 2),
        'drivers' => (int)$row['drivers'],
    ];
}
$res->close();

$stmtBalanceClears = $mysqli->prepare(
    "SELECT balance_type, driver_id, vendor_scope
       FROM dashboard_balance_tracker_clears
      WHERE week_start = ?"
);
if ($stmtBalanceClears) {
    $stmtBalanceClears->bind_param('s', $dashboardWeekStart);
    $stmtBalanceClears->execute();
    $stmtBalanceClears->bind_result($clearTypeRow, $clearDriverIdRow, $clearScopeRow);
    while ($stmtBalanceClears->fetch()) {
        $balanceTrackerClears[(string)$clearTypeRow . '|' . (int)$clearDriverIdRow . '|' . lonestar_payout_normalize_vendor_scope((string)$clearScopeRow)] = true;
    }
    $stmtBalanceClears->close();
}

try {
    lonestar_auto_nextier_trailer_rental_deductions($mysqli, $dashboardWeekStart, $dashboardWeekEnd);
} catch (Throwable $e) {
    error_log('dashboard NexTier trailer deduction sync failed: ' . $e->getMessage());
}

$ownerPayoutAmounts = dashboard_owner_payout_amounts($mysqli, $dashboardWeekStart);

$vendorDriverWeekRows = [];
$res = $mysqli->query(
    "SELECT DATE_SUB(dp.payout_date, INTERVAL (DAYOFWEEK(dp.payout_date)-1) DAY) AS week_start,
            {$vendorCase} AS vendor_label,
            dp.driver_contact_id,
            COALESCE(NULLIF(CONCAT(dc.first_name, ' ', dc.last_name), ''), dp.driver_name) AS driver_name,
            COALESCE(NULLIF(dc.owner_name, ''), dp.driver_name) AS owner_name,
            COALESCE(NULLIF(dc.owner_dot_number, ''), 'Missing DOT') AS dot_number,
            dc.email AS owner_email,
            COALESCE(SUM(dp.tss_pay),0) AS gross_total
       FROM driver_payouts dp
       LEFT JOIN driver_contacts dc ON dc.id = dp.driver_contact_id
      WHERE dp.payout_date BETWEEN '{$mysqli->real_escape_string($dashboardWeekStart)}' AND '{$mysqli->real_escape_string($dashboardWeekEnd)}'
        AND dp.driver_contact_id IS NOT NULL
        AND dp.driver_contact_id <> 0
        AND {$vendorFilter}
      GROUP BY week_start, vendor_label, dp.driver_contact_id, driver_name, owner_name, dot_number, owner_email
      ORDER BY vendor_label, gross_total DESC"
);
while ($row = $res->fetch_assoc()) {
    $vendorDriverWeekRows[] = $row;
}
$res->close();

$payoutByDriverVendor = [];
$statementNetByDriverVendor = [];
foreach ($vendorDriverWeekRows as $row) {
    $driverId = (int)($row['driver_contact_id'] ?? 0);
    if ($driverId <= 0) {
        continue;
    }
    $scope = dashboard_vendor_scope_from_name((string)($row['vendor_label'] ?? 'TSS'));
    $payoutByDriverVendor[$driverId . '|' . $scope] = true;
}

$fuelByDriver = [];
foreach ($vendorDriverWeekRows as $row) {
    $driverId = (int)($row['driver_contact_id'] ?? 0);
    $vendorLabel = (string)($row['vendor_label'] ?? 'TSS');
    $scope = dashboard_vendor_scope_from_name($vendorLabel);
    // The grouped query above has already assigned each payout row to one
    // driver/vendor bucket. Re-running the broad identity lookup here can
    // include the same TSS row in more than one driver's net calculation.
    $gross = round((float)($row['gross_total'] ?? 0), 2);
    if ($scope === 'nextier') {
        // NexTier statement gross is derived from its detail rows rather than
        // the raw driver_payouts amount, matching the Owner Payout Report.
        $gross = lonestar_driver_vendor_week_gross(
            $mysqli,
            $driverId,
            $dashboardWeekStart,
            $dashboardWeekEnd,
            'nextier'
        );
    }
    $trailerFee = $scope === 'tss'
        ? lonestar_driver_tss_week_trailer_fee_total($mysqli, $driverId, $dashboardWeekStart, $dashboardWeekEnd)
        : lonestar_driver_trailer_fee_estimate($mysqli, $driverId, $gross, $scope, $dashboardWeekStart, $dashboardWeekEnd);
    $breakdown = lonestar_driver_week_net_breakdown($mysqli, $driverId, $gross, $trailerFee, $dashboardWeekStart, $dashboardWeekEnd, $scope);
    $net = (float)($breakdown['net_total'] ?? 0);
    if ($scope === 'rtex') {
        $net = round(
            $gross
            - (float)($breakdown['broker_amt'] ?? 0)
            - (float)($breakdown['insurance'] ?? 0)
            - (float)($breakdown['fuel'] ?? 0)
            + (float)($breakdown['misc_adjustment_total'] ?? 0)
            + (float)($breakdown['fuel_surcharge_total'] ?? 0),
            2
        );
    }
    $statementNetByDriverVendor[$driverId . '|' . $scope] = $net;
    $profit = round($gross - $net, 2);
    $profitRow = [
        'vendor_label' => $vendorLabel,
        'driver_id' => $driverId,
        'driver_name' => (string)($row['driver_name'] ?? ''),
        'owner_name' => (string)($row['owner_name'] ?? ''),
        'dot_number' => (string)($row['dot_number'] ?? ''),
        'owner_email' => (string)($row['owner_email'] ?? ''),
        'gross_total' => $gross,
        'driver_net' => $net,
        'fuel' => (float)($breakdown['fuel'] ?? 0),
        'insurance' => (float)($breakdown['insurance'] ?? 0),
        'trailer_fee' => $trailerFee,
        'broker_fee' => (float)($breakdown['broker_amt'] ?? 0),
        'misc_adjustment' => (float)($breakdown['misc_adjustment_total'] ?? 0),
        'fuel_surcharge' => (float)($breakdown['fuel_surcharge_total'] ?? 0),
        'estimated_profit' => $profit,
        'remaining_fuel_after' => (float)($breakdown['remaining_fuel_after'] ?? 0),
        'total_week_fuel' => (float)($breakdown['total_week_fuel'] ?? 0),
    ];
    $vendorProfitRows[] = $profitRow;
    if (!isset($vendorProfitSummary[$vendorLabel])) {
        $vendorProfitSummary[$vendorLabel] = [
            'vendor_label' => $vendorLabel,
            'gross_total' => 0.0,
            'driver_net' => 0.0,
            'fuel' => 0.0,
            'insurance' => 0.0,
            'trailer_fee' => 0.0,
            'broker_fee' => 0.0,
            'misc_adjustment' => 0.0,
            'fuel_surcharge' => 0.0,
            'estimated_profit' => 0.0,
        ];
    }
    foreach (['gross_total','driver_net','fuel','insurance','trailer_fee','broker_fee','misc_adjustment','fuel_surcharge','estimated_profit'] as $key) {
        $vendorProfitSummary[$vendorLabel][$key] += (float)$profitRow[$key];
    }
    if (!isset($fuelByDriver[$driverId])) {
        $fuelByDriver[$driverId] = [
            'driver_name' => (string)($row['driver_name'] ?? ''),
            'total_week_fuel' => (float)($breakdown['total_week_fuel'] ?? 0),
            'remaining' => null,
            'save_balance' => true,
        ];
    }
    $fuelByDriver[$driverId]['total_week_fuel'] = max($fuelByDriver[$driverId]['total_week_fuel'], (float)($breakdown['total_week_fuel'] ?? 0));
    $remainingAfterVendor = (float)($breakdown['remaining_fuel_after'] ?? $fuelByDriver[$driverId]['total_week_fuel']);
    $fuelByDriver[$driverId]['remaining'] = $fuelByDriver[$driverId]['remaining'] === null
        ? $remainingAfterVendor
        : min((float)$fuelByDriver[$driverId]['remaining'], $remainingAfterVendor);
}

foreach ($ownerPayoutAmounts as $scope => $vendorGross) {
    $vendorLabel = dashboard_vendor_label_from_scope((string)$scope);
    if (!isset($vendorProfitSummary[$vendorLabel])) {
        $vendorProfitSummary[$vendorLabel] = [
            'vendor_label' => $vendorLabel,
            'gross_total' => 0.0,
            'driver_net' => 0.0,
            'fuel' => 0.0,
            'insurance' => 0.0,
            'trailer_fee' => 0.0,
            'broker_fee' => 0.0,
            'misc_adjustment' => 0.0,
            'fuel_surcharge' => 0.0,
            'estimated_profit' => 0.0,
        ];
    }
    if (lonestar_payout_normalize_vendor_scope((string)$scope) !== 'nickelrock') {
        $vendorProfitSummary[$vendorLabel]['gross_total'] = round((float)$vendorGross, 2);
    }
}

foreach ($vendorProfitSummary as $vendorLabel => $summary) {
    $profitGross = dashboard_vendor_scope_from_name((string)$vendorLabel) === 'nickelrock'
        ? round((float)$summary['gross_total'] * 0.95, 2)
        : (float)$summary['gross_total'];
    $vendorProfitSummary[$vendorLabel]['estimated_profit'] = lonestar_owner_payout_profit_total(
        $profitGross,
        (float)$summary['driver_net'],
        (float)$summary['insurance'],
        (float)$summary['fuel']
    );
}

if (dashboard_table_exists($mysqli, 'driver_gas_costs')) {
    $res = $mysqli->query(
        "SELECT dgc.driver_id,
                CONCAT(dc.first_name, ' ', dc.last_name) AS driver_name,
                COALESCE(SUM(dgc.amount),0) AS total_fuel
           FROM driver_gas_costs dgc
           JOIN driver_contacts dc ON dc.id = dgc.driver_id
          WHERE dgc.cost_date BETWEEN '{$mysqli->real_escape_string($dashboardWeekStart)}' AND '{$mysqli->real_escape_string($dashboardWeekEnd)}'
            AND COALESCE(dc.is_disabled,0) = 0
          GROUP BY dgc.driver_id, driver_name"
    );
    while ($row = $res->fetch_assoc()) {
        $driverId = (int)($row['driver_id'] ?? 0);
        if ($driverId <= 0 || isset($fuelByDriver[$driverId])) {
            continue;
        }
        $priorFuelBalance = lonestar_driver_open_fuel_balance($mysqli, $driverId, $dashboardWeekStart);
        $totalFuel = round((float)($row['total_fuel'] ?? 0) + $priorFuelBalance, 2);
        if ($totalFuel <= 0.005) {
            continue;
        }
        $fuelByDriver[$driverId] = [
            'driver_name' => (string)($row['driver_name'] ?? ''),
            'total_week_fuel' => $totalFuel,
            'remaining' => $totalFuel,
            'save_balance' => true,
        ];
    }
    $res->close();
}

if (dashboard_table_exists($mysqli, 'fuel_report_transactions')) {
    $fuelUsageDateColumn = dashboard_column_exists($mysqli, 'fuel_report_transactions', 'visit_date')
        ? 'COALESCE(visit_date, txn_date)'
        : 'txn_date';
    $res = $mysqli->query(
        "SELECT DATE_SUB({$fuelUsageDateColumn}, INTERVAL (DAYOFWEEK({$fuelUsageDateColumn}) - 1) DAY) AS week_start,
                ROUND(COALESCE(SUM(COALESCE(gross_amt,0) + COALESCE(fees_amt,0)),0), 2) AS total_usage
           FROM fuel_report_transactions
          WHERE {$fuelUsageDateColumn} >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            AND {$fuelUsageDateColumn} <= CURDATE()
          GROUP BY week_start
          ORDER BY week_start ASC"
    );
    while ($row = $res->fetch_assoc()) {
        $weeklyFuelUsageRows[] = [
            'week_start' => (string)$row['week_start'],
            'total_usage' => (float)$row['total_usage'],
        ];
    }
    $res->close();
}

$fuelWriteOffExistsSql = '0';
if (dashboard_table_exists($mysqli, 'tss_misc_adjustments')) {
    $fuelWriteOffExistsSql = "EXISTS (SELECT 1 FROM tss_misc_adjustments wa WHERE wa.driver_contact_id=dfb.driver_id AND wa.adjustment_type='write_off' AND wa.payout_week_start='{$mysqli->real_escape_string($dashboardWeekStart)}')";
}
if (dashboard_table_exists($mysqli, 'driver_fuel_balances')) {
    $res = $mysqli->query(
        "SELECT dfb.driver_id,
                CONCAT(dc.first_name, ' ', dc.last_name) AS driver_name,
                COALESCE(dfb.balance,0) AS balance
           FROM driver_fuel_balances dfb
           JOIN driver_contacts dc ON dc.id = dfb.driver_id
          WHERE (COALESCE(dfb.balance,0) > 0 OR {$fuelWriteOffExistsSql})
            AND (COALESCE(dc.is_disabled,0) = 0 OR {$fuelWriteOffExistsSql})
            AND (dfb.last_calculated_week_start IS NULL OR dfb.last_calculated_week_start <= '{$mysqli->real_escape_string($dashboardWeekStart)}')"
    );
    while ($row = $res->fetch_assoc()) {
        $driverId = (int)($row['driver_id'] ?? 0);
        if ($driverId <= 0 || isset($fuelByDriver[$driverId])) {
            continue;
        }
        $balance = lonestar_driver_open_fuel_balance($mysqli, $driverId, $dashboardWeekStart);
        if ($balance <= 0.005) {
            continue;
        }
        $fuelByDriver[$driverId] = [
            'driver_name' => (string)($row['driver_name'] ?? ''),
            'total_week_fuel' => $balance,
            'remaining' => $balance,
            'save_balance' => false,
        ];
    }
    $res->close();
}

$miscFuelCreditByDriver = [];
$miscFuelCreditByDriverScope = [];
$miscFuelCreditAppliedByScope = [];
$miscCreditSeenKeys = [];
if (dashboard_table_exists($mysqli, 'tss_misc_adjustments')) {
    $hasAdjustmentVendorColumnForFuel = dashboard_column_exists($mysqli, 'tss_misc_adjustments', 'payout_vendor');
    $adjustScopeExprForFuel = $hasAdjustmentVendorColumnForFuel
        ? "CASE WHEN UPPER(TRIM(COALESCE(tma.payout_vendor, 'TSS'))) = 'NEXTIER' THEN 'nextier' WHEN UPPER(TRIM(COALESCE(tma.payout_vendor, 'TSS'))) = 'RTEX' THEN 'rtex' WHEN UPPER(TRIM(COALESCE(tma.payout_vendor, 'TSS'))) IN ('NICKELROCK', 'NICKEL ROCK') THEN 'nickelrock' ELSE 'tss' END"
        : "'tss'";
    $adjustAutoTrailerWhereForFuel = $hasAdjustmentVendorColumnForFuel
        ? "AND NOT (
              UPPER(TRIM(COALESCE(tma.payout_vendor, 'TSS'))) = 'NEXTIER'
              AND tma.adjustment_type = 'misc_deduction'
              AND LOWER(COALESCE(tma.comments, '')) LIKE 'auto nextier trailer rental deduction%'
          )"
        : "";
    $res = $mysqli->query(
        "SELECT {$adjustScopeExprForFuel} AS vendor_scope,
                tma.driver_contact_id,
                COALESCE(SUM(CASE WHEN tma.adjustment_type='misc_deduction' THEN -ABS(tma.amount) ELSE ABS(tma.amount) END),0) AS net_amount
           FROM tss_misc_adjustments tma
          WHERE tma.payout_week_start = '{$mysqli->real_escape_string($dashboardWeekStart)}'
            {$adjustAutoTrailerWhereForFuel}
          GROUP BY vendor_scope, tma.driver_contact_id"
    );
    while ($row = $res->fetch_assoc()) {
        $driverId = (int)($row['driver_contact_id'] ?? 0);
        $scope = (string)($row['vendor_scope'] ?? 'tss');
        $key = $driverId . '|' . $scope;
        $miscCreditSeenKeys[$key] = true;
        if ($driverId <= 0 || !empty($payoutByDriverVendor[$key])) {
            continue;
        }
        $netCredit = round((float)($row['net_amount'] ?? 0) + lonestar_driver_open_misc_balance($mysqli, $driverId, $scope, $dashboardWeekStart), 2);
        if ($netCredit <= 0.005) {
            continue;
        }
        $miscFuelCreditByDriver[$driverId] = round((float)($miscFuelCreditByDriver[$driverId] ?? 0) + $netCredit, 2);
        $miscFuelCreditByDriverScope[$key] = round((float)($miscFuelCreditByDriverScope[$key] ?? 0) + $netCredit, 2);
    }
    $res->close();
}
if (dashboard_table_exists($mysqli, 'driver_misc_adjustment_balances')) {
    $res = $mysqli->query(
        "SELECT driver_id, vendor_scope, COALESCE(balance,0) AS balance
           FROM driver_misc_adjustment_balances
          WHERE COALESCE(balance,0) > 0.005
            AND (last_calculated_week_start IS NULL OR last_calculated_week_start < '{$mysqli->real_escape_string($dashboardWeekStart)}')"
    );
    while ($row = $res->fetch_assoc()) {
        $driverId = (int)($row['driver_id'] ?? 0);
        $scope = (string)($row['vendor_scope'] ?? 'tss');
        $key = $driverId . '|' . $scope;
        if ($driverId <= 0 || isset($miscCreditSeenKeys[$key]) || !empty($payoutByDriverVendor[$key])) {
            continue;
        }
        $balance = round((float)($row['balance'] ?? 0), 2);
        if ($balance <= 0.005) {
            continue;
        }
        $miscFuelCreditByDriver[$driverId] = round((float)($miscFuelCreditByDriver[$driverId] ?? 0) + $balance, 2);
        $miscFuelCreditByDriverScope[$key] = round((float)($miscFuelCreditByDriverScope[$key] ?? 0) + $balance, 2);
    }
    $res->close();
}

$ownerPayoutSummaryRows = lonestar_owner_payout_summary_rows($mysqli, $dashboardWeekStart);
foreach ($ownerPayoutSummaryRows as $scope => $ownerSummary) {
    $vendorLabel = dashboard_vendor_label_from_scope((string)$scope);
    if (!isset($vendorProfitSummary[$vendorLabel])) {
        $vendorProfitSummary[$vendorLabel] = [
            'vendor_label' => $vendorLabel,
            'gross_total' => 0.0,
            'driver_net' => 0.0,
            'fuel' => 0.0,
            'insurance' => 0.0,
            'trailer_fee' => 0.0,
            'broker_fee' => 0.0,
            'misc_adjustment' => 0.0,
            'fuel_surcharge' => 0.0,
            'estimated_profit' => 0.0,
        ];
    }
    $vendorProfitSummary[$vendorLabel]['gross_total'] = (float)$ownerSummary['company_gross'];
    $vendorProfitSummary[$vendorLabel]['driver_net'] = (float)$ownerSummary['driver_net'];
    $vendorProfitSummary[$vendorLabel]['fuel'] = (float)$ownerSummary['fuel'];
    $vendorProfitSummary[$vendorLabel]['broker_fee'] = (float)$ownerSummary['broker_fee'];
    $vendorProfitSummary[$vendorLabel]['misc_adjustment'] = (float)$ownerSummary['misc_revenue'];
    $vendorProfitSummary[$vendorLabel]['estimated_profit'] = (float)$ownerSummary['total_profit'];
}

$currentDashboardYear = (int)date('Y');
lonestar_owner_payout_summary_ensure_table($mysqli);
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'rebuild_profitability') {
        if (!hash_equals(hash('sha256', 'dashboard-backfill|' . session_id()), (string)($_POST['rebuild_token'] ?? ''))) {
            throw new RuntimeException('Reload the dashboard before rebuilding history.');
        }
        dashboard_backfill_annual_profitability($mysqli, $currentDashboardYear);
        $balanceTrackerMessage = 'Missing annual profitability summaries rebuilt.';
    }
} catch (Throwable $e) {
    error_log('Annual client profitability backfill failed: ' . $e->getMessage());
    $balanceTrackerMessage = 'Annual profitability could not be rebuilt. Check the server error log.';
    $balanceTrackerMessageType = 'danger';
}
$stmtAnnualProfit = $mysqli->prepare(
    "SELECT payout_vendor,
            ROUND(SUM(company_gross), 2) AS company_gross,
            ROUND(SUM(total_profit), 2) AS total_profit
       FROM owner_payout_summary_snapshots
      WHERE YEAR(payout_week_start) = ?
      GROUP BY payout_vendor"
);
$stmtAnnualProfit->bind_param('i', $currentDashboardYear);
$stmtAnnualProfit->execute();
$stmtAnnualProfit->bind_result($annualScope, $annualGross, $annualProfit);
while ($stmtAnnualProfit->fetch()) {
    $gross = (float)$annualGross;
    $profit = (float)$annualProfit;
    $vendorAnnualProfitabilityRows[] = [
        'vendor_label' => dashboard_vendor_label_from_scope((string)$annualScope),
        'company_gross' => $gross,
        'total_profit' => $profit,
        'margin_pct' => $gross != 0.0 ? round(($profit / $gross) * 100, 2) : 0.0,
    ];
}
$stmtAnnualProfit->close();
usort(
    $vendorAnnualProfitabilityRows,
    static fn($a, $b) => ((float)$b['total_profit'] <=> (float)$a['total_profit'])
);

$vendorProfitSummary = array_values($vendorProfitSummary);
usort($vendorProfitSummary, static fn($a, $b) => ($b['estimated_profit'] <=> $a['estimated_profit']));

foreach ($fuelByDriver as $driverId => $fuelRow) {
    $totalFuel = round((float)$fuelRow['total_week_fuel'], 2);
    $remainingFuel = round((float)($fuelRow['remaining'] ?? $totalFuel), 2);
    if (!empty($balanceTrackerClears['fuel|' . (int)$driverId . '|tss'])) {
        lonestar_driver_save_fuel_balance($mysqli, (int)$driverId, 0.0, $dashboardWeekStart);
        continue;
    }
    $availableMiscCredit = round((float)($miscFuelCreditByDriver[$driverId] ?? 0), 2);
    $miscCreditApplied = round(min($remainingFuel, max(0.0, $availableMiscCredit)), 2);
    if ($miscCreditApplied > 0.005) {
        $remainingFuel = round(max(0.0, $remainingFuel - $miscCreditApplied), 2);
        $leftToApply = $miscCreditApplied;
        foreach ($miscFuelCreditByDriverScope as $creditKey => $scopeCredit) {
            if (strpos($creditKey, $driverId . '|') !== 0 || $scopeCredit <= 0.005 || $leftToApply <= 0.005) {
                continue;
            }
            $scopeApplied = round(min($scopeCredit, $leftToApply), 2);
            $miscFuelCreditAppliedByScope[$creditKey] = round((float)($miscFuelCreditAppliedByScope[$creditKey] ?? 0) + $scopeApplied, 2);
            $leftToApply = round(max(0.0, $leftToApply - $scopeApplied), 2);
        }
    }
    $collectedFuel = round(max(0.0, $totalFuel - $remainingFuel), 2);
    if (!empty($fuelRow['save_balance']) || $miscCreditApplied > 0.005) {
        lonestar_driver_save_fuel_balance($mysqli, (int)$driverId, $remainingFuel, $dashboardWeekStart);
    }
    if ($remainingFuel > 0.005) {
        $fuelExposureRows[] = [
            'driver_id' => (int)$driverId,
            'driver_name' => $fuelRow['driver_name'],
            'total_week_fuel' => $totalFuel,
            'collected' => $collectedFuel,
            'remaining' => $remainingFuel,
        ];
    }
}
usort($fuelExposureRows, static fn($a, $b) => ($b['remaining'] <=> $a['remaining']));

$driverProfitMap = [];
$ownerSummaryMap = [];
foreach ($vendorProfitRows as $row) {
    $driverId = (int)$row['driver_id'];
    if (!isset($driverProfitMap[$driverId])) {
        $driverProfitMap[$driverId] = [
            'driver_name' => $row['driver_name'],
            'gross_total' => 0.0,
            'driver_net' => 0.0,
            'estimated_profit' => 0.0,
        ];
    }
    $driverProfitMap[$driverId]['gross_total'] += (float)$row['gross_total'];
    $driverProfitMap[$driverId]['driver_net'] += (float)$row['driver_net'];
    $driverProfitMap[$driverId]['estimated_profit'] += (float)$row['estimated_profit'];

    $dot = (string)$row['dot_number'];
    if (!isset($ownerSummaryMap[$dot])) {
        $ownerSummaryMap[$dot] = [
            'dot_number' => $dot,
            'owner_name' => (string)$row['owner_name'],
            'owner_email' => (string)$row['owner_email'],
            'drivers' => [],
            'gross_total' => 0.0,
            'driver_net' => 0.0,
            'fuel' => 0.0,
            'remaining_fuel_by_driver' => [],
        ];
    }
    $ownerSummaryMap[$dot]['drivers'][$driverId] = true;
    $ownerSummaryMap[$dot]['gross_total'] += (float)$row['gross_total'];
    $ownerSummaryMap[$dot]['driver_net'] += (float)$row['driver_net'];
    $ownerSummaryMap[$dot]['fuel'] += (float)$row['fuel'];
    $ownerSummaryMap[$dot]['remaining_fuel_by_driver'][$driverId] = max(
        (float)($ownerSummaryMap[$dot]['remaining_fuel_by_driver'][$driverId] ?? 0),
        (float)$row['remaining_fuel_after']
    );
}
foreach ($driverProfitMap as $row) {
    $driverProfitRows[] = [
        'driver_name' => $row['driver_name'],
        'gross_total' => round((float)$row['gross_total'], 2),
        'driver_net' => round((float)$row['driver_net'], 2),
        'estimated_profit' => round((float)$row['estimated_profit'], 2),
    ];
}
usort($driverProfitRows, static fn($a, $b) => ($b['gross_total'] <=> $a['gross_total']));
foreach ($ownerSummaryMap as $row) {
    $row['driver_count'] = count($row['drivers']);
    $row['remaining_fuel'] = array_sum($row['remaining_fuel_by_driver']);
    unset($row['drivers']);
    unset($row['remaining_fuel_by_driver']);
    $ownerSummaryRows[] = $row;
}
usort($ownerSummaryRows, static fn($a, $b) => ($b['driver_net'] <=> $a['driver_net']));

// Build the DOT board as a year-to-date owner roster rather than a current-week
// activity list. Seeding from driver_contacts keeps owners with no YTD payouts
// visible and lets the board call out owners who no longer have active drivers.
$annualOwnerSummaryMap = [];
$annualDriverDots = [];
$res = $mysqli->query(
    "SELECT id,
            TRIM(COALESCE(owner_dot_number, '')) AS dot_number,
            TRIM(COALESCE(owner_name, '')) AS owner_name,
            TRIM(COALESCE(email, '')) AS owner_email,
            COALESCE(is_disabled, 0) AS is_disabled
       FROM driver_contacts
      WHERE TRIM(COALESCE(owner_dot_number, '')) <> ''
         OR TRIM(COALESCE(owner_name, '')) <> ''
      ORDER BY owner_name, dot_number, id"
);
while ($row = $res->fetch_assoc()) {
    $driverId = (int)($row['id'] ?? 0);
    $dotNumber = trim((string)($row['dot_number'] ?? ''));
    $ownerName = trim((string)($row['owner_name'] ?? ''));
    $dotKey = $dotNumber !== '' ? $dotNumber : 'missing|' . strtolower($ownerName !== '' ? $ownerName : (string)$driverId);
    if (!isset($annualOwnerSummaryMap[$dotKey])) {
        $annualOwnerSummaryMap[$dotKey] = [
            'dot_number' => $dotNumber !== '' ? $dotNumber : 'Missing DOT',
            'owner_name' => (string)($row['owner_name'] ?? ''),
            'owner_email' => (string)($row['owner_email'] ?? ''),
            'driver_ids' => [],
            'active_driver_count' => 0,
            'gross_total' => 0.0,
            'driver_net' => 0.0,
            'fuel' => 0.0,
            'remaining_fuel' => 0.0,
        ];
    }
    if ($annualOwnerSummaryMap[$dotKey]['owner_name'] === '' && $ownerName !== '') {
        $annualOwnerSummaryMap[$dotKey]['owner_name'] = (string)$row['owner_name'];
    }
    if ($annualOwnerSummaryMap[$dotKey]['owner_email'] === '' && trim((string)($row['owner_email'] ?? '')) !== '') {
        $annualOwnerSummaryMap[$dotKey]['owner_email'] = (string)$row['owner_email'];
    }
    $annualOwnerSummaryMap[$dotKey]['driver_ids'][$driverId] = true;
    if ((int)($row['is_disabled'] ?? 0) === 0) {
        $annualOwnerSummaryMap[$dotKey]['active_driver_count']++;
    }
    $annualDriverDots[$driverId] = $dotKey;
}
$res->close();

$annualStart = sprintf('%04d-01-01', $currentDashboardYear);
$annualEnd = sprintf('%04d-12-31', $currentDashboardYear);
$annualGrossByDriver = [];
$res = $mysqli->query(
    "SELECT dp.driver_contact_id,
            COALESCE(SUM(dp.tss_pay), 0) AS gross_total
       FROM driver_payouts dp
      WHERE dp.payout_date BETWEEN '{$mysqli->real_escape_string($annualStart)}' AND '{$mysqli->real_escape_string($annualEnd)}'
        AND dp.driver_contact_id IS NOT NULL
        AND dp.driver_contact_id <> 0
        AND {$vendorFilter}
      GROUP BY dp.driver_contact_id"
);
while ($row = $res->fetch_assoc()) {
    $driverId = (int)($row['driver_contact_id'] ?? 0);
    $annualGrossByDriver[$driverId] = (float)($row['gross_total'] ?? 0);
}
$res->close();

$annualFuelChargesByDriver = [];
if (dashboard_table_exists($mysqli, 'driver_gas_costs')) {
    $res = $mysqli->query(
        "SELECT driver_id, COALESCE(SUM(amount), 0) AS fuel_total
           FROM driver_gas_costs
          WHERE cost_date BETWEEN '{$mysqli->real_escape_string($annualStart)}' AND '{$mysqli->real_escape_string($annualEnd)}'
          GROUP BY driver_id"
    );
    while ($row = $res->fetch_assoc()) {
        $annualFuelChargesByDriver[(int)$row['driver_id']] = (float)$row['fuel_total'];
    }
    $res->close();
}

$currentFuelBalanceByDriver = [];
if (dashboard_table_exists($mysqli, 'driver_fuel_balances')) {
    $res = $mysqli->query("SELECT driver_id, COALESCE(balance, 0) AS balance FROM driver_fuel_balances");
    while ($row = $res->fetch_assoc()) {
        $currentFuelBalanceByDriver[(int)$row['driver_id']] = max(0.0, (float)$row['balance']);
    }
    $res->close();
}

$openingFuelBalanceByDriver = [];
if (dashboard_table_exists($mysqli, 'driver_fuel_balance_history')) {
    $res = $mysqli->query(
        "SELECT h.driver_id, h.ending_balance
           FROM driver_fuel_balance_history h
           JOIN (
                SELECT driver_id, MAX(week_start) AS latest_week
                  FROM driver_fuel_balance_history
                 WHERE week_start < '{$mysqli->real_escape_string($annualStart)}'
                 GROUP BY driver_id
           ) latest
             ON latest.driver_id = h.driver_id
            AND latest.latest_week = h.week_start"
    );
    while ($row = $res->fetch_assoc()) {
        $openingFuelBalanceByDriver[(int)$row['driver_id']] = max(0.0, (float)$row['ending_balance']);
    }
    $res->close();
}

foreach ($annualDriverDots as $driverId => $dot) {
    $gross = (float)($annualGrossByDriver[$driverId] ?? 0);
    $fuelCharges = (float)($annualFuelChargesByDriver[$driverId] ?? 0);
    $openingFuel = (float)($openingFuelBalanceByDriver[$driverId] ?? 0);
    $remainingFuel = (float)($currentFuelBalanceByDriver[$driverId] ?? 0);
    $annualOwnerSummaryMap[$dot]['gross_total'] += $gross;
    $annualOwnerSummaryMap[$dot]['fuel'] += max(0.0, $openingFuel + $fuelCharges - $remainingFuel);
    $annualOwnerSummaryMap[$dot]['remaining_fuel'] += $remainingFuel;
}

foreach ($annualOwnerSummaryMap as &$ownerRow) {
    $ownerRow['driver_count'] = count($ownerRow['driver_ids']);
    $ownerRow['gross_total'] = round((float)$ownerRow['gross_total'], 2);
    $ownerRow['driver_net'] = round((float)$ownerRow['driver_net'], 2);
    $ownerRow['fuel'] = round((float)$ownerRow['fuel'], 2);
    $ownerRow['remaining_fuel'] = round((float)$ownerRow['remaining_fuel'], 2);
    unset($ownerRow['driver_ids']);
}
unset($ownerRow);
$ownerSummaryRows = array_values($annualOwnerSummaryMap);
usort($ownerSummaryRows, static function ($a, $b) {
    $activeComparison = ((int)$b['active_driver_count'] <=> (int)$a['active_driver_count']);
    return $activeComparison !== 0 ? $activeComparison : ((float)$b['gross_total'] <=> (float)$a['gross_total']);
});

foreach ($vendorProfitSummary as $row) {
    $trailerRevenueRows[] = [
        'vendor_label' => $row['vendor_label'],
        'trailer_fee' => round((float)$row['trailer_fee'], 2),
    ];
    $fuelSurchargeRows[] = [
        'vendor_label' => $row['vendor_label'],
        'fuel_surcharge' => round((float)$row['fuel_surcharge'], 2),
    ];
}

$seenAdjustmentKeys = [];
if (dashboard_table_exists($mysqli, 'tss_misc_adjustments')) {
    $hasAdjustmentVendorColumn = dashboard_column_exists($mysqli, 'tss_misc_adjustments', 'payout_vendor');
    $adjustVendorExpr = $hasAdjustmentVendorColumn
        ? "CASE WHEN UPPER(TRIM(COALESCE(tma.payout_vendor, 'TSS'))) = 'NEXTIER' THEN 'NexTier' WHEN UPPER(TRIM(COALESCE(tma.payout_vendor, 'TSS'))) = 'RTEX' THEN 'RTEX' WHEN UPPER(TRIM(COALESCE(tma.payout_vendor, 'TSS'))) IN ('NICKELROCK', 'NICKEL ROCK') THEN 'Nickel Rock' ELSE 'TSS' END"
        : "'TSS'";
    $adjustScopeExpr = $hasAdjustmentVendorColumn
        ? "CASE WHEN UPPER(TRIM(COALESCE(tma.payout_vendor, 'TSS'))) = 'NEXTIER' THEN 'nextier' WHEN UPPER(TRIM(COALESCE(tma.payout_vendor, 'TSS'))) = 'RTEX' THEN 'rtex' WHEN UPPER(TRIM(COALESCE(tma.payout_vendor, 'TSS'))) IN ('NICKELROCK', 'NICKEL ROCK') THEN 'nickelrock' ELSE 'tss' END"
        : "'tss'";
    $autoTrailerRentalExpr = $hasAdjustmentVendorColumn
        ? "CASE
              WHEN UPPER(TRIM(COALESCE(tma.payout_vendor, 'TSS'))) = 'NEXTIER'
               AND tma.adjustment_type = 'misc_deduction'
               AND LOWER(COALESCE(tma.comments, '')) LIKE 'auto nextier trailer rental deduction%'
              THEN ABS(tma.amount)
              ELSE 0
           END"
        : "0";
    $res = $mysqli->query(
        "SELECT {$adjustVendorExpr} AS vendor_label,
                {$adjustScopeExpr} AS vendor_scope,
                tma.driver_contact_id,
                COALESCE(NULLIF(CONCAT(dc.first_name, ' ', dc.last_name), ''), 'Unassigned') AS driver_name,
                COALESCE(SUM(CASE WHEN tma.adjustment_type='misc_deduction' THEN ABS(tma.amount) ELSE 0 END),0) AS deductions,
                COALESCE(SUM(CASE WHEN tma.adjustment_type='misc_payment' THEN ABS(tma.amount) ELSE 0 END),0) AS payments,
                COALESCE(SUM(CASE WHEN tma.adjustment_type='write_off' THEN ABS(tma.amount) ELSE 0 END),0) AS write_offs,
                COALESCE(SUM({$autoTrailerRentalExpr}),0) AS trailer_rental_deductions,
                COUNT(*) AS adjustments
           FROM tss_misc_adjustments tma
           LEFT JOIN driver_contacts dc ON dc.id = tma.driver_contact_id
          WHERE tma.payout_week_start = '{$mysqli->real_escape_string($dashboardWeekStart)}'
          GROUP BY vendor_label, vendor_scope, tma.driver_contact_id, driver_name
          ORDER BY vendor_label, driver_name"
    );
    while ($row = $res->fetch_assoc()) {
        $driverId = (int)($row['driver_contact_id'] ?? 0);
        $scope = (string)($row['vendor_scope'] ?? 'tss');
        $key = $driverId . '|' . $scope;
        $seenAdjustmentKeys[$key] = true;
        $currentNet = round((float)$row['payments'] + (float)$row['write_offs'] - (float)$row['deductions'], 2);
        $trailerRentalDeductions = round((float)($row['trailer_rental_deductions'] ?? 0), 2);
        $priorNet = $driverId > 0 ? lonestar_driver_open_misc_balance($mysqli, $driverId, $scope, $dashboardWeekStart) : 0.0;
        $net = round($currentNet + $priorNet, 2);
        $hasPayout = $driverId > 0 && !empty($payoutByDriverVendor[$driverId . '|' . $scope]);
        $appliedToFuel = round((float)($miscFuelCreditAppliedByScope[$key] ?? 0), 2);
        $statementNet = (float)($statementNetByDriverVendor[$key] ?? 0.0);
        $balanceToSave = $hasPayout ? min(0.0, round($statementNet, 2)) : $net;
        if (!$hasPayout && $net > 0.005 && $appliedToFuel > 0.005) {
            $balanceToSave = round(max(0.0, $net - $appliedToFuel), 2);
        }
        $status = $hasPayout ? 'Applied this week' : 'Open';
        if ($hasPayout && $balanceToSave < -0.005) {
            $status = 'Partially applied - balance due';
        }
        if (!$hasPayout && $appliedToFuel > 0.005) {
            $status = $balanceToSave > 0.005 ? 'Partially applied to fuel' : 'Applied to fuel';
        }
        $isManuallyCleared = !empty($balanceTrackerClears['misc|' . $driverId . '|' . $scope]);
        if ($isManuallyCleared) {
            $balanceToSave = 0.0;
            $status = 'Manually cleared';
        }
        if ($driverId > 0) {
            lonestar_driver_save_misc_balance($mysqli, $driverId, $scope, $balanceToSave, $dashboardWeekStart);
        }
        if ($isManuallyCleared) {
            continue;
        }
        $adjustmentRows[] = [
            'vendor_label' => (string)$row['vendor_label'],
            'driver_id' => $driverId,
            'vendor_scope' => $scope,
            'driver_name' => (string)$row['driver_name'],
            'balance_type' => ($trailerRentalDeductions > 0.005 && (float)$row['payments'] <= 0.005 && abs((float)$row['deductions'] - $trailerRentalDeductions) <= 0.005)
                ? 'Trailer Rental Fee'
                : 'Misc Adjustment',
            'deductions' => (float)$row['deductions'],
            'payments' => (float)$row['payments'],
            'prior_balance' => $priorNet,
            'net' => $net,
            'remaining_balance' => $balanceToSave,
            'adjustments' => (int)$row['adjustments'],
            'status' => $status,
        ];
    }
    $res->close();
}

if (dashboard_table_exists($mysqli, 'driver_misc_adjustment_balances')) {
    $res = $mysqli->query(
        "SELECT dmab.driver_id,
                dmab.vendor_scope,
                COALESCE(NULLIF(CONCAT(dc.first_name, ' ', dc.last_name), ''), 'Unassigned') AS driver_name,
                COALESCE(dmab.balance,0) AS balance
           FROM driver_misc_adjustment_balances dmab
           LEFT JOIN driver_contacts dc ON dc.id = dmab.driver_id
          WHERE ABS(COALESCE(dmab.balance,0)) > 0.005
            AND (dmab.last_calculated_week_start IS NULL OR dmab.last_calculated_week_start < '{$mysqli->real_escape_string($dashboardWeekStart)}')
          ORDER BY driver_name"
    );
    while ($row = $res->fetch_assoc()) {
        $driverId = (int)($row['driver_id'] ?? 0);
        $scope = (string)($row['vendor_scope'] ?? 'tss');
        $key = $driverId . '|' . $scope;
        if (isset($seenAdjustmentKeys[$key])) {
            continue;
        }
        $balance = round((float)($row['balance'] ?? 0), 2);
        $hasPayout = $driverId > 0 && !empty($payoutByDriverVendor[$driverId . '|' . $scope]);
        $appliedToFuel = round((float)($miscFuelCreditAppliedByScope[$key] ?? 0), 2);
        $balanceToSave = $balance;
        if ($hasPayout) {
            $statementNet = (float)($statementNetByDriverVendor[$key] ?? 0.0);
            $balanceToSave = min(0.0, round($statementNet, 2));
        } elseif ($balance > 0.005 && $appliedToFuel > 0.005) {
            $balanceToSave = round(max(0.0, $balance - $appliedToFuel), 2);
        }
        $status = $hasPayout ? 'Applied this week' : 'Open';
        if ($hasPayout && $balanceToSave < -0.005) {
            $status = 'Partially applied - balance due';
        }
        if (!$hasPayout && $appliedToFuel > 0.005) {
            $status = $balanceToSave > 0.005 ? 'Partially applied to fuel' : 'Applied to fuel';
        }
        $isManuallyCleared = !empty($balanceTrackerClears['misc|' . $driverId . '|' . $scope]);
        if ($isManuallyCleared) {
            $balanceToSave = 0.0;
            $status = 'Manually cleared';
        }
        if ($hasPayout) {
            lonestar_driver_save_misc_balance($mysqli, $driverId, $scope, $balanceToSave, $dashboardWeekStart);
        } elseif ($appliedToFuel > 0.005) {
            lonestar_driver_save_misc_balance($mysqli, $driverId, $scope, $balanceToSave, $dashboardWeekStart);
        } elseif ($isManuallyCleared) {
            lonestar_driver_save_misc_balance($mysqli, $driverId, $scope, 0.0, $dashboardWeekStart);
        }
        if ($isManuallyCleared) {
            continue;
        }
        $adjustmentRows[] = [
            'vendor_label' => dashboard_vendor_label_from_scope($scope),
            'driver_id' => $driverId,
            'vendor_scope' => $scope,
            'driver_name' => (string)$row['driver_name'],
            'balance_type' => 'Misc Balance',
            'deductions' => $balance < 0 ? abs($balance) : 0.0,
            'payments' => $balance > 0 ? $balance : 0.0,
            'prior_balance' => $balance,
            'net' => $balance,
            'remaining_balance' => $balanceToSave,
            'adjustments' => 0,
            'status' => $status,
        ];
    }
    $res->close();
}

$balanceDriverNameExpr = "COALESCE(NULLIF(CONCAT(dc.first_name, ' ', dc.last_name), ''), CONCAT('Driver #', dc.id))";
if (dashboard_table_exists($mysqli, 'driver_fuel_balances')) {
    $res = $mysqli->query(
        "SELECT dfb.driver_id,
                {$balanceDriverNameExpr} AS driver_name,
                COALESCE(dfb.balance,0) AS balance,
                dfb.last_calculated_week_start,
                dfb.updated_at
           FROM driver_fuel_balances dfb
           JOIN driver_contacts dc ON dc.id = dfb.driver_id
          WHERE COALESCE(dfb.balance,0) > 0.005
          ORDER BY COALESCE(dfb.balance,0) DESC, driver_name"
    );
    while ($row = $res->fetch_assoc()) {
        $driverId = (int)($row['driver_id'] ?? 0);
        if (!empty($balanceTrackerClears['fuel|' . $driverId . '|tss'])) {
            continue;
        }
        $balance = round((float)($row['balance'] ?? 0), 2);
        $outstandingBalanceRows[] = [
            'vendor_label' => 'All Clients',
            'driver_id' => $driverId,
            'driver_name' => (string)($row['driver_name'] ?? ''),
            'balance_type' => 'Fuel Balance',
            'amount' => $balance,
            'status' => 'Driver owes',
            'last_calculated_week_start' => (string)($row['last_calculated_week_start'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
        $outstandingBalanceTotal += abs($balance);
    }
    $res->close();
}

$balanceTrackerTotal = $outstandingBalanceTotal;
foreach ($adjustmentRows as $row) {
    $balanceTrackerTotal += abs((float)($row['remaining_balance'] ?? 0.0));
}
usort($outstandingBalanceRows, static fn($a, $b) => abs((float)$b['amount']) <=> abs((float)$a['amount']));

$needsAttentionQueries = [
    'Unmatched payout rows' => "SELECT COUNT(*) AS cnt FROM driver_payouts WHERE driver_contact_id IS NULL OR driver_contact_id = 0",
    'Drivers missing DOT' => "SELECT COUNT(*) AS cnt FROM driver_contacts WHERE COALESCE(is_disabled,0)=0 AND COALESCE(owner_name,'') <> '' AND COALESCE(owner_dot_number,'') = ''",
    'Drivers missing email' => "SELECT COUNT(*) AS cnt FROM driver_contacts WHERE COALESCE(is_disabled,0)=0 AND COALESCE(email,'') = ''",
];
foreach ($needsAttentionQueries as $label => $sql) {
    $res = $mysqli->query($sql);
    $count = 0;
    if ($row = $res->fetch_assoc()) {
        $count = (int)$row['cnt'];
    }
    $res->close();
    $needsAttention[] = ['label' => $label, 'count' => $count];
}
if (
    dashboard_table_exists($mysqli, 'trailer_assets')
    && dashboard_column_exists($mysqli, 'trailer_assets', 'trailer_number')
    && dashboard_column_exists($mysqli, 'trailer_assets', 'trailer_fee_value')
) {
    $res = $mysqli->query(
        "SELECT COUNT(*) AS cnt
           FROM driver_contacts dc
      LEFT JOIN trailer_assets ta
             ON TRIM(COALESCE(ta.trailer_number,'')) = TRIM(COALESCE(dc.trailer_no,''))
          WHERE COALESCE(dc.is_disabled,0)=0
            AND TRIM(COALESCE(dc.trailer_no,'')) <> ''
            AND LOWER(TRIM(COALESCE(dc.trailer_no,''))) NOT IN ('own','owned','n/a')
            AND (ta.trailer_number IS NULL OR COALESCE(ta.trailer_fee_value,0) = 0)"
    );
    $row = $res->fetch_assoc();
    $needsAttention[] = ['label' => 'Trailers missing fee setup', 'count' => (int)($row['cnt'] ?? 0)];
    $res->close();
}
if (dashboard_column_exists($mysqli, 'driver_contacts', 'trailer_received_date')) {
    $res = $mysqli->query(
        "SELECT COUNT(DISTINCT dc.id) AS cnt
           FROM driver_contacts dc
           JOIN driver_payouts dp ON dp.driver_contact_id = dc.id
          WHERE COALESCE(dc.is_disabled,0)=0
            AND UPPER(TRIM(dp.vendor_name)) = 'NEXTIER'
            AND TRIM(COALESCE(dc.trailer_no,'')) <> ''
            AND LOWER(TRIM(COALESCE(dc.trailer_no,''))) NOT IN ('own','owned','n/a')
            AND COALESCE(dc.trailer_received_date, '') = ''"
    );
    $row = $res->fetch_assoc();
    $needsAttention[] = ['label' => 'NexTier trailers missing received date', 'count' => (int)($row['cnt'] ?? 0)];
    $res->close();
}

$activeDriverRatio = $driverCount > 0 ? round($activeDriverCount / $driverCount * 100, 1) : 0;
$inactiveTotal = $inactiveDriversWithFuelCount + $inactiveDriversWithoutFuelCount;
$inactiveWithFuelRatio = $inactiveTotal > 0 ? round($inactiveDriversWithFuelCount / $inactiveTotal * 100, 1) : 0;
$unmatchedPayoutRatio = $totalPayoutAmount > 0 ? round($unmatchedPayoutAmount / $totalPayoutAmount * 100, 1) : 0;
$recent30DayAverage = $recent30DayPayoutCount > 0 ? round($recent30DayPayoutAmount / $recent30DayPayoutCount, 2) : 0;

$weeklyLabels = array_map(fn($row) => $row['week_start'], $weeklyTotals);
$weeklyValues = array_map(fn($row) => $row['total'], $weeklyTotals);
$vendorLabels = array_map(fn($row) => $row['vendor_label'], $vendorTotals);
$vendorValues = array_map(fn($row) => $row['total'], $vendorTotals);
$weeklyVendorLabels = array_values(array_unique(array_map(fn($row) => $row['week_start'], $weeklyVendorRows)));
foreach ($weeklyLabels as $label) {
    if (!in_array($label, $weeklyVendorLabels, true)) {
        $weeklyVendorLabels[] = $label;
    }
}
sort($weeklyVendorLabels);
$weeklyVendorNames = array_values(array_unique(array_map(fn($row) => $row['vendor_label'], $weeklyVendorRows)));
$weeklyVendorPalette = ['#0d6efd', '#198754', '#fd7e14', '#6f42c1', '#20c997', '#dc3545', '#6c757d'];
$weeklyVendorDatasets = [];
foreach ($weeklyVendorNames as $idx => $vendorName) {
    $values = [];
    foreach ($weeklyVendorLabels as $weekLabel) {
        $sum = 0.0;
        foreach ($weeklyVendorRows as $row) {
            if ($row['week_start'] === $weekLabel && $row['vendor_label'] === $vendorName) {
                $sum += (float)$row['total'];
            }
        }
        $values[] = $sum;
    }
    $color = $weeklyVendorPalette[$idx % count($weeklyVendorPalette)];
    $weeklyVendorDatasets[] = [
        'label' => $vendorName,
        'data' => $values,
        'borderColor' => $color,
        'backgroundColor' => $color,
        'tension' => 0.35,
        'pointRadius' => 3,
    ];
}
$typeLabels = array_map(fn($row) => $row['label'], $driverTypeCounts);
$typeValues = array_map(fn($row) => $row['count'], $driverTypeCounts);
$weeklyFuelUsageLabels = array_map(fn($row) => $row['week_start'], $weeklyFuelUsageRows);
$weeklyFuelUsageValues = array_map(fn($row) => round((float)$row['total_usage'], 2), $weeklyFuelUsageRows);
$annualProfitabilityLabels = array_map(fn($row) => $row['vendor_label'], $vendorAnnualProfitabilityRows);
$annualProfitabilityMargins = array_map(fn($row) => (float)$row['margin_pct'], $vendorAnnualProfitabilityRows);
$annualProfitabilityGross = array_map(fn($row) => (float)$row['company_gross'], $vendorAnnualProfitabilityRows);
$annualProfitabilityProfit = array_map(fn($row) => (float)$row['total_profit'], $vendorAnnualProfitabilityRows);
$trailerVendorLabels = array_map(fn($row) => $row['vendor_label'], $trailerRevenueRows);
$trailerVendorValues = array_map(fn($row) => round((float)$row['trailer_fee'], 2), $trailerRevenueRows);
$fscVendorLabels = array_map(fn($row) => $row['vendor_label'], $fuelSurchargeRows);
$fscVendorValues = array_map(fn($row) => round((float)$row['fuel_surcharge'], 2), $fuelSurchargeRows);
$vendorMixLabels = $weeklyVendorLabels;
$vendorMixDatasets = [];
foreach ($weeklyVendorNames as $idx => $vendorName) {
    $values = [];
    foreach ($vendorMixLabels as $weekLabel) {
        $weekTotal = 0.0;
        $vendorTotal = 0.0;
        foreach ($weeklyVendorRows as $row) {
            if ($row['week_start'] === $weekLabel) {
                $weekTotal += (float)$row['total'];
                if ($row['vendor_label'] === $vendorName) {
                    $vendorTotal += (float)$row['total'];
                }
            }
        }
        $values[] = $weekTotal > 0 ? round($vendorTotal / $weekTotal * 100, 1) : 0;
    }
    $color = $weeklyVendorPalette[$idx % count($weeklyVendorPalette)];
    $vendorMixDatasets[] = [
        'label' => $vendorName,
        'data' => $values,
        'borderColor' => $color,
        'backgroundColor' => $color,
        'tension' => 0.35,
        'pointRadius' => 3,
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    body { margin:0; font-family:sans-serif; background:#f4f6fb; }
    .page-shell { display:flex; min-height: calc(100vh - var(--banner-h)); }
    .sidebar { width:250px; background:#333; color:#fff; height:calc(100vh - var(--banner-h)); position:fixed; top:var(--banner-h); overflow:auto; transition:transform .3s ease; }
    .sidebar.collapsed { transform: translateX(-250px); }
    .sidebar a { display:block; color:#fff; padding:15px; text-decoration:none; }
    .sidebar a:hover { background:#444; }
    .main { margin-left:250px; padding:20px; flex:1; min-width:0; }
    .sidebar.collapsed + .main { margin-left:0; }
    @media(max-width:768px) {
      .sidebar { transform:translateX(-250px); }
      .sidebar.open { transform: translateX(0); }
      .main { margin:0; }
    }
    .metric-card { min-height: 140px; }
    .metric-card .metric-value { font-size: 2rem; font-weight: 700; }
    .metric-card .metric-label { color: #6c757d; }
    .chart-card { min-height: 320px; }
    .drivers-week-chart { width: 50%; min-height: 160px; margin: 0 auto; }
    .table-card { background: #fff; border-radius: 1rem; padding: 1rem; box-shadow: 0 0 30px rgba(0,0,0,.05); }
    .dashboard-header { margin-bottom: 1.5rem; }
    .dashboard-header h1 { margin-bottom: 0.25rem; }
    .dashboard-box { background:#fff; border-radius:1rem; padding:1.25rem; box-shadow:0 12px 26px rgba(0,0,0,.05); }
    .gauge-label { font-size:.9rem; color:#6c757d; }
    .gauge-value { font-size:1.6rem; font-weight:700; }
    .gauge-bar { height: 14px; border-radius: 999px; overflow:hidden; background:#e9ecef; }
    .gauge-fill { height: 100%; border-radius: 999px; }
    .card-small { background:#fff; border-radius:1rem; padding:1rem; box-shadow:0 10px 20px rgba(0,0,0,.04); }
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main">
      <div class="dashboard-header">
        <h1>Operations Dashboard</h1>
        <form method="post" action="dashboard.php" class="mb-2">
          <input type="hidden" name="action" value="rebuild_profitability">
          <input type="hidden" name="rebuild_token" value="<?= h(hash('sha256', 'dashboard-backfill|' . session_id())) ?>">
          <button class="btn btn-sm btn-outline-secondary" type="submit">Rebuild missing annual profitability</button>
        </form>
        <p class="text-muted">Live snapshot of driver, payout, and owner metrics across the system.</p>
      </div>
      <?php if ($balanceTrackerMessage !== '' && (string)($_GET['refresh_balances'] ?? '') !== '1'): ?>
        <div class="alert alert-<?= h($balanceTrackerMessageType) ?> mb-3">
          <?= h($balanceTrackerMessage) ?>
        </div>
      <?php endif; ?>

      <div class="row g-3 mb-4">
        <div class="col-lg-6">
          <div class="dashboard-box h-100">
            <h2 class="h5 mb-3">Client Summary - 2026</h2>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Client</th>
                    <th class="text-end">Loads</th>
                    <th class="text-end">Drivers</th>
                    <th class="text-end">Avg Driver Load</th>
                    <th class="text-end">Total</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($vendorTotals): ?>
                    <?php foreach ($vendorTotals as $vendor): ?>
                      <tr>
                        <td><?= h($vendor['vendor_label']) ?></td>
                        <td class="text-end"><?= (int)($vendor['loads'] ?? $vendor['payouts']) ?></td>
                        <td class="text-end"><?= (int)$vendor['drivers'] ?></td>
                        <td class="text-end"><?= money((float)($vendor['avg_driver_load'] ?? 0)) ?></td>
                        <td class="text-end"><?= money((float)$vendor['total']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr><td colspan="5" class="text-muted">No client payout data available.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="dashboard-box h-100">
            <h2 class="h5 mb-1">Client Summary - Week</h2>
            <p class="text-muted small mb-3"><?= h($dashboardWeekStart) ?> to <?= h($dashboardWeekEnd) ?></p>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Client</th>
                    <th class="text-end">Loads</th>
                    <th class="text-end">Drivers</th>
                    <th class="text-end">Avg Driver Load</th>
                    <th class="text-end">Total</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($weeklyVendorTotals): ?>
                    <?php foreach ($weeklyVendorTotals as $vendor): ?>
                      <tr>
                        <td><?= h($vendor['vendor_label']) ?></td>
                        <td class="text-end"><?= (int)($vendor['loads'] ?? $vendor['payouts']) ?></td>
                        <td class="text-end"><?= (int)$vendor['drivers'] ?></td>
                        <td class="text-end"><?= money((float)($vendor['avg_driver_load'] ?? 0)) ?></td>
                        <td class="text-end"><?= money((float)$vendor['total']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr><td colspan="5" class="text-muted">No client payout data available for this week.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3" id="driver-status-row">
        <div class="col-xl-4 d-none">
          <div class="dashboard-box h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                <h2 class="h6 mb-1">Drivers Worked Today</h2>
                <p class="text-muted mb-0">Active drivers vs total roster.</p>
              </div>
            </div>
            <div class="chart-card">
              <canvas id="driversWorkedChart"></canvas>
            </div>
          </div>
        </div>
        <div class="col-xl-6">
          <div class="dashboard-box h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                <h2 class="h6 mb-1">Drivers Worked This Week</h2>
                <p class="text-muted mb-0">Last 7 days vs total roster.</p>
              </div>
            </div>
            <div class="chart-card drivers-week-chart">
              <canvas id="driversWorkedWeekChart"></canvas>
            </div>
          </div>
        </div>
        <div class="col-xl-6">
          <div class="dashboard-box">
            <h2 class="h6 mb-3">Inactive Drivers with Active Fuel</h2>
            <div class="table-responsive">
              <table class="table table-borderless mb-0">
                <thead>
                  <tr>
                    <th>Driver</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($inactiveDriversWithFuel): ?>
                    <?php foreach ($inactiveDriversWithFuel as $driver): ?>
                      <tr>
                        <td><span class="badge bg-warning text-dark me-2">Active Fuel</span><?= h($driver['driver_name']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr><td class="text-muted">All inactive drivers have no active fuel cards.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <!--<div class="col-xl-4">
          <div class="dashboard-box">
            <h2 class="h6 mb-3">Top Owners by Payout</h2>
            <div class="table-responsive">
              <table class="table table-borderless mb-0">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th class="text-end">Amount</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($topOwners): ?>
                    <?php foreach ($topOwners as $owner): ?>
                      <tr>
                        <td><?= h($owner['owner_name']) ?></td>
                        <td class="text-end"><?= money($owner['total']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr><td colspan="2" class="text-muted">No owner payout data available.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="col-xl-4">
          <div class="dashboard-box">
            <h2 class="h6 mb-3">Top Drivers by Payout</h2>
            <div class="table-responsive">
              <table class="table table-borderless mb-0">
                <thead>
                  <tr>
                    <th>Driver</th>
                    <th class="text-end">Amount</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($topDrivers): ?>
                    <?php foreach ($topDrivers as $driver): ?>
                      <tr>
                        <td><?= h($driver['driver_name']) ?></td>
                        <td class="text-end"><?= money($driver['total']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr><td colspan="2" class="text-muted">No driver payout data available.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>-->
        
      </div>
<br />
      <div class="row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3 mb-3">
       <!-- <div class="col">
          <div class="card card-small">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <div>
                <div class="text-uppercase text-muted small">Active Drivers</div>
                <div class="metric-value"><?= $activeDriverCount ?></div>
              </div>
              <div class="text-end text-success"><?= $activeDriverRatio ?>%</div>
            </div>
            <div class="gauge-label">of total drivers (<?= $driverCount ?>)</div>
            <div class="gauge-bar mt-2"><div class="gauge-fill" style="width: <?= $activeDriverRatio ?>%; background:#0d6efd;"></div></div>
          </div>
        </div>-->
        <div class="col">
          <div class="card card-small">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <div>
                <div class="text-uppercase text-muted small">Owner Operators</div>
                <div class="metric-value"><?= $ownerCount ?></div>
              </div>
              <div class="text-end text-warning"><?= $missingDotCount ?> missing DOT</div>
            </div>
            <div class="gauge-label">drivers without an owner DOT number</div>
            <div class="gauge-bar mt-2"><div class="gauge-fill" style="width: <?= $driverCount ? round(($driverCount - $missingDotCount) / $driverCount * 100, 1) : 0 ?>%; background:#198754;"></div></div>
          </div>
        </div>
        <div class="col d-none">
          <div class="card card-small">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <div>
                <div class="text-uppercase text-muted small">Total Payouts</div>
                <div class="metric-value"><?= money($totalPayoutAmount) ?></div>
              </div>
              <div class="text-end text-danger"><?= $unmatchedPayoutRatio ?>% unmatched</div>
            </div>
            <div class="gauge-label">payout dollars without a driver link</div>
            <div class="gauge-bar mt-2"><div class="gauge-fill" style="width: <?= min(100, $unmatchedPayoutRatio) ?>%; background:#dc3545;"></div></div>
          </div>
        </div>
        <div class="col">
          <div class="card card-small">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <div>
                <div class="text-uppercase text-muted small">Inactive (3+ Days)</div>
                <div class="metric-value"><?= $inactiveTotal ?></div>
              </div>
              <div class="text-end text-warning"><?= $inactiveDriversWithFuelCount ?> with fuel</div>
            </div>
            <div class="gauge-label">active drivers with no recent loads</div>
            <div class="gauge-bar mt-2"><div class="gauge-fill" style="width: <?= $inactiveWithFuelRatio ?>%; background:#ff9800;"></div></div>
          </div>
        </div>
        <div class="col d-none">
          <div class="card card-small">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <div>
                <div class="text-uppercase text-muted small">Last 30 Days</div>
                <div class="metric-value"><?= money($recent30DayPayoutAmount) ?></div>
              </div>
              <div class="text-end text-secondary"><?= $recent30DayPayoutCount ?> payouts</div>
            </div>
            <div class="gauge-label">avg. <?= money($recent30DayAverage) ?> per payout</div>
            <div class="gauge-bar mt-2"><div class="gauge-fill" style="width: <?= $recent30DayPayoutAmount && $totalPayoutAmount ? min(100, round($recent30DayPayoutAmount / $totalPayoutAmount * 100, 1)) : 0 ?>%; background:#6610f2;"></div></div>
          </div>
        </div>
      </div>
<br />
      <div class="row g-3 mb-4" id="attention-profitability-row">
        <div class="col-lg-6">
          <div class="dashboard-box h-100">
            <h2 class="h5 mb-3">Needs Attention</h2>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <tbody>
                  <?php foreach ($needsAttention as $item): ?>
                    <tr>
                      <td><?= h($item['label']) ?></td>
                      <td class="text-end">
                        <span class="badge <?= ((int)$item['count'] > 0) ? 'bg-warning text-dark' : 'bg-success' ?>">
                          <?= (int)$item['count'] ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="dashboard-box h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                <h2 class="h5 mb-1">Client Profitability</h2>
                <p class="text-muted mb-0"><?= h($dashboardWeekStart) ?> to <?= h($dashboardWeekEnd) ?></p>
              </div>
            </div>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Client</th>
                    <th class="text-end">Company Gross</th>
                    <th class="text-end">Driver Net</th>
                    <th class="text-end">Fuel</th>
                    <th class="text-end">Broker Fee</th>
                    <th class="text-end">Misc. Revenue</th>
                    <th class="text-end">Total Profit</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($vendorProfitSummary): ?>
                    <?php foreach ($vendorProfitSummary as $row): ?>
                      <tr>
                        <td><?= h($row['vendor_label']) ?></td>
                        <td class="text-end"><?= money((float)$row['gross_total']) ?></td>
                        <td class="text-end"><?= money((float)$row['driver_net']) ?></td>
                        <td class="text-end"><?= money((float)$row['fuel']) ?></td>
                        <td class="text-end"><?= money((float)$row['broker_fee']) ?></td>
                        <td class="text-end"><?= money((float)$row['misc_adjustment']) ?></td>
                        <td class="text-end fw-semibold"><?= money((float)$row['estimated_profit']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr><td colspan="7" class="text-muted">No current week client profitability data available.</td></tr>
                  <?php endif; ?>
                </tbody>
                <?php if ($vendorProfitSummary): ?>
                  <tfoot>
                    <tr class="table-light fw-bold">
                      <th>Total</th>
                      <th class="text-end"><?= money(array_sum(array_map(static fn($row) => (float)$row['gross_total'], $vendorProfitSummary))) ?></th>
                      <th class="text-end"><?= money(array_sum(array_map(static fn($row) => (float)$row['driver_net'], $vendorProfitSummary))) ?></th>
                      <th class="text-end"><?= money(array_sum(array_map(static fn($row) => (float)$row['fuel'], $vendorProfitSummary))) ?></th>
                      <th class="text-end"><?= money(array_sum(array_map(static fn($row) => (float)$row['broker_fee'], $vendorProfitSummary))) ?></th>
                      <th class="text-end"><?= money(array_sum(array_map(static fn($row) => (float)$row['misc_adjustment'], $vendorProfitSummary))) ?></th>
                      <th class="text-end"><?= money(array_sum(array_map(static fn($row) => (float)$row['estimated_profit'], $vendorProfitSummary))) ?></th>
                    </tr>
                  </tfoot>
                <?php endif; ?>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="dashboard-box mb-4" id="weekly-fuel-usage-board">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h2 class="h5 mb-1">Weekly Fuel Card Usage</h2>
            <p class="text-muted mb-0">Total Amt + Fees Amt by week for the last six months.</p>
          </div>
        </div>
        <div class="chart-card">
          <canvas id="weeklyFuelUsageChart"></canvas>
        </div>
      </div>

      <div class="dashboard-box mb-4" id="annual-vendor-profitability-board">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h2 class="h5 mb-1">Client Profitability Margin - <?= $currentDashboardYear ?></h2>
            <p class="text-muted mb-0">Full-year client revenue and profit through the latest saved payout week, ranked by total profit.</p>
          </div>
        </div>
        <?php if ($vendorAnnualProfitabilityRows): ?>
          <div class="chart-card">
            <canvas id="annualVendorProfitabilityChart"></canvas>
          </div>
          <div class="table-responsive mt-3">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Rank</th>
                  <th>Client</th>
                  <th class="text-end">Year Revenue</th>
                  <th class="text-end">Year Profit</th>
                  <th class="text-end">Year Margin</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($vendorAnnualProfitabilityRows as $rank => $row): ?>
                  <tr>
                    <td><?= $rank + 1 ?></td>
                    <td><?= h((string)$row['vendor_label']) ?></td>
                    <td class="text-end"><?= money((float)$row['company_gross']) ?></td>
                    <td class="text-end fw-semibold <?= (float)$row['total_profit'] < 0 ? 'text-danger' : 'text-success' ?>"><?= money((float)$row['total_profit']) ?></td>
                    <td class="text-end"><?= number_format((float)$row['margin_pct'], 2) ?>%</td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="text-muted mb-0">No Owner Payout profitability summaries are available for <?= $currentDashboardYear ?>.</p>
        <?php endif; ?>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-lg-4">
          <div class="dashboard-box h-100">
            <h2 class="h5 mb-3">Fuel Exposure</h2>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Driver</th>
                    <th class="text-end">Fuel</th>
                    <th class="text-end">Collected</th>
                    <th class="text-end">Remaining</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($fuelExposureRows): ?>
                    <?php foreach (array_slice($fuelExposureRows, 0, 8) as $row): ?>
                      <tr>
                        <td><?= h($row['driver_name']) ?></td>
                        <td class="text-end"><?= money((float)$row['total_week_fuel']) ?></td>
                        <td class="text-end"><?= money((float)$row['collected']) ?></td>
                        <td class="text-end text-danger fw-semibold"><?= money((float)$row['remaining']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr><td colspan="4" class="text-muted">No remaining fuel exposure found for the current payout week.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="col-lg-8">
          <div class="dashboard-box h-100" id="balance-tracker">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                <h2 class="h5 mb-1">Balance Tracker</h2>
                <p class="text-muted mb-0">Fuel exposure plus misc adjustment balances across all clients.</p>
              </div>
              <div class="d-flex align-items-center gap-2">
                <a href="dashboard.php?refresh_balances=1#balance-tracker" class="btn btn-sm btn-outline-primary">Refresh</a>
                <div class="text-end fw-semibold"><?= money($balanceTrackerTotal) ?></div>
              </div>
            </div>
            <?php if ((string)($_GET['refresh_balances'] ?? '') === '1' && $balanceTrackerMessage !== ''): ?>
              <div class="alert alert-<?= h($balanceTrackerMessageType) ?> py-2 mb-3" role="status">
                <?= h($balanceTrackerMessage) ?>
              </div>
            <?php endif; ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Client</th>
                    <th>Driver</th>
                    <th>Type</th>
                    <th class="text-end">Payments</th>
                    <th class="text-end">Deductions</th>
                    <th class="text-end">Prior</th>
                    <th class="text-end">Remaining</th>
                    <th>Status</th>
                    <th>Last Week</th>
                    <th class="text-end">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($outstandingBalanceRows || $adjustmentRows): ?>
                    <?php foreach ($outstandingBalanceRows as $row): ?>
                      <tr>
                        <td><?= h($row['vendor_label']) ?></td>
                        <td><?= h($row['driver_name']) ?></td>
                        <td><?= h($row['balance_type']) ?></td>
                        <td class="text-end">
                          <span class="text-muted">-</span>
                        </td>
                        <td class="text-end"><?= money(abs((float)$row['amount'])) ?></td>
                        <td class="text-end"><span class="text-muted">-</span></td>
                        <td class="text-end text-danger fw-semibold"><?= money(abs((float)$row['amount'])) ?></td>
                        <td><span class="badge bg-warning text-dark"><?= h($row['status']) ?></span></td>
                        <td><?= h($row['last_calculated_week_start'] !== '' ? $row['last_calculated_week_start'] : '-') ?></td>
                        <td class="text-end">
                          <form method="post" class="d-inline" onsubmit="return confirm('Clear this fuel balance from the tracker?');">
                            <input type="hidden" name="action" value="clear_balance_tracker">
                            <input type="hidden" name="balance_type" value="fuel">
                            <input type="hidden" name="driver_id" value="<?= (int)($row['driver_id'] ?? 0) ?>">
                            <input type="hidden" name="vendor_scope" value="tss">
                            <input type="hidden" name="week_start" value="<?= h($dashboardWeekStart) ?>">
                            <input type="hidden" name="clear_token" value="<?= h(dashboard_balance_clear_token('fuel', (int)($row['driver_id'] ?? 0), 'tss', $dashboardWeekStart)) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Clear</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <?php foreach ($adjustmentRows as $row): ?>
                      <?php
                        $remainingBalance = (float)($row['remaining_balance'] ?? 0.0);
                        $status = (string)($row['status'] ?? '');
                        $statusClass = ($status === 'Open' || strpos($status, 'Partially') === 0) ? 'bg-warning text-dark' : 'bg-success';
                        $remainingClass = $remainingBalance < -0.005 ? 'text-danger' : ($remainingBalance > 0.005 ? 'text-success' : 'text-muted');
                      ?>
                      <tr>
                        <td><?= h($row['vendor_label']) ?></td>
                        <td><?= h($row['driver_name']) ?></td>
                        <td><?= h($row['balance_type']) ?></td>
                        <td class="text-end text-success"><?= money((float)$row['payments']) ?></td>
                        <td class="text-end text-danger"><?= money((float)$row['deductions']) ?></td>
                        <td class="text-end"><?= money((float)$row['prior_balance']) ?></td>
                        <td class="text-end fw-semibold <?= $remainingClass ?>"><?= money(abs($remainingBalance)) ?></td>
                        <td><span class="badge <?= $statusClass ?>"><?= h($status) ?></span></td>
                        <td><span class="text-muted">-</span></td>
                        <td class="text-end">
                          <?php if (abs($remainingBalance) > 0.005 && (int)($row['driver_id'] ?? 0) > 0): ?>
                            <?php $rowScope = lonestar_payout_normalize_vendor_scope((string)($row['vendor_scope'] ?? 'tss')); ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Clear this misc balance from the tracker?');">
                              <input type="hidden" name="action" value="clear_balance_tracker">
                              <input type="hidden" name="balance_type" value="misc">
                              <input type="hidden" name="driver_id" value="<?= (int)$row['driver_id'] ?>">
                              <input type="hidden" name="vendor_scope" value="<?= h($rowScope) ?>">
                              <input type="hidden" name="week_start" value="<?= h($dashboardWeekStart) ?>">
                              <input type="hidden" name="clear_token" value="<?= h(dashboard_balance_clear_token('misc', (int)$row['driver_id'], $rowScope, $dashboardWeekStart)) ?>">
                              <button type="submit" class="btn btn-sm btn-outline-secondary">Clear</button>
                            </form>
                          <?php else: ?>
                            <span class="text-muted">-</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr><td colspan="10" class="text-muted">No fuel exposure or misc adjustment balances found.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-lg-12">
          <div class="dashboard-box h-100">
            <h2 class="h5 mb-3">Client Mix Over Time</h2>
            <div class="chart-card">
              <canvas id="vendorMixChart"></canvas>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-lg-7">
          <div class="dashboard-box h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                <h2 class="h5 mb-1">Weekly Payout Trend by Client</h2>
                <p class="text-muted mb-0">Rolling 8-week summary across all client payout history.</p>
              </div>
            </div>
            <div class="chart-card">
              <canvas id="weeklyPayoutChart"></canvas>
            </div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="dashboard-box h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                <h2 class="h5 mb-1">Driver Mix by Type</h2>
                <p class="text-muted mb-0">Breakdown of current driver roster.</p>
              </div>
            </div>
            <div class="chart-card">
              <canvas id="driverTypeChart"></canvas>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-lg-6">
          <div class="dashboard-box h-100">
            <h2 class="h5 mb-3">Top Drivers by Gross</h2>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Driver</th>
                    <th class="text-end">Gross</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($driverProfitRows): ?>
                    <?php foreach (array_slice($driverProfitRows, 0, 8) as $row): ?>
                      <tr>
                        <td class="<?= (float)$row['driver_net'] < 0 ? 'text-danger fw-bold' : '' ?>"><?= h($row['driver_name']) ?></td>
                        <td class="text-end"><?= money((float)$row['gross_total']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr><td colspan="2" class="text-muted">No driver payout data available for the current payout week.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="dashboard-box h-100">
            <h2 class="h5 mb-3">Bottom Drivers by Gross</h2>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Driver</th>
                    <th class="text-end">Gross</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($driverProfitRows): ?>
                    <?php foreach (array_slice(array_reverse($driverProfitRows), 0, 8) as $row): ?>
                      <tr>
                        <td class="<?= (float)$row['driver_net'] < 0 ? 'text-danger fw-bold' : '' ?>"><?= h($row['driver_name']) ?></td>
                        <td class="text-end"><?= money((float)$row['gross_total']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr><td colspan="2" class="text-muted">No driver payout data available for the current payout week.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-lg-12">
          <div class="dashboard-box h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                <h2 class="h5 mb-1">Payout Mix by Client</h2>
                <p class="text-muted mb-0">Total payout dollars grouped by client.</p>
              </div>
            </div>
            <div class="chart-card">
              <canvas id="vendorPayoutChart"></canvas>
            </div>
          </div>
        </div>
        <div class="col-lg-4 d-none">
          <div class="dashboard-box h-100">
            <h2 class="h5 mb-3">Client Summary - 2026</h2>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Client</th>
                    <th class="text-end">Loads</th>
                    <th class="text-end">Drivers</th>
                    <th class="text-end">Avg Driver Load</th>
                    <th class="text-end">Total</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($vendorTotals): ?>
                    <?php foreach ($vendorTotals as $vendor): ?>
                      <tr>
                        <td><?= h($vendor['vendor_label']) ?></td>
                        <td class="text-end"><?= (int)($vendor['loads'] ?? $vendor['payouts']) ?></td>
                        <td class="text-end"><?= (int)$vendor['drivers'] ?></td>
                        <td class="text-end"><?= money((float)($vendor['avg_driver_load'] ?? 0)) ?></td>
                        <td class="text-end"><?= money((float)$vendor['total']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr><td colspan="5" class="text-muted">No client payout data available.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="col-lg-4 d-none">
          <div class="dashboard-box h-100">
            <h2 class="h5 mb-1">Client Summary - Week</h2>
            <p class="text-muted small mb-3"><?= h($dashboardWeekStart) ?> to <?= h($dashboardWeekEnd) ?></p>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Client</th>
                    <th class="text-end">Loads</th>
                    <th class="text-end">Drivers</th>
                    <th class="text-end">Avg Driver Load</th>
                    <th class="text-end">Total</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($weeklyVendorTotals): ?>
                    <?php foreach ($weeklyVendorTotals as $vendor): ?>
                      <tr>
                        <td><?= h($vendor['vendor_label']) ?></td>
                        <td class="text-end"><?= (int)($vendor['loads'] ?? $vendor['payouts']) ?></td>
                        <td class="text-end"><?= (int)$vendor['drivers'] ?></td>
                        <td class="text-end"><?= money((float)($vendor['avg_driver_load'] ?? 0)) ?></td>
                        <td class="text-end"><?= money((float)$vendor['total']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr><td colspan="5" class="text-muted">No client payout data available for this week.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="dashboard-box mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <h2 class="h5 mb-0">Owner Operator Summary by DOT &mdash; <?= (int)$currentDashboardYear ?> YTD</h2>
          <span class="small text-muted"><span class="badge bg-danger me-1">No active drivers</span> rows need roster review</span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>DOT</th>
                <th>Owner</th>
                <th>Email</th>
                <th class="text-end">Active / Total Drivers</th>
                <th class="text-end">YTD Gross</th>
                <th class="text-end">YTD Fuel Deducted</th>
                <th class="text-end">Current Fuel Remaining</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($ownerSummaryRows): ?>
                <?php foreach ($ownerSummaryRows as $row): ?>
                  <tr class="<?= (int)$row['active_driver_count'] === 0 ? 'table-danger' : '' ?>">
                    <td><?= h($row['dot_number']) ?></td>
                    <td><?= h($row['owner_name']) ?></td>
                    <td><?= h($row['owner_email']) ?></td>
                    <td class="text-end">
                      <?= (int)$row['active_driver_count'] ?> / <?= (int)$row['driver_count'] ?>
                      <?php if ((int)$row['active_driver_count'] === 0): ?>
                        <span class="badge bg-danger ms-1">Inactive</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end"><?= money((float)$row['gross_total']) ?></td>
                    <td class="text-end"><?= money((float)$row['fuel']) ?></td>
                    <td class="text-end"><?= money((float)$row['remaining_fuel']) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="7" class="text-muted">No owner-operator DOT records are available.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>

  <script id="dashboardCharts">
    const weeklyLabels = <?= json_encode($weeklyLabels, JSON_HEX_TAG) ?>;
    const weeklyValues = <?= json_encode($weeklyValues, JSON_HEX_TAG) ?>;
    const weeklyVendorLabels = <?= json_encode($weeklyVendorLabels, JSON_HEX_TAG) ?>;
    const weeklyVendorDatasets = <?= json_encode($weeklyVendorDatasets, JSON_HEX_TAG) ?>;
    const vendorLabels = <?= json_encode($vendorLabels, JSON_HEX_TAG) ?>;
    const vendorValues = <?= json_encode($vendorValues, JSON_HEX_TAG) ?>;
    const trailerVendorLabels = <?= json_encode($trailerVendorLabels, JSON_HEX_TAG) ?>;
    const trailerVendorValues = <?= json_encode($trailerVendorValues, JSON_HEX_TAG) ?>;
    const fscVendorLabels = <?= json_encode($fscVendorLabels, JSON_HEX_TAG) ?>;
    const fscVendorValues = <?= json_encode($fscVendorValues, JSON_HEX_TAG) ?>;
    const vendorMixLabels = <?= json_encode($vendorMixLabels, JSON_HEX_TAG) ?>;
    const vendorMixDatasets = <?= json_encode($vendorMixDatasets, JSON_HEX_TAG) ?>;
    const typeLabels = <?= json_encode($typeLabels, JSON_HEX_TAG) ?>;
    const typeValues = <?= json_encode($typeValues, JSON_HEX_TAG) ?>;
    const weeklyFuelUsageLabels = <?= json_encode($weeklyFuelUsageLabels, JSON_HEX_TAG) ?>;
    const weeklyFuelUsageValues = <?= json_encode($weeklyFuelUsageValues, JSON_HEX_TAG) ?>;
    const annualProfitabilityLabels = <?= json_encode($annualProfitabilityLabels, JSON_HEX_TAG) ?>;
    const annualProfitabilityMargins = <?= json_encode($annualProfitabilityMargins, JSON_HEX_TAG) ?>;
    const annualProfitabilityGross = <?= json_encode($annualProfitabilityGross, JSON_HEX_TAG) ?>;
    const annualProfitabilityProfit = <?= json_encode($annualProfitabilityProfit, JSON_HEX_TAG) ?>;
    const driversWorkedToday = <?= $driversWorkedTodayCount ?>;
    const driversWorkedThisWeek = <?= $driversWorkedThisWeekCount ?>;
    const totalDrivers = <?= $activeDriverCount ?>;

    const driverStatusRow = document.getElementById('driver-status-row');
    const attentionProfitabilityRow = document.getElementById('attention-profitability-row');
    const weeklyFuelUsageBoard = document.getElementById('weekly-fuel-usage-board');
    const annualVendorProfitabilityBoard = document.getElementById('annual-vendor-profitability-board');
    if (driverStatusRow && attentionProfitabilityRow) {
      driverStatusRow.insertAdjacentElement('afterend', attentionProfitabilityRow);
      if (weeklyFuelUsageBoard) {
        attentionProfitabilityRow.insertAdjacentElement('afterend', weeklyFuelUsageBoard);
        if (annualVendorProfitabilityBoard) {
          weeklyFuelUsageBoard.insertAdjacentElement('afterend', annualVendorProfitabilityBoard);
        }
      }
    }

    const ctxWeeklyFuelUsage = document.getElementById('weeklyFuelUsageChart');
    if (ctxWeeklyFuelUsage) {
      new Chart(ctxWeeklyFuelUsage, {
        type: 'bar',
        data: {
          labels: weeklyFuelUsageLabels,
          datasets: [{
            label: 'Fuel Card Usage',
            data: weeklyFuelUsageValues,
            backgroundColor: '#0d6efd',
            borderColor: '#0b5ed7',
            borderWidth: 1,
            borderRadius: 6,
            maxBarThickness: 34,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: context => '$' + Number(context.parsed.y || 0).toLocaleString(undefined, {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2
                })
              }
            }
          },
          scales: {
            x: { grid: { display: false } },
            y: {
              beginAtZero: true,
              ticks: { callback: value => '$' + Number(value).toLocaleString() }
            }
          }
        }
      });
    }

    const ctxAnnualProfitability = document.getElementById('annualVendorProfitabilityChart');
    if (ctxAnnualProfitability) {
      const profitabilityColors = annualProfitabilityMargins.map(value =>
        Number(value) >= 0 ? '#198754' : '#dc3545'
      );
      new Chart(ctxAnnualProfitability, {
        type: 'bar',
        data: {
          labels: annualProfitabilityLabels,
          datasets: [{
            label: 'Profit Margin',
            data: annualProfitabilityMargins,
            backgroundColor: profitabilityColors,
            borderColor: profitabilityColors,
            borderWidth: 1,
            borderRadius: 7,
            maxBarThickness: 42,
          }]
        },
        options: {
          indexAxis: 'y',
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: function(context) {
                  const index = context.dataIndex;
                  const margin = Number(annualProfitabilityMargins[index] || 0);
                  const gross = Number(annualProfitabilityGross[index] || 0);
                  const profit = Number(annualProfitabilityProfit[index] || 0);
                  return [
                    'Margin: ' + margin.toFixed(2) + '%',
                    'Company Gross: $' + gross.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                    'Total Profit: $' + profit.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                  ];
                }
              }
            }
          },
          scales: {
            x: {
              ticks: { callback: value => Number(value).toFixed(0) + '%' },
              grid: { color: context => context.tick.value === 0 ? '#6c757d' : 'rgba(0,0,0,.08)' }
            },
            y: { grid: { display: false } }
          }
        }
      });
    }

    const ctxWeekly = document.getElementById('weeklyPayoutChart');
    if (ctxWeekly) {
      new Chart(ctxWeekly, {
        type: 'line',
        data: {
          labels: weeklyVendorLabels.length ? weeklyVendorLabels : weeklyLabels,
          datasets: weeklyVendorDatasets.length ? weeklyVendorDatasets : [{
            label: 'Weekly Payouts',
            data: weeklyValues,
            borderColor: '#0d6efd',
            backgroundColor: '#0d6efd',
            tension: 0.35,
            pointRadius: 4,
          }]
        },
        options: {
          responsive: true,
          plugins: {
            legend: { display: true, position: 'bottom' },
            tooltip: { mode: 'index', intersect: false }
          },
          scales: {
            x: { grid: { display: false } },
            y: { ticks: { callback: value => '$' + value.toLocaleString() } }
          }
        }
      });
    }

    const moneyTick = value => '$' + Number(value || 0).toLocaleString();

    const ctxTrailer = document.getElementById('trailerRevenueChart');
    if (ctxTrailer) {
      new Chart(ctxTrailer, {
        type: 'bar',
        data: {
          labels: trailerVendorLabels,
          datasets: [{
            label: 'Trailer Fees',
            data: trailerVendorValues,
            backgroundColor: '#198754',
            borderRadius: 8,
            maxBarThickness: 42,
          }]
        },
        options: {
          responsive: true,
          plugins: { legend: { display: false } },
          scales: {
            x: { grid: { display: false } },
            y: { beginAtZero: true, ticks: { callback: moneyTick } }
          }
        }
      });
    }

    const ctxFsc = document.getElementById('fuelSurchargeChart');
    if (ctxFsc) {
      new Chart(ctxFsc, {
        type: 'bar',
        data: {
          labels: fscVendorLabels,
          datasets: [{
            label: 'Fuel Surcharge',
            data: fscVendorValues,
            backgroundColor: '#fd7e14',
            borderRadius: 8,
            maxBarThickness: 42,
          }]
        },
        options: {
          responsive: true,
          plugins: { legend: { display: false } },
          scales: {
            x: { grid: { display: false } },
            y: { beginAtZero: true, ticks: { callback: moneyTick } }
          }
        }
      });
    }

    const ctxVendorMix = document.getElementById('vendorMixChart');
    if (ctxVendorMix) {
      new Chart(ctxVendorMix, {
        type: 'line',
        data: {
          labels: vendorMixLabels,
          datasets: vendorMixDatasets
        },
        options: {
          responsive: true,
          plugins: {
            legend: { position: 'bottom' },
            tooltip: {
              mode: 'index',
              intersect: false,
              callbacks: { label: context => `${context.dataset.label}: ${context.parsed.y}%` }
            }
          },
          scales: {
            x: { grid: { display: false } },
            y: { min: 0, max: 100, ticks: { callback: value => value + '%' } }
          }
        }
      });
    }

    const ctxVendor = document.getElementById('vendorPayoutChart');
    if (ctxVendor) {
      new Chart(ctxVendor, {
        type: 'doughnut',
        data: {
          labels: vendorLabels,
          datasets: [{
            data: vendorValues,
            backgroundColor: ['#0d6efd', '#198754', '#fd7e14', '#6f42c1', '#20c997', '#dc3545', '#6c757d'],
            borderColor: '#fff',
            borderWidth: 2,
          }]
        },
        options: {
          responsive: true,
          plugins: {
            legend: { position: 'bottom' },
            tooltip: {
              callbacks: {
                label: function(context) {
                  const label = context.label || '';
                  const value = Number(context.parsed || 0);
                  return label + ': $' + value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
              }
            }
          }
        }
      });
    }

    const ctxType = document.getElementById('driverTypeChart');
    if (ctxType) {
      new Chart(ctxType, {
        type: 'bar',
        data: {
          labels: typeLabels,
          datasets: [{
            label: 'Driver Count',
            data: typeValues,
            backgroundColor: '#20c997',
            borderRadius: 12,
            maxBarThickness: 36,
          }]
        },
        options: {
          responsive: true,
          plugins: { legend: { display: false } },
          scales: {
            x: { grid: { display: false } },
            y: { beginAtZero: true }
          }
        }
      });
    }

    const ctxDrivers = document.getElementById('driversWorkedChart');
    if (ctxDrivers) {
      new Chart(ctxDrivers, {
        type: 'pie',
        data: {
          labels: ['Worked Today', 'Not Worked Today'],
          datasets: [{
            data: [driversWorkedToday, totalDrivers - driversWorkedToday],
            backgroundColor: ['#28a745', '#dc3545'],
            borderWidth: 2,
            borderColor: '#fff',
          }]
        },
        options: {
          responsive: true,
          plugins: {
            legend: { position: 'bottom' },
            tooltip: {
              callbacks: {
                label: function(context) {
                  const label = context.label || '';
                  const value = context.parsed;
                  const total = context.dataset.data.reduce((a, b) => a + b, 0);
                  const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                  return label + ': ' + value + ' (' + percentage + '%)';
                }
              }
            }
          }
        }
      });
    }

    const ctxDriversWeek = document.getElementById('driversWorkedWeekChart');
    if (ctxDriversWeek) {
      new Chart(ctxDriversWeek, {
        type: 'pie',
        data: {
          labels: ['Worked This Week', 'Not Worked This Week'],
          datasets: [{
            data: [driversWorkedThisWeek, totalDrivers - driversWorkedThisWeek],
            backgroundColor: ['#20c997', '#ffc107'],
            borderWidth: 2,
            borderColor: '#fff',
          }]
        },
        options: {
          responsive: true,
          plugins: {
            legend: { position: 'bottom' },
            tooltip: {
              callbacks: {
                label: function(context) {
                  const label = context.label || '';
                  const value = context.parsed;
                  const total = context.dataset.data.reduce((a, b) => a + b, 0);
                  const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                  return label + ': ' + value + ' (' + percentage + '%)';
                }
              }
            }
          }
        }
      });
    }
  </script>
</body>
</html>
