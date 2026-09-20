<?php
// driver_payouts_lookup.php
// Shows a driver dropdown (contacts table only) and lists all payouts that have been matched
// to that contact, regardless of which alias/name the original report used.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php'; // provides $mysqli

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');

// ---------------------------
// Helpers
// ---------------------------
function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES); }
function digits_only($s) { return preg_replace('/\D+/', '', (string)$s); }
function norm_full($name) { return strtolower(trim(preg_replace('/\s+/', ' ', (string)$name))); }
function norm_name($first, $last) { return norm_full(trim(($first ?? '') . ' ' . ($last ?? ''))); }
function money_to_float($s) {
  // Converts strings like "1,234.56" or "$800" to float
  if ($s === null || $s === '') return 0.0;
  $n = preg_replace('/[^0-9\.-]/', '', (string)$s);
  if ($n === '' || $n === '-' || $n === '.' || $n === '-.') return 0.0;
  return (float)$n;
}

$errors = [];
$success = false;

// ---------------------------
// Fetch driver list (contacts only)
// ---------------------------
$drivers = [];
$qd = $mysqli->query("SELECT id, first_name, last_name, truck_no, alt_truck_no FROM driver_contacts ORDER BY last_name, first_name");
while ($row = $qd->fetch_assoc()) { $drivers[] = $row; }
$qd->close();

// Index contacts for quick lookup
$driversById = [];
foreach ($drivers as $d) { $driversById[(int)$d['id']] = $d; }

// Selected driver
$selectedId = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : 0;
$dateFrom = $_GET['from'] ?? '';
$dateTo   = $_GET['to']   ?? '';

// Optional: include unmatched rows that name-match aliases (off by default)
$includeUnmatched = !empty($_GET['include_unmatched']);

// Compute alias list for the selected contact (for display/reference and optional matching)
$aliasList = [];
if ($selectedId && isset($driversById[$selectedId])) {
  $primary = $driversById[$selectedId];
  $aliasList[] = norm_name($primary['first_name'] ?? '', $primary['last_name'] ?? '');
  $stmtA = $mysqli->prepare("SELECT alias_full_norm FROM driver_name_aliases WHERE driver_contact_id = ?");
  $stmtA->bind_param('i', $selectedId);
  $stmtA->execute();
  $resA = $stmtA->get_result();
  while ($a = $resA->fetch_assoc()) {
    $al = strtolower(trim($a['alias_full_norm'] ?? ''));
    if ($al !== '') $aliasList[] = $al;
  }
  $stmtA->close();
  $aliasList = array_values(array_unique($aliasList));
}

// Build filters for SQL
$where = ['1=1'];
$params = [];
$types  = '';

if ($selectedId) {
  $where[] = 'driver_contact_id = ?';
  $types  .= 'i';
  $params[] = $selectedId;
}

if ($dateFrom !== '') { $where[] = 'payout_date >= ?'; $types .= 's'; $params[] = $dateFrom; }
if ($dateTo   !== '') { $where[] = 'payout_date <= ?'; $types .= 's'; $params[] = $dateTo; }

$matchedRows = [];
$matchedTotal = 0.0;
$matchedCount = 0;

if ($selectedId) {
  $sql = "SELECT id, payout_date, ticket_number, driver_name, vendor_name, tss_pay, upload_date, driver_contact_id
          FROM driver_payouts
          WHERE " . implode(' AND ', $where) . "
          ORDER BY payout_date DESC, id DESC";
  $stmt = $mysqli->prepare($sql);
  if ($types !== '') { $stmt->bind_param($types, ...$params); }
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    $matchedRows[] = $r;
    $matchedTotal += money_to_float($r['tss_pay'] ?? 0);
    $matchedCount++;
  }
  $stmt->close();
}

// Optionally pull unmatched rows whose driver_name text matches an alias for this driver
$unmatchedRows = [];
$unmatchedTotal = 0.0;
$unmatchedCount = 0;

if ($selectedId && $includeUnmatched && !empty($aliasList)) {
  // Pull a reasonable window (filter by dates if provided) and then filter in PHP by normalized name
  $whereU = ['driver_contact_id IS NULL'];
  $typesU = '';
  $paramsU = [];
  if ($dateFrom !== '') { $whereU[] = 'payout_date >= ?'; $typesU .= 's'; $paramsU[] = $dateFrom; }
  if ($dateTo   !== '') { $whereU[] = 'payout_date <= ?'; $typesU .= 's'; $paramsU[] = $dateTo; }
  $sqlU = "SELECT id, payout_date, ticket_number, driver_name, vendor_name, tss_pay, upload_date, driver_contact_id
           FROM driver_payouts
           WHERE " . implode(' AND ', $whereU) . "
           ORDER BY payout_date DESC, id DESC";
  $stmtU = $mysqli->prepare($sqlU);
  if ($typesU !== '') { $stmtU->bind_param($typesU, ...$paramsU); }
  $stmtU->execute();
  $resU = $stmtU->get_result();
  while ($r = $resU->fetch_assoc()) {
    $norm = norm_full($r['driver_name'] ?? '');
    if ($norm !== '' && in_array($norm, $aliasList, true)) {
      $unmatchedRows[] = $r;
      $unmatchedTotal += money_to_float($r['tss_pay'] ?? 0);
      $unmatchedCount++;
    }
  }
  $stmtU->close();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Driver Payouts Lookup</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root { --sidebar-w: 250px; }
    html, body { height:100%; }
    body { margin:0; font-family:sans-serif; overflow:auto; }
    .page-shell { display:flex; min-height: calc(100vh - var(--banner-h)); }
    .sidebar { width:var(--sidebar-w); background:#333; color:#fff; height:calc(100vh - var(--banner-h)); position:fixed; top:var(--banner-h); left:0; overflow:auto; }
    .sidebar.collapsed { transform:translateX(-250px); }
    .sidebar a { display:block; color:#fff; padding:15px; text-decoration:none; }
    .sidebar a:hover { background:#444; }
    .main { margin-left:var(--sidebar-w); padding:20px; flex:1; min-width:0; height:calc(100vh - var(--banner-h)); overflow:auto; -webkit-overflow-scrolling:touch; }
    .sidebar.collapsed + .main { margin-left:0; }
    @media (max-width: 768px) { .sidebar { transform:translateX(-250px); } .sidebar.open { transform:translateX(0); } .main { margin:0; } }
    .nowrap { white-space:nowrap; }
    .table-sm .currency { text-align:right; }
    .summary { background:#f7f7f7; border:1px solid #e5e5e5; border-radius:8px; padding:12px 16px; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main">

    <div class="d-flex align-items-center justify-content-between">
      <h1 class="mb-0">Driver Payouts Lookup</h1>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger mt-3">
        <strong>Errors:</strong>
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?>
            <li><?= h($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="get" class="row g-3 mt-3 align-items-end">
      <div class="col-12 col-md-5">
        <label class="form-label">Driver (from Contacts)</label>
        <select name="driver_id" class="form-select" required>
          <option value="">— Select Driver —</option>
          <?php foreach ($drivers as $d): ?>
            <?php
              $label = trim(($d['last_name'] ?? '') . ', ' . ($d['first_name'] ?? ''));
              $truck = trim(($d['truck_no'] ?? ''));
              $alt   = trim(($d['alt_truck_no'] ?? ''));
              $extra = $truck ? (" · Truck " . h($truck)) : '';
              if ($alt) $extra .= " · Alt " . h($alt);
            ?>
            <option value="<?= (int)$d['id'] ?>" <?= ($selectedId === (int)$d['id']) ? 'selected' : '' ?>>
              <?= h($label) ?><?= $extra ? ' ' . $extra : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">From</label>
        <input type="date" name="from" value="<?= h($dateFrom) ?>" class="form-control">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">To</label>
        <input type="date" name="to" value="<?= h($dateTo) ?>" class="form-control">
      </div>
      <div class="col-12 col-md-2 form-check mt-4">
        <input class="form-check-input" type="checkbox" id="includeUnmatched" name="include_unmatched" value="1" <?= $includeUnmatched ? 'checked' : '' ?>>
        <label class="form-check-label" for="includeUnmatched">
          Include unmatched name-matches
        </label>
      </div>
      <div class="col-12 col-md-1">
        <button type="submit" class="btn btn-primary w-100">Lookup</button>
      </div>
    </form>

    <?php if ($selectedId && isset($driversById[$selectedId])): ?>
      <?php $sel = $driversById[$selectedId]; ?>

      <div class="summary mt-3">
        <div><strong>Contact:</strong> <?= h(trim(($sel['first_name'] ?? '') . ' ' . ($sel['last_name'] ?? ''))) ?> (ID <?= (int)$selectedId ?>)</div>
        <div class="small text-muted">
          Aliases used for matching: <?= h(implode(', ', $aliasList)) ?: '—' ?>
        </div>
      </div>

      <h2 class="mt-4">Matched Payouts</h2>
      <div class="table-responsive">
        <table class="table table-striped table-sm align-middle">
          <thead>
            <tr>
              <th class="nowrap">Payout Date</th>
              <th>Ticket #</th>
              <th>Driver Name (from report)</th>
              <th>Client</th>
              <th class="text-end">TSS Pay</th>
              <th>Upload Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($matchedRows)): ?>
              <tr><td colspan="6" class="text-muted">No matched payouts for this driver.</td></tr>
            <?php else: ?>
              <?php foreach ($matchedRows as $r): ?>
                <tr>
                  <td class="nowrap"><?= h($r['payout_date']) ?></td>
                  <td><?= h($r['ticket_number']) ?></td>
                  <td><?= h($r['driver_name']) ?></td>
                  <td><?= h($r['vendor_name']) ?></td>
                  <td class="text-end">$<?= number_format(money_to_float($r['tss_pay']), 2) ?></td>
                  <td><?= h($r['upload_date']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <tfoot>
            <tr>
              <th colspan="4" class="text-end">Total (Matched)</th>
              <th class="text-end">$<?= number_format($matchedTotal, 2) ?></th>
              <th></th>
            </tr>
            <tr>
              <th colspan="4" class="text-end">Count (Matched)</th>
              <th class="text-end"><?= (int)$matchedCount ?></th>
              <th></th>
            </tr>
          </tfoot>
        </table>
      </div>

      <?php if ($includeUnmatched): ?>
        <h2 class="mt-4">Unmatched rows that name-match aliases</h2>
        <div class="table-responsive">
          <table class="table table-bordered table-sm align-middle">
            <thead>
              <tr>
                <th class="nowrap">Payout Date</th>
                <th>Ticket #</th>
                <th>Driver Name</th>
                <th>Client</th>
                <th class="text-end">TSS Pay</th>
                <th>Upload Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($unmatchedRows)): ?>
                <tr><td colspan="6" class="text-muted">No unmatched rows matched aliases in this range.</td></tr>
              <?php else: ?>
                <?php foreach ($unmatchedRows as $r): ?>
                  <tr class="table-warning">
                    <td class="nowrap"><?= h($r['payout_date']) ?></td>
                    <td><?= h($r['ticket_number']) ?></td>
                    <td><?= h($r['driver_name']) ?></td>
                    <td><?= h($r['vendor_name']) ?></td>
                    <td class="text-end">$<?= number_format(money_to_float($r['tss_pay']), 2) ?></td>
                    <td><?= h($r['upload_date']) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
            <tfoot>
              <tr>
                <th colspan="4" class="text-end">Total (Unmatched)</th>
                <th class="text-end">$<?= number_format($unmatchedTotal, 2) ?></th>
                <th></th>
              </tr>
              <tr>
                <th colspan="4" class="text-end">Count (Unmatched)</th>
                <th class="text-end"><?= (int)$unmatchedCount ?></th>
                <th></th>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>

    <?php elseif ($selectedId): ?>
      <div class="alert alert-warning mt-3">Selected driver not found.</div>
    <?php endif; ?>

  </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
