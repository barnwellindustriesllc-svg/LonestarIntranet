<?php
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/safer_client.php';

$usdot = isset($_GET['usdot']) ? preg_replace('/\D+/', '', $_GET['usdot']) : '';
$tab   = isset($_GET['tab']) ? $_GET['tab'] : 'snapshot'; // snapshot|inspections|violations|crashes
if ($usdot === '') { header('Location: index.php'); exit; }

// Optional JSON download of the snapshot
if (isset($_GET['download']) && $_GET['download'] === 'json') {
    try {
        $snap = safer_call('/v2/usdot/snapshot/' . rawurlencode($usdot), 'snap_' . $usdot);
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="snapshot_' . $usdot . '.json"');
        echo json_encode($snap, JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$error = null;
$snapshot = null;
$data = null;

try {
    $snapshot = safer_call('/v2/usdot/snapshot/' . rawurlencode($usdot), 'snap_' . $usdot);
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Lazy load history by tab
try {
    if ($tab === 'inspections') {
        $data = safer_call('/v3/history/inspection/' . rawurlencode($usdot), 'hist_ins_' . $usdot);
    } elseif ($tab === 'violations') {
        $data = safer_call('/v3/history/violation/' . rawurlencode($usdot), 'hist_vio_' . $usdot);
    } elseif ($tab === 'crashes') {
        $data = safer_call('/v3/history/crash/' . rawurlencode($usdot), 'hist_cr_' . $usdot);
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

function badge($label) {
    return '<span class="pill">' . h($label) . '</span>';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>USDOT <?=h($usdot)?> - Carrier Details</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
  <style>
    *{ box-sizing:border-box; }
    body { margin:0; font-family:sans-serif; }
    .page-shell { display:flex; min-height: calc(100vh - var(--banner-h)); }
    .sidebar {
      width:250px; background:#333; color:#fff;
      height:calc(100vh - var(--banner-h));
      position:fixed; top:var(--banner-h); left:0; overflow:auto;
      transition:transform .3s ease; z-index:1000;
    }
    .sidebar.collapsed { transform: translateX(-250px); }
    .sidebar a { display:block; color:#fff; padding:15px; text-decoration:none; }
    .sidebar a:hover { background:#444; }
    .main { margin-left:250px; padding:20px; flex:1; min-width:0; }
    .sidebar.collapsed + .main { margin-left:0; }
    @media (max-width:768px){
      .sidebar { transform: translateX(-250px); }
      .sidebar.open { transform: translateX(0); }
      .main { margin:0; }
    }

    a{ color:var(--texas-red); text-decoration:none; }
    .container{ max-width:1100px; margin:0 auto; padding:0 16px; }
    .card{ background:#fff; border:1px solid #e3e7ef; border-radius:16px; padding:22px; box-shadow:0 10px 30px rgba(0,0,0,.08); }
    h1{ margin:0 0 12px; font-size:28px; color:var(--texas-navy); }
    .muted{ color:#6a6a6a; }
    .grid{ display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap:12px; }
    .stat{ background:#f7f9fc; border:1px solid #e3e7ef; padding:14px; border-radius:14px; }
    .stat h4{ margin:0; font-size:13px; color:#6a6a6a; }
    .stat div{ font-size:20px; font-weight:800; }
    .tabs{ display:flex; gap:8px; margin-top:14px; flex-wrap:wrap; }
    .tab{ padding:10px 14px; border-radius:12px; background:#f1f4f9; border:1px solid #dde3ee; color:#001f44; }
    .tab.active{ background:var(--texas-red); color:#fff; border-color:transparent; }
    .row{ display:flex; gap:12px; flex-wrap:wrap; }
    .pill{ display:inline-block; padding:4px 10px; border-radius:999px; background:rgba(186,31,46,.1); color:var(--texas-red); border:1px solid rgba(186,31,46,.3); font-size:12px; }
    .table{ width:100%; border-collapse:collapse; margin-top:14px; }
    .table th,.table td{ padding:10px 8px; border-bottom:1px dashed #d7dce5; text-align:left; font-size:14px; }
    .footer{ margin-top:28px; font-size:13px; color:#6a6a6a; }
    @media (max-width: 900px){ .grid { grid-template-columns: repeat(2, minmax(0,1fr)); } }
    @media (max-width: 640px){ .grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="main">
      <div class="container">
        <div class="card" style="margin-bottom:12px">
          <a href="index.php">← Back</a>
          <h1>Carrier Snapshot — USDOT <?=h($usdot)?></h1>
          <?php if ($error): ?>
            <div class="muted">Error: <?=h($error)?></div>
          <?php endif; ?>
          <?php if ($snapshot): ?>
            <div class="row" style="margin-top:8px">
              <a class="tab <?= $tab==='snapshot'?'active':'' ?>" href="?usdot=<?=urlencode($usdot)?>&tab=snapshot">Snapshot</a>
              <a class="tab <?= $tab==='inspections'?'active':'' ?>" href="?usdot=<?=urlencode($usdot)?>&tab=inspections">Inspections</a>
              <a class="tab <?= $tab==='violations'?'active':'' ?>" href="?usdot=<?=urlencode($usdot)?>&tab=violations">Violations</a>
              <a class="tab <?= $tab==='crashes'?'active':'' ?>" href="?usdot=<?=urlencode($usdot)?>&tab=crashes">Crashes</a>
              <a class="tab" href="?usdot=<?=urlencode($usdot)?>&download=json" title="Download snapshot JSON">Export JSON</a>
            </div>
          <?php endif; ?>
        </div>

  <?php if ($snapshot && $tab === 'snapshot'): ?>
    <div class="card">
      <div class="grid">
        <div class="stat"><h4>Legal Name</h4><div><?=h($snapshot['legal_name'] ?? '-')?></div></div>
        <div class="stat"><h4>DBA</h4><div><?=h($snapshot['dba_name'] ?? '-')?></div></div>
        <div class="stat"><h4>Entity Type</h4><div><?=h($snapshot['entity_type'] ?? '-')?></div></div>
        <div class="stat"><h4>Operating Status</h4><div><?=h($snapshot['operating_status'] ?? '-')?></div></div>
        <div class="stat"><h4>Drivers</h4><div><?=h($snapshot['drivers'] ?? '-')?></div></div>
        <div class="stat"><h4>Power Units</h4><div><?=h($snapshot['power_units'] ?? '-')?></div></div>
      </div>

      <div class="row" style="margin-top:14px">
        <div class="stat" style="flex:1 1 380px">
          <h4>Addresses</h4>
          <div class="muted">Physical</div>
          <div><?=h($snapshot['physical_address'] ?? '-')?></div>
          <div class="muted" style="margin-top:8px">Mailing</div>
          <div><?=h($snapshot['mailing_address'] ?? '-')?></div>
        </div>
        <div class="stat" style="flex:1 1 280px">
          <h4>Contacts</h4>
          <div>Phone: <?=h($snapshot['phone'] ?? '-')?></div>
          <div>MC/MX/FF: <?=h($snapshot['mc_mx_ff_numbers'] ?? '-')?></div>
          <div>MCS-150 date: <?=h($snapshot['mcs_150_form_date'] ?? '-')?></div>
        </div>
        <div class="stat" style="flex:1 1 280px">
          <h4>Operations</h4>
          <div class="muted">Operation Classification</div>
          <div><?php foreach (($snapshot['operation_classification'] ?? []) as $oc) { echo badge($oc) . ' '; } ?></div>
          <div class="muted" style="margin-top:8px">Carrier Operation</div>
          <div><?php foreach (($snapshot['carrier_operation'] ?? []) as $co) { echo badge($co) . ' '; } ?></div>
        </div>
      </div>

      <div class="row" style="margin-top:14px">
        <div class="stat" style="flex:1 1 380px">
          <h4>Cargo Carried</h4>
          <div><?php foreach (($snapshot['cargo_carried'] ?? []) as $cg) { echo badge($cg) . ' '; } ?></div>
        </div>
        <div class="stat" style="flex:1 1 280px">
          <h4>Inspections (US)</h4>
          <?php if (isset($snapshot['united_states_inspections'])): $us=$snapshot['united_states_inspections']; ?>
            <div>Vehicle OOS%: <?=h($us['vehicle']['out_of_service_percent'] ?? '-')?></div>
            <div>Driver OOS%: <?=h($us['driver']['out_of_service_percent'] ?? '-')?></div>
            <div>Hazmat OOS%: <?=h($us['hazmat']['out_of_service_percent'] ?? '-')?></div>
          <?php endif; ?>
        </div>
        <div class="stat" style="flex:1 1 280px">
          <h4>Crashes (US)</h4>
          <?php if (isset($snapshot['united_states_crashes'])): $cr=$snapshot['united_states_crashes']; ?>
            <div>Total: <?=h($cr['total'] ?? '-')?></div>
            <div>Fatal: <?=h($cr['fatal'] ?? '-')?></div>
            <div>Injury: <?=h($cr['injury'] ?? '-')?></div>
            <div>Tow: <?=h($cr['tow'] ?? '-')?></div>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!empty($snapshot['url'])): ?>
        <div class="muted" style="margin-top:10px">Source: <a target="_blank" rel="noopener" href="<?=h($snapshot['url'])?>">FMCSA SAFER</a></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'inspections'): $rows = $data['inspection_records'] ?? []; ?>
    <div class="card">
      <h2 style="margin-top:0">Inspection Records</h2>
      <?php if (empty($rows)): ?>
        <div class="muted">No records.</div>
      <?php else: ?>
        <table class="table">
          <thead><tr>
            <th>Date</th><th>State</th><th>Level</th><th>OOS (Driver/Vehicle/Hazmat)</th><th>Units</th><th>Violations</th>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?=h($r['inspection_date'] ?? '')?></td>
              <td><?=h($r['report_state'] ?? '')?></td>
              <td><?=h($r['inspection_level'] ?? '')?></td>
              <td><?=h(($r['out_of_service_violations']['driver'] ?? 0) . '/' . ($r['out_of_service_violations']['vehicle'] ?? 0) . '/' . ($r['out_of_service_violations']['hazmat'] ?? 0))?></td>
              <td><?=h(count($r['units_inspected'] ?? []))?></td>
              <td><?=h(($r['violation_totals']['basic'] ?? 0))?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'violations'): $rows = $data['violation_records'] ?? []; ?>
    <div class="card">
      <h2 style="margin-top:0">Violation Records</h2>
      <?php if (empty($rows)): ?>
        <div class="muted">No records.</div>
      <?php else: ?>
        <table class="table">
          <thead><tr>
            <th>Date</th><th>Code</th><th>Basic</th><th>OOS</th><th>Severity</th><th>Group</th><th>Section</th>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?=h($r['inspection_date'] ?? '')?></td>
              <td><?=h($r['vioation_code'] ?? '')?></td>
              <td><?=h($r['basic_description'] ?? '')?></td>
              <td><?=h(($r['out_of_service_violation'] ?? false) ? 'Yes' : 'No')?></td>
              <td><?=h($r['severity_weight'] ?? '')?></td>
              <td><?=h($r['group_description'] ?? '')?></td>
              <td><?=h($r['section_descripton'] ?? '')?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'crashes'): $rows = $data['crash_records'] ?? []; ?>
    <div class="card">
      <h2 style="margin-top:0">Crash Records</h2>
      <?php if (empty($rows)): ?>
        <div class="muted">No records.</div>
      <?php else: ?>
        <table class="table">
          <thead><tr>
            <th>Date</th><th>State</th><th>Injuries</th><th>Fatalities</th><th>Tow</th><th>Road</th><th>Weather</th><th>Light</th>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?=h($r['report_date'] ?? '')?></td>
              <td><?=h($r['report_state'] ?? '')?></td>
              <td><?=h($r['total_injuries'] ?? 0)?></td>
              <td><?=h($r['total_fatalities'] ?? 0)?></td>
              <td><?=h(($r['tow_away'] ?? false) ? 'Yes' : 'No')?></td>
              <td><?=h($r['trafficway_description'] ?? '')?></td>
              <td><?=h($r['conditions']['weather'] ?? '')?></td>
              <td><?=h($r['conditions']['light'] ?? '')?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>

      <div class="footer">
        Endpoints referenced:
        <code>/v2/usdot/snapshot/:USDotNumber</code>,
        <code>/v2/name/:CarrierName</code>,
        <code>/v3/history/inspection/:USDotNumber</code>,
        <code>/v3/history/violation/:USDotNumber</code>,
        <code>/v3/history/crash/:USDotNumber</code>.
      </div>
    </div>
  </div>
</div>
</body>
</html>
