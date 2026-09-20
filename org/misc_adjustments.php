<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES);
}

function money($value): string {
    return '$' . number_format((float)$value, 2);
}

function parse_money_value($value): float {
    $raw = preg_replace('/[^0-9.\-]/', '', (string)$value);
    if ($raw === '' || $raw === '-' || $raw === '.' || $raw === '-.') {
        return 0.0;
    }
    return round((float)$raw, 2);
}

function adjustment_week_start(string $date): string {
    try {
        $dt = new DateTimeImmutable($date);
    } catch (Throwable $e) {
        $dt = new DateTimeImmutable('today');
    }
    $dow = (int)$dt->format('w');
    return $dt->modify("-{$dow} days")->format('Y-m-d');
}

function ensure_misc_adjustments_table(mysqli $mysqli): void {
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS tss_misc_adjustments (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $columns = [
        'payout_vendor' => "ALTER TABLE tss_misc_adjustments ADD COLUMN payout_vendor VARCHAR(20) NOT NULL DEFAULT 'TSS' AFTER id",
        'adjustment_date' => "ALTER TABLE tss_misc_adjustments ADD COLUMN adjustment_date DATE NULL AFTER source_upload_date",
        'updated_at' => "ALTER TABLE tss_misc_adjustments ADD COLUMN updated_at DATETIME NULL AFTER created_at",
    ];
    foreach ($columns as $column => $sql) {
        $res = $mysqli->query("SHOW COLUMNS FROM tss_misc_adjustments LIKE '" . $mysqli->real_escape_string($column) . "'");
        if (!$res || $res->num_rows === 0) {
            $mysqli->query($sql);
        }
        if ($res) {
            $res->close();
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
    $mysqli->query("
        UPDATE tss_misc_adjustments
           SET payout_vendor = CASE
                WHEN UPPER(REPLACE(REPLACE(COALESCE(payout_vendor, 'TSS'), ' ', ''), '-', '')) = 'NICKELROCK' THEN 'NICKELROCK'
                WHEN UPPER(REPLACE(REPLACE(COALESCE(payout_vendor, 'TSS'), ' ', ''), '-', '')) = 'NEXTIER' THEN 'NEXTIER'
                WHEN UPPER(REPLACE(REPLACE(COALESCE(payout_vendor, 'TSS'), ' ', ''), '-', '')) = 'RTEX' THEN 'RTEX'
                WHEN UPPER(REPLACE(REPLACE(COALESCE(payout_vendor, 'TSS'), ' ', ''), '-', '')) = 'DETMAR' THEN 'DETMAR'
                ELSE 'TSS'
           END
         WHERE payout_vendor IS NULL
            OR payout_vendor = ''
            OR UPPER(REPLACE(REPLACE(COALESCE(payout_vendor, 'TSS'), ' ', ''), '-', '')) IN ('TSS', 'NICKELROCK', 'NEXTIER', 'RTEX', 'DETMAR')
    ");
}

function vendor_options(): array {
    return [
        'TSS' => 'TSS',
        'NEXTIER' => 'NexTier',
        'RTEX' => 'RTEX',
        'NICKELROCK' => 'Nickel Rock',
    ];
}

function normalize_adjustment_vendor($vendor): string {
    $key = strtoupper(str_replace([' ', '-'], '', trim((string)$vendor)));
    if ($key === 'NICKELROCK') {
        return 'NICKELROCK';
    }
    if (in_array($key, ['TSS', 'DETMAR', 'NEXTIER', 'RTEX'], true)) {
        return $key;
    }
    return '';
}

ensure_misc_adjustments_table($mysqli);

$drivers = [];
$res = $mysqli->query("SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM driver_contacts WHERE COALESCE(is_disabled,0)=0 ORDER BY last_name, first_name");
while ($row = $res->fetch_assoc()) {
    $drivers[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
}
$res->close();

$message = '';
$messageType = 'success';
$errors = [];
$editingId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$form = [
    'id' => 0,
    'payout_vendor' => 'TSS',
    'driver_contact_id' => '',
    'adjustment_date' => date('Y-m-d'),
    'adjustment_type' => 'misc_payment',
    'amount' => '',
    'comments' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete_adjustment') {
        $deleteId = (int)($_POST['id'] ?? 0);
        if ($deleteId > 0) {
            $stmt = $mysqli->prepare("DELETE FROM tss_misc_adjustments WHERE id=? LIMIT 1");
            $stmt->bind_param('i', $deleteId);
            $stmt->execute();
            $stmt->close();
            $message = 'Adjustment deleted.';
        }
    } elseif ($action === 'save_adjustment') {
        $form = [
            'id' => (int)($_POST['id'] ?? 0),
            'payout_vendor' => normalize_adjustment_vendor($_POST['payout_vendor'] ?? 'TSS'),
            'driver_contact_id' => trim((string)($_POST['driver_contact_id'] ?? '')),
            'adjustment_date' => trim((string)($_POST['adjustment_date'] ?? '')),
            'adjustment_type' => trim((string)($_POST['adjustment_type'] ?? 'misc_payment')),
            'amount' => trim((string)($_POST['amount'] ?? '')),
            'comments' => trim((string)($_POST['comments'] ?? '')),
        ];
        if ($form['payout_vendor'] === '' || !array_key_exists($form['payout_vendor'], vendor_options())) {
            $errors[] = 'Select a valid client.';
        }
        $driverId = (int)$form['driver_contact_id'];
        if ($driverId <= 0) {
            $errors[] = 'Select a driver.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['adjustment_date'])) {
            $errors[] = 'Select a valid adjustment date.';
        }
        if (!in_array($form['adjustment_type'], ['misc_payment', 'misc_deduction'], true)) {
            $errors[] = 'Select a valid adjustment type.';
        }
        $amount = parse_money_value($form['amount']);
        if ($amount <= 0) {
            $errors[] = 'Enter an amount greater than 0.';
        }
        if ($form['comments'] === '') {
            $errors[] = 'Enter comments explaining the adjustment.';
        }
        if (!$errors) {
            $weekStart = adjustment_week_start($form['adjustment_date']);
            $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            if ((int)$form['id'] > 0) {
                $stmt = $mysqli->prepare("
                    UPDATE tss_misc_adjustments
                       SET payout_vendor=?,
                           source_upload_date=?,
                           adjustment_date=?,
                           payout_week_start=?,
                           driver_contact_id=?,
                           adjustment_type=?,
                           amount=?,
                           comments=?,
                           updated_at=NOW()
                     WHERE id=?
                     LIMIT 1
                ");
                $stmt->bind_param(
                    'ssssisdsi',
                    $form['payout_vendor'],
                    $form['adjustment_date'],
                    $form['adjustment_date'],
                    $weekStart,
                    $driverId,
                    $form['adjustment_type'],
                    $amount,
                    $form['comments'],
                    $form['id']
                );
                $stmt->execute();
                $stmt->close();
                $message = 'Adjustment updated.';
            } else {
                $stmt = $mysqli->prepare("
                    INSERT INTO tss_misc_adjustments
                    (payout_vendor, source_upload_date, adjustment_date, payout_week_start, driver_contact_id, adjustment_type, amount, comments, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?)
                ");
                $stmt->bind_param(
                    'ssssisdsi',
                    $form['payout_vendor'],
                    $form['adjustment_date'],
                    $form['adjustment_date'],
                    $weekStart,
                    $driverId,
                    $form['adjustment_type'],
                    $amount,
                    $form['comments'],
                    $createdBy
                );
                $stmt->execute();
                $stmt->close();
                $message = 'Adjustment saved.';
            }
            $form = [
                'id' => 0,
                'payout_vendor' => 'TSS',
                'driver_contact_id' => '',
                'adjustment_date' => date('Y-m-d'),
                'adjustment_type' => 'misc_payment',
                'amount' => '',
                'comments' => '',
            ];
            $editingId = 0;
        } else {
            $messageType = 'danger';
            $message = implode(' ', $errors);
            $editingId = (int)$form['id'];
        }
    }
}

if ($editingId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $mysqli->prepare("
        SELECT id, payout_vendor, driver_contact_id, COALESCE(adjustment_date, source_upload_date), adjustment_type, amount, comments
          FROM tss_misc_adjustments
         WHERE id=?
         LIMIT 1
    ");
    $stmt->bind_param('i', $editingId);
    $stmt->execute();
    $stmt->bind_result($id, $vendor, $driverId, $adjustmentDate, $type, $amount, $comments);
    if ($stmt->fetch()) {
        $form = [
            'id' => (int)$id,
            'payout_vendor' => normalize_adjustment_vendor($vendor) ?: 'TSS',
            'driver_contact_id' => (string)$driverId,
            'adjustment_date' => (string)$adjustmentDate,
            'adjustment_type' => (string)$type,
            'amount' => number_format((float)$amount, 2, '.', ''),
            'comments' => (string)$comments,
        ];
    }
    $stmt->close();
}

$filterVendor = normalize_adjustment_vendor($_GET['vendor'] ?? '');
$filterDriver = (int)($_GET['driver_id'] ?? 0);
$filterFrom = trim((string)($_GET['from'] ?? ''));
$filterTo = trim((string)($_GET['to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = [];
$types = '';
$params = [];
if ($filterVendor !== '' && array_key_exists($filterVendor, vendor_options())) {
    $where[] = "UPPER(REPLACE(REPLACE(COALESCE(tma.payout_vendor, 'TSS'), ' ', ''), '-', '')) = ?";
    $types .= 's';
    $params[] = $filterVendor;
}
if ($filterDriver > 0) {
    $where[] = 'tma.driver_contact_id = ?';
    $types .= 'i';
    $params[] = $filterDriver;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $where[] = 'COALESCE(tma.adjustment_date, tma.source_upload_date) >= ?';
    $types .= 's';
    $params[] = $filterFrom;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $where[] = 'COALESCE(tma.adjustment_date, tma.source_upload_date) <= ?';
    $types .= 's';
    $params[] = $filterTo;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$totalRows = 0;
$countSql = "SELECT COUNT(*) FROM tss_misc_adjustments tma {$whereSql}";
$stmt = $mysqli->prepare($countSql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$stmt->bind_result($totalRows);
$stmt->fetch();
$stmt->close();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$rows = [];
$sql = "
    SELECT tma.id,
           tma.payout_vendor,
           COALESCE(tma.adjustment_date, tma.source_upload_date) AS adjustment_date,
           tma.payout_week_start,
           tma.driver_contact_id,
           CONCAT(dc.first_name, ' ', dc.last_name) AS driver_name,
           tma.adjustment_type,
           tma.amount,
           tma.comments,
           tma.created_at,
           tma.updated_at
      FROM tss_misc_adjustments tma
      LEFT JOIN driver_contacts dc ON dc.id = tma.driver_contact_id
      {$whereSql}
     ORDER BY adjustment_date DESC, tma.id DESC
     LIMIT ? OFFSET ?
";
$stmt = $mysqli->prepare($sql);
$queryTypes = $types . 'ii';
$queryParams = array_merge($params, [$perPage, $offset]);
$stmt->bind_param($queryTypes, ...$queryParams);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

$queryBase = $_GET;
unset($queryBase['page']);
$baseQuery = http_build_query($queryBase);
$pageUrl = static function (int $targetPage) use ($baseQuery): string {
    return '?' . ($baseQuery !== '' ? $baseQuery . '&' : '') . 'page=' . $targetPage;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Miscellaneous Payment Adjustments</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { margin:0; font-family:sans-serif; background:#f4f6fb; }
    .page-shell { display:flex; min-height: calc(100vh - var(--banner-h)); }
    .sidebar { width:250px; background:#333; color:#fff; height:calc(100vh - var(--banner-h)); position:fixed; top:var(--banner-h); overflow:auto; transition:transform .3s ease; }
    .sidebar.collapsed { transform: translateX(-250px); }
    .sidebar a { display:block; color:#fff; padding:15px; text-decoration:none; }
    .sidebar a:hover { background:#444; }
    .main { margin-left:250px; padding:20px; flex:1; min-width:0; }
    .sidebar.collapsed + .main { margin-left:0; }
    @media(max-width:768px) { .sidebar { transform:translateX(-250px); } .sidebar.open { transform:translateX(0); } .main { margin:0; } }
    .tool-box { background:#fff; border-radius:8px; padding:1.25rem; box-shadow:0 10px 22px rgba(0,0,0,.05); }
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main class="main">
      <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
          <h1 class="h3 mb-1">Miscellaneous Payment Adjustments</h1>
          <p class="text-muted mb-0">Add, edit, and review driver payments or deductions for any past or future date.</p>
        </div>
      </div>

      <?php if ($message !== ''): ?>
        <div class="alert alert-<?= h($messageType) ?>"><?= h($message) ?></div>
      <?php endif; ?>

      <section class="tool-box mb-4">
        <h2 class="h5 mb-3"><?= ((int)$form['id'] > 0) ? 'Edit Adjustment' : 'Add Adjustment' ?></h2>
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="save_adjustment">
          <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
          <div class="col-md-2">
            <label class="form-label">Client</label>
            <select name="payout_vendor" class="form-select" required>
              <?php foreach (vendor_options() as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= $form['payout_vendor'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Driver</label>
            <select name="driver_contact_id" class="form-select" required>
              <option value="">Select driver...</option>
              <?php foreach ($drivers as $driver): ?>
                <option value="<?= (int)$driver['id'] ?>" <?= (string)$driver['id'] === (string)$form['driver_contact_id'] ? 'selected' : '' ?>>
                  <?= h($driver['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Adjustment Date</label>
            <input type="date" name="adjustment_date" value="<?= h($form['adjustment_date']) ?>" class="form-control" required>
          </div>
          <div class="col-md-2">
            <label class="form-label">Type</label>
            <select name="adjustment_type" class="form-select" required>
              <option value="misc_payment" <?= $form['adjustment_type'] === 'misc_payment' ? 'selected' : '' ?>>Misc Payment</option>
              <option value="misc_deduction" <?= $form['adjustment_type'] === 'misc_deduction' ? 'selected' : '' ?>>Misc Deduction</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Amount</label>
            <input type="number" step="0.01" min="0.01" name="amount" value="<?= h($form['amount']) ?>" class="form-control" required>
          </div>
          <div class="col-md-8">
            <label class="form-label">Comments</label>
            <input type="text" name="comments" maxlength="255" value="<?= h($form['comments']) ?>" class="form-control" placeholder="Explain adjustment" required>
          </div>
          <div class="col-md-4 d-flex align-items-end gap-2">
            <button type="submit" class="btn btn-primary"><?= ((int)$form['id'] > 0) ? 'Update Adjustment' : 'Save Adjustment' ?></button>
            <?php if ((int)$form['id'] > 0): ?>
              <a href="misc_adjustments.php" class="btn btn-outline-secondary">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </section>

      <section class="tool-box">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h2 class="h5 mb-0">Adjustment History</h2>
          <div class="text-muted small"><?= number_format((int)$totalRows) ?> rows</div>
        </div>
        <form method="get" class="row g-2 mb-3">
          <div class="col-md-2">
            <select name="vendor" class="form-select">
              <option value="">All clients</option>
              <?php foreach (vendor_options() as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= $filterVendor === $value ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <select name="driver_id" class="form-select">
              <option value="0">All drivers</option>
              <?php foreach ($drivers as $driver): ?>
                <option value="<?= (int)$driver['id'] ?>" <?= $filterDriver === (int)$driver['id'] ? 'selected' : '' ?>><?= h($driver['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2"><input type="date" name="from" value="<?= h($filterFrom) ?>" class="form-control"></div>
          <div class="col-md-2"><input type="date" name="to" value="<?= h($filterTo) ?>" class="form-control"></div>
          <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-outline-primary" type="submit">Filter</button>
            <a href="misc_adjustments.php" class="btn btn-outline-secondary">Reset</a>
          </div>
        </form>

        <div class="table-responsive">
          <table class="table table-sm table-striped align-middle">
            <thead>
              <tr>
                <th>Date</th>
                <th>Week</th>
                <th>Client</th>
                <th>Driver</th>
                <th>Type</th>
                <th class="text-end">Amount</th>
                <th>Comments</th>
                <th>Updated</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="9" class="text-muted">No adjustments found.</td></tr>
              <?php else: ?>
                <?php foreach ($rows as $row): ?>
                  <tr>
                    <td><?= h($row['adjustment_date']) ?></td>
                    <td><?= h($row['payout_week_start']) ?></td>
                    <td><?= h(vendor_options()[$row['payout_vendor']] ?? $row['payout_vendor']) ?></td>
                    <td><?= h($row['driver_name'] ?: ('Driver #' . $row['driver_contact_id'])) ?></td>
                    <td><?= $row['adjustment_type'] === 'misc_deduction' ? 'Misc Deduction' : 'Misc Payment' ?></td>
                    <td class="text-end"><?= money($row['amount']) ?></td>
                    <td><?= h($row['comments']) ?></td>
                    <td><?= h($row['updated_at'] ?: $row['created_at']) ?></td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-outline-primary" href="?edit_id=<?= (int)$row['id'] ?>">Edit</a>
                      <form method="post" class="d-inline" onsubmit="return confirm('Delete this adjustment?');">
                        <input type="hidden" name="action" value="delete_adjustment">
                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <nav aria-label="Adjustment pages">
          <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= h($pageUrl(max(1, $page - 1))) ?>">Previous</a></li>
            <li class="page-item disabled"><span class="page-link">Page <?= (int)$page ?> of <?= (int)$totalPages ?></span></li>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= h($pageUrl(min($totalPages, $page + 1))) ?>">Next</a></li>
          </ul>
        </nav>
      </section>
    </main>
  </div>
</body>
</html>
