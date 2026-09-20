<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/audit.php';

$currentUser = $_SESSION['username'] ?? '';
$currentUserType = $_SESSION['user_type'] ?? '';
if ($currentUser === 'admin' && $currentUserType === '') {
    $currentUserType = 'admin';
}
if (!in_array($currentUserType, ['admin', 'owner'], true)) {
    header('Location: dashboard.php');
    exit;
}

audit_log_ensure_table($mysqli);

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES);
}

function page_label($entityType): string {
    $labels = [
        'user' => 'User Management',
        'owner_operator' => 'Owner Operators',
        'trailer' => 'Trailer Manager',
        'trailer_assignment' => 'Trailer Manager',
        'driver_contact' => 'Driver Contacts',
        'upload_vendor_broker_fee' => 'Uploads',
    ];
    return $labels[$entityType] ?? ucwords(str_replace('_', ' ', (string)$entityType));
}

$logs = [];
$res = $mysqli->query(
    "SELECT id, created_at, username, user_type, action, entity_type, description, change_record, before_data, after_data
       FROM change_logs
      ORDER BY created_at DESC, id DESC
      LIMIT 1000"
);
while ($row = $res->fetch_assoc()) {
    if (($row['change_record'] ?? '') === '') {
        $before = $row['before_data'] ? json_decode($row['before_data'], true) : null;
        $after = $row['after_data'] ? json_decode($row['after_data'], true) : null;
        $row['change_record'] = audit_log_format_change_record(
            is_array($before) ? $before : null,
            is_array($after) ? $after : null
        );
    }
    $logs[] = $row;
}
$res->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Change Logs</title>
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
  </style>
</head>
<body>
<?php include __DIR__ . '/includes/ls_title.php'; ?>
<div class="page-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
      <div>
        <h1 class="mb-1">Change Logs</h1>
        <div class="text-muted">Showing the latest 1,000 recorded system changes.</div>
      </div>
    </div>

    <div class="panel">
      <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Date</th>
              <th>User</th>
              <th>Action</th>
              <th>Page</th>
              <th>Change Record</th>
              <th>Description</th>
            </tr>
            <tr>
              <th><input type="search" class="form-control form-control-sm log-filter" data-column="0" placeholder="Filter date"></th>
              <th><input type="search" class="form-control form-control-sm log-filter" data-column="1" placeholder="Filter user"></th>
              <th><input type="search" class="form-control form-control-sm log-filter" data-column="2" placeholder="Filter action"></th>
              <th><input type="search" class="form-control form-control-sm log-filter" data-column="3" placeholder="Filter page"></th>
              <th><input type="search" class="form-control form-control-sm log-filter" data-column="4" placeholder="Filter change"></th>
              <th><input type="search" class="form-control form-control-sm log-filter" data-column="5" placeholder="Filter description"></th>
            </tr>
          </thead>
          <tbody id="changeLogsBody">
            <?php if (!$logs): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">No changes have been logged yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($logs as $log): ?>
              <tr>
                <td><?= h($log['created_at']) ?></td>
                <td>
                  <strong><?= h($log['username']) ?></strong>
                  <?php if ($log['user_type']): ?><div class="text-muted small"><?= h($log['user_type']) ?></div><?php endif; ?>
                </td>
                <td><?= h($log['action']) ?></td>
                <td><?= h(page_label($log['entity_type'])) ?></td>
                <td><?= h($log['change_record']) ?></td>
                <td><?= h($log['description']) ?></td>
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
  var tbody = document.getElementById('changeLogsBody');
  if (!filters.length || !tbody) return;
  function normalize(value) { return (value || '').toString().trim().toLowerCase(); }
  function applyFilters() {
    var active = filters.map(function (input) {
      return { column: parseInt(input.dataset.column || '0', 10), value: normalize(input.value) };
    }).filter(function (filter) { return filter.value !== ''; });
    Array.prototype.slice.call(tbody.querySelectorAll('tr')).forEach(function (row) {
      var visible = active.every(function (filter) {
        var cell = row.children[filter.column];
        return cell && normalize(cell.textContent).indexOf(filter.value) !== -1;
      });
      row.style.display = visible ? '' : 'none';
    });
  }
  filters.forEach(function (input) { input.addEventListener('input', applyFilters); });
})();
</script>
</body>
</html>
