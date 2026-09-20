<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/trailer_assignment_history.php';

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
lonestar_trailer_history_ensure_table($mysqli);

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES);
}

$today = date('Y-m-d');
$defaultStart = date('Y-m-d', strtotime('-30 days'));
$startDate = trim((string)($_GET['start_date'] ?? $defaultStart));
$endDate = trim((string)($_GET['end_date'] ?? $today));
$errors = [];

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $errors[] = 'Start date must be a valid date.';
    $startDate = $defaultStart;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $errors[] = 'End date must be a valid date.';
    $endDate = $today;
}
if ($startDate > $endDate) {
    $errors[] = 'Start date cannot be after end date.';
    [$startDate, $endDate] = [$endDate, $startDate];
}

$historyRows = [];
$stmt = $mysqli->prepare(
    "SELECT tah.id,
            tah.trailer_number,
            tah.assigned_date,
            tah.removed_date,
            COALESCE(NULLIF(tah.vendor, ''), ta.vendor) AS vendor,
            COALESCE(NULLIF(tah.trailer_type, ''), ta.trailer_type) AS trailer_type,
            COALESCE(NULLIF(tah.trailer_status, ''), ta.status) AS status,
            COALESCE(NULLIF(tah.trailer_fee_mode, ''), ta.trailer_fee_mode) AS trailer_fee_mode,
            COALESCE(tah.trailer_fee_value, ta.trailer_fee_value) AS trailer_fee_value,
            dc.id AS driver_contact_id,
            CONCAT(COALESCE(dc.first_name, ''), ' ', COALESCE(dc.last_name, '')) AS driver_name,
            dc.owner_name,
            dc.truck_no
       FROM trailer_assignment_history tah
  LEFT JOIN trailer_assets ta
         ON ta.id = tah.trailer_id
         OR TRIM(COALESCE(ta.trailer_number, '')) = TRIM(COALESCE(tah.trailer_number, ''))
  LEFT JOIN driver_contacts dc
         ON dc.id = tah.driver_contact_id
      WHERE tah.assigned_date <= ?
        AND COALESCE(tah.removed_date, ?) >= ?
   ORDER BY tah.assigned_date DESC, tah.id DESC
      LIMIT 5000"
);
$stmt->bind_param('sss', $endDate, $endDate, $startDate);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $historyRows[] = $row;
}
$stmt->close();

function trailer_fee_display_asset_log($mode, $value): string {
    if ($value === null || $value === '') {
        return '';
    }
    $amount = (float)$value;
    if ($mode === 'flat') {
        return '$' . number_format($amount, 2) . '/day';
    }
    return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.') . '%';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Asset Historical Logs</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { margin:0; font-family:sans-serif; background:#f5f7fb; }
    .page-shell { display:flex; min-height:calc(100vh - var(--banner-h)); }
    .sidebar { width:250px; background:#333; color:#fff; height:calc(100vh - var(--banner-h)); position:fixed; top:var(--banner-h); overflow:auto; transition:transform .3s; }
    .sidebar.collapsed { transform:translateX(-250px); }
    .sidebar a { display:block; color:#fff; padding:15px; text-decoration:none; }
    .sidebar a:hover { background:#444; }
    .main { margin-left:250px; padding:24px; flex:1; min-width:0; }
    .sidebar.collapsed + .main { margin-left:0; }
    @media(max-width:768px){ .sidebar{transform:translateX(-250px);} .sidebar.open{transform:translateX(0);} .main{margin:0;} }
    .panel { background:#fff; border:1px solid #dde4ee; border-radius:8px; padding:18px; box-shadow:0 10px 24px rgba(17,24,39,.05); }
    .table td { vertical-align:middle; }
    .log-filter { min-width: 110px; }
    .date-filter-form { background:#fff; border:1px solid #dde4ee; border-radius:8px; padding:14px; }
  </style>
</head>
<body>
<?php include __DIR__ . '/includes/ls_title.php'; ?>
<div class="page-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
      <div>
        <h1 class="mb-1">Asset Historical Logs</h1>
        <div class="text-muted">Trailer assignment history narrowed by assigned/removed date overlap.</div>
      </div>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-warning">
        <ul class="mb-0">
          <?php foreach ($errors as $error): ?>
            <li><?= h($error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="get" class="date-filter-form mb-3">
      <div class="row g-3 align-items-end">
        <div class="col-12 col-md-3">
          <label class="form-label">Start Date</label>
          <input type="date" name="start_date" value="<?= h($startDate) ?>" class="form-control" required>
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label">End Date</label>
          <input type="date" name="end_date" value="<?= h($endDate) ?>" class="form-control" required>
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-primary">Search Dates</button>
        </div>
      </div>
    </form>

    <div class="panel">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <div class="fw-semibold"><?= number_format(count($historyRows)) ?> records found</div>
        <input type="search" id="globalAssetSearch" class="form-control form-control-sm" style="max-width:320px" placeholder="Search visible results">
      </div>
      <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Driver</th>
              <th>Owner</th>
              <th>Truck</th>
              <th>Trailer</th>
              <th>Client</th>
              <th>Trailer Type</th>
              <th>Status</th>
              <th>Trailer Fee</th>
              <th>Assigned Date</th>
              <th>Removed Date</th>
            </tr>
            <tr>
              <th><input type="search" class="form-control form-control-sm log-filter" data-column="0" placeholder="#"></th>
              <th><input type="search" class="form-control form-control-sm log-filter" data-column="1" placeholder="Filter driver"></th>
              <th><input type="search" class="form-control form-control-sm log-filter" data-column="2" placeholder="Filter owner"></th>
              <th><input type="search" class="form-control form-control-sm log-filter" data-column="3" placeholder="Filter truck"></th>
              <th><input type="search" class="form-control form-control-sm log-filter" data-column="4" placeholder="Filter trailer"></th>
              <th><input type="search" class="form-control form-control-sm log-filter" data-column="5" placeholder="Filter client"></th>
              <th><input type="search" class="form-control form-control-sm log-filter" data-column="6" placeholder="Filter type"></th>
              <th><input type="search" class="form-control form-control-sm log-filter" data-column="7" placeholder="Filter status"></th>
              <th><input type="search" class="form-control form-control-sm log-filter" data-column="8" placeholder="Filter fee"></th>
              <th><input type="search" class="form-control form-control-sm log-filter" data-column="9" placeholder="Filter assigned"></th>
              <th><input type="search" class="form-control form-control-sm log-filter" data-column="10" placeholder="Filter removed"></th>
            </tr>
          </thead>
          <tbody id="assetHistoryBody">
            <?php if (!$historyRows): ?>
              <tr><td colspan="11" class="text-center text-muted py-4">No asset history was found for the selected dates.</td></tr>
            <?php endif; ?>
            <?php foreach ($historyRows as $index => $row): ?>
              <tr>
                <td><?= (int)$index + 1 ?></td>
                <td><?= h(trim((string)($row['driver_name'] ?? ''))) ?></td>
                <td><?= h($row['owner_name'] ?? '') ?></td>
                <td><?= h($row['truck_no'] ?? '') ?></td>
                <td><?= h($row['trailer_number'] ?? '') ?></td>
                <td><?= h($row['vendor'] ?? '') ?></td>
                <td><?= h($row['trailer_type'] ?? '') ?></td>
                <td><?= h($row['status'] ?? '') ?></td>
                <td><?= h(trailer_fee_display_asset_log($row['trailer_fee_mode'] ?? '', $row['trailer_fee_value'] ?? null)) ?></td>
                <td><?= h($row['assigned_date'] ?? '') ?></td>
                <td><?= h($row['removed_date'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>
<script>
(function () {
  var filters = Array.prototype.slice.call(document.querySelectorAll('.log-filter'));
  var globalSearch = document.getElementById('globalAssetSearch');
  var tbody = document.getElementById('assetHistoryBody');
  if (!tbody) return;
  function normalize(value) { return (value || '').toString().trim().toLowerCase(); }
  function applyFilters() {
    var globalValue = normalize(globalSearch ? globalSearch.value : '');
    var active = filters.map(function (input) {
      return { column: parseInt(input.dataset.column || '0', 10), value: normalize(input.value) };
    }).filter(function (filter) { return filter.value !== ''; });
    Array.prototype.slice.call(tbody.querySelectorAll('tr')).forEach(function (row) {
      if (row.children.length <= 1) return;
      var rowText = normalize(row.textContent);
      var matchesGlobal = globalValue === '' || rowText.indexOf(globalValue) !== -1;
      var matchesColumns = active.every(function (filter) {
        var cell = row.children[filter.column];
        return cell && normalize(cell.textContent).indexOf(filter.value) !== -1;
      });
      row.style.display = matchesGlobal && matchesColumns ? '' : 'none';
    });
  }
  filters.forEach(function (input) { input.addEventListener('input', applyFilters); });
  if (globalSearch) globalSearch.addEventListener('input', applyFilters);
})();
</script>
</body>
</html>
