<?php
// additional_information.php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
session_start();
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';

// AJAX for gas cost lookup or insurance lookup
if (isset($_GET['ajax'])) {
    $type      = $_GET['ajax'];
    $driver_id = intval($_GET['driver_id']);
    header('Content-Type: application/json');
    if ($type === 'gas') {
        $date = $_GET['date'];
        $stmt = $mysqli->prepare(
            "SELECT amount FROM driver_gas_costs WHERE driver_id=? AND cost_date=?"
        );
        $stmt->bind_param('is', $driver_id, $date);
    } elseif ($type === 'insurance') {
        $stmt = $mysqli->prepare(
            "SELECT amount FROM driver_insurance_costs WHERE driver_id=?"
        );
        $stmt->bind_param('i', $driver_id);
    }
    $stmt->execute();
    $stmt->bind_result($amount);
    echo json_encode(['amount' => $stmt->fetch() ? $amount : '']);
    exit;
}

// Handle form submissions
$errors     = [];
$successGas = false;
$successIns = false;

// Gas form inputs
$driver_id  = $_POST['driver_id'] ?? '';
$dates      = $_POST['dates'] ?? [];
$amounts    = $_POST['amounts'] ?? [];

// Insurance form inputs
$ins_driver = $_POST['ins_driver_id'] ?? '';
$ins_amt    = $_POST['ins_amount'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Gas form
    if (isset($_POST['form_type']) && $_POST['form_type'] === 'gas') {
        if (!$driver_id) {
            $errors[] = 'Select a driver for gas.';
        } else {
            for ($i = 0; $i < 7; $i++) {
                if (!empty($dates[$i])) {
                    $d = $dates[$i];
                    $a = floatval($amounts[$i]);
                    // upsert gas cost
                    $stmt = $mysqli->prepare(
                        "SELECT id FROM driver_gas_costs WHERE driver_id=? AND cost_date=?"
                    );
                    $stmt->bind_param('is', $driver_id, $d);
                    $stmt->execute();
                    $stmt->store_result();
                    if ($stmt->num_rows) {
                        $u = $mysqli->prepare(
                            "UPDATE driver_gas_costs SET amount=?, updated_at=NOW() WHERE driver_id=? AND cost_date=?"
                        );
                        $u->bind_param('dis', $a, $driver_id, $d);
                        $u->execute();
                        $u->close();
                    } else {
                        $i2 = $mysqli->prepare(
                            "INSERT INTO driver_gas_costs (driver_id, cost_date, amount) VALUES (?, ?, ?)"
                        );
                        $i2->bind_param('isd', $driver_id, $d, $a);
                        $i2->execute();
                        $i2->close();
                    }
                    $stmt->close();
                }
            }
            $successGas = true;
        }
    }
    // Insurance form
    if (isset($_POST['form_type']) && $_POST['form_type'] === 'insurance') {
        if (!$ins_driver) {
            $errors[] = 'Select a driver for insurance.';
        } else {
            $a = floatval($ins_amt);
            // upsert insurance cost
            $stmt = $mysqli->prepare(
                "SELECT id FROM driver_insurance_costs WHERE driver_id=?"
            );
            $stmt->bind_param('i', $ins_driver);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows) {
                $u = $mysqli->prepare(
                    "UPDATE driver_insurance_costs SET amount=?, updated_at=NOW() WHERE driver_id=?"
                );
                $u->bind_param('di', $a, $ins_driver);
                $u->execute();
                $u->close();
            } else {
                $i2 = $mysqli->prepare(
                    "INSERT INTO driver_insurance_costs (driver_id, amount) VALUES (?, ?)"
                );
                $i2->bind_param('id', $ins_driver, $a);
                $i2->execute();
                $i2->close();
            }
            $stmt->close();
            $successIns = true;
        }
    }
}

// Fetch drivers
$drivers = [];
$res = $mysqli->query(
    "SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM driver_contacts ORDER BY last_name"
);
while ($r = $res->fetch_assoc()) {
    $drivers[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Additional Information</title>
  <style>
    body { margin:0; font-family:sans-serif; }
    .page-shell { display:flex; min-height: calc(100vh - var(--banner-h)); }
    .sidebar { width:250px; background:#333; color:#fff; height:calc(100vh - var(--banner-h)); position:fixed; top:var(--banner-h); overflow:auto; transition:transform .3s; }
    .sidebar.collapsed { transform:translateX(-250px); }
    .sidebar a { display:block; color:#fff; padding:15px; text-decoration:none; }
    .sidebar a:hover { background:#444; }
    .main { margin-left:250px; padding:20px; flex:1; min-width:0; }
    .sidebar.collapsed + .main { margin-left:0; }
    @media(max-width:768px){ .sidebar{transform:translateX(-250px);} .sidebar.open{transform:translateX(0);} .main{margin:0;} }
    form{border:1px solid #ccc;padding:20px;border-radius:5px;margin-bottom:20px}
    label,select,input,button{display:block;width:95%;margin:8px 0;padding:8px}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th,td{border:1px solid #ccc;padding:8px;text-align:left}
    .errors{color:red}
    .success{color:green}
  </style>
</head>
<body>
<?php include __DIR__ . '/includes/ls_title.php'; ?>
<div class="page-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main">

  <h1>Gas Costs Entry</h1>
  <?php if ($successGas): ?><p class="success">Gas costs saved.</p><?php endif; ?>
  <form method="post"><input type="hidden" name="form_type" value="gas">
    <label>Driver
      <select name="driver_id" id="driver-gas" required>
        <option value="">--Select Driver--</option>
        <?php foreach ($drivers as $d): ?>
          <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <table><thead><tr><th>Date</th><th>Amount</th></tr></thead><tbody>
      <?php for ($i = 0; $i < 7; $i++): ?>
        <tr>
          <td><input type="date" name="dates[]" class="date-input" data-idx="<?= $i ?>"></td>
          <td><input type="number" step="0.01" name="amounts[]" id="gas-<?= $i ?>"></td>
        </tr>
      <?php endfor; ?>
    </tbody></table>
    <button type="submit">Save Gas Costs</button>
  </form>

  <h1>Insurance Cost Entry</h1>
  <?php if ($successIns): ?><p class="success">Insurance cost saved.</p><?php endif; ?>
  <form method="post"><input type="hidden" name="form_type" value="insurance">
    <label>Driver
      <select name="ins_driver_id" id="driver-insurance" required>
        <option value="">--Select Driver--</option>
        <?php foreach ($drivers as $d): ?>
          <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Amount
      <input type="number" step="0.01" name="ins_amount" id="ins-amt" required>
    </label>
    <button type="submit">Save Insurance Cost</button>
  </form>
</div>
</div>
<script>
  // Gas cost AJAX
  document.querySelectorAll('.date-input').forEach(input => {
    input.addEventListener('change', e => {
      const idx = e.target.dataset.idx,
            drv = document.getElementById('driver-gas').value,
            d   = e.target.value;
      if (drv && d) fetch(`?ajax=gas&driver_id=${drv}&date=${d}`)
        .then(r => r.json()).then(j => document.getElementById(`gas-${idx}`).value = j.amount);
    });
  });

  // Insurance AJAX
  const insSelect = document.getElementById('driver-insurance');
  insSelect.addEventListener('change', () => {
    const drv = insSelect.value;
    if (drv) fetch(`?ajax=insurance&driver_id=${drv}`)
      .then(r => r.json()).then(j => document.getElementById('ins-amt').value = j.amount);
  });
</script>
</body>
</html>
