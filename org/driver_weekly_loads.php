<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';
require __DIR__ . '/SimpleXLSX.php';
require __DIR__ . '/includes/driver_load_tools.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');

$errors = [];
$success = '';
$uploadSummary = null;

try {
    dlt_ensure_tables($mysqli);
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_action']) && empty($errors)) {
    $vendorCode = trim((string)($_POST['vendor_code'] ?? 'tss'));
    $newVendorName = trim((string)($_POST['new_vendor_name'] ?? ''));

    if (empty($_FILES['data_file']) || (int)$_FILES['data_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please upload a valid spreadsheet file.';
    } else {
        try {
            $vendor = dlt_get_or_create_vendor($mysqli, $vendorCode, $newVendorName);

            $tmpPath = (string)$_FILES['data_file']['tmp_name'];
            $fileName = (string)($_FILES['data_file']['name'] ?? 'driver_load_data.csv');
            $fileHash = hash_file('sha256', $tmpPath) ?: '';
            if ($fileHash === '') {
                throw new RuntimeException('Unable to fingerprint uploaded file.');
            }

            $parsedFile = dlt_parse_uploaded_file($tmpPath, $fileName);
            if (!empty($parsedFile['error'])) {
                throw new RuntimeException((string)$parsedFile['error']);
            }

            $parsedRows = dlt_parse_driver_rows(
                $parsedFile['headers'],
                $parsedFile['rows'],
                (string)($vendor['source_timezone'] ?? 'America/Chicago')
            );
            if (!empty($parsedRows['errors'])) {
                $errors = array_merge($errors, $parsedRows['errors']);
            }

            if (empty($parsedRows['rows'])) {
                $errors[] = 'No valid driver load rows were found in the file.';
            }

            if (empty($errors)) {
                $userName = trim((string)($_SESSION['user_name'] ?? $_SESSION['username'] ?? ''));
                $uploadSummary = dlt_upsert_driver_rows(
                    $mysqli,
                    $vendor,
                    $fileName,
                    $fileHash,
                    $parsedRows['rows'],
                    $userName
                );

                $success = 'Driver load file processed successfully.';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$vendorOptions = dlt_get_vendor_options($mysqli);
$selectedVendorCode = trim((string)($_GET['vendor_code'] ?? ''));

$weekOptions = dlt_get_week_options($mysqli, 120);
$selectedWeek = trim((string)($_GET['week_start'] ?? ''));
if ($selectedWeek === '' && !empty($weekOptions)) {
    $selectedWeek = $weekOptions[0]['week_start'];
}

$matrix = null;
if ($selectedWeek !== '') {
    $matrix = dlt_get_weekly_matrix($mysqli, $selectedWeek, $selectedVendorCode);
}

function format_day_label(string $date): string {
    return date('D m/d', strtotime($date));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Driver Weekly Loads</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { margin:0; font-family:sans-serif; }
    .page-shell { display:flex; min-height: calc(100vh - var(--banner-h)); }
    .sidebar { width:250px; background:#333; color:#fff; height:calc(100vh - var(--banner-h)); position:fixed; top:var(--banner-h); overflow:auto; transition:transform .3s; }
    .sidebar.collapsed { transform:translateX(-250px); }
    .sidebar a { display:block; color:#fff; padding:15px; text-decoration:none; }
    .sidebar a:hover { background:#444; }
    .main { margin-left:250px; padding:20px; flex:1; min-width:0; }
    .sidebar.collapsed + .main { margin-left:0; }
    @media(max-width:768px){ .sidebar{transform:translateX(-250px);} .sidebar.open{transform:translateX(0);} .main{margin:0;} }

    .dashboard-card { border:1px solid #e6e6e6; border-radius:10px; padding:14px; background:#fff; }
    .table thead th { white-space: nowrap; }
    .table tfoot th { background:#f7f7f7; }
    .money { text-align:right; white-space:nowrap; }
    .count { text-align:right; }
    .cell-stack { line-height:1.15; }
    .cell-stack small { color:#6b7280; display:block; }
    .missing-driver-row > td { background:#fff7cc !important; }
  </style>
</head>
<body>
<?php include __DIR__ . '/includes/ls_title.php'; ?>
<div class="page-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main">
    <h1>Driver Weekly Load Manager</h1>
    <p class="text-muted">Rolling 3-month weekly view (Sunday through Saturday). Drivers with no loads in the selected week are excluded.</p>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?>
            <li><?= dlt_h($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php elseif ($success !== ''): ?>
      <div class="alert alert-success">
        <strong><?= dlt_h($success) ?></strong>
        <?php if ($uploadSummary): ?>
          <div class="small mt-1">
            Rows inserted: <?= (int)$uploadSummary['inserted'] ?>,
            rows updated: <?= (int)$uploadSummary['updated'] ?>.
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="dashboard-card mb-4">
      <h2 class="h5">Upload Driver Earnings Spreadsheet</h2>
      <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
        <input type="hidden" name="upload_action" value="1">
        <div class="col-12 col-md-3">
          <label class="form-label">Client</label>
          <select name="vendor_code" class="form-select">
            <?php foreach ($vendorOptions as $vendor): ?>
              <option value="<?= dlt_h($vendor['vendor_code']) ?>" <?= $vendor['vendor_code'] === 'tss' ? 'selected' : '' ?>>
                <?= dlt_h($vendor['vendor_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label">Or New Client Name</label>
          <input type="text" name="new_vendor_name" class="form-control" placeholder="Use when adding a new contract client">
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label">Spreadsheet (.csv, .tsv, .xlsx)</label>
          <input type="file" name="data_file" class="form-control" accept=".csv,.tsv,.txt,.xlsx" required>
        </div>
        <div class="col-12 col-md-2">
          <button type="submit" class="btn btn-primary w-100">Upload Data</button>
        </div>
      </form>
    </div>

    <div class="dashboard-card mb-4">
      <form method="get" class="row g-3 align-items-end">
        <div class="col-12 col-md-3">
          <label class="form-label">Week (Sunday-Saturday)</label>
          <select name="week_start" class="form-select">
            <?php foreach ($weekOptions as $week): ?>
              <option value="<?= dlt_h($week['week_start']) ?>" <?= $week['week_start'] === $selectedWeek ? 'selected' : '' ?>>
                <?= dlt_h($week['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label">Client Filter</label>
          <select name="vendor_code" class="form-select">
            <option value="">All Clients</option>
            <?php foreach ($vendorOptions as $vendor): ?>
              <option value="<?= dlt_h($vendor['vendor_code']) ?>" <?= $vendor['vendor_code'] === $selectedVendorCode ? 'selected' : '' ?>>
                <?= dlt_h($vendor['vendor_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-2">
          <button type="submit" class="btn btn-outline-primary w-100">Apply</button>
        </div>
      </form>
    </div>

    <?php if (!$matrix || empty($matrix['days'])): ?>
      <div class="alert alert-info">No uploaded driver-load data found yet.</div>
    <?php else: ?>
      <h2 class="h5 mb-3">
        Weekly Earnings: <?= dlt_h(date('M j, Y', strtotime($matrix['week_start']))) ?> - <?= dlt_h(date('M j, Y', strtotime($matrix['week_end']))) ?>
      </h2>
      <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>Driver</th>
              <th>Client</th>
              <?php foreach ($matrix['days'] as $day): ?>
                <th class="money"><?= dlt_h(format_day_label($day)) ?></th>
              <?php endforeach; ?>
              <th class="money">Weekly Earnings</th>
              <th class="count">Weekly Load Count</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($matrix['rows'])): ?>
              <tr>
                <td colspan="<?= 5 + count($matrix['days']) ?>" class="text-muted">No drivers with loads in this week.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($matrix['rows'] as $index => $row): ?>
                <tr class="<?= empty($row['driver_exists']) ? 'missing-driver-row' : '' ?>">
                  <td><?= (int)$index + 1 ?></td>
                  <td>
                    <?= dlt_h($row['driver_name']) ?>
                    <?php if (empty($row['driver_exists'])): ?>
                      <small class="d-block text-muted">Not found in Driver Details</small>
                    <?php endif; ?>
                  </td>
                  <td><?= dlt_h($row['vendor_name']) ?></td>
                  <?php foreach ($matrix['days'] as $day): ?>
                    <td class="money cell-stack">
                      $<?= number_format((float)$row['daily_rates'][$day], 2) ?>
                      <small>Loads: <?= (int)$row['daily_loads'][$day] ?></small>
                    </td>
                  <?php endforeach; ?>
                  <td class="money"><strong>$<?= number_format((float)$row['weekly_rate'], 2) ?></strong></td>
                  <td class="count"><strong><?= (int)$row['weekly_loads'] ?></strong></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <tfoot>
            <tr>
              <th colspan="3" class="text-end">Total Earnings</th>
              <?php foreach ($matrix['days'] as $day): ?>
                <th class="money">$<?= number_format((float)$matrix['totals']['daily_rates'][$day], 2) ?></th>
              <?php endforeach; ?>
              <th class="money">$<?= number_format((float)$matrix['totals']['weekly_rate'], 2) ?></th>
              <th class="count"><?= (int)$matrix['totals']['weekly_loads'] ?></th>
            </tr>
            <tr>
              <th colspan="3" class="text-end">Total Load Count</th>
              <?php foreach ($matrix['days'] as $day): ?>
                <th class="count"><?= (int)$matrix['totals']['daily_loads'][$day] ?></th>
              <?php endforeach; ?>
              <th class="count">-</th>
              <th class="count"><?= (int)$matrix['totals']['weekly_loads'] ?></th>
            </tr>
          </tfoot>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
