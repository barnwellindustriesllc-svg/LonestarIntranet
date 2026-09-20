<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/dot_status_tools.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');

$refresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
$search = trim($_GET['search'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$includeDisabled = isset($_GET['show_disabled']) && $_GET['show_disabled'] === '1';
$apiKeyOk = dot_status_api_available();

$drivers = [];
$query = "SELECT id, CONCAT(first_name, ' ', last_name) AS name, owner_dot_number, driver_type
          FROM driver_contacts
          WHERE owner_dot_number IS NOT NULL AND owner_dot_number <> ''";
if (!$includeDisabled) {
  $query .= " AND is_disabled = 0";
}
if ($typeFilter !== '' && in_array($typeFilter, ['Leased', 'Brokered'], true)) {
  $query .= " AND driver_type = '" . $mysqli->real_escape_string($typeFilter) . "'";
}
if ($search !== '') {
  $like = '%' . $mysqli->real_escape_string($search) . '%';
  $query .= " AND (owner_dot_number LIKE '{$like}' OR first_name LIKE '{$like}' OR last_name LIKE '{$like}')";
}
$query .= " ORDER BY first_name, last_name";
$res = $mysqli->query($query);
while ($row = $res->fetch_assoc()) {
  $drivers[] = $row;
}

function get_field(array $data, array $keys, string $default = '—'): string {
  foreach ($keys as $key) {
    if (isset($data[$key]) && $data[$key] !== '') {
      return (string)$data[$key];
    }
  }
  return $default;
}

function latest_inspection_date(array $inspectionRecords): string {
  $latest = null;
  foreach ($inspectionRecords as $rec) {
    $date = $rec['inspection_date'] ?? '';
    if ($date === '') continue;
    $ts = strtotime($date);
    if ($ts && ($latest === null || $ts > $latest)) {
      $latest = $ts;
    }
  }
  return $latest ? date('Y-m-d', $latest) : '—';
}

function count_inspections_with_violations(array $inspectionRecords): string {
  $count = 0;
  foreach ($inspectionRecords as $rec) {
    $viol = $rec['violation_totals']['basic'] ?? 0;
    if (is_numeric($viol) && (int)$viol > 0) {
      $count++;
    }
  }
  return (string)$count;
}

$dotNumbers = [];
foreach ($drivers as $driver) {
  $dot = dot_status_normalize_number((string)($driver['owner_dot_number'] ?? ''));
  if ($dot !== '') {
    $dotNumbers[] = $dot;
  }
}
$dotStatusMap = dot_status_get_map($mysqli, $dotNumbers, true, $refresh);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Owner Op DOT Status</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { margin:0; font-family:sans-serif; }
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
    .status-table th { position: sticky; top: 0; background: #f8fafc; }
    .status-table td, .status-table th { white-space: nowrap; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main">
      <h1>Owner Op DOT Status</h1>
      <?php if (!$apiKeyOk): ?>
        <div class="alert alert-warning">
          SAFER API key missing. Set <strong>SAFER_API_KEY</strong> in <code>dot/config.php</code> to enable DOT lookups.
        </div>
      <?php endif; ?>

      <form method="get" class="row g-2 align-items-end mb-3">
        <div class="col-md-4">
          <label class="form-label">Search (Driver or DOT)</label>
          <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Type</label>
          <select name="type" class="form-select">
            <option value="">All</option>
            <option value="Leased" <?= $typeFilter === 'Leased' ? 'selected' : '' ?>>Leased</option>
            <option value="Brokered" <?= $typeFilter === 'Brokered' ? 'selected' : '' ?>>Brokered</option>
          </select>
        </div>
        <div class="col-md-2">
          <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" id="showDisabled" name="show_disabled" value="1" <?= $includeDisabled ? 'checked' : '' ?>>
            <label class="form-check-label" for="showDisabled">Show disabled drivers</label>
          </div>
        </div>
        <div class="col-md-2">
          <button class="btn btn-primary" type="submit">Apply</button>
        </div>
        <div class="col-md-2">
          <a class="btn btn-outline-secondary" href="owner_op_dot_status.php?refresh=1">Refresh Data</a>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-bordered table-sm status-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Driver</th>
              <th>DOT #</th>
              <th>Type</th>
              <th>Legal Name</th>
              <th>DBA</th>
              <th>Entity Type</th>
              <th>Operating Status</th>
              <th>Last Inspection</th>
              <th>Crashes</th>
              <th>Violations</th>
              <th>Inspections (w/ violations)</th>
              <th>Out of Service</th>
              <th>Last Checked</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$drivers): ?>
              <tr><td colspan="14" class="text-muted">No owner operator DOT numbers found.</td></tr>
            <?php else: ?>
              <?php foreach ($drivers as $index => $driver): ?>
                <?php
                  $dot = dot_status_normalize_number((string)$driver['owner_dot_number']);
                  $statusRow = $dot !== '' ? ($dotStatusMap[$dot] ?? null) : null;
                  $snapshot = [];
                  $inspectionRecords = [];
                  $violationRecords = [];
                  $legalName = trim((string)($statusRow['legal_name'] ?? '')) ?: 'â€”';
                  $dbaName = trim((string)($statusRow['dba_name'] ?? '')) ?: 'â€”';
                  $entityType = trim((string)($statusRow['entity_type'] ?? '')) ?: 'â€”';
                  $operStatus = trim((string)($statusRow['operating_status'] ?? '')) ?: 'â€”';
                  $lastInspection = trim((string)($statusRow['last_inspection_date'] ?? '')) ?: 'â€”';
                  $crashes = isset($statusRow['crashes_total']) ? (string)(int)$statusRow['crashes_total'] : 'â€”';
                  $violations = isset($statusRow['violations_total']) ? (string)(int)$statusRow['violations_total'] : 'â€”';
                  $violations = $violationRecords ? (string)count($violationRecords) : '—';
                  $inspections = count_inspections_with_violations($inspectionRecords);
                  $outOfService = get_field($snapshot['united_states_inspections']['vehicle'] ?? [], ['out_of_service_percent']);
                  $fetched = get_field($snapshot, ['latest_update']);
                  $legalName = trim((string)($statusRow['legal_name'] ?? '')) ?: '-';
                  $dbaName = trim((string)($statusRow['dba_name'] ?? '')) ?: '-';
                  $entityType = trim((string)($statusRow['entity_type'] ?? '')) ?: '-';
                  $operStatus = trim((string)($statusRow['operating_status'] ?? '')) ?: '-';
                  $lastInspection = trim((string)($statusRow['last_inspection_date'] ?? '')) ?: '-';
                  $crashes = isset($statusRow['crashes_total']) ? (string)(int)$statusRow['crashes_total'] : '-';
                  $violations = isset($statusRow['violations_total']) ? (string)(int)$statusRow['violations_total'] : '-';
                  $inspections = isset($statusRow['inspections_with_violations']) ? (string)(int)$statusRow['inspections_with_violations'] : '-';
                  $outOfService = trim((string)($statusRow['out_of_service_percent'] ?? '')) ?: '-';
                  $checkedAt = trim((string)($statusRow['checked_at'] ?? ''));
                  $errorMsg = trim((string)($statusRow['api_error'] ?? ''));
                  $fetched = $checkedAt !== '' ? $checkedAt : ($errorMsg !== '' ? $errorMsg : '-');
                ?>
                <tr>
                  <td><?= (int)$index + 1 ?></td>
                  <td><?= htmlspecialchars($driver['name'] ?? '', ENT_QUOTES) ?></td>
                  <td>
                    <?php if ($dot !== ''): ?>
                      <a href="/dot/carrier?usdot=<?= rawurlencode($dot) ?>"><?= htmlspecialchars($dot, ENT_QUOTES) ?></a>
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($driver['driver_type'] ?? '', ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($legalName, ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($dbaName, ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($entityType, ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($operStatus, ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($lastInspection, ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($crashes, ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($violations, ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($inspections, ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($outOfService, ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($fetched !== '—' ? $fetched : ($errorMsg ?: '—'), ENT_QUOTES) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <small class="text-muted">
        Data sourced from FMCSA API. Some columns may be unavailable depending on DOT record completeness.
      </small>
    </div>
  </div>
</body>
</html>
