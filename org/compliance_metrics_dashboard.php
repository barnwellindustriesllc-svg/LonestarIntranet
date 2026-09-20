<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/dot_status_tools.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');

$categories = [
  'PEC',
  'H2S',
  'D&A Testing',
  'Insurance',
  'Inspection',
  'CDL',
  'Medical Card',
  'MVR',
  'DOT Registration',
];

$showDisabledDrivers = (($_GET['show_disabled'] ?? '') === '1');

$drivers = [];
$driverSql = "
  SELECT id, CONCAT(first_name, ' ', last_name) AS name, driver_type, truck_no, owner_dot_number
    FROM driver_contacts
";
if (!$showDisabledDrivers) {
  $driverSql .= " WHERE COALESCE(is_disabled, 0) = 0";
}
$driverSql .= " ORDER BY first_name, last_name";
$resDrivers = $mysqli->query($driverSql);
while ($row = $resDrivers->fetch_assoc()) {
  $drivers[] = $row;
}

$dotNumbers = [];
foreach ($drivers as $driver) {
  $dot = dot_status_normalize_number((string)($driver['owner_dot_number'] ?? ''));
  if ($dot !== '') $dotNumbers[] = $dot;
}
$dotStatusMap = dot_status_get_map($mysqli, $dotNumbers, true, false);

$items = [];
$resItems = $mysqli->query(
  "SELECT driver_contact_id, category, expiry_date, status_override
     FROM driver_compliance_items"
);
while ($row = $resItems->fetch_assoc()) {
  $items[$row['driver_contact_id']][$row['category']] = $row;
}

$documentComplianceMap = [
  'PEC' => 'pec',
  'H2S' => 'h2s',
  'Insurance' => 'coi',
  'CDL' => 'license',
  'Medical Card' => 'medical_card',
  'MVR' => 'mvr',
];
$expiringDocumentTypes = ['pec', 'h2s', 'coi', 'license', 'medical_card'];
$documentComplianceDocs = [];
$documentTypes = array_values($documentComplianceMap);
if (!empty($documentTypes)) {
  $docTypeList = implode("','", array_map([$mysqli, 'real_escape_string'], $documentTypes));
  $resComplianceDocs = $mysqli->query(
    "SELECT driver_contact_id, doc_type, expiration_date
       FROM driver_documents
      WHERE driver_contact_id IS NOT NULL
        AND doc_type IN ('{$docTypeList}')
   ORDER BY uploaded_at DESC, id DESC"
  );
  while ($row = $resComplianceDocs->fetch_assoc()) {
    $driverId = (int)$row['driver_contact_id'];
    $docType = (string)$row['doc_type'];
    if (!isset($documentComplianceDocs[$driverId][$docType])) {
      $documentComplianceDocs[$driverId][$docType] = $row;
    }
  }
}

function compliance_status(?string $expiryDate, ?string $override): array {
  if ($override) {
    return [$override, $override];
  }
  if (!$expiryDate) {
    return ['gray', 'Missing'];
  }
  $ts = strtotime($expiryDate);
  if (!$ts) {
    return ['red', 'Invalid expiration date'];
  }
  $today = strtotime(date('Y-m-d'));
  $days = (int)floor(($ts - $today) / 86400);
  if ($days < 0) {
    return ['red', 'Expired'];
  }
  if ($days <= 30) {
    return ['yellow', 'Due Soon'];
  }
  return ['green', 'Compliant'];
}

function document_compliance_status(?array $document, bool $requiresExpiration, ?string $override): array {
  if (!$document) {
    return ['gray', 'Missing'];
  }
  if ($override) {
    return [$override, $override];
  }
  if (!$requiresExpiration) {
    return ['green', 'Compliant'];
  }
  $expirationDate = trim((string)($document['expiration_date'] ?? ''));
  if ($expirationDate === '') {
    return ['red', 'Invalid expiration date'];
  }
  return compliance_status($expirationDate, null);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Compliance Metrics Dashboard</title>
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

    .compliance-table {
      background: #0b0b0c;
      color: #fff;
      border-radius: 12px;
      overflow: auto;
      max-height: clamp(320px, calc(100vh - var(--banner-h) - 220px), 900px);
      scrollbar-gutter: stable;
      padding: 8px;
    }
    .compliance-table table {
      width: 100%;
      border-collapse: collapse;
      min-width: 1100px;
    }
    .compliance-table th,
    .compliance-table td {
      padding: 10px 8px;
      border-bottom: 1px solid rgba(255,255,255,0.08);
      font-size: 0.85rem;
      text-align: left;
    }
    .compliance-table th {
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-size: 0.7rem;
      color: #d1d5db;
      position: sticky;
      top: 0;
      background: #0b0b0c;
      z-index: 2;
      box-shadow: 0 2px 0 rgba(255,255,255,0.12);
    }
    .status-dot {
      width: 14px;
      height: 14px;
      border-radius: 50%;
      display: inline-block;
      box-shadow: 0 0 0 2px rgba(0,0,0,0.4);
    }
    .status-green { background: #16a34a; }
    .status-yellow { background: #f59e0b; }
    .status-red { background: #dc2626; }
    .status-gray { background: #9ca3af; }
    .status-cell { display:flex; align-items:center; gap:6px; }
    .legend { display:flex; gap:16px; align-items:center; font-size:0.85rem; margin:8px 0 16px; }
    .legend span { display:flex; align-items:center; gap:6px; color:#111; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main">
      <h1>Compliance Metrics Dashboard</h1>
      <form method="get" class="mb-3">
        <div class="form-check">
          <input
            class="form-check-input"
            type="checkbox"
            id="showDisabledDrivers"
            name="show_disabled"
            value="1"
            <?= $showDisabledDrivers ? 'checked' : '' ?>
            onchange="this.form.submit()"
          >
          <label class="form-check-label" for="showDisabledDrivers">View disabled drivers</label>
        </div>
      </form>
      <div class="legend">
        <span><span class="status-dot status-green"></span> Compliant</span>
        <span><span class="status-dot status-yellow"></span> Due Soon (≤30 days)</span>
        <span><span class="status-dot status-gray"></span> Missing</span>
        <span><span class="status-dot status-red"></span> Expired/Invalid</span>
      </div>

      <div class="compliance-table">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Driver</th>
              <th>Type</th>
              <th>Truck #</th>
              <?php foreach ($categories as $cat): ?>
                <th><?= htmlspecialchars($cat, ENT_QUOTES) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (!$drivers): ?>
              <tr><td colspan="<?= 4 + count($categories) ?>">No drivers found.</td></tr>
            <?php else: ?>
              <?php foreach ($drivers as $index => $driver): ?>
                <tr>
                  <td><?= (int)$index + 1 ?></td>
                  <td><?= htmlspecialchars($driver['name'] ?? '', ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($driver['driver_type'] ?? '', ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($driver['truck_no'] ?? '', ENT_QUOTES) ?></td>
                  <?php foreach ($categories as $cat): ?>
                    <?php
                      $driverType = (string)($driver['driver_type'] ?? '');
                      $isBrokered = $driverType === 'Brokered';
                      $isNotApplicable = ($isBrokered && $cat === 'D&A Testing');
                      $row = $items[$driver['id']][$cat] ?? null;
                      $expiry = $row['expiry_date'] ?? null;
                      $override = $row['status_override'] ?? null;
                      [$color, $label] = compliance_status($expiry, $override);
                      $driverDot = dot_status_normalize_number((string)($driver['owner_dot_number'] ?? ''));
                      if ($cat === 'DOT Registration') {
                        $dotStatus = $driverDot !== '' ? ($dotStatusMap[$driverDot] ?? null) : null;
                        $apiError = strtoupper(trim((string)($dotStatus['api_error'] ?? '')));
                        $hasSaferRecord = $dotStatus && (
                          trim((string)($dotStatus['legal_name'] ?? '')) !== ''
                          || trim((string)($dotStatus['entity_type'] ?? '')) !== ''
                          || trim((string)($dotStatus['usdot_status'] ?? '')) !== ''
                          || trim((string)($dotStatus['operating_status'] ?? '')) !== ''
                        );
                        $recordNotFound = (strpos($apiError, 'NOT FOUND') !== false || strpos($apiError, 'HTTP 404') !== false);
                        $recordMissing = !$dotStatus || ($apiError === '' && !$hasSaferRecord);
                        $usdotStatus = strtoupper(trim((string)($dotStatus['usdot_status'] ?? '')));
                        $authorityStatus = strtoupper(trim((string)($dotStatus['operating_status'] ?? '')));
                        $usdotStatusKey = preg_replace('/[\s_-]+/', ' ', $usdotStatus);
                        $authorityStatusKey = preg_replace('/[\s_-]+/', ' ', $authorityStatus);

                        if ($driverDot === '') {
                          $color = 'gray';
                          $label = 'Missing DOT number';
                        } elseif ($recordMissing) {
                          $color = 'gray';
                          $label = 'Missing DOT record';
                        } elseif ($recordNotFound) {
                          $color = 'red';
                          $label = 'DOT record not found';
                        } elseif ($apiError !== '' && $usdotStatus === '') {
                          $color = 'yellow';
                          $label = 'SAFER status unavailable';
                        } elseif (in_array($usdotStatusKey, ['INACTIVE', 'OUT OF SERVICE'], true)) {
                          $color = 'red';
                          $label = 'USDOT status: ' . $usdotStatus;
                        } elseif ($authorityStatusKey === 'OUT OF SERVICE') {
                          $color = 'red';
                          $label = 'Operating Authority Status: ' . $authorityStatus;
                        } else {
                          $color = 'green';
                          $label = 'Compliant';
                        }
                      }
                      if (isset($documentComplianceMap[$cat])) {
                        $requiredDocType = $documentComplianceMap[$cat];
                        $document = $documentComplianceDocs[(int)$driver['id']][$requiredDocType] ?? null;
                        [$color, $label] = document_compliance_status(
                          $document,
                          in_array($requiredDocType, $expiringDocumentTypes, true),
                          $override
                        );
                        $expiry = $document['expiration_date'] ?? null;
                      }
                    ?>
                    <td>
                      <?php if ($isNotApplicable): ?>
                        <span style="color:#fff;">N/A</span>
                      <?php else: ?>
                        <div class="status-cell" title="<?= htmlspecialchars($label . ($expiry ? " (exp {$expiry})" : ''), ENT_QUOTES) ?>">
                          <span class="status-dot status-<?= $color ?>"></span>
                        </div>
                      <?php endif; ?>
                    </td>
                  <?php endforeach; ?>
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
