<?php
// Initial dashboard response: only inexpensive roster counts.
$counts = $mysqli->query("SELECT COUNT(*) AS drivers, SUM(COALESCE(is_disabled,0)=0) AS active FROM driver_contacts")->fetch_assoc();
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    body { margin:0; font-family:sans-serif; background:#f4f6fb; }
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
    .metric-card { min-height: 140px; }
    .metric-card .metric-value { font-size: 2rem; font-weight: 700; }
    .metric-card .metric-label { color: #6c757d; }
    .chart-card { min-height: 320px; }
    .drivers-week-chart { width: 50%; min-height: 160px; margin: 0 auto; }
    .table-card { background: #fff; border-radius: 1rem; padding: 1rem; box-shadow: 0 0 30px rgba(0,0,0,.05); }
    .dashboard-header { margin-bottom: 1.5rem; }
    .dashboard-header h1 { margin-bottom: 0.25rem; }
    .dashboard-box { background:#fff; border-radius:1rem; padding:1.25rem; box-shadow:0 12px 26px rgba(0,0,0,.05); }
    .gauge-label { font-size:.9rem; color:#6c757d; }
    .gauge-value { font-size:1.6rem; font-weight:700; }
    .gauge-bar { height: 14px; border-radius: 999px; overflow:hidden; background:#e9ecef; }
    .gauge-fill { height: 100%; border-radius: 999px; }
    .card-small { background:#fff; border-radius:1rem; padding:1rem; box-shadow:0 10px 20px rgba(0,0,0,.04); }
  </style>
</head>
<body>
<?php include __DIR__ . '/ls_title.php'; ?>
<div class="page-shell">
<?php include __DIR__ . '/sidebar.php'; ?>
<div class="main" id="dashboardContent" aria-busy="true">
<h1>Operations Dashboard</h1>
<p>Drivers: <?= (int)$counts['drivers'] ?> · Active: <?= (int)$counts['active'] ?></p>
<p role="status" id="dashboardLoading">Loading payout totals, balances, and charts…</p>
<button type="button" id="dashboardRetry" class="btn btn-primary" hidden>Retry</button>
<noscript><a href="dashboard.php?dashboard_data=1">Open the full dashboard</a></noscript>
</div>
</div>
<script>
(() => {
  const target = document.getElementById('dashboardContent');
  const retry = document.getElementById('dashboardRetry');
  async function loadDashboard() {
    retry.hidden = true;
    target.setAttribute('aria-busy','true');
    document.getElementById('dashboardLoading').textContent = 'Loading payout totals, balances, and charts…';
    const url = new URL(window.location.href);
    url.searchParams.set('dashboard_data','1');
    try {
      const response = await fetch(url, {credentials:'same-origin',cache:'no-store'});
      if (!response.ok) throw new Error('Dashboard request failed');
      const page = new DOMParser().parseFromString(await response.text(),'text/html');
      const content = page.querySelector('.main');
      const charts = page.getElementById('dashboardCharts');
      if (!content || !charts) throw new Error('Dashboard response unavailable');
      target.innerHTML = content.innerHTML;
      target.setAttribute('aria-busy','false');
      const script = document.createElement('script');
      script.textContent = '(() => {' + charts.textContent + '\n})();';
      document.body.appendChild(script);
    } catch (error) {
      target.setAttribute('aria-busy','false');
      const status = document.getElementById('dashboardLoading');
      if (status) status.textContent = 'Dashboard details could not load. Retry, or reload the page to check your login.';
      retry.hidden = false;
    }
  }
  retry.addEventListener('click',loadDashboard);
  loadDashboard();
})();
</script>
</body></html>