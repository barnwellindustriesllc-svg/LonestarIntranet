<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';

function h($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES); }
function money($n) { return '$' . number_format((float)$n, 2); }
function truck_digits($value): string {
  return preg_replace('/\D+/', '', (string)($value ?? ''));
}
function clean_date($d): ?string {
  $v = trim((string)($d ?? ''));
  if ($v === '' || $v === '0000-00-00') return null;
  return $v;
}

function table_exists(mysqli $db, string $table): bool {
  $t = $db->real_escape_string($table);
  $q = $db->query("SHOW TABLES LIKE '{$t}'");
  return $q && $q->num_rows > 0;
}

$errors = [];
$messages = [];
$rows = [];
$summary = [
  'total' => 0,
  'ok' => 0,
  'missing_ls' => 0,
  'missing_tss' => 0,
  'truck_mismatch' => 0,
  'pay_mismatch' => 0,
  'date_mismatch' => 0,
  'ls_duplicates' => 0,
  'tss_duplicates' => 0,
];

if (!table_exists($mysqli, 'tss_payout_rows')) {
  $errors[] = 'Table tss_payout_rows does not exist yet. Run migration 014 first.';
}
if (!table_exists($mysqli, 'ls_detail_raw')) {
  $errors[] = 'Table ls_detail_raw does not exist.';
}
$hasRequiredTables = empty($errors);

$tssUploads = [];
$lsUploads = [];

if ($hasRequiredTables) {
  $q1 = $mysqli->query("SELECT DISTINCT upload_date FROM tss_payout_rows ORDER BY upload_date DESC");
  while ($q1 && ($r = $q1->fetch_assoc())) $tssUploads[] = $r['upload_date'];
  if ($q1) $q1->close();

  $q2 = $mysqli->query("SELECT DISTINCT upload_date FROM ls_detail_raw ORDER BY upload_date DESC");
  while ($q2 && ($r = $q2->fetch_assoc())) $lsUploads[] = $r['upload_date'];
  if ($q2) $q2->close();
}

$selectedTssUpload = $_GET['tss_upload'] ?? ($tssUploads[0] ?? '');
$selectedLsUpload  = $_GET['ls_upload'] ?? ($lsUploads[0] ?? '');
$fromDate          = $_GET['from'] ?? '';
$toDate            = $_GET['to'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $selectedTssUpload = $_POST['tss_upload'] ?? $selectedTssUpload;
  $selectedLsUpload = $_POST['ls_upload'] ?? $selectedLsUpload;
  $fromDate = $_POST['from'] ?? $fromDate;
  $toDate = $_POST['to'] ?? $toDate;
  $action = $_POST['action'] ?? '';

  if ($action === 'update_recon_tss_row') {
    $uploadDate = trim((string)($_POST['row_upload_date'] ?? ''));
    $lineNo = (int)($_POST['source_line_no'] ?? 0);
    $ticketNumber = trim((string)($_POST['ticket_number'] ?? ''));
    $digits = truck_digits($_POST['truck_digits'] ?? '');
    $workDate = trim((string)($_POST['work_date'] ?? ''));
    $payAmount = (float)($_POST['pay_amount'] ?? 0);

    if ($uploadDate === '' || $lineNo <= 0 || $ticketNumber === '' || $digits === '' || $workDate === '') {
      $errors[] = 'Enter ticket, truck, date, and pay before saving the TSS row.';
    } else {
      $truckRaw = $digits . ' LS';
      $stmt = $mysqli->prepare("
        UPDATE tss_payout_rows
           SET ticket_number=?,
               truck_digits=?,
               truck_raw=?,
               work_date=?,
               pay_amount=?
         WHERE upload_date=?
           AND source_line_no=?
           AND row_type='ticket'
         LIMIT 1
      ");
      if ($stmt) {
        $stmt->bind_param('ssssdsi', $ticketNumber, $digits, $truckRaw, $workDate, $payAmount, $uploadDate, $lineNo);
        $stmt->execute();
        $messages[] = $stmt->affected_rows >= 0 ? 'TSS ticket row updated.' : 'No TSS row was updated.';
        $stmt->close();
      } else {
        $errors[] = 'Unable to prepare TSS update: ' . $mysqli->error;
      }
    }
  }

  if ($action === 'delete_recon_tss_row') {
    $uploadDate = trim((string)($_POST['row_upload_date'] ?? ''));
    $lineNo = (int)($_POST['source_line_no'] ?? 0);
    if ($uploadDate === '' || $lineNo <= 0) {
      $errors[] = 'Unable to identify the TSS row to remove.';
    } else {
      $stmt = $mysqli->prepare("
        DELETE FROM tss_payout_rows
         WHERE upload_date=?
           AND source_line_no=?
           AND row_type='ticket'
         LIMIT 1
      ");
      if ($stmt) {
        $stmt->bind_param('si', $uploadDate, $lineNo);
        $stmt->execute();
        $messages[] = $stmt->affected_rows > 0 ? 'TSS duplicate ticket row removed.' : 'No TSS row was removed.';
        $stmt->close();
      } else {
        $errors[] = 'Unable to prepare TSS delete: ' . $mysqli->error;
      }
    }
  }

  if ($action === 'update_recon_ls_rows') {
    $uploadDate = trim((string)($_POST['row_upload_date'] ?? ''));
    $oldTicket = trim((string)($_POST['old_ticket_number'] ?? ''));
    $oldTruck = truck_digits($_POST['old_truck_digits'] ?? '');
    $oldDate = trim((string)($_POST['old_delivery_date'] ?? ''));
    $ticketNumber = trim((string)($_POST['ticket_number'] ?? ''));
    $digits = truck_digits($_POST['truck_digits'] ?? '');
    $deliveryDate = trim((string)($_POST['delivery_date'] ?? ''));
    $payAmount = (float)($_POST['pay_amount'] ?? 0);

    if ($uploadDate === '' || $oldTicket === '' || $oldTruck === '' || $ticketNumber === '' || $digits === '' || $deliveryDate === '') {
      $errors[] = 'Enter ticket, truck, date, and pay before saving the LS row.';
    } else {
      $lsTruckExpr = "TRIM(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(IFNULL(`Truck #`,'')),'WTX',''),'LS',''),' ',''),'-',''))";
      $stmt = $mysqli->prepare("
        UPDATE ls_detail_raw
           SET `Truckload ID`=?,
               `Truck #`=?,
               `Delivery Date`=?,
               `Calculated Freight Rate (Carrier)`=?
         WHERE upload_date=?
           AND `Truckload ID`=?
           AND {$lsTruckExpr}=?
           AND (? = '' OR `Delivery Date` = ?)
      ");
      if ($stmt) {
        $truckRaw = $digits . ' LS';
        $payString = number_format($payAmount, 2, '.', '');
        $stmt->bind_param('sssssssss', $ticketNumber, $truckRaw, $deliveryDate, $payString, $uploadDate, $oldTicket, $oldTruck, $oldDate, $oldDate);
        $stmt->execute();
        $messages[] = $stmt->affected_rows > 0 ? 'LS detail row updated.' : 'No LS row was updated.';
        $stmt->close();
      } else {
        $errors[] = 'Unable to prepare LS update: ' . $mysqli->error;
      }
    }
  }

  if ($action === 'create_recon_tss_from_ls') {
    $uploadDate = trim((string)($_POST['tss_upload'] ?? ''));
    $ticketNumber = trim((string)($_POST['ticket_number'] ?? ''));
    $digits = truck_digits($_POST['truck_digits'] ?? '');
    $workDate = trim((string)($_POST['work_date'] ?? ''));
    $payAmount = (float)($_POST['pay_amount'] ?? 0);

    if ($uploadDate === '' || $ticketNumber === '' || $digits === '' || $workDate === '') {
      $errors[] = 'Enter ticket, truck, date, and pay before creating the TSS row.';
    } else {
      $lineNo = 1;
      $qLine = $mysqli->prepare("SELECT COALESCE(MAX(source_line_no), 0) + 1 FROM tss_payout_rows WHERE upload_date=?");
      if ($qLine) {
        $qLine->bind_param('s', $uploadDate);
        $qLine->execute();
        $qLine->bind_result($nextLineNo);
        if ($qLine->fetch()) $lineNo = (int)$nextLineNo;
        $qLine->close();
      }

      $stmt = $mysqli->prepare("
        INSERT INTO tss_payout_rows
        (upload_date, as_of_date, section_no, source_line_no, row_type, truck_raw, truck_digits, work_date,
         ticket_number, bol_number, unloaded_at, job_description, quantity, pay_amount, misc_category,
         misc_description, extracted_ticket_number, matched_contact_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
      ");
      if ($stmt) {
        $rowType = 'ticket';
        $truckRaw = $digits . ' LS';
        $sectionNo = 0;
        $null = null;
        $stmt->bind_param(
          'ssiisssssssssdsssi',
          $uploadDate,
          $workDate,
          $sectionNo,
          $lineNo,
          $rowType,
          $truckRaw,
          $digits,
          $workDate,
          $ticketNumber,
          $null,
          $null,
          $null,
          $null,
          $payAmount,
          $null,
          $null,
          $null,
          $null
        );
        $stmt->execute();
        $messages[] = 'TSS ticket row created from LS detail.';
        $stmt->close();
      } else {
        $errors[] = 'Unable to prepare TSS insert: ' . $mysqli->error;
      }
    }
  }
}

if ($hasRequiredTables && $selectedTssUpload !== '' && $selectedLsUpload !== '') {
  $lsTruckExpr = "TRIM(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(IFNULL(`Truck #`,'')),'WTX',''),'LS',''),' ',''),'-',''))";
  $lsDateExpr = "COALESCE(
    CASE
      WHEN `Delivery Date` REGEXP '^[0-9]+(\\\\.[0-9]+)?$'
      THEN DATE(DATE_ADD('1899-12-30', INTERVAL CAST(`Delivery Date` AS UNSIGNED) DAY))
      ELSE NULL
    END,
    DATE(STR_TO_DATE(`Delivery Date`, '%Y-%m-%d %H:%i:%s')),
    DATE(STR_TO_DATE(`Delivery Date`, '%Y-%m-%d %H:%i')),
    DATE(STR_TO_DATE(`Delivery Date`, '%Y-%m-%d')),
    DATE(STR_TO_DATE(`Delivery Date`, '%m/%d/%Y %H:%i:%s')),
    DATE(STR_TO_DATE(`Delivery Date`, '%m/%d/%Y %H:%i')),
    DATE(STR_TO_DATE(`Delivery Date`, '%m/%d/%Y')),
    DATE(STR_TO_DATE(`Delivery Date`, '%c/%e/%Y %H:%i:%s')),
    DATE(STR_TO_DATE(`Delivery Date`, '%c/%e/%Y %H:%i')),
    DATE(STR_TO_DATE(`Delivery Date`, '%c/%e/%Y'))
  )";

  $lsAggSql = "
    SELECT
      `Truckload ID` AS ticket_number,
      {$lsTruckExpr} AS truck_digits,
      COUNT(*) AS ls_count,
      SUM(CAST(REPLACE(REPLACE(IFNULL(`Calculated Freight Rate (Carrier)`,'0'), ',', ''), '$', '') AS DECIMAL(12,2))) AS ls_pay_total,
      MIN({$lsDateExpr}) AS ls_first_date
    FROM ls_detail_raw
    WHERE upload_date = ?
      AND `Truckload ID` IS NOT NULL
      AND `Truckload ID` <> ''
      AND {$lsTruckExpr} <> ''";

  $typesLs = 's';
  $paramsLs = [$selectedLsUpload];
  if ($fromDate !== '') { $lsAggSql .= " AND {$lsDateExpr} >= ?"; $typesLs .= 's'; $paramsLs[] = $fromDate; }
  if ($toDate !== '')   { $lsAggSql .= " AND {$lsDateExpr} <= ?"; $typesLs .= 's'; $paramsLs[] = $toDate; }
  $lsAggSql .= " GROUP BY `Truckload ID`, {$lsTruckExpr}";

  $sqlTssVsLs = "
    SELECT
      t.ticket_number,
      t.truck_digits,
      t.work_date,
      t.pay_amount AS tss_pay,
      t.section_no,
      t.source_line_no,
      l.ls_count,
      l.ls_pay_total,
      l.ls_first_date,
      lt.ls_ticket_count,
      lt.ls_ticket_pay_total,
      lt.ls_ticket_first_date,
      td.tss_duplicate_count
    FROM tss_payout_rows t
    LEFT JOIN (
      {$lsAggSql}
    ) l
      ON l.ticket_number = t.ticket_number
     AND l.truck_digits = t.truck_digits
    LEFT JOIN (
      SELECT
        `Truckload ID` AS ticket_number,
        COUNT(*) AS ls_ticket_count,
        SUM(CAST(REPLACE(REPLACE(IFNULL(`Calculated Freight Rate (Carrier)`,'0'), ',', ''), '$', '') AS DECIMAL(12,2))) AS ls_ticket_pay_total,
        MIN({$lsDateExpr}) AS ls_ticket_first_date
      FROM ls_detail_raw
      WHERE upload_date = ?
        AND `Truckload ID` IS NOT NULL
        AND `Truckload ID` <> ''";

  $typesTicket = 's';
  $paramsTicket = [$selectedLsUpload];
  if ($fromDate !== '') { $sqlTssVsLs .= " AND {$lsDateExpr} >= ?"; $typesTicket .= 's'; $paramsTicket[] = $fromDate; }
  if ($toDate !== '')   { $sqlTssVsLs .= " AND {$lsDateExpr} <= ?"; $typesTicket .= 's'; $paramsTicket[] = $toDate; }
  $sqlTssVsLs .= "
      GROUP BY `Truckload ID`
    ) lt
      ON lt.ticket_number = t.ticket_number
    LEFT JOIN (
      SELECT ticket_number, COUNT(*) AS tss_duplicate_count
        FROM tss_payout_rows
       WHERE row_type = 'ticket'
         AND upload_date = ?
       GROUP BY ticket_number
      HAVING COUNT(*) > 1
    ) td
      ON td.ticket_number = t.ticket_number
    WHERE t.row_type = 'ticket'
      AND t.upload_date = ?";

  $typesMain = $typesLs . $typesTicket . 'ss';
  $paramsMain = array_merge($paramsLs, $paramsTicket, [$selectedTssUpload, $selectedTssUpload]);
  if ($fromDate !== '') { $sqlTssVsLs .= " AND t.work_date >= ?"; $typesMain .= 's'; $paramsMain[] = $fromDate; }
  if ($toDate !== '')   { $sqlTssVsLs .= " AND t.work_date <= ?"; $typesMain .= 's'; $paramsMain[] = $toDate; }
  $sqlTssVsLs .= " ORDER BY t.work_date DESC, t.ticket_number ASC";

  $stmt1 = $mysqli->prepare($sqlTssVsLs);
  if (!$stmt1) {
    $errors[] = 'Prepare error (TSS vs LS): ' . $mysqli->error;
  } else {
    $stmt1->bind_param($typesMain, ...$paramsMain);
    if (!$stmt1->execute()) {
      $errors[] = 'Query error (TSS vs LS): ' . $stmt1->error;
    } else {
      $res1 = $stmt1->get_result();
      while ($r = $res1->fetch_assoc()) {
        $flags = [];
        $strictMatch = ($r['ls_count'] !== null);
        $ticketOnlyMatch = ($r['ls_ticket_count'] !== null);
        if ((int)($r['tss_duplicate_count'] ?? 0) > 1) {
          $flags[] = 'duplicate_ticket';
          $summary['tss_duplicates']++;
        }

        if (!$strictMatch && $ticketOnlyMatch) {
          $flags[] = 'truck_mismatch';
          $summary['truck_mismatch']++;
          $payDiffTicket = abs((float)$r['tss_pay'] - (float)$r['ls_ticket_pay_total']);
          if ($payDiffTicket > 0.01) {
            $flags[] = 'pay_mismatch';
            $summary['pay_mismatch']++;
          }
          $tssDate = clean_date($r['work_date'] ?? null);
          $lsDateTicket = clean_date($r['ls_ticket_first_date'] ?? null);
          if ($tssDate && $lsDateTicket && $tssDate !== $lsDateTicket) {
            $flags[] = 'date_mismatch';
            $summary['date_mismatch']++;
          }
        } elseif (!$strictMatch && !$ticketOnlyMatch) {
          $flags[] = 'missing_in_ls';
          $summary['missing_ls']++;
        } else {
          if ((int)$r['ls_count'] > 1) {
            $flags[] = 'ls_duplicate_rows';
            $summary['ls_duplicates']++;
          }
          $payDiff = abs((float)$r['tss_pay'] - (float)$r['ls_pay_total']);
          if ($payDiff > 0.01) {
            $flags[] = 'pay_mismatch';
            $summary['pay_mismatch']++;
          }
          $tssDate = clean_date($r['work_date'] ?? null);
          $lsDateStrict = clean_date($r['ls_first_date'] ?? null);
          if ($tssDate && $lsDateStrict && $tssDate !== $lsDateStrict) {
            $flags[] = 'date_mismatch';
            $summary['date_mismatch']++;
          }
        }

        if (empty($flags)) $summary['ok']++;
        $summary['total']++;

        $rows[] = [
          'status' => empty($flags) ? 'ok' : implode(', ', $flags),
          'ticket_number' => $r['ticket_number'],
          'truck_digits' => $r['truck_digits'],
          'work_date' => clean_date($r['work_date'] ?? null),
          'ls_date' => clean_date(($r['ls_first_date'] ?? null) ?: ($r['ls_ticket_first_date'] ?? null)),
          'tss_pay' => (float)$r['tss_pay'],
          'ls_pay' => ($r['ls_pay_total'] !== null) ? (float)$r['ls_pay_total'] : (($r['ls_ticket_pay_total'] !== null) ? (float)$r['ls_ticket_pay_total'] : null),
          'ls_count' => ($r['ls_count'] !== null) ? (int)$r['ls_count'] : (($r['ls_ticket_count'] !== null) ? (int)$r['ls_ticket_count'] : null),
          'source' => 'tss',
          'tss_upload_date' => $selectedTssUpload,
          'ls_upload_date' => $selectedLsUpload,
          'source_line_no' => (int)($r['source_line_no'] ?? 0),
          'tss_duplicate_count' => (int)($r['tss_duplicate_count'] ?? 0),
        ];
      }
      $res1->close();
    }
    $stmt1->close();
  }

  $sqlLsOnly = "
    SELECT l.ticket_number, l.truck_digits, l.ls_count, l.ls_pay_total, l.ls_first_date, tt.tss_ticket_count
    FROM (
      {$lsAggSql}
    ) l
    LEFT JOIN (
      SELECT DISTINCT ticket_number, truck_digits
      FROM tss_payout_rows
      WHERE row_type = 'ticket'
        AND upload_date = ?";

  $typesLsOnly = $typesLs . 's';
  $paramsLsOnly = array_merge($paramsLs, [$selectedTssUpload]);
  if ($fromDate !== '') { $sqlLsOnly .= " AND work_date >= ?"; $typesLsOnly .= 's'; $paramsLsOnly[] = $fromDate; }
  if ($toDate !== '')   { $sqlLsOnly .= " AND work_date <= ?"; $typesLsOnly .= 's'; $paramsLsOnly[] = $toDate; }
  $sqlLsOnly .= " ) t ON t.ticket_number = l.ticket_number AND t.truck_digits = l.truck_digits
                  LEFT JOIN (
                    SELECT ticket_number, COUNT(*) AS tss_ticket_count
                    FROM tss_payout_rows
                    WHERE row_type = 'ticket'
                      AND upload_date = ?";
  $typesLsOnly .= 's';
  $paramsLsOnly[] = $selectedTssUpload;
  if ($fromDate !== '') { $sqlLsOnly .= " AND work_date >= ?"; $typesLsOnly .= 's'; $paramsLsOnly[] = $fromDate; }
  if ($toDate !== '')   { $sqlLsOnly .= " AND work_date <= ?"; $typesLsOnly .= 's'; $paramsLsOnly[] = $toDate; }
  $sqlLsOnly .= " GROUP BY ticket_number
                  ) tt ON tt.ticket_number = l.ticket_number
                  WHERE t.ticket_number IS NULL
                  ORDER BY l.ls_first_date DESC, l.ticket_number ASC";

  $stmt2 = $mysqli->prepare($sqlLsOnly);
  if (!$stmt2) {
    $errors[] = 'Prepare error (LS-only): ' . $mysqli->error;
  } else {
    $stmt2->bind_param($typesLsOnly, ...$paramsLsOnly);
    if (!$stmt2->execute()) {
      $errors[] = 'Query error (LS-only): ' . $stmt2->error;
    } else {
      $res2 = $stmt2->get_result();
      while ($r = $res2->fetch_assoc()) {
        if ($r['tss_ticket_count'] !== null) {
          $summary['truck_mismatch']++;
          $status = 'truck_mismatch';
        } else {
          $summary['missing_tss']++;
          $status = 'missing_in_tss';
        }
        $summary['total']++;
        if ((int)$r['ls_count'] > 1) $summary['ls_duplicates']++;

        $rows[] = [
          'status' => $status,
          'ticket_number' => $r['ticket_number'],
          'truck_digits' => $r['truck_digits'],
          'work_date' => null,
          'ls_date' => clean_date($r['ls_first_date'] ?? null),
          'tss_pay' => null,
          'ls_pay' => ($r['ls_pay_total'] !== null) ? (float)$r['ls_pay_total'] : null,
          'ls_count' => (int)$r['ls_count'],
          'source' => 'ls',
          'tss_upload_date' => $selectedTssUpload,
          'ls_upload_date' => $selectedLsUpload,
          'source_line_no' => null,
          'tss_duplicate_count' => 0,
        ];
      }
      $res2->close();
    }
    $stmt2->close();
  }
}

usort($rows, static function($a, $b) {
  $ad = $a['work_date'] ?: $a['ls_date'] ?: '0000-00-00';
  $bd = $b['work_date'] ?: $b['ls_date'] ?: '0000-00-00';
  if ($ad === $bd) {
    return strcmp((string)$a['ticket_number'], (string)$b['ticket_number']);
  }
  return strcmp($bd, $ad);
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>TSS Reconciliation</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { margin:0; font-family:sans-serif; }
    .page-shell { display:flex; min-height: calc(100vh - var(--banner-h)); }
    .sidebar { width:250px; background:#333; color:#fff; height:calc(100vh - var(--banner-h)); position:fixed; top:var(--banner-h); left:0; overflow:auto; transition:transform .3s ease; z-index:1000; transform: translateX(0); }
    .sidebar.collapsed { transform: translateX(-250px); }
    .sidebar a { display:block; color:#fff; padding:15px; text-decoration:none; }
    .sidebar a:hover { background:#444; }
    .main { margin-left:250px; padding:20px; flex:1; min-width:0; }
    .sidebar.collapsed + .main { margin-left:0; }
    @media(max-width:768px) { .sidebar { transform: translateX(-250px); } .sidebar.open { transform: translateX(0); } .main { margin:0; } }
    .summary-box { border:1px solid #e8e8e8; border-radius:8px; padding:10px 12px; background:#fafafa; }
    .row-ok { background:#f6fff8; }
    .row-bad { background:#fff7f7; }
    .nowrap { white-space: nowrap; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main">
      <h1 class="mb-3">TSS vs LS Reconciliation</h1>

      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
          <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
              <li><?= h($e) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <?php if (!empty($messages)): ?>
        <div class="alert alert-success">
          <ul class="mb-0">
            <?php foreach ($messages as $m): ?>
              <li><?= h($m) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="get" class="row g-3 align-items-end mb-3">
        <div class="col-12 col-md-3">
          <label class="form-label">TSS Upload Date</label>
          <select name="tss_upload" class="form-select" required>
            <?php foreach ($tssUploads as $d): ?>
              <option value="<?= h($d) ?>" <?= $selectedTssUpload === $d ? 'selected' : '' ?>><?= h($d) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label">LS Upload Date</label>
          <select name="ls_upload" class="form-select" required>
            <?php foreach ($lsUploads as $d): ?>
              <option value="<?= h($d) ?>" <?= $selectedLsUpload === $d ? 'selected' : '' ?>><?= h($d) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">From</label>
          <input type="date" name="from" value="<?= h($fromDate) ?>" class="form-control">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">To</label>
          <input type="date" name="to" value="<?= h($toDate) ?>" class="form-control">
        </div>
        <div class="col-12 col-md-2">
          <button class="btn btn-primary w-100" type="submit">Run</button>
        </div>
      </form>

      <div class="row g-2 mb-3">
        <div class="col-6 col-md-2"><div class="summary-box"><strong>Total:</strong> <?= (int)$summary['total'] ?></div></div>
        <div class="col-6 col-md-2"><div class="summary-box"><strong>OK:</strong> <?= (int)$summary['ok'] ?></div></div>
        <div class="col-6 col-md-2"><div class="summary-box"><strong>Missing LS:</strong> <?= (int)$summary['missing_ls'] ?></div></div>
        <div class="col-6 col-md-2"><div class="summary-box"><strong>Missing TSS:</strong> <?= (int)$summary['missing_tss'] ?></div></div>
        <div class="col-6 col-md-2"><div class="summary-box"><strong>Truck Mismatch:</strong> <?= (int)$summary['truck_mismatch'] ?></div></div>
        <div class="col-6 col-md-2"><div class="summary-box"><strong>Pay Mismatch:</strong> <?= (int)$summary['pay_mismatch'] ?></div></div>
        <div class="col-6 col-md-2"><div class="summary-box"><strong>Date Mismatch:</strong> <?= (int)$summary['date_mismatch'] ?></div></div>
        <div class="col-6 col-md-2"><div class="summary-box"><strong>LS Duplicates:</strong> <?= (int)$summary['ls_duplicates'] ?></div></div>
        <div class="col-6 col-md-2"><div class="summary-box"><strong>TSS Duplicates:</strong> <?= (int)$summary['tss_duplicates'] ?></div></div>
      </div>

      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle">
          <thead>
            <tr>
              <th>Status</th>
              <th class="nowrap">Ticket #</th>
              <th class="nowrap">Truck</th>
              <th class="nowrap">TSS Date</th>
              <th class="nowrap">LS Date</th>
              <th class="text-end nowrap">TSS Pay</th>
              <th class="text-end nowrap">LS Pay</th>
              <th class="text-end nowrap">Delta</th>
              <th class="text-center nowrap">LS Rows</th>
              <th class="nowrap">Source</th>
              <th>Fix</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="11" class="text-muted">No rows found for selected filters.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r):
                $isOk = ($r['status'] === 'ok');
                $delta = (($r['tss_pay'] ?? 0) - ($r['ls_pay'] ?? 0));
              ?>
                <tr class="<?= $isOk ? 'row-ok' : 'row-bad' ?>">
                  <td><code><?= h($r['status']) ?></code></td>
                  <td class="nowrap"><?= h($r['ticket_number']) ?></td>
                  <td class="nowrap"><?= h($r['truck_digits']) ?></td>
                  <td class="nowrap"><?= h($r['work_date'] ?? '') ?></td>
                  <td class="nowrap"><?= h($r['ls_date'] ?? '') ?></td>
                  <td class="text-end nowrap"><?= $r['tss_pay'] !== null ? money($r['tss_pay']) : '—' ?></td>
                  <td class="text-end nowrap"><?= $r['ls_pay'] !== null ? money($r['ls_pay']) : '—' ?></td>
                  <td class="text-end nowrap"><?= ($r['tss_pay'] !== null || $r['ls_pay'] !== null) ? money($delta) : '—' ?></td>
                  <td class="text-center"><?= $r['ls_count'] !== null ? (int)$r['ls_count'] : '—' ?></td>
                  <td><?= h($r['source']) ?></td>
                  <td style="min-width: 360px;">
                    <?php if ($isOk): ?>
                      <span class="text-muted">No fix needed</span>
                    <?php elseif ($r['source'] === 'tss'): ?>
                      <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="action" value="update_recon_tss_row">
                        <input type="hidden" name="tss_upload" value="<?= h($selectedTssUpload) ?>">
                        <input type="hidden" name="ls_upload" value="<?= h($selectedLsUpload) ?>">
                        <input type="hidden" name="from" value="<?= h($fromDate) ?>">
                        <input type="hidden" name="to" value="<?= h($toDate) ?>">
                        <input type="hidden" name="row_upload_date" value="<?= h($r['tss_upload_date'] ?? $selectedTssUpload) ?>">
                        <input type="hidden" name="source_line_no" value="<?= (int)($r['source_line_no'] ?? 0) ?>">
                        <div class="col-6">
                          <label class="form-label small mb-1">Ticket</label>
                          <input name="ticket_number" value="<?= h($r['ticket_number']) ?>" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-3">
                          <label class="form-label small mb-1">Truck</label>
                          <input name="truck_digits" value="<?= h($r['truck_digits']) ?>" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-3">
                          <label class="form-label small mb-1">Pay</label>
                          <input type="number" step="0.01" name="pay_amount" value="<?= h($r['tss_pay']) ?>" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6">
                          <label class="form-label small mb-1">TSS Date</label>
                          <input type="date" name="work_date" value="<?= h($r['work_date']) ?>" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6">
                          <button type="submit" class="btn btn-sm btn-primary">Save TSS</button>
                        </div>
                      </form>
                      <?php if (strpos((string)$r['status'], 'duplicate_ticket') !== false): ?>
                        <form method="post" class="mt-2" onsubmit="return confirm('Remove this TSS ticket row from reconciliation data?');">
                          <input type="hidden" name="action" value="delete_recon_tss_row">
                          <input type="hidden" name="tss_upload" value="<?= h($selectedTssUpload) ?>">
                          <input type="hidden" name="ls_upload" value="<?= h($selectedLsUpload) ?>">
                          <input type="hidden" name="from" value="<?= h($fromDate) ?>">
                          <input type="hidden" name="to" value="<?= h($toDate) ?>">
                          <input type="hidden" name="row_upload_date" value="<?= h($r['tss_upload_date'] ?? $selectedTssUpload) ?>">
                          <input type="hidden" name="source_line_no" value="<?= (int)($r['source_line_no'] ?? 0) ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger">Remove Duplicate</button>
                        </form>
                      <?php endif; ?>
                    <?php elseif ($r['source'] === 'ls'): ?>
                      <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="action" value="update_recon_ls_rows">
                        <input type="hidden" name="tss_upload" value="<?= h($selectedTssUpload) ?>">
                        <input type="hidden" name="ls_upload" value="<?= h($selectedLsUpload) ?>">
                        <input type="hidden" name="from" value="<?= h($fromDate) ?>">
                        <input type="hidden" name="to" value="<?= h($toDate) ?>">
                        <input type="hidden" name="row_upload_date" value="<?= h($r['ls_upload_date'] ?? $selectedLsUpload) ?>">
                        <input type="hidden" name="old_ticket_number" value="<?= h($r['ticket_number']) ?>">
                        <input type="hidden" name="old_truck_digits" value="<?= h($r['truck_digits']) ?>">
                        <input type="hidden" name="old_delivery_date" value="<?= h($r['ls_date']) ?>">
                        <div class="col-6">
                          <label class="form-label small mb-1">Ticket</label>
                          <input name="ticket_number" value="<?= h($r['ticket_number']) ?>" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-3">
                          <label class="form-label small mb-1">Truck</label>
                          <input name="truck_digits" value="<?= h($r['truck_digits']) ?>" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-3">
                          <label class="form-label small mb-1">Pay</label>
                          <input type="number" step="0.01" name="pay_amount" value="<?= h($r['ls_pay']) ?>" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6">
                          <label class="form-label small mb-1">LS Date</label>
                          <input type="date" name="delivery_date" value="<?= h($r['ls_date']) ?>" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6">
                          <button type="submit" class="btn btn-sm btn-primary">Save LS</button>
                        </div>
                      </form>
                      <?php if (strpos((string)$r['status'], 'missing_in_tss') !== false): ?>
                        <form method="post" class="mt-2">
                          <input type="hidden" name="action" value="create_recon_tss_from_ls">
                          <input type="hidden" name="tss_upload" value="<?= h($selectedTssUpload) ?>">
                          <input type="hidden" name="ls_upload" value="<?= h($selectedLsUpload) ?>">
                          <input type="hidden" name="from" value="<?= h($fromDate) ?>">
                          <input type="hidden" name="to" value="<?= h($toDate) ?>">
                          <input type="hidden" name="ticket_number" value="<?= h($r['ticket_number']) ?>">
                          <input type="hidden" name="truck_digits" value="<?= h($r['truck_digits']) ?>">
                          <input type="hidden" name="work_date" value="<?= h($r['ls_date']) ?>">
                          <input type="hidden" name="pay_amount" value="<?= h($r['ls_pay']) ?>">
                          <button type="submit" class="btn btn-sm btn-outline-success">Add TSS From LS</button>
                        </form>
                      <?php endif; ?>
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
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
