<?php
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/safer_client.php';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$type = isset($_GET['type']) ? $_GET['type'] : 'name'; // 'name' or 'usdot'
$error = null;
$results = [];

if ($q !== '') {
    if ($type === 'usdot') {
        $usdot = preg_replace('/\D+/', '', $q);
        if ($usdot === '') {
            $error = 'Please enter a numeric USDOT number.';
        } else {
            header('Location: carrier.php?usdot=' . urlencode($usdot));
            exit;
        }
    } else {
        // Search by carrier name
        try {
            $endpoint = '/v2/name/' . rawurlencode($q);
            $results = safer_call($endpoint, 'name_' . strtolower($q));
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>DOT Lookup</title>
  <style>
    * { box-sizing:border-box; }
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

    .card{
      background:#fff;
      border:1px solid #e3e7ef;
      border-radius:16px;
      padding:22px;
      box-shadow:0 10px 30px rgba(0,0,0,.08);
    }
    h1{ margin:0 0 12px; font-size:28px; color:var(--texas-navy); }
    .muted{ color:#6a6a6a; }
    form .row{ display:flex; gap:10px; flex-wrap:wrap; }
    input[type=text]{
      flex:1; min-width:260px; border-radius:12px;
      border:1px solid #d7dce5; background:#fff; color:#1a1a1a;
      padding:14px 14px; font-size:16px;
    }
    .radio{ display:flex; gap:16px; align-items:center; }
    .btn{
      background:var(--texas-red); color:#fff; padding:12px 16px;
      border-radius:12px; font-weight:700; border:none; cursor:pointer;
    }
    .table{ width:100%; border-collapse:collapse; margin-top:14px; }
    .table th,.table td{ padding:12px 10px; border-bottom:1px dashed #d7dce5; text-align:left; }
    .pill{
      display:inline-block; padding:4px 10px; border-radius:999px;
      background:rgba(186,31,46,.1); color:var(--texas-red);
      border:1px solid rgba(186,31,46,.3); font-size:12px;
    }
    .footer{ margin-top:28px; font-size:13px; color:#6a6a6a; }
  </style>
  
</head>
<body>
  <?php include __DIR__ . '/../includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="main">  
  <br />
  <div class="card" style="margin-bottom:16px">
    <h1>DOT Carrier & Driver Snapshot</h1>
    <div class="muted">Live data from SaferWebAPI (FMCSA / SAFER). Enter a USDOT or search by carrier name.</div>
  </div>

  <div class="card">
    <form method="get">
      <div class="row">
        <input type="text" name="q" placeholder="e.g., 195624, or 'Acme Logistics'" value="<?=h($q)?>">
        <button class="btn" type="submit">Search</button>
      </div>
      <div class="row" style="margin-top:10px">
        <label class="radio"><input type="radio" name="type" value="name" <?= $type!=='usdot'?'checked':''; ?>> <span style="margin-left:6px">Search by Carrier Name</span></label>
        <label class="radio"><input type="radio" name="type" value="usdot" <?= $type==='usdot'?'checked':''; ?>> <span style="margin-left:6px">Lookup by USDOT</span></label>
      </div>
    </form>

    <?php if ($error): ?>
      <p class="muted" style="margin-top:12px;color:#fca5a5">Error: <?=h($error)?></p>
    <?php endif; ?>

    <?php if ($q !== '' && $type !== 'usdot'): ?>
      <h3 style="margin-top:18px">Results</h3>
      <?php if (empty($results)): ?>
        <p class="muted">No active carriers found for "<?=h($q)?>".</p>
      <?php else: ?>
        <table class="table">
          <thead><tr><th>Name</th><th>Location</th><th>USDOT</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($results as $r): ?>
              <tr>
                <td><?=h($r['name'] ?? '')?></td>
                <td><span class="pill"><?=h($r['location'] ?? '')?></span></td>
                <td><strong><?=h($r['usdot'] ?? '')?></strong></td>
                <td><a class="btn" href="carrier.php?usdot=<?=urlencode($r['usdot'])?>">Open</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="footer">
    Built with PHP + cURL. Endpoints used: <code>/v2/name/:CarrierName</code> and <code>/v2/usdot/snapshot/:USDotNumber</code> plus optional history endpoints.
  </div>

  </div>
  </div>
</body>
</html>
