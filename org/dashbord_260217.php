<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';

function fetch_totals($mysqli, $sql, $types = '', $params = []) {
    $rows = [];
    if ($stmt = $mysqli->prepare($sql)) {
        if ($types && $params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        if (method_exists($stmt, 'get_result')) {
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $rows[] = $row;
        } else {
            $stmt->bind_result($label, $total);
            while ($stmt->fetch()) {
                $rows[] = ['label' => $label, 'total' => $total];
            }
        }
        $stmt->close();
    }
    return $rows;
}

function has_driver_id_column($mysqli) {
    $has = false;
    $stmt = $mysqli->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'driver_payouts'
           AND COLUMN_NAME = 'driver_id'"
    );
    if ($stmt) {
        $stmt->execute();
        $stmt->bind_result($cnt);
        $stmt->fetch();
        $has = $cnt > 0;
        $stmt->close();
    }
    return $has;
}

function fetch_driver_names($mysqli, $driverId) {
    $names = [];
    if ($driverId <= 0) return $names;

    if ($stmt = $mysqli->prepare("SELECT first_name, last_name FROM driver_contacts WHERE id=?")) {
        $stmt->bind_param('i', $driverId);
        $stmt->execute();
        $stmt->bind_result($first, $last);
        if ($stmt->fetch()) {
            $name = trim(($first ?? '') . ' ' . ($last ?? ''));
            if ($name !== '') $names[] = $name;
        }
        $stmt->close();
    }

    if ($stmt = $mysqli->prepare("SELECT alias_first_name, alias_last_name FROM driver_name_aliases WHERE driver_contact_id=?")) {
        $stmt->bind_param('i', $driverId);
        $stmt->execute();
        if (method_exists($stmt, 'get_result')) {
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $alias = trim(($row['alias_first_name'] ?? '') . ' ' . ($row['alias_last_name'] ?? ''));
                if ($alias !== '') $names[] = $alias;
            }
        } else {
            $stmt->bind_result($af, $al);
            while ($stmt->fetch()) {
                $alias = trim(($af ?? '') . ' ' . ($al ?? ''));
                if ($alias !== '') $names[] = $alias;
            }
        }
        $stmt->close();
    }

    return array_values(array_unique($names));
}

$drivers = [];
$resDrivers = $mysqli->query(
    "SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM driver_contacts ORDER BY last_name, first_name"
);
while ($r = $resDrivers->fetch_assoc()) {
    $drivers[] = $r;
}

$selectedDriverId = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : (isset($drivers[0]['id']) ? (int)$drivers[0]['id'] : 0);
$hasDriverId = has_driver_id_column($mysqli);
$driverNames = fetch_driver_names($mysqli, $selectedDriverId);

$filterSql = '';
$filterTypes = '';
$filterParams = [];
if ($selectedDriverId > 0) {
    if ($hasDriverId) {
        $filterSql = ' AND (driver_id = ?';
        $filterTypes = 'i';
        $filterParams[] = $selectedDriverId;
        if (!empty($driverNames)) {
            $placeholders = implode(',', array_fill(0, count($driverNames), '?'));
            $filterSql .= " OR (driver_id IS NULL AND driver_name IN ($placeholders))";
            $filterTypes .= str_repeat('s', count($driverNames));
            $filterParams = array_merge($filterParams, $driverNames);
        }
        $filterSql .= ')';
    } else {
        if (!empty($driverNames)) {
            $placeholders = implode(',', array_fill(0, count($driverNames), '?'));
            $filterSql = " AND driver_name IN ($placeholders)";
            $filterTypes = str_repeat('s', count($driverNames));
            $filterParams = $driverNames;
        } else {
            $filterSql = " AND 1=0";
        }
    }
} else {
    $filterSql = " AND 1=0";
}

$monthRows = fetch_totals(
    $mysqli,
    "SELECT DATE(payout_date) AS label, COALESCE(SUM(tss_pay),0) AS total
     FROM driver_payouts
     WHERE payout_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
       AND payout_date < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
     $filterSql
     GROUP BY DATE(payout_date)
     ORDER BY label",
    $filterTypes,
    $filterParams
);

$yearOptions = [2024, 2025, 2026];
$yearRowsByYear = [];
$yearTotalsByYear = [];
foreach ($yearOptions as $year) {
    $rows = fetch_totals(
        $mysqli,
        "SELECT DATE_FORMAT(payout_date, '%Y-%m') AS label, COALESCE(SUM(tss_pay),0) AS total
         FROM driver_payouts
         WHERE YEAR(payout_date) = ?
         $filterSql
         GROUP BY DATE_FORMAT(payout_date, '%Y-%m')
         ORDER BY label",
        'i' . $filterTypes,
        array_merge([$year], $filterParams)
    );
    $yearRowsByYear[$year] = $rows;
    $yearTotalsByYear[$year] = array_sum(array_map(fn($r) => (float)$r['total'], $rows));
}

$lifetimeRows = fetch_totals(
    $mysqli,
    "SELECT YEAR(payout_date) AS label, COALESCE(SUM(tss_pay),0) AS total
     FROM driver_payouts
     WHERE 1=1
     $filterSql
     GROUP BY YEAR(payout_date)
     ORDER BY label",
    $filterTypes,
    $filterParams
);

$monthTotal = array_sum(array_map(fn($r) => (float)$r['total'], $monthRows));
$defaultYear = 2025;
$yearTotal = $yearTotalsByYear[$defaultYear] ?? 0.0;
$lifeTotal = array_sum(array_map(fn($r) => (float)$r['total'], $lifetimeRows));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard</title>
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
    .card-shadow { box-shadow:0 12px 26px rgba(0,0,0,.08); border:1px solid #e5e9f0; }
    .chart-box { height: 220px; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
          <h1 class="mb-1">Driver Payouts Dashboard</h1>
          <div class="text-muted">Track payouts by month, year, or lifetime for the selected driver.</div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
          <form method="get" class="d-flex align-items-center gap-2">
            <label for="driverSelect" class="form-label mb-0">Driver</label>
            <select id="driverSelect" name="driver_id" class="form-select">
              <?php foreach ($drivers as $d): ?>
                <option value="<?= (int)$d['id'] ?>" <?= ((int)$selectedDriverId === (int)$d['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($d['name'], ENT_QUOTES) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline-primary">Load</button>
          </form>
          <label for="timeframeSelect" class="form-label mb-0">Timeframe</label>
          <select id="timeframeSelect" class="form-select">
            <option value="month">Last 30 days</option>
            <?php foreach ($yearOptions as $year): ?>
              <option value="<?= (int)$year ?>" <?= $year === $defaultYear ? 'selected' : '' ?>><?= (int)$year ?></option>
            <?php endforeach; ?>
            <option value="lifetime">Lifetime</option>
          </select>
        </div>
      </div>

      <?php if (empty($drivers)): ?>
        <div class="alert alert-info">Add a driver contact to see payout analytics.</div>
      <?php endif; ?>

      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <div class="card card-shadow p-3 h-100">
            <div class="text-muted">Last 30 days</div>
            <div class="h4 mb-0">$<?= number_format($monthTotal, 2) ?></div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card card-shadow p-3 h-100">
            <div class="text-muted" id="yearTotalLabel">Year <?= (int)$defaultYear ?></div>
            <div class="h4 mb-0" id="yearTotalValue">$<?= number_format($yearTotal, 2) ?></div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card card-shadow p-3 h-100">
            <div class="text-muted">Lifetime</div>
            <div class="h4 mb-0">$<?= number_format($lifeTotal, 2) ?></div>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-12">
          <div class="card card-shadow p-3">
            <h5 class="mb-3">Payouts Overview (Bar)</h5>
            <div class="chart-box">
              <canvas id="payoutBar"></canvas>
            </div>
          </div>
        </div>
        <div class="col-12">
          <div class="card card-shadow p-3">
            <h5 class="mb-3">Payouts Trend (Line)</h5>
            <div class="chart-box">
              <canvas id="payoutLine"></canvas>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script>
    const datasets = {
      month: {
        labels: <?= json_encode(array_column($monthRows, 'label')) ?>,
        data: <?= json_encode(array_map('floatval', array_column($monthRows, 'total'))) ?>
      },
      <?php foreach ($yearOptions as $year): ?>
      "<?= (int)$year ?>": {
        labels: <?= json_encode(array_column($yearRowsByYear[$year] ?? [], 'label')) ?>,
        data: <?= json_encode(array_map('floatval', array_column($yearRowsByYear[$year] ?? [], 'total'))) ?>
      },
      <?php endforeach; ?>
      lifetime: {
        labels: <?= json_encode(array_column($lifetimeRows, 'label')) ?>,
        data: <?= json_encode(array_map('floatval', array_column($lifetimeRows, 'total'))) ?>
      }
    };
    const yearTotals = <?= json_encode($yearTotalsByYear) ?>;

    const formatCurrency = (value) => new Intl.NumberFormat('en-US', {
      style: 'currency', currency: 'USD', maximumFractionDigits: 2
    }).format(value || 0);

    const barCtx = document.getElementById('payoutBar');
    const lineCtx = document.getElementById('payoutLine');

    const baseOptions = {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: {
          ticks: { maxTicksLimit: 31, autoSkip: false }
        },
        y: {
          min: 0,
          ticks: { callback: (val) => formatCurrency(val) }
        }
      },
      plugins: {
        tooltip: {
          callbacks: { label: (ctx) => formatCurrency(ctx.parsed.y) }
        },
        legend: { display: false }
      }
    };

    const initialTimeframe = document.getElementById('timeframeSelect').value;
    const barChart = new Chart(barCtx, {
      type: 'bar',
      data: {
        labels: datasets[initialTimeframe].labels,
        datasets: [{
          label: 'Payouts',
          data: datasets[initialTimeframe].data,
          backgroundColor: 'rgba(186,31,46,0.75)',
          borderColor: 'rgba(186,31,46,1)',
          borderWidth: 1
        }]
      },
      options: baseOptions
    });

    const lineChart = new Chart(lineCtx, {
      type: 'line',
      data: {
        labels: datasets[initialTimeframe].labels,
        datasets: [{
          label: 'Payouts',
          data: datasets[initialTimeframe].data,
          borderColor: 'rgba(0,42,92,1)',
          backgroundColor: 'rgba(0,42,92,0.15)',
          tension: 0.3,
          fill: true,
          pointRadius: 3
        }]
      },
      options: baseOptions
    });

    const select = document.getElementById('timeframeSelect');
    const applyScale = (chart, timeframe) => {
      const yScale = chart.options.scales.y;
      const xScale = chart.options.scales.x;
      if (timeframe === 'month') {
        yScale.min = 0;
        yScale.max = 10000;
        yScale.ticks.stepSize = 2000;
        xScale.ticks.autoSkip = false;
        xScale.ticks.maxTicksLimit = 31;
      } else if (timeframe === '2024' || timeframe === '2025' || timeframe === '2026') {
        yScale.min = 0;
        yScale.max = 40000;
        yScale.ticks.stepSize = 5000;
        xScale.ticks.autoSkip = true;
        xScale.ticks.maxTicksLimit = 12;
      } else {
        yScale.min = 0;
        yScale.max = 40000;
        yScale.ticks.stepSize = 5000;
        xScale.ticks.autoSkip = true;
        xScale.ticks.maxTicksLimit = 12;
      }
    };

    applyScale(barChart, initialTimeframe);
    applyScale(lineChart, initialTimeframe);

    select.addEventListener('change', () => {
      const set = datasets[select.value];
      barChart.data.labels = set.labels;
      barChart.data.datasets[0].data = set.data;
      applyScale(barChart, select.value);
      barChart.update();

      lineChart.data.labels = set.labels;
      lineChart.data.datasets[0].data = set.data;
      applyScale(lineChart, select.value);
      lineChart.update();

      const yearTotalLabel = document.getElementById('yearTotalLabel');
      const yearTotalValue = document.getElementById('yearTotalValue');
      if (yearTotalLabel && yearTotalValue && Object.prototype.hasOwnProperty.call(yearTotals, select.value)) {
        yearTotalLabel.textContent = `Year ${select.value}`;
        yearTotalValue.textContent = formatCurrency(yearTotals[select.value]);
      }
    });
  </script>
</body>
</html>
