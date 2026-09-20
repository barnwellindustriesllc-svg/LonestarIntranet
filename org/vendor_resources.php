<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';

$vendor = $_GET['vendor'] ?? '';
$vendorMap = [
    'sanddrive' => [
        'name' => 'Sand Drive',
        'url' => 'https://sanddrive.tssands.com/analytics'
    ],
    'rtswex' => [
        'name' => 'RTS/WEX',
        'url' => 'https://emgr.efsllc.com/'
    ]
];

$selectedVendor = $vendorMap[$vendor] ?? null;

if (!$selectedVendor) {
    $vendor = 'sanddrive';
    $selectedVendor = $vendorMap[$vendor];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($selectedVendor['name'], ENT_QUOTES) ?> - Additional Resources</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { margin: 0; font-family: sans-serif; background: #f5f7fb; }
    .page-shell { display: flex; min-height: calc(100vh - var(--banner-h)); }
    .sidebar { width: 250px; background: #333; color: #fff; height: calc(100vh - var(--banner-h)); position: fixed; top: var(--banner-h); overflow: auto; transition: transform .3s ease; }
    .sidebar.collapsed { transform: translateX(-250px); }
    .sidebar a { display: block; color: #fff; padding: 15px; text-decoration: none; }
    .sidebar a:hover { background: #444; }
    .main { margin-left: 250px; padding: 20px; flex: 1; min-width: 0; }
    .sidebar.collapsed + .main { margin-left: 0; }
    @media (max-width: 768px) {
      .sidebar { transform: translateX(-250px); }
      .sidebar.open { transform: translateX(0); }
      .main { margin: 0; }
    }

    .vendor-header { margin-bottom: 20px; }
    .vendor-header h1 { margin-bottom: 0.25rem; }
    .vendor-header p { color: #6c757d; }
    .vendor-frame-container { background: #fff; border-radius: 1rem; box-shadow: 0 12px 26px rgba(0,0,0,.05); overflow: hidden; }
    .vendor-frame { width: 100%; height: calc(100vh - var(--banner-h) - 150px); border: none; }
    .vendor-selector { margin-bottom: 20px; }
    .vendor-selector a { display: inline-block; margin-right: 12px; padding: 8px 14px; background: #e9ecef; color: #495057; text-decoration: none; border-radius: 6px; transition: background .2s ease; }
    .vendor-selector a.active { background: #0d6efd; color: #fff; }
    .vendor-selector a:hover { background: #0d6efd; color: #fff; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main">
      <div class="vendor-header">
        <h1><?= htmlspecialchars($selectedVendor['name'], ENT_QUOTES) ?></h1>
        <p>Access your client application directly within Lone Star tools.</p>
      </div>

      <div class="vendor-selector">
        <a href="?vendor=sanddrive" <?= $vendor === 'sanddrive' ? 'class="active"' : '' ?>>Sand Drive</a>
        <a href="?vendor=rtswex" <?= $vendor === 'rtswex' ? 'class="active"' : '' ?>>RTS/WEX</a>
      </div>

      <div class="alert alert-info" role="alert">
        These client apps are loaded through an internal proxy so they can display inside the tool. Some pages may still require login or may not render perfectly if the client has complex JavaScript or security policies.
      </div>

      <div class="mb-3">
        <a class="btn btn-primary me-2" href="<?= htmlspecialchars($selectedVendor['url'], ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer">Open <?= htmlspecialchars($selectedVendor['name'], ENT_QUOTES) ?> in new tab</a>
        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($selectedVendor['url'], ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer">Open external site</a>
      </div>

      <div class="vendor-frame-container">
        <iframe class="vendor-frame" src="vendor_proxy.php?vendor=<?= urlencode($vendor) ?>" title="<?= htmlspecialchars($selectedVendor['name'], ENT_QUOTES) ?> Application"></iframe>
      </div>
    </div>
  </div>
</body>
</html>
