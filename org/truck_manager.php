<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Truck Manager</title>
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
      padding:24px;
      box-shadow:0 10px 30px rgba(17, 24, 39, 0.06);
    }

    .placeholder-card {
      margin-top:20px;
      background:#fff;
      border:1px solid #dde4ee;
      border-radius:18px;
      padding:22px;
      box-shadow:0 10px 24px rgba(17, 24, 39, 0.05);
    }

    .coming-soon-badge {
      display:inline-flex;
      align-items:center;
      padding:6px 12px;
      border-radius:999px;
      background:#fff3cd;
      color:#8a6d3b;
      font-weight:700;
      letter-spacing:.02em;
      text-transform:uppercase;
      font-size:12px;
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
          <div class="coming-soon-badge">Coming Soon</div>
          <h1 class="mt-3 mb-2">Truck Manager</h1>
          <div class="text-muted">
            This placeholder page is reserved for future truck tracking for Lone Star leased-on owner operators and company drivers.
          </div>
        </div>
      </div>
    </div>

    <div class="placeholder-card">
      <h2 class="h5 mb-3">Planned Functionality</h2>
      <p class="mb-3">
        The Truck Manager tool will be added here in a future update. For now, this page is a placeholder so the menu structure is ready.
      </p>
      <ul class="mb-0">
        <li>Track trucks assigned to Lone Star leased-on owner operators and company drivers</li>
        <li>Provide a management view similar to Trailer Manager</li>
        <li>Support future assignment and asset tracking workflows</li>
      </ul>
    </div>
  </div>
</div>
</body>
</html>
