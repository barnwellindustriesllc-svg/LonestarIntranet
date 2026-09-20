<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/payout_net_helpers.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/retro_balance_audit.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');

$userType = (string)($_SESSION['user_type'] ?? '');
if (($_SESSION['username'] ?? '') === 'admin' && $userType === '') {
    $userType = 'admin';
}
if (!in_array($userType, ['admin', 'owner'], true)) {
    http_response_code(403);
    exit('Accounting access is required.');
}

function rba_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES);
}

function rba_money($value): string {
    $amount = (float)$value;
    return ($amount < 0 ? '-$' : '$') . number_format(abs($amount), 2);
}

function rba_ensure_corrections_table(mysqli $mysqli): void {
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS retro_balance_audit_corrections (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          driver_id INT NOT NULL,
          vendor_scope VARCHAR(30) NOT NULL,
          as_of_week_start DATE NOT NULL,
          previous_balance DECIMAL(12,2) NOT NULL,
          corrected_balance DECIMAL(12,2) NOT NULL,
          applied_by INT NULL,
          applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY idx_retro_correction_driver_week (driver_id, as_of_week_start),
          KEY idx_retro_correction_applied_at (applied_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function rba_csrf_token(): string {
    if (empty($_SESSION['retro_balance_audit_csrf'])) {
        $_SESSION['retro_balance_audit_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['retro_balance_audit_csrf'];
}

function rba_audit_checksum(int $driverId, string $throughDate, array $audit): string {
    $values = [];
    foreach ($audit['corrections'] ?? [] as $scope => $row) {
        $values[$scope] = [
            round((float)($row['expected'] ?? 0), 2),
            round((float)($row['stored'] ?? 0), 2),
            (string)($row['stored_week'] ?? ''),
            round((float)($row['current_balance'] ?? 0), 2),
            (string)($row['current_week'] ?? ''),
            (int)($row['history_mismatches'] ?? 0),
            round((float)($row['historical_missed'] ?? 0), 2),
        ];
    }
    return hash_hmac(
        'sha256',
        json_encode([$driverId, $throughDate, $values], JSON_UNESCAPED_SLASHES),
        rba_csrf_token()
    );
}

rba_ensure_corrections_table($mysqli);
lonestar_driver_misc_balance_history_ensure_table($mysqli);
lonestar_driver_fuel_balance_history_ensure_table($mysqli);

$drivers = [];
$res = $mysqli->query(
    "SELECT id,
            CONCAT(first_name, ' ', last_name) AS driver_name,
            COALESCE(is_disabled,0) AS is_disabled
       FROM driver_contacts
      ORDER BY is_disabled, last_name, first_name"
);
while ($row = $res->fetch_assoc()) {
    $drivers[] = [
        'id' => (int)$row['id'],
        'name' => trim((string)$row['driver_name']),
        'disabled' => (int)$row['is_disabled'] === 1,
    ];
}
$res->close();

$selectedDriverId = (int)($_REQUEST['driver_id'] ?? 0);
$currentWeekStart = retro_balance_week_start(date('Y-m-d'));
$lastCompletedWeekEnd = (new DateTimeImmutable($currentWeekStart))->modify('-1 day')->format('Y-m-d');
$throughDate = trim((string)($_REQUEST['through_date'] ?? $lastCompletedWeekEnd));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $throughDate)) {
    $throughDate = date('Y-m-d');
}
$vendorFilter = strtolower(trim((string)($_REQUEST['vendor_scope'] ?? 'all')));
$allowedVendorFilters = array_merge(['all'], lonestar_payout_vendor_order(), ['fuel']);
if (!in_array($vendorFilter, $allowedVendorFilters, true)) {
    $vendorFilter = 'all';
}
$showAllRows = !empty($_REQUEST['show_all']);
$message = '';
$messageType = 'success';
$audit = null;

if ($selectedDriverId > 0) {
    $audit = retro_balance_run($mysqli, $selectedDriverId, $throughDate);
}
$auditPeriodComplete = $audit === null
    || retro_balance_week_end((string)$audit['through_week']) <= date('Y-m-d');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['action'] ?? '') === 'apply_corrections')) {
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    $postedChecksum = (string)($_POST['audit_checksum'] ?? '');
    $selectedScopes = array_values(array_unique(array_map(
        static fn($scope) => strtolower(trim((string)$scope)),
        (array)($_POST['correction_scopes'] ?? [])
    )));

    if (!hash_equals(rba_csrf_token(), $postedCsrf)) {
        $message = 'The confirmation expired. Refresh the audit and try again.';
        $messageType = 'danger';
    } elseif ($audit === null || !hash_equals(rba_audit_checksum($selectedDriverId, $throughDate, $audit), $postedChecksum)) {
        $message = 'The underlying balance data changed. Review the refreshed audit before applying corrections.';
        $messageType = 'warning';
    } elseif (!$auditPeriodComplete) {
        $message = 'Corrections cannot be applied to an incomplete statement week.';
        $messageType = 'warning';
    } elseif (!$selectedScopes) {
        $message = 'Select at least one correction.';
        $messageType = 'warning';
    } else {
        $validScopes = array_merge(lonestar_payout_vendor_order(), ['fuel']);
        $selectedScopes = array_values(array_intersect($selectedScopes, $validScopes));
        $applied = 0;
        try {
            $mysqli->begin_transaction();
            $appliedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            foreach ($selectedScopes as $scope) {
                $correction = $audit['corrections'][$scope] ?? null;
                if (
                    !$correction
                    || empty($correction['needs_correction'])
                    || empty($correction['can_apply'])
                ) {
                    continue;
                }
                $beforeBalance = round((float)($correction['current_balance'] ?? $correction['stored']), 2);
                $expectedBalance = round((float)$correction['expected'], 2);

                foreach ($audit['rows'] as $weeklyRow) {
                    if (($weeklyRow['scope'] ?? '') !== $scope) {
                        continue;
                    }
                    if ($scope === 'fuel') {
                        lonestar_driver_fuel_balance_history_save(
                            $mysqli,
                            $selectedDriverId,
                            (string)$weeklyRow['week_start'],
                            (float)$weeklyRow['expected_ending'],
                            'retro_audit',
                            $appliedBy
                        );
                    } else {
                        lonestar_driver_misc_balance_history_save(
                            $mysqli,
                            $selectedDriverId,
                            $scope,
                            (string)$weeklyRow['week_start'],
                            (float)$weeklyRow['expected_ending'],
                            'retro_audit',
                            $appliedBy
                        );
                    }
                }
                $monetaryChange = abs((float)$correction['variance']) > 0.005;
                if ($monetaryChange) {
                    if ($scope === 'fuel') {
                        lonestar_driver_save_fuel_balance(
                            $mysqli,
                            $selectedDriverId,
                            $expectedBalance,
                            (string)$audit['through_week']
                        );
                    } else {
                        lonestar_driver_save_misc_balance(
                            $mysqli,
                            $selectedDriverId,
                            $scope,
                            $expectedBalance,
                            (string)$audit['through_week']
                        );
                    }
                }
                if ($scope === 'fuel') {
                    lonestar_driver_fuel_balance_history_save(
                        $mysqli,
                        $selectedDriverId,
                        (string)$audit['through_week'],
                        $expectedBalance,
                        'retro_audit',
                        $appliedBy
                    );
                } else {
                    lonestar_driver_misc_balance_history_save(
                        $mysqli,
                        $selectedDriverId,
                        $scope,
                        (string)$audit['through_week'],
                        $expectedBalance,
                        'retro_audit',
                        $appliedBy
                    );
                }

                $stmt = $mysqli->prepare(
                    "INSERT INTO retro_balance_audit_corrections
                        (driver_id, vendor_scope, as_of_week_start, previous_balance, corrected_balance, applied_by)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $throughWeek = (string)$audit['through_week'];
                $stmt->bind_param(
                    'issddi',
                    $selectedDriverId,
                    $scope,
                    $throughWeek,
                    $beforeBalance,
                    $expectedBalance,
                    $appliedBy
                );
                $stmt->execute();
                $stmt->close();

                audit_log_change(
                    $mysqli,
                    'apply_retro_balance_correction',
                    'driver_balance',
                    $selectedDriverId . '|' . $scope,
                    $monetaryChange
                        ? 'Applied retroactive ' . retro_balance_vendor_label($scope) . ' balance correction.'
                        : 'Backfilled retroactive ' . retro_balance_vendor_label($scope) . ' balance history.',
                    [
                        'balance' => $beforeBalance,
                        'week_start' => (string)($correction['stored_week'] ?? ''),
                    ],
                    [
                        'balance' => $expectedBalance,
                        'week_start' => $throughWeek,
                    ]
                );
                $applied++;
            }
            $mysqli->commit();
            $message = $applied > 0
                ? "Applied {$applied} confirmed balance correction" . ($applied === 1 ? '.' : 's.')
                : 'No eligible corrections were applied.';
            $messageType = $applied > 0 ? 'success' : 'warning';
        } catch (Throwable $e) {
            $mysqli->rollback();
            error_log('Retroactive balance correction failed: ' . $e->getMessage());
            $message = 'The corrections could not be applied. No changes were saved.';
            $messageType = 'danger';
        }
        $audit = retro_balance_run($mysqli, $selectedDriverId, $throughDate);
        $auditPeriodComplete = retro_balance_week_end((string)$audit['through_week']) <= date('Y-m-d');
    }
}

$selectedDriverName = '';
foreach ($drivers as $driver) {
    if ($driver['id'] === $selectedDriverId) {
        $selectedDriverName = $driver['name'];
        break;
    }
}

$displayRows = [];
$mismatchCount = 0;
if ($audit !== null) {
    foreach ($audit['rows'] as $row) {
        if ($vendorFilter !== 'all' && ($row['scope'] ?? '') !== $vendorFilter) {
            continue;
        }
        if (!empty($row['mismatch'])) {
            $mismatchCount++;
        }
        if ($showAllRows || !empty($row['mismatch'])) {
            $displayRows[] = $row;
        }
    }
}
$auditChecksum = $audit !== null ? rba_audit_checksum($selectedDriverId, $throughDate, $audit) : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Retroactive Balance Audit</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <style>
    body { margin:0; font-family:sans-serif; background:#f5f7fa; }
    .page-shell { display:flex; min-height:calc(100vh - var(--banner-h)); }
    .sidebar { width:250px; background:#333; color:#fff; height:calc(100vh - var(--banner-h)); position:fixed; top:var(--banner-h); left:0; overflow:auto; transition:transform .3s ease; z-index:1000; transform:translateX(0); }
    .sidebar.collapsed { transform:translateX(-250px); }
    .sidebar a { display:block; color:#fff; padding:15px; text-decoration:none; }
    .sidebar a:hover { background:#444; }
    .main { margin-left:250px; padding:20px; flex:1; min-width:0; }
    .sidebar.collapsed + .main { margin-left:0; }
    .card { border:0; box-shadow:0 2px 10px rgba(0,0,0,.07); }
    .metric { font-size:1.45rem; font-weight:700; }
    .money-neg { color:#b42318; font-weight:600; }
    .money-pos { color:#067647; }
    .table thead th { position:sticky; top:0; background:#343a40; color:#fff; z-index:2; white-space:nowrap; }
    .table td { white-space:nowrap; vertical-align:middle; }
    .table-wrap { max-height:620px; overflow:auto; }
    .badge-missing { background:#fff0c2; color:#7a5200; }
    .badge-mismatch { background:#fee4e2; color:#b42318; }
    .badge-match { background:#dcfae6; color:#067647; }
    @media (max-width:768px) {
      .sidebar { transform:translateX(-250px); }
      .sidebar.open { transform:translateX(0); }
      .main { margin:0; padding:14px; }
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/includes/ls_title.php'; ?>
<div class="page-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
      <div>
        <h1 class="h3 mb-1">Retroactive Balance Audit</h1>
        <p class="text-muted mb-0">Reconstructs weekly balances from raw payout, adjustment, insurance, and fuel data.</p>
      </div>
      <span class="badge badge-info p-2">Audit scans are read-only</span>
    </div>

    <?php if ($message !== ''): ?>
      <div class="alert alert-<?= rba_h($messageType) ?>"><?= rba_h($message) ?></div>
    <?php endif; ?>

    <section class="card mb-4">
      <div class="card-body">
        <form method="get" class="form-row align-items-end">
          <div class="form-group col-lg-5">
            <label for="driver_id">Driver</label>
            <select class="form-control" id="driver_id" name="driver_id" required>
              <option value="">Select a driver</option>
              <?php foreach ($drivers as $driver): ?>
                <option value="<?= (int)$driver['id'] ?>" <?= $selectedDriverId === $driver['id'] ? 'selected' : '' ?>>
                  <?= rba_h($driver['name'] . ($driver['disabled'] ? ' (disabled)' : '')) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-lg-2">
            <label for="through_date">Audit through</label>
            <input class="form-control" type="date" id="through_date" name="through_date" value="<?= rba_h($throughDate) ?>" required>
          </div>
          <div class="form-group col-lg-2">
            <label for="vendor_scope">Client display</label>
            <select class="form-control" id="vendor_scope" name="vendor_scope">
              <option value="all">All clients</option>
              <?php foreach (array_merge(lonestar_payout_vendor_order(), ['fuel']) as $scope): ?>
                <option value="<?= rba_h($scope) ?>" <?= $vendorFilter === $scope ? 'selected' : '' ?>>
                  <?= rba_h(retro_balance_vendor_label($scope)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-lg-2">
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" id="show_all" name="show_all" value="1" <?= $showAllRows ? 'checked' : '' ?>>
              <label class="form-check-label" for="show_all">Show matching weeks</label>
            </div>
          </div>
          <div class="form-group col-lg-1">
            <button class="btn btn-primary btn-block" type="submit">Run</button>
          </div>
        </form>
      </div>
    </section>

    <?php if ($audit !== null): ?>
      <?php if (!empty($audit['truncated'])): ?>
        <div class="alert alert-danger">The scan exceeded 800 weeks and was stopped. Do not apply corrections until the data range is reviewed.</div>
      <?php endif; ?>
      <?php if (!$auditPeriodComplete): ?>
        <div class="alert alert-warning">This audit includes the current, incomplete statement week. Review is available, but corrections are disabled until the week closes.</div>
      <?php endif; ?>

      <div class="row mb-4">
        <div class="col-md-3 mb-3">
          <div class="card h-100"><div class="card-body">
            <div class="text-muted small">Driver</div>
            <div class="metric"><?= rba_h($selectedDriverName) ?></div>
          </div></div>
        </div>
        <div class="col-md-3 mb-3">
          <div class="card h-100"><div class="card-body">
            <div class="text-muted small">Period reconstructed</div>
            <div class="metric"><?= rba_h($audit['first_week'] ?: 'No activity') ?></div>
            <div class="small text-muted">through <?= rba_h($audit['through_week']) ?></div>
          </div></div>
        </div>
        <div class="col-md-3 mb-3">
          <div class="card h-100"><div class="card-body">
            <div class="text-muted small">Weekly mismatches shown</div>
            <div class="metric"><?= number_format($mismatchCount) ?></div>
          </div></div>
        </div>
        <div class="col-md-3 mb-3">
          <div class="card h-100"><div class="card-body">
            <div class="text-muted small">Expected unpaid statement balance</div>
            <div class="metric money-neg"><?= rba_money($audit['statement_debt_expected'] ?? 0) ?></div>
            <div class="small text-muted">
              Includes <?= rba_money($audit['fuel_balance_expected'] ?? 0) ?> of open fuel debt. Fuel is selectable below.
            </div>
          </div></div>
        </div>
      </div>

      <section class="card mb-4">
        <div class="card-header bg-white">
          <h2 class="h5 mb-1">Current corrections</h2>
          <p class="text-muted small mb-0">Only checked rows are written. Weekly history is saved with the correction for future comparisons.</p>
        </div>
        <div class="card-body p-0">
          <form method="post" id="correction-form">
            <input type="hidden" name="action" value="apply_corrections">
            <input type="hidden" name="driver_id" value="<?= (int)$selectedDriverId ?>">
            <input type="hidden" name="through_date" value="<?= rba_h($throughDate) ?>">
            <input type="hidden" name="vendor_scope" value="<?= rba_h($vendorFilter) ?>">
            <input type="hidden" name="show_all" value="<?= $showAllRows ? '1' : '0' ?>">
            <input type="hidden" name="csrf_token" value="<?= rba_h(rba_csrf_token()) ?>">
            <input type="hidden" name="audit_checksum" value="<?= rba_h($auditChecksum) ?>">
            <div class="table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead>
                  <tr>
                    <th class="pl-3">Apply</th>
                    <th>Client</th>
                    <th class="text-right">Stored at cutoff</th>
                    <th class="text-right">Expected at cutoff</th>
                    <th class="text-right">Correction</th>
                    <th class="text-right">Missed collection</th>
                    <th>Cutoff ledger week</th>
                    <th>Current snapshot</th>
                    <th>Weekly issues</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($audit['corrections'] as $scope => $correction): ?>
                  <?php
                    if ($vendorFilter !== 'all' && $scope !== $vendorFilter) continue;
                    $needs = !empty($correction['needs_correction']);
                    $canApply = !empty($correction['can_apply'])
                      && empty($audit['truncated'])
                      && $auditPeriodComplete;
                  ?>
                  <tr>
                    <td class="pl-3">
                      <input
                        type="checkbox"
                        name="correction_scopes[]"
                        value="<?= rba_h($scope) ?>"
                        <?= (!$needs || !$canApply) ? 'disabled' : '' ?>
                        aria-label="Apply <?= rba_h(retro_balance_vendor_label($scope)) ?> correction"
                      >
                    </td>
                    <td><?= rba_h(retro_balance_vendor_label($scope)) ?></td>
                    <td class="text-right"><?= rba_money($correction['stored']) ?></td>
                    <td class="text-right <?= ($scope === 'fuel' && (float)$correction['expected'] > 0) || (float)$correction['expected'] < 0 ? 'money-neg' : 'money-pos' ?>">
                      <?= rba_money($correction['expected']) ?>
                    </td>
                    <td class="text-right <?= ($scope === 'fuel' && (float)$correction['variance'] > 0) || (float)$correction['variance'] < 0 ? 'money-neg' : '' ?>">
                      <?= rba_money($correction['variance']) ?>
                    </td>
                    <td class="text-right <?= (float)($correction['historical_missed'] ?? 0) > 0.005 ? 'money-neg' : '' ?>">
                      <?= rba_money($correction['historical_missed'] ?? 0) ?>
                    </td>
                    <td><?= rba_h($correction['stored_week'] ?: 'Not recorded') ?></td>
                    <td>
                      <?= rba_money($correction['current_balance'] ?? 0) ?>
                      <span class="text-muted small">@ <?= rba_h($correction['current_week'] ?: 'none') ?></span>
                    </td>
                    <td><?= number_format((int)($correction['history_mismatches'] ?? 0)) ?></td>
                    <td>
                      <?php if (!$canApply): ?>
                        <span class="badge badge-warning">Cannot apply</span>
                      <?php elseif ($needs && abs((float)$correction['variance']) > 0.005): ?>
                        <span class="badge badge-danger">Balance correction</span>
                      <?php elseif ($needs): ?>
                        <span class="badge badge-warning">History backfill</span>
                      <?php else: ?>
                        <span class="badge badge-success">Matches</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="p-3 border-top">
              <button
                class="btn btn-danger"
                type="submit"
                onclick="return confirm('Apply only the selected balance corrections? This action is logged.');"
              >Apply Selected Corrections</button>
            </div>
          </form>
        </div>
      </section>

      <section class="card">
        <div class="card-header bg-white">
          <h2 class="h5 mb-1">Weekly findings</h2>
          <p class="text-muted small mb-0">
            “Not recorded” means no weekly ledger existed for that historical week. Expected balances are independently reconstructed.
          </p>
        </div>
        <div class="table-wrap">
          <table class="table table-sm table-hover mb-0">
            <thead>
              <tr>
                <th>Week</th>
                <th>Client</th>
                <th class="text-right">Expected opening</th>
                <th class="text-right">Gross / Collected</th>
                <th class="text-right">Deductions / New Fuel</th>
                <th class="text-right">Misc/FSC</th>
                <th class="text-right">Expected ending</th>
                <th class="text-right">Stored ending</th>
                <th class="text-right">Variance</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$displayRows): ?>
              <tr><td colspan="10" class="text-center text-muted p-4">No weekly mismatches were found for this filter.</td></tr>
            <?php else: ?>
              <?php foreach ($displayRows as $row): ?>
                <?php
                  $storedMissing = $row['stored_ending'] === null;
                  $statusClass = $storedMissing ? 'badge-missing' : (!empty($row['mismatch']) ? 'badge-mismatch' : 'badge-match');
                  $statusText = $storedMissing ? 'Not recorded' : (!empty($row['mismatch']) ? 'Mismatch' : 'Matches');
                ?>
                <tr>
                  <td><?= rba_h($row['week_start']) ?></td>
                  <td><?= rba_h(retro_balance_vendor_label($row['scope'])) ?></td>
                  <td class="text-right <?= (($row['scope'] ?? '') === 'fuel' && (float)$row['opening'] > 0) || (float)$row['opening'] < 0 ? 'money-neg' : '' ?>"><?= rba_money($row['opening']) ?></td>
                  <td class="text-right"><?= rba_money($row['gross']) ?></td>
                  <td class="text-right"><?= rba_money($row['deductions']) ?></td>
                  <td class="text-right"><?= rba_money((float)$row['misc'] + (float)$row['fuel_surcharge']) ?></td>
                  <td class="text-right <?= (($row['scope'] ?? '') === 'fuel' && (float)$row['expected_ending'] > 0) || (float)$row['expected_ending'] < 0 ? 'money-neg' : '' ?>"><?= rba_money($row['expected_ending']) ?></td>
                  <td class="text-right"><?= $storedMissing ? '—' : rba_money($row['stored_ending']) ?></td>
                  <td class="text-right <?= (($row['scope'] ?? '') === 'fuel' && (float)$row['variance'] > 0) || (float)$row['variance'] < 0 ? 'money-neg' : '' ?>"><?= rba_money($row['variance']) ?></td>
                  <td><span class="badge <?= rba_h($statusClass) ?>"><?= rba_h($statusText) ?></span></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
