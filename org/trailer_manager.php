<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/trailer_assignment_history.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');

$errors = [];
$success = '';
$vendorOptions = ['TSS', 'NexTier', 'DetMar', 'RTEX', 'Lone Star'];
$statusOptions = ['Owned', 'Leased'];
$trailerFeeModeOptions = ['percentage' => 'Percentage', 'flat' => 'Flat Rate / Day'];

function parse_decimal_input($value): ?float {
    $raw = preg_replace('/[^0-9\.\-]/', '', (string)$value);
    if ($raw === '' || $raw === '-' || $raw === '.' || $raw === '-.') {
        return null;
    }
    return (float)$raw;
}

function trailer_fee_display($mode, $value): string {
    if ($value === null || $value === '') {
        return '';
    }
    $amount = (float)$value;
    if ($mode === 'flat') {
        return '$' . number_format($amount, 2) . '/day';
    }
    return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.') . '%';
}

function default_trailer_fee_for_vendor(string $vendor): array {
    $normalizedVendor = strtolower(trim($vendor));
    if ($normalizedVendor === 'tss') {
        return ['percentage', 7.00];
    }
    if ($normalizedVendor === 'nextier') {
        return ['flat', 70.00];
    }
    return ['percentage', null];
}

try {
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS trailer_assets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            trailer_number VARCHAR(80) NOT NULL,
            vendor VARCHAR(150) NOT NULL,
            trailer_type VARCHAR(120) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Owned',
            nextier_trailer_fee DECIMAL(12,2) NULL,
            trailer_fee_mode VARCHAR(20) NOT NULL DEFAULT 'percentage',
            trailer_fee_value DECIMAL(12,2) NULL,
            comments TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_trailer_number (trailer_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $colRes = $mysqli->query(
        "SELECT COUNT(*)
           AS col_count
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'trailer_assets'
            AND COLUMN_NAME = 'status'"
    );
    $colRow = $colRes ? $colRes->fetch_assoc() : null;
    if ($colRes) {
        $colRes->close();
    }
    if ((int)($colRow['col_count'] ?? 0) === 0) {
        $mysqli->query(
            "ALTER TABLE trailer_assets
             ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Owned' AFTER trailer_type"
        );
    }
    $feeRes = $mysqli->query(
        "SELECT COUNT(*)
           AS col_count
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'trailer_assets'
            AND COLUMN_NAME = 'nextier_trailer_fee'"
    );
    $feeRow = $feeRes ? $feeRes->fetch_assoc() : null;
    if ($feeRes) {
        $feeRes->close();
    }
    if ((int)($feeRow['col_count'] ?? 0) === 0) {
        $mysqli->query(
            "ALTER TABLE trailer_assets
             ADD COLUMN nextier_trailer_fee DECIMAL(12,2) NULL AFTER status"
        );
    }
    $modeRes = $mysqli->query(
        "SELECT COUNT(*) AS col_count
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'trailer_assets'
            AND COLUMN_NAME = 'trailer_fee_mode'"
    );
    $modeRow = $modeRes ? $modeRes->fetch_assoc() : null;
    if ($modeRes) $modeRes->close();
    if ((int)($modeRow['col_count'] ?? 0) === 0) {
        $mysqli->query(
            "ALTER TABLE trailer_assets
             ADD COLUMN trailer_fee_mode VARCHAR(20) NOT NULL DEFAULT 'percentage' AFTER nextier_trailer_fee"
        );
    }
    $valueRes = $mysqli->query(
        "SELECT COUNT(*) AS col_count
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'trailer_assets'
            AND COLUMN_NAME = 'trailer_fee_value'"
    );
    $valueRow = $valueRes ? $valueRes->fetch_assoc() : null;
    if ($valueRes) $valueRes->close();
    if ((int)($valueRow['col_count'] ?? 0) === 0) {
        $mysqli->query(
            "ALTER TABLE trailer_assets
             ADD COLUMN trailer_fee_value DECIMAL(12,2) NULL AFTER trailer_fee_mode"
        );
        $mysqli->query(
            "UPDATE trailer_assets
                SET trailer_fee_mode = 'flat',
                    trailer_fee_value = nextier_trailer_fee
              WHERE nextier_trailer_fee IS NOT NULL
                AND trailer_fee_value IS NULL"
        );
    }
} catch (Throwable $e) {
    $errors[] = 'Unable to prepare trailer assets storage: ' . $e->getMessage();
}

try {
    $receivedDateRes = $mysqli->query(
        "SELECT COUNT(*) AS col_count
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'driver_contacts'
            AND COLUMN_NAME = 'trailer_received_date'"
    );
    $receivedDateRow = $receivedDateRes ? $receivedDateRes->fetch_assoc() : null;
    if ($receivedDateRes) $receivedDateRes->close();
    if ((int)($receivedDateRow['col_count'] ?? 0) === 0) {
        $mysqli->query("ALTER TABLE driver_contacts ADD COLUMN trailer_received_date DATE NULL AFTER trailer_no");
    }
    $removedDateRes = $mysqli->query(
        "SELECT COUNT(*) AS col_count
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'driver_contacts'
            AND COLUMN_NAME = 'trailer_removed_date'"
    );
    $removedDateRow = $removedDateRes ? $removedDateRes->fetch_assoc() : null;
    if ($removedDateRes) $removedDateRes->close();
    if ((int)($removedDateRow['col_count'] ?? 0) === 0) {
        $mysqli->query("ALTER TABLE driver_contacts ADD COLUMN trailer_removed_date DATE NULL AFTER trailer_received_date");
    }
} catch (Throwable $e) {
    $errors[] = 'Unable to prepare trailer received date storage: ' . $e->getMessage();
}

try {
    lonestar_trailer_history_ensure_table($mysqli);
} catch (Throwable $e) {
    $errors[] = 'Unable to prepare trailer assignment history storage: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'add_trailer') {
            $trailerNumber = trim((string)($_POST['trailer_number'] ?? ''));
            $vendor = trim((string)($_POST['vendor'] ?? ''));
            $trailerType = trim((string)($_POST['trailer_type'] ?? ''));
            $status = trim((string)($_POST['status'] ?? ''));
            $trailerFeeMode = trim((string)($_POST['trailer_fee_mode'] ?? 'percentage'));
            $trailerFeeValue = parse_decimal_input($_POST['trailer_fee_value'] ?? '');
            $comments = trim((string)($_POST['comments'] ?? ''));

            if ($trailerFeeValue === null) {
                [$trailerFeeMode, $trailerFeeValue] = default_trailer_fee_for_vendor($vendor);
            }
            $nextierTrailerFee = $trailerFeeMode === 'flat' ? $trailerFeeValue : null;

            if ($trailerNumber === '' || $vendor === '' || $trailerType === '' || $status === '') {
                $errors[] = 'Trailer Number, Client, Trailer Type, and Status are required.';
            } elseif (!in_array($vendor, $vendorOptions, true)) {
                $errors[] = 'Please select a valid Client.';
            } elseif (!in_array($status, $statusOptions, true)) {
                $errors[] = 'Please select a valid Status.';
            } elseif (!array_key_exists($trailerFeeMode, $trailerFeeModeOptions)) {
                $errors[] = 'Please select a valid Trailer Fee type.';
            } elseif ($trailerFeeValue !== null && $trailerFeeValue < 0) {
                $errors[] = 'Trailer Fee cannot be negative.';
            } elseif ($trailerFeeMode === 'percentage' && $trailerFeeValue !== null && $trailerFeeValue > 100) {
                $errors[] = 'Trailer Fee percentage cannot be greater than 100.';
            } else {
                $stmt = $mysqli->prepare(
                    "INSERT INTO trailer_assets (trailer_number, vendor, trailer_type, status, nextier_trailer_fee, trailer_fee_mode, trailer_fee_value, comments)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('ssssdsds', $trailerNumber, $vendor, $trailerType, $status, $nextierTrailerFee, $trailerFeeMode, $trailerFeeValue, $comments);
                $stmt->execute();
                $trailerId = $stmt->insert_id;
                $stmt->close();
                $success = 'Trailer added successfully.';
                audit_log_change($mysqli, 'create', 'trailer', $trailerId, 'Created trailer ' . $trailerNumber, null, [
                    'trailer_number' => $trailerNumber,
                    'vendor' => $vendor,
                    'trailer_type' => $trailerType,
                    'status' => $status,
                    'trailer_fee_mode' => $trailerFeeMode,
                    'trailer_fee_value' => $trailerFeeValue,
                ]);
            }
        } elseif ($action === 'edit_trailer') {
            $trailerId = (int)($_POST['trailer_id'] ?? 0);
            $trailerNumber = trim((string)($_POST['trailer_number'] ?? ''));
            $vendor = trim((string)($_POST['vendor'] ?? ''));
            $trailerType = trim((string)($_POST['trailer_type'] ?? ''));
            $status = trim((string)($_POST['status'] ?? ''));
            $trailerFeeMode = trim((string)($_POST['trailer_fee_mode'] ?? 'percentage'));
            $trailerFeeValue = parse_decimal_input($_POST['trailer_fee_value'] ?? '');
            $comments = trim((string)($_POST['comments'] ?? ''));

            if ($trailerFeeValue === null) {
                [$trailerFeeMode, $trailerFeeValue] = default_trailer_fee_for_vendor($vendor);
            }
            $nextierTrailerFee = $trailerFeeMode === 'flat' ? $trailerFeeValue : null;

            if ($trailerId <= 0 || $trailerNumber === '' || $vendor === '' || $trailerType === '' || $status === '') {
                $errors[] = 'A valid trailer plus Trailer Number, Client, Trailer Type, and Status are required.';
            } elseif (!in_array($vendor, $vendorOptions, true)) {
                $errors[] = 'Please select a valid Client.';
            } elseif (!in_array($status, $statusOptions, true)) {
                $errors[] = 'Please select a valid Status.';
            } elseif (!array_key_exists($trailerFeeMode, $trailerFeeModeOptions)) {
                $errors[] = 'Please select a valid Trailer Fee type.';
            } elseif ($trailerFeeValue !== null && $trailerFeeValue < 0) {
                $errors[] = 'Trailer Fee cannot be negative.';
            } elseif ($trailerFeeMode === 'percentage' && $trailerFeeValue !== null && $trailerFeeValue > 100) {
                $errors[] = 'Trailer Fee percentage cannot be greater than 100.';
            } else {
                $oldTrailerNumber = '';
                $oldTrailerStmt = $mysqli->prepare("SELECT trailer_number FROM trailer_assets WHERE id = ? LIMIT 1");
                if ($oldTrailerStmt) {
                    $oldTrailerStmt->bind_param('i', $trailerId);
                    $oldTrailerStmt->execute();
                    $oldTrailerStmt->bind_result($oldTrailerNumberValue);
                    if ($oldTrailerStmt->fetch()) {
                        $oldTrailerNumber = trim((string)$oldTrailerNumberValue);
                    }
                    $oldTrailerStmt->close();
                }

                $stmt = $mysqli->prepare(
                    "UPDATE trailer_assets
                        SET trailer_number = ?, vendor = ?, trailer_type = ?, status = ?, nextier_trailer_fee = ?, trailer_fee_mode = ?, trailer_fee_value = ?, comments = ?
                      WHERE id = ?
                      LIMIT 1"
                );
                $stmt->bind_param('ssssdsdsi', $trailerNumber, $vendor, $trailerType, $status, $nextierTrailerFee, $trailerFeeMode, $trailerFeeValue, $comments, $trailerId);
                $stmt->execute();
                $stmt->close();
                $historyUpdate = $mysqli->prepare(
                    "UPDATE trailer_assignment_history
                        SET trailer_id = ?,
                            trailer_number = ?,
                            updated_at = NOW()
                      WHERE trailer_id = ?
                         OR TRIM(trailer_number) = TRIM(?)"
                );
                if ($historyUpdate) {
                    $historyUpdate->bind_param('isis', $trailerId, $trailerNumber, $trailerId, $oldTrailerNumber);
                    $historyUpdate->execute();
                    $historyUpdate->close();
                }
                if ($oldTrailerNumber !== '' && strcasecmp($oldTrailerNumber, $trailerNumber) !== 0) {
                    $contactTrailerUpdate = $mysqli->prepare(
                        "UPDATE driver_contacts
                            SET trailer_no = ?
                          WHERE TRIM(COALESCE(trailer_no, '')) = TRIM(?)"
                    );
                    if ($contactTrailerUpdate) {
                        $contactTrailerUpdate->bind_param('ss', $trailerNumber, $oldTrailerNumber);
                        $contactTrailerUpdate->execute();
                        $contactTrailerUpdate->close();
                    }
                }
                $success = 'Trailer updated successfully.';
                audit_log_change($mysqli, 'update', 'trailer', $trailerId, 'Updated trailer ' . $trailerNumber, null, [
                    'trailer_number' => $trailerNumber,
                    'vendor' => $vendor,
                    'trailer_type' => $trailerType,
                    'status' => $status,
                    'trailer_fee_mode' => $trailerFeeMode,
                    'trailer_fee_value' => $trailerFeeValue,
                ]);
            }
        } elseif ($action === 'remove_driver') {
            $trailerId = (int)($_POST['trailer_id'] ?? 0);
            if ($trailerId <= 0) {
                $errors[] = 'A valid trailer is required to remove the driver assignment.';
            } else {
                $lookupStmt = $mysqli->prepare(
                    "SELECT trailer_number
                       FROM trailer_assets
                      WHERE id = ?
                      LIMIT 1"
                );
                $lookupStmt->bind_param('i', $trailerId);
                $lookupStmt->execute();
                $lookupStmt->bind_result($assignedTrailerNumber);
                $foundTrailer = $lookupStmt->fetch();
                $lookupStmt->close();

                if (!$foundTrailer || trim((string)$assignedTrailerNumber) === '') {
                    $errors[] = 'The selected trailer could not be found.';
                } else {
                    $assignedTrailerNumber = trim((string)$assignedTrailerNumber);
                    $assignedDrivers = [];
                    $driverLookup = $mysqli->prepare(
                        "SELECT tah.driver_contact_id, tah.assigned_date, dc.trailer_removed_date
                           FROM trailer_assignment_history tah
                           JOIN driver_contacts dc
                             ON dc.id = tah.driver_contact_id
                          WHERE TRIM(COALESCE(tah.trailer_number, '')) = TRIM(?)
                            AND tah.removed_date IS NULL
                            AND COALESCE(dc.is_disabled, 0) = 0"
                    );
                    if ($driverLookup) {
                        $driverLookup->bind_param('s', $assignedTrailerNumber);
                        $driverLookup->execute();
                        $driverRes = $driverLookup->get_result();
                        while ($driverRow = $driverRes->fetch_assoc()) {
                            $assignedDrivers[] = [
                                'id' => (int)$driverRow['id'],
                                'assigned_date' => (string)($driverRow['assigned_date'] ?? ''),
                                'removed_date' => (string)($driverRow['trailer_removed_date'] ?? ''),
                            ];
                        }
                        $driverLookup->close();
                    }

                    $blockedRemove = false;
                    foreach ($assignedDrivers as $assignedDriver) {
                        $removedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $assignedDriver['removed_date'])
                            ? $assignedDriver['removed_date']
                            : date('Y-m-d');
                        if (
                            preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$assignedDriver['assigned_date'])
                            && $removedDate < (string)$assignedDriver['assigned_date']
                        ) {
                            $errors[] = 'Trailer removed date cannot be earlier than the received date for trailer ' . $assignedTrailerNumber . '.';
                            $blockedRemove = true;
                            continue;
                        }
                        lonestar_trailer_history_close_open($mysqli, (int)$assignedDriver['id'], $removedDate, $assignedTrailerNumber);
                    }

                    if (!$blockedRemove) {
                        $stmt = $mysqli->prepare(
                            "UPDATE driver_contacts
                                SET trailer_no = NULL,
                                    trailer_received_date = NULL
                              WHERE TRIM(COALESCE(trailer_no, '')) = TRIM(?)
                                AND COALESCE(is_disabled, 0) = 0"
                        );
                        $stmt->bind_param('s', $assignedTrailerNumber);
                        $stmt->execute();
                        $removedAssignments = $stmt->affected_rows;
                        $stmt->close();

                        $success = count($assignedDrivers) > 0
                            ? 'Driver removed from trailer and set to unassigned.'
                            : 'No active driver assignment was found for that trailer.';
                        audit_log_change($mysqli, 'remove_assignment', 'trailer', $trailerId, 'Removed driver assignment from trailer ' . $assignedTrailerNumber, null, [
                            'trailer_number' => $assignedTrailerNumber,
                            'removed_date_source' => 'driver_contacts',
                        ]);
                    }
                }
            }
        } elseif ($action === 'delete_trailer') {
            $trailerId = (int)($_POST['trailer_id'] ?? 0);
            if ($trailerId <= 0) {
                $errors[] = 'A valid trailer is required to delete.';
            } else {
                $lookupStmt = $mysqli->prepare(
                    "SELECT trailer_number
                       FROM trailer_assets
                      WHERE id = ?
                      LIMIT 1"
                );
                $lookupStmt->bind_param('i', $trailerId);
                $lookupStmt->execute();
                $lookupStmt->bind_result($deleteTrailerNumber);
                $foundTrailer = $lookupStmt->fetch();
                $lookupStmt->close();

                if (!$foundTrailer || trim((string)$deleteTrailerNumber) === '') {
                    $errors[] = 'The selected trailer could not be found.';
                } else {
                    $deleteTrailerNumber = trim((string)$deleteTrailerNumber);

                    $mysqli->begin_transaction();
                    try {
                        $unassignStmt = $mysqli->prepare(
                            "UPDATE driver_contacts
                                SET trailer_no = NULL,
                                    trailer_received_date = NULL
                              WHERE TRIM(COALESCE(trailer_no, '')) = TRIM(?)"
                        );
                        $unassignStmt->bind_param('s', $deleteTrailerNumber);
                        $unassignStmt->execute();
                        $unassignStmt->close();

                        $historyLookup = $mysqli->prepare(
                            "SELECT driver_contact_id
                               FROM trailer_assignment_history
                              WHERE TRIM(trailer_number) = TRIM(?)
                                AND removed_date IS NULL"
                        );
                        if ($historyLookup) {
                            $historyLookup->bind_param('s', $deleteTrailerNumber);
                            $historyLookup->execute();
                            $historyRes = $historyLookup->get_result();
                            while ($historyDriverRow = $historyRes->fetch_assoc()) {
                                lonestar_trailer_history_close_open($mysqli, (int)$historyDriverRow['driver_contact_id'], date('Y-m-d'), $deleteTrailerNumber);
                            }
                            $historyLookup->close();
                        }

                        $stmt = $mysqli->prepare("DELETE FROM trailer_assets WHERE id = ? LIMIT 1");
                        $stmt->bind_param('i', $trailerId);
                        $stmt->execute();
                        $stmt->close();

                        $mysqli->commit();
                        $success = 'Trailer deleted successfully.';
                        audit_log_change($mysqli, 'delete', 'trailer', $trailerId, 'Deleted trailer ' . $deleteTrailerNumber);
                    } catch (Throwable $deleteError) {
                        $mysqli->rollback();
                        throw $deleteError;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if (stripos($message, 'Duplicate entry') !== false) {
            $errors[] = 'That Trailer Number already exists.';
        } else {
            $errors[] = 'Trailer manager action failed: ' . $message;
        }
    }
}

$trailers = [];
if (empty($errors) || !empty($success)) {
    try {
        $res = $mysqli->query(
            "SELECT ta.id,
                    ta.trailer_number,
                    ta.vendor,
                    ta.trailer_type,
                    ta.status,
                    ta.nextier_trailer_fee,
                    ta.trailer_fee_mode,
                    ta.trailer_fee_value,
                    ta.comments,
                    ta.updated_at,
                    current_assignment.assigned_date AS trailer_received_date,
                    COALESCE(current_assignment.removed_date, latest_removed.removed_date) AS trailer_removed_date,
                    dc.id AS driver_contact_id,
                    CONCAT(COALESCE(dc.first_name, ''), ' ', COALESCE(dc.last_name, '')) AS driver_name
               FROM trailer_assets ta
          LEFT JOIN trailer_assignment_history current_assignment
                 ON TRIM(COALESCE(current_assignment.trailer_number, '')) = TRIM(COALESCE(ta.trailer_number, ''))
                AND current_assignment.removed_date IS NULL
          LEFT JOIN driver_contacts dc
                 ON dc.id = current_assignment.driver_contact_id
                AND COALESCE(dc.is_disabled, 0) = 0
          LEFT JOIN (
                    SELECT trailer_number, MAX(removed_date) AS removed_date
                      FROM trailer_assignment_history
                     WHERE removed_date IS NOT NULL
                  GROUP BY trailer_number
          ) latest_removed
                 ON TRIM(COALESCE(latest_removed.trailer_number, '')) = TRIM(COALESCE(ta.trailer_number, ''))
           ORDER BY trailer_number ASC"
        );
        while ($row = $res->fetch_assoc()) {
            $trailers[] = $row;
        }
        $res->close();
    } catch (Throwable $e) {
        $errors[] = 'Unable to load trailers: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Trailer Manager</title>
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

    .hero-card {
      background:linear-gradient(135deg, #ffffff 0%, #eef4ff 100%);
      border:1px solid #d9e3f0;
      border-radius:18px;
      padding:20px 22px;
      box-shadow:0 10px 30px rgba(17, 24, 39, 0.06);
    }

    .table-card {
      margin-top:20px;
      background:#fff;
      border:1px solid #dde4ee;
      border-radius:18px;
      padding:18px;
      box-shadow:0 10px 24px rgba(17, 24, 39, 0.05);
    }

    .table th, .table td { vertical-align:middle; }
    .assignment-note {
      color:#b91c1c;
      font-weight:700;
      margin-top:12px;
    }
    .table-tools {
      display:flex;
      flex-wrap:wrap;
      justify-content:space-between;
      align-items:center;
      gap:12px;
      margin-bottom:14px;
    }
    .table-tools .form-control { max-width:320px; }
    .sort-btn {
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding:0;
      border:none;
      background:none;
      color:inherit;
      font:inherit;
      font-weight:600;
      cursor:pointer;
    }
    .sort-indicator {
      font-size:11px;
      color:#64748b;
    }
    .column-filter-row input {
      min-width:110px;
    }
    .column-filter-row th:first-child input {
      display:none;
    }
    .unassigned-row > td {
      background:#fff7cc !important;
    }
    .driver-reference {
      color:#b91c1c !important;
      font-weight:700;
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/includes/ls_title.php'; ?>
<div class="page-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main">
    <div class="hero-card">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
          <h1 class="mb-1">Trailer Manager</h1>
          <div class="text-muted">Track trailer assets, vendors, trailer types, comments, and the driver currently tied to each trailer from Driver Details.</div>
          <div class="assignment-note">Driver assignments cannot be made on this page and must be made on the Driver Contacts page.</div>
        </div>
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addTrailerModal">Add Trailer</button>
      </div>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger mt-3 mb-0">
        <ul class="mb-0">
          <?php foreach ($errors as $error): ?>
            <li><?= htmlspecialchars($error, ENT_QUOTES) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php elseif ($success !== ''): ?>
      <div class="alert alert-success mt-3 mb-0"><?= htmlspecialchars($success, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <div class="table-card">
      <div class="table-tools">
        <div class="d-flex flex-wrap align-items-center gap-3">
          <div class="fw-semibold">Trailer Inventory</div>
          <div class="form-check m-0">
            <input class="form-check-input" type="checkbox" id="onlyUnassignedToggle">
            <label class="form-check-label" for="onlyUnassignedToggle">Only Show Unassigned Trailers</label>
          </div>
        </div>
        <input
          type="search"
          id="tableGlobalSearch"
          class="form-control form-control-sm"
          placeholder="Search all trailer columns"
        >
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle" id="trailerTable">
          <thead>
            <tr>
              <th><button type="button" class="sort-btn" data-sort-index="0"># <span class="sort-indicator">↑</span></button></th>
              <th><button type="button" class="sort-btn" data-sort-index="1">Driver Name <span class="sort-indicator">↑</span></button></th>
              <th><button type="button" class="sort-btn" data-sort-index="2">Trailer Number <span class="sort-indicator">↑</span></button></th>
              <th><button type="button" class="sort-btn" data-sort-index="3">Client <span class="sort-indicator">↑</span></button></th>
              <th><button type="button" class="sort-btn" data-sort-index="4">Trailer Type <span class="sort-indicator">↑</span></button></th>
              <th><button type="button" class="sort-btn" data-sort-index="5">Status <span class="sort-indicator">↑</span></button></th>
              <th><button type="button" class="sort-btn" data-sort-index="6">Trailer Fee <span class="sort-indicator">↑</span></button></th>
              <th><button type="button" class="sort-btn" data-sort-index="7">Comments <span class="sort-indicator">↑</span></button></th>
              <th><button type="button" class="sort-btn" data-sort-index="8">Received Date <span class="sort-indicator">â†‘</span></button></th>
              <th><button type="button" class="sort-btn" data-sort-index="9">Removed Date <span class="sort-indicator">â†‘</span></button></th>
              <th>Actions</th>
            </tr>
            <tr class="column-filter-row">
              <th><input type="search" class="form-control form-control-sm column-filter" data-filter-index="0" placeholder="Filter #"></th>
              <th><input type="search" class="form-control form-control-sm column-filter" data-filter-index="1" placeholder="Filter driver"></th>
              <th><input type="search" class="form-control form-control-sm column-filter" data-filter-index="2" placeholder="Filter trailer"></th>
              <th><input type="search" class="form-control form-control-sm column-filter" data-filter-index="3" placeholder="Filter client"></th>
              <th><input type="search" class="form-control form-control-sm column-filter" data-filter-index="4" placeholder="Filter type"></th>
              <th><input type="search" class="form-control form-control-sm column-filter" data-filter-index="5" placeholder="Filter status"></th>
              <th><input type="search" class="form-control form-control-sm column-filter" data-filter-index="6" placeholder="Filter fee"></th>
              <th><input type="search" class="form-control form-control-sm column-filter" data-filter-index="7" placeholder="Filter comments"></th>
              <th><input type="search" class="form-control form-control-sm column-filter" data-filter-index="8" placeholder="Filter received"></th>
              <th><input type="search" class="form-control form-control-sm column-filter" data-filter-index="9" placeholder="Filter removed"></th>
              <th></th>
            </tr>
          </thead>
          <tbody id="trailerTableBody">
            <?php if (!$trailers): ?>
              <tr>
                <td colspan="11" class="text-muted">No trailers have been added yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($trailers as $index => $trailer): ?>
                <?php $driverName = trim((string)($trailer['driver_name'] ?? '')); ?>
                <tr class="<?= $driverName === '' ? 'unassigned-row' : '' ?>">
                  <td><?= (int)$index + 1 ?></td>
                  <td class="driver-reference"><?= htmlspecialchars($driverName !== '' ? $driverName : 'Unassigned', ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars((string)$trailer['trailer_number'], ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars((string)$trailer['vendor'], ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars((string)$trailer['trailer_type'], ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars((string)$trailer['status'], ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars(trailer_fee_display($trailer['trailer_fee_mode'] ?? 'percentage', $trailer['trailer_fee_value'] ?? null), ENT_QUOTES) ?></td>
                  <td><?= nl2br(htmlspecialchars((string)($trailer['comments'] ?? ''), ENT_QUOTES)) ?></td>
                  <td><?= htmlspecialchars((string)($trailer['trailer_received_date'] ?? ''), ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars((string)($trailer['trailer_removed_date'] ?? ''), ENT_QUOTES) ?></td>
                  <td>
                    <?php if ($driverName !== ''): ?>
                      <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="remove_driver">
                        <input type="hidden" name="trailer_id" value="<?= (int)$trailer['id'] ?>">
                        <button
                          class="btn btn-sm btn-outline-warning"
                          type="submit"
                          onclick="return confirm('Remove this driver from the trailer and set them to unassigned? The removed date is maintained on Driver Contacts.');"
                        >
                          Remove Driver
                        </button>
                      </form>
                    <?php endif; ?>
                    <button
                      class="btn btn-sm btn-outline-primary edit-trailer-btn"
                      type="button"
                      data-bs-toggle="modal"
                      data-bs-target="#editTrailerModal"
                      data-trailer-id="<?= (int)$trailer['id'] ?>"
                      data-trailer-number="<?= htmlspecialchars((string)$trailer['trailer_number'], ENT_QUOTES) ?>"
                      data-vendor="<?= htmlspecialchars((string)$trailer['vendor'], ENT_QUOTES) ?>"
                      data-trailer-type="<?= htmlspecialchars((string)$trailer['trailer_type'], ENT_QUOTES) ?>"
                      data-status="<?= htmlspecialchars((string)$trailer['status'], ENT_QUOTES) ?>"
                      data-trailer-fee-mode="<?= htmlspecialchars((string)($trailer['trailer_fee_mode'] ?? 'percentage'), ENT_QUOTES) ?>"
                      data-trailer-fee-value="<?= htmlspecialchars((string)($trailer['trailer_fee_value'] ?? ''), ENT_QUOTES) ?>"
                      data-comments="<?= htmlspecialchars((string)($trailer['comments'] ?? ''), ENT_QUOTES) ?>"
                    >
                      Edit
                    </button>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="delete_trailer">
                      <input type="hidden" name="trailer_id" value="<?= (int)$trailer['id'] ?>">
                      <button
                        class="btn btn-sm btn-outline-danger"
                        type="submit"
                        onclick="return confirm('Are you sure you want to delete this trailer? This will permanently delete the trailer, and it will need to be added back if this is done in error.');"
                      >
                        Delete Trailer
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<div class="modal fade" id="addTrailerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="add_trailer">
        <div class="modal-header">
          <h5 class="modal-title">Add Trailer</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Trailer Number</label>
            <input type="text" name="trailer_number" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Client</label>
            <select name="vendor" class="form-select trailer-vendor-select" required>
              <option value="">Select client</option>
              <?php foreach ($vendorOptions as $vendorOption): ?>
                <option value="<?= htmlspecialchars($vendorOption, ENT_QUOTES) ?>"><?= htmlspecialchars($vendorOption, ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Trailer Type</label>
            <input type="text" name="trailer_type" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select" required>
              <?php foreach ($statusOptions as $statusOption): ?>
                <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES) ?>" <?= $statusOption === 'Owned' ? 'selected' : '' ?>>
                  <?= htmlspecialchars($statusOption, ENT_QUOTES) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Trailer Fee</label>
            <div class="row g-2">
              <div class="col-5">
                <select name="trailer_fee_mode" class="form-select trailer-fee-mode">
                  <?php foreach ($trailerFeeModeOptions as $modeValue => $modeLabel): ?>
                    <option value="<?= htmlspecialchars($modeValue, ENT_QUOTES) ?>"><?= htmlspecialchars($modeLabel, ENT_QUOTES) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-7">
                <div class="input-group">
                  <input type="number" name="trailer_fee_value" step="0.01" min="0" max="100" class="form-control trailer-fee-value" data-default-managed="1">
                  <span class="input-group-text trailer-fee-unit">%</span>
                </div>
              </div>
            </div>
          </div>
          <div>
            <label class="form-label">Comments</label>
            <textarea name="comments" class="form-control" rows="4"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Trailer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="editTrailerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="edit_trailer">
        <input type="hidden" name="trailer_id" id="editTrailerId">
        <div class="modal-header">
          <h5 class="modal-title">Edit Trailer</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Trailer Number</label>
            <input type="text" name="trailer_number" id="editTrailerNumber" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Client</label>
            <select name="vendor" id="editVendor" class="form-select trailer-vendor-select" required>
              <option value="">Select client</option>
              <?php foreach ($vendorOptions as $vendorOption): ?>
                <option value="<?= htmlspecialchars($vendorOption, ENT_QUOTES) ?>"><?= htmlspecialchars($vendorOption, ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Trailer Type</label>
            <input type="text" name="trailer_type" id="editTrailerType" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" id="editStatus" class="form-select" required>
              <?php foreach ($statusOptions as $statusOption): ?>
                <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES) ?>"><?= htmlspecialchars($statusOption, ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Trailer Fee</label>
            <div class="row g-2">
              <div class="col-5">
                <select name="trailer_fee_mode" id="editTrailerFeeMode" class="form-select trailer-fee-mode">
                  <?php foreach ($trailerFeeModeOptions as $modeValue => $modeLabel): ?>
                    <option value="<?= htmlspecialchars($modeValue, ENT_QUOTES) ?>"><?= htmlspecialchars($modeLabel, ENT_QUOTES) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-7">
                <div class="input-group">
                  <input type="number" name="trailer_fee_value" id="editTrailerFeeValue" step="0.01" min="0" max="100" class="form-control trailer-fee-value" data-default-managed="0">
                  <span class="input-group-text trailer-fee-unit">%</span>
                </div>
              </div>
            </div>
          </div>
          <div>
            <label class="form-label">Comments</label>
            <textarea name="comments" id="editComments" class="form-control" rows="4"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.edit-trailer-btn').forEach(function (button) {
  button.addEventListener('click', function () {
    document.getElementById('editTrailerId').value = this.dataset.trailerId || '';
    document.getElementById('editTrailerNumber').value = this.dataset.trailerNumber || '';
    document.getElementById('editVendor').value = this.dataset.vendor || '';
    document.getElementById('editTrailerType').value = this.dataset.trailerType || '';
    document.getElementById('editStatus').value = this.dataset.status || 'Owned';
    document.getElementById('editTrailerFeeMode').value = this.dataset.trailerFeeMode || 'percentage';
    document.getElementById('editTrailerFeeValue').value = this.dataset.trailerFeeValue || '';
    document.getElementById('editTrailerFeeValue').dataset.defaultManaged = this.dataset.trailerFeeValue ? '0' : '1';
    document.getElementById('editComments').value = this.dataset.comments || '';
    updateTrailerFeeControls(document.getElementById('editTrailerFeeMode'));
  });
});

function trailerFeeDefaultForVendor(vendor) {
  var normalized = (vendor || '').toString().trim().toLowerCase();
  if (normalized === 'tss') {
    return { mode: 'percentage', value: '7.00' };
  }
  if (normalized === 'nextier') {
    return { mode: 'flat', value: '70.00' };
  }
  return null;
}

function applyVendorTrailerFeeDefault(vendorSelect, force) {
  if (!vendorSelect) return;
  var form = vendorSelect.closest('form');
  if (!form) return;
  var modeSelect = form.querySelector('.trailer-fee-mode');
  var valueInput = form.querySelector('.trailer-fee-value');
  var defaults = trailerFeeDefaultForVendor(vendorSelect.value);
  if (!modeSelect || !valueInput || !defaults) return;
  if (!force && valueInput.value !== '' && valueInput.dataset.defaultManaged !== '1') return;
  modeSelect.value = defaults.mode;
  valueInput.value = defaults.value;
  valueInput.dataset.defaultManaged = '1';
  updateTrailerFeeControls(modeSelect);
}

function updateTrailerFeeControls(modeSelect) {
  if (!modeSelect) return;
  var container = modeSelect.closest('.mb-3') || modeSelect.parentElement;
  var valueInput = container ? container.querySelector('.trailer-fee-value') : null;
  var unit = container ? container.querySelector('.trailer-fee-unit') : null;
  var isFlat = modeSelect.value === 'flat';
  if (valueInput) {
    valueInput.max = isFlat ? '' : '100';
  }
  if (unit) {
    unit.textContent = isFlat ? '$/day' : '%';
  }
}

document.querySelectorAll('.trailer-fee-mode').forEach(function (select) {
  updateTrailerFeeControls(select);
  select.addEventListener('change', function () {
    var form = select.closest('form');
    var valueInput = form ? form.querySelector('.trailer-fee-value') : null;
    if (valueInput) valueInput.dataset.defaultManaged = '0';
    updateTrailerFeeControls(select);
  });
});

document.querySelectorAll('.trailer-fee-value').forEach(function (input) {
  input.addEventListener('input', function () {
    input.dataset.defaultManaged = '0';
  });
});

document.querySelectorAll('.trailer-vendor-select').forEach(function (select) {
  select.addEventListener('change', function () {
    applyVendorTrailerFeeDefault(select, false);
  });
});

(function () {
  var table = document.getElementById('trailerTable');
  var tbody = document.getElementById('trailerTableBody');
  if (!table || !tbody) return;

  var globalSearch = document.getElementById('tableGlobalSearch');
  var onlyUnassignedToggle = document.getElementById('onlyUnassignedToggle');
  var columnFilters = Array.prototype.slice.call(document.querySelectorAll('.column-filter'));
  var sortButtons = Array.prototype.slice.call(document.querySelectorAll('.sort-btn'));
  var sortState = { index: null, direction: 'asc' };

  function normalize(value) {
    return (value || '').toString().trim().toLowerCase();
  }

  function getRows() {
    return Array.prototype.slice.call(tbody.querySelectorAll('tr')).filter(function (row) {
      return row.children.length > 1;
    });
  }

  function cellValue(row, index) {
    var cell = row.children[index];
    return cell ? normalize(cell.textContent) : '';
  }

  function updateVisibleRowNumbers() {
    var visibleIndex = 0;
    getRows().forEach(function (row) {
      if (row.style.display === 'none') {
        return;
      }
      visibleIndex += 1;
      if (row.children[0]) {
        row.children[0].textContent = visibleIndex;
      }
    });
  }

  function applyFilters() {
    var globalValue = normalize(globalSearch ? globalSearch.value : '');
    var rows = getRows();

    rows.forEach(function (row) {
      var matchesGlobal = true;
      if (globalValue !== '') {
        matchesGlobal = normalize(row.textContent).indexOf(globalValue) !== -1;
      }

      var matchesColumns = columnFilters.every(function (input) {
        var filterValue = normalize(input.value);
        if (filterValue === '') return true;
        var columnIndex = parseInt(input.getAttribute('data-filter-index'), 10);
        if (columnIndex === 0) return true;
        return cellValue(row, columnIndex).indexOf(filterValue) !== -1;
      });

      var matchesUnassigned = true;
      if (onlyUnassignedToggle && onlyUnassignedToggle.checked) {
        matchesUnassigned = row.classList.contains('unassigned-row');
      }

      row.style.display = (matchesGlobal && matchesColumns && matchesUnassigned) ? '' : 'none';
    });

    updateVisibleRowNumbers();
  }

  function sortRows(index) {
    if (index === 0) {
      updateVisibleRowNumbers();
      return;
    }

    var rows = getRows();
    if (sortState.index === index) {
      sortState.direction = sortState.direction === 'asc' ? 'desc' : 'asc';
    } else {
      sortState.index = index;
      sortState.direction = 'asc';
    }

    rows.sort(function (a, b) {
      var aValue = cellValue(a, index);
      var bValue = cellValue(b, index);
      var aNum = parseFloat(aValue);
      var bNum = parseFloat(bValue);
      var isNumeric = !isNaN(aNum) && !isNaN(bNum);
      var comparison = 0;

      if (isNumeric) {
        comparison = aNum - bNum;
      } else {
        comparison = aValue.localeCompare(bValue);
      }

      return sortState.direction === 'asc' ? comparison : -comparison;
    });

    rows.forEach(function (row) {
      tbody.appendChild(row);
    });

    sortButtons.forEach(function (button) {
      var indicator = button.querySelector('.sort-indicator');
      if (!indicator) return;
      var buttonIndex = parseInt(button.getAttribute('data-sort-index'), 10);
      if (buttonIndex === sortState.index) {
        indicator.textContent = sortState.direction === 'asc' ? '↑' : '↓';
      } else {
        indicator.textContent = '↑';
      }
    });

    applyFilters();
  }

  if (globalSearch) {
    globalSearch.addEventListener('input', applyFilters);
  }

  if (onlyUnassignedToggle) {
    onlyUnassignedToggle.addEventListener('change', applyFilters);
  }

  columnFilters.forEach(function (input) {
    input.addEventListener('input', applyFilters);
  });

  sortButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      var index = parseInt(button.getAttribute('data-sort-index'), 10);
      sortRows(index);
    });
  });

  updateVisibleRowNumbers();
})();
</script>
</body>
</html>
