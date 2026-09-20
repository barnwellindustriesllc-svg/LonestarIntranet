<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/driver_load_tools.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');

$errors = [];
$alertStats = null;
$flashMessage = $_SESSION['driver_activity_flash'] ?? null;
unset($_SESSION['driver_activity_flash']);

try {
    dlt_ensure_tables($mysqli);
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

$vendorOptions = dlt_get_vendor_options($mysqli);
$selectedVendorCode = trim((string)($_GET['vendor_code'] ?? ''));
$showDisabledDrivers = isset($_GET['show_disabled']) && $_GET['show_disabled'] === '1';
$autoSendEnabled = empty($errors) ? dlt_is_auto_send_enabled($mysqli) : false;
$monitorRows = [];
$today = date('Y-m-d');
$lastStatusUpdateAt = null;
$lastStatusSourceDate = null;
$isStatusUpdatedToday = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $action = trim((string)($_POST['action'] ?? ''));
    $redirectUrl = (string)($_SERVER['REQUEST_URI'] ?? 'driver_activity_monitor.php');

    if ($action === 'set_auto_send') {
        $requestedEnabled = (string)($_POST['auto_send_enabled'] ?? '0') === '1';
        if (dlt_set_auto_send_enabled($mysqli, $requestedEnabled)) {
            $_SESSION['driver_activity_flash'] = [
                'type' => 'success',
                'text' => $requestedEnabled
                    ? 'Automatic driver alerts are now ON. New yellow and red statuses will auto-send messages after the daily status update.'
                    : 'Automatic driver alerts are now OFF. Drivers will not be auto-messaged until you turn it back on.',
            ];
        } else {
            $_SESSION['driver_activity_flash'] = [
                'type' => 'danger',
                'text' => 'Unable to update the auto-send setting.',
            ];
        }
        header('Location: ' . $redirectUrl);
        exit;
    }
}

if (empty($errors)) {
    try {
        $uploadSql = "
            SELECT u.created_at, u.source_date
              FROM driver_load_uploads u
              JOIN driver_load_vendor_sources v ON v.id = u.vendor_id
        ";
        $uploadTypes = '';
        $uploadParams = [];
        if ($selectedVendorCode !== '') {
            $uploadSql .= " WHERE v.vendor_code = ?";
            $uploadTypes = 's';
            $uploadParams[] = $selectedVendorCode;
        }
        $uploadSql .= " ORDER BY u.source_date DESC, u.created_at DESC LIMIT 1";
        $uploadStmt = $mysqli->prepare($uploadSql);
        if ($uploadTypes !== '') {
            $uploadStmt->bind_param($uploadTypes, ...$uploadParams);
        }
        $uploadStmt->execute();
        $uploadRes = $uploadStmt->get_result();
        if ($uploadRow = $uploadRes->fetch_assoc()) {
            $lastStatusUpdateAt = $uploadRow['created_at'] ?? null;
            $lastStatusSourceDate = $uploadRow['source_date'] ?? null;
            $isStatusUpdatedToday = ($lastStatusSourceDate === $today);
        }
        $uploadStmt->close();

        $monitorRows = dlt_get_activity_monitor_rows($mysqli, 120, $selectedVendorCode, $showDisabledDrivers);
        if ($isStatusUpdatedToday && $autoSendEnabled) {
            $alertStats = dlt_send_out_of_compliance_alerts($mysqli, $monitorRows);
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$contactMap = [];
$contactIds = [];
foreach ($monitorRows as $row) {
    if (!empty($row['driver_contact_id'])) {
        $contactIds[(int)$row['driver_contact_id']] = true;
    }
}
if (!empty($contactIds)) {
    $ids = array_keys($contactIds);
    $types = str_repeat('i', count($ids));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $mysqli->prepare("SELECT id, email, phone FROM driver_contacts WHERE id IN ($placeholders)");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $contactMap[(int)$row['id']] = $row;
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'send_manual_red_alert') {
        $driverContactId = (int)($_POST['driver_contact_id'] ?? 0);
        $vendorId = (int)($_POST['vendor_id'] ?? 0);
        $manualStats = dlt_create_alert_stats();
        $targetRow = null;
        foreach ($monitorRows as $row) {
            if ((int)($row['driver_contact_id'] ?? 0) === $driverContactId && (int)($row['vendor_id'] ?? 0) === $vendorId) {
                $targetRow = $row;
                break;
            }
        }

        if ($autoSendEnabled) {
            $_SESSION['driver_activity_flash'] = [
                'type' => 'warning',
                'text' => 'Manual send is only available while auto-send is turned off.',
            ];
        } elseif (!$targetRow || (string)($targetRow['status'] ?? '') !== 'red') {
            $_SESSION['driver_activity_flash'] = [
                'type' => 'danger',
                'text' => 'That driver is no longer in No Load in 3+ Days status.',
            ];
        } else {
            $alertContacts = dlt_get_alert_contacts($mysqli, [$targetRow]);
            $sent = dlt_send_single_out_of_compliance_alert($mysqli, $targetRow, $alertContacts, $manualStats, false);
            if ($sent) {
                $driverName = trim((string)($targetRow['driver_name'] ?? 'Driver'));
                $_SESSION['driver_activity_flash'] = [
                    'type' => 'success',
                    'text' => 'Manual alert sent for ' . $driverName . '. Email sent: '
                        . (int)$manualStats['sent_email']
                        . ', SMS sent: ' . (int)$manualStats['sent_text'] . '.',
                ];
            } else {
                $errorText = !empty($manualStats['errors'])
                    ? implode(' ', array_map('strval', $manualStats['errors']))
                    : 'No alert was sent for that driver.';
                $_SESSION['driver_activity_flash'] = [
                    'type' => 'danger',
                    'text' => $errorText,
                ];
            }
        }

        $redirectUrl = (string)($_SERVER['REQUEST_URI'] ?? 'driver_activity_monitor.php');
        header('Location: ' . $redirectUrl);
        exit;
    }
}

$alertSentLookup = [];
if (!empty($monitorRows)) {
    $stmt = $mysqli->prepare(
        "SELECT driver_contact_id, vendor_id, alert_status, alert_date
           FROM driver_activity_alert_log
          WHERE alert_date = ?"
    );
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $key = (int)$row['driver_contact_id'] . '|' . (int)$row['vendor_id'] . '|' . $row['alert_status'];
        $alertSentLookup[$key] = true;
    }
    $stmt->close();
}

function status_label(string $status): string {
    if ($status === 'green') return 'Active';
    if ($status === 'yellow') return 'No Load in 2 Days';
    return 'No Load in 3+ Days';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Driver Activity Monitor</title>
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

    .status-dot { width:14px; height:14px; border-radius:50%; display:inline-block; margin-right:8px; vertical-align:middle; }
    .status-green { background:#16a34a; }
    .status-yellow { background:#f59e0b; }
    .status-red { background:#dc2626; }
    .summary-card { border:1px solid #e5e7eb; border-radius:10px; padding:12px; background:#fff; }
    .status-key { display:flex; flex-wrap:wrap; gap:16px; border:1px solid #e5e7eb; border-radius:10px; padding:10px 12px; background:#f8fafc; }
    .status-key-item { display:flex; align-items:center; gap:8px; font-size:14px; }
    .status-update-card { border:1px solid #d1d5db; border-radius:10px; padding:10px 12px; background:#f9fafb; }
    .control-card { border:1px solid #d1d5db; border-radius:10px; padding:12px; background:#ffffff; }
    .table th, .table td { vertical-align:middle; }
    .fc-alert-yellow > td { background:#fff7cc !important; }
    .fc-alert-red > td { background:#fde2e2 !important; }
    .missing-driver-row > td { background:#fff7cc !important; }
  </style>
</head>
<body>
<?php include __DIR__ . '/includes/ls_title.php'; ?>
<div class="page-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main">
    <h1>Driver Activity Monitor</h1>
    <div class="alert <?= $isStatusUpdatedToday ? 'alert-success' : 'alert-warning' ?> mb-3" role="alert">
      <strong>Status Update Schedule:</strong> Status updates take place daily at 10:00 AM EST / 9:00 AM CST.
      <!--<?php if ($isStatusUpdatedToday): ?>
        <div class="small mt-1">Today's status update has been completed.</div>
      <?php else: ?>
        <div class="small mt-1">Today's status update is pending.</div>
      <?php endif; ?>-->
    </div>
    <div class="status-key mb-3">
      <div class="status-key-item"><span class="status-dot status-green"></span><strong>Green</strong>: Active (load in last 0-1 day)</div>
      <div class="status-key-item"><span class="status-dot status-yellow"></span><strong>Yellow</strong>: No load in 2 days (48 hours)</div>
      <div class="status-key-item"><span class="status-dot status-red"></span><strong>Red</strong>: No load in 3+ days (72+ hours)</div>
    </div>
    <div class="status-update-card mb-3">
      <strong>Last Status Update:</strong>
      <?php if ($lastStatusUpdateAt): ?>
        <?= dlt_h(date('m/d/Y h:i A', strtotime($lastStatusUpdateAt))) ?>
      <?php else: ?>
        <span class="text-muted">No uploads found</span>
      <?php endif; ?>
      <?php if (!$isStatusUpdatedToday): ?>
        <div class="small text-warning mt-1">Today's file has not been uploaded yet. Statuses are pending update.</div>
      <?php endif; ?>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?>
            <li><?= dlt_h($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($flashMessage): ?>
      <div class="alert alert-<?= dlt_h((string)($flashMessage['type'] ?? 'info')) ?> mb-3">
        <?= dlt_h((string)($flashMessage['text'] ?? '')) ?>
      </div>
    <?php endif; ?>

    <div class="control-card mb-3">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
          <strong>Driver Alert Auto-Send</strong>
          <div class="small text-muted">
            When ON, yellow and red driver alerts are automatically sent after today’s status update. When OFF, nothing auto-sends.
          </div>
        </div>
        <form method="post" class="d-flex align-items-center gap-2">
          <input type="hidden" name="action" value="set_auto_send">
          <input type="hidden" name="auto_send_enabled" value="<?= $autoSendEnabled ? '0' : '1' ?>">
          <span class="small text-muted"><?= $autoSendEnabled ? 'ON' : 'OFF' ?></span>
          <div class="form-check form-switch m-0">
            <input
              class="form-check-input"
              type="checkbox"
              role="switch"
              id="autoSendSwitch"
              <?= $autoSendEnabled ? 'checked' : '' ?>
              onchange="this.form.submit()"
            >
            <label class="form-check-label" for="autoSendSwitch">Auto Send</label>
          </div>
        </form>
      </div>
      <?php if (!$autoSendEnabled): ?>
        <div class="small text-muted mt-2">
          Manual send buttons are available for drivers in <strong>No Load in 3+ Days</strong>.
        </div>
      <?php endif; ?>
    </div>

    <form method="get" class="row g-3 align-items-end mb-3">
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
      <div class="col-12 col-md-3">
        <div class="form-check mt-4 pt-2">
          <input class="form-check-input" type="checkbox" id="showDisabledDrivers" name="show_disabled" value="1" <?= $showDisabledDrivers ? 'checked' : '' ?>>
          <label class="form-check-label" for="showDisabledDrivers">Show disabled drivers</label>
        </div>
      </div>
      <div class="col-12 col-md-2">
        <button type="submit" class="btn btn-outline-primary w-100">Apply</button>
      </div>
    </form>

    <?php if ($alertStats): ?>
      <div class="summary-card mb-3">
        <strong>Automatic Alert Run (<?= dlt_h($today) ?>)</strong>
        <div class="small mt-1">
          Eligible: <?= (int)$alertStats['eligible'] ?>,
          Email sent: <?= (int)$alertStats['sent_email'] ?>,
          Fuel-card admin email sent: <?= (int)($alertStats['sent_fuel_card_admin_email'] ?? 0) ?>,
          Text sent: <?= (int)$alertStats['sent_text'] ?>,
          Already sent today: <?= (int)$alertStats['skipped_already_sent'] ?>,
          Missing contact match: <?= (int)$alertStats['skipped_missing_contact'] ?>.
        </div>
        <?php if (!empty($alertStats['errors'])): ?>
          <div class="small text-danger mt-1">
            <?php foreach ($alertStats['errors'] as $err): ?>
              <div><?= dlt_h($err) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="table-responsive">
      <table class="table table-bordered table-striped table-sm">
        <thead>
          <tr>
            <th>#</th>
            <th>Driver</th>
            <th>Truck No.</th>
            <th>Trailer No.</th>
            <th>FC</th>
            <th>Client</th>
            <th>Status</th>
            <th>Last Load Date/Time</th>
            <th>Days Since Last Load</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Alert Sent Today</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($monitorRows)): ?>
            <tr>
              <td colspan="13" class="text-muted">No uploaded driver-load data found.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($monitorRows as $idx => $row): ?>
              <?php
                $contact = $row['driver_contact_id'] ? ($contactMap[(int)$row['driver_contact_id']] ?? null) : null;
                $email = $contact['email'] ?? '';
                $phone = $contact['phone'] ?? '';
                $status = (string)$row['status'];
                $fuelCardStatus = (string)($row['fuel_card_status'] ?? 'No');
                $dotClass = 'status-' . $status;
                $alertKey = $row['driver_contact_id']
                    ? ((int)$row['driver_contact_id'] . '|' . (int)$row['vendor_id'] . '|' . $status)
                    : '';
                $alertSentToday = ($status === 'yellow' || $status === 'red') && $alertKey !== '' && isset($alertSentLookup[$alertKey]);
                $rowClass = '';
                if (empty($row['driver_exists'])) {
                    $rowClass = 'missing-driver-row';
                } elseif ($fuelCardStatus === 'Active' && $status === 'yellow') {
                    $rowClass = 'fc-alert-yellow';
                } elseif ($fuelCardStatus === 'Active' && $status === 'red') {
                    $rowClass = 'fc-alert-red';
                }
              ?>
              <tr class="<?= dlt_h($rowClass) ?>">
                <td><?= (int)$idx + 1 ?></td>
                <td><?= dlt_h($row['driver_name']) ?></td>
                <td><?= dlt_h($row['truck_number'] ?? '') ?></td>
                <td><?= dlt_h($row['trailer_no'] ?? '') ?></td>
                <td><?= dlt_h($fuelCardStatus) ?></td>
                <td><?= dlt_h($row['vendor_name']) ?></td>
                <td>
                  <?php if ($isStatusUpdatedToday): ?>
                    <span class="status-dot <?= dlt_h($dotClass) ?>"></span>
                    <?= dlt_h(status_label($status)) ?>
                  <?php else: ?>
                    <span class="text-muted">Status has not yet been updated for today</span>
                  <?php endif; ?>
                </td>
                <td><?= dlt_h(date('m/d/Y H:i', strtotime($row['last_activity_at']))) ?></td>
                <td><?= (int)$row['days_since'] ?></td>
                <td><?= dlt_h($email) ?></td>
                <td><?= dlt_h($phone) ?></td>
                <td><?= $isStatusUpdatedToday ? ($alertSentToday ? 'Yes' : (($status === 'green') ? 'N/A' : 'No')) : 'N/A' ?></td>
                <td>
                  <?php if (!$autoSendEnabled && $status === 'red' && !empty($row['driver_contact_id'])): ?>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="send_manual_red_alert">
                      <input type="hidden" name="driver_contact_id" value="<?= (int)$row['driver_contact_id'] ?>">
                      <input type="hidden" name="vendor_id" value="<?= (int)$row['vendor_id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger">Send Alert Now</button>
                    </form>
                  <?php else: ?>
                    <span class="text-muted small">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body>
</html>
