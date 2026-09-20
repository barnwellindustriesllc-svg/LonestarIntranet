<?php
// driver_name_aliases.php
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);
session_start();
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES); }
function norm_full($s){ return strtolower(trim(preg_replace('/\s+/', ' ', (string)$s))); }

$errors=[]; $success=false;

// Add alias
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='add') {
  $cid = (int)($_POST['driver_contact_id'] ?? 0);
  $af  = trim($_POST['alias_first_name'] ?? '');
  $al  = trim($_POST['alias_last_name'] ?? '');

  if ($cid <= 0 || $af==='' || $al==='') {
    $errors[] = 'Contact, alias first & last name are required.';
  } else {
    $stmt = $mysqli->prepare("INSERT INTO driver_name_aliases (driver_contact_id, alias_first_name, alias_last_name) VALUES (?,?,?)");
    if (!$stmt) {
      $errors[] = 'Insert prepare error: '.$mysqli->error;
    } else {
      $stmt->bind_param('iss', $cid, $af, $al);
      if (!$stmt->execute()) {
        $errors[] = 'Insert error: '.$stmt->error;
      } else {
        $success = true;
      }
      $stmt->close();
    }
  }
}

// Delete alias
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='delete') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0) {
    $del = $mysqli->prepare("DELETE FROM driver_name_aliases WHERE id=? LIMIT 1");
    if ($del) {
      $del->bind_param('i', $id);
      if (!$del->execute()) $errors[] = 'Delete error: '.$del->error;
      else $success=true;
      $del->close();
    } else {
      $errors[]='Delete prepare error: '.$mysqli->error;
    }
  }
}

// Load contacts
$contacts = [];
$q = $mysqli->query("SELECT id, first_name, last_name FROM driver_contacts ORDER BY last_name, first_name");
while ($c = $q->fetch_assoc()) $contacts[] = $c;
$q->close();

// Load aliases with contact names
$aliases = [];
$qa = $mysqli->query("
  SELECT a.id, a.driver_contact_id, a.alias_first_name, a.alias_last_name,
         c.first_name, c.last_name
    FROM driver_name_aliases a
    JOIN driver_contacts c ON c.id = a.driver_contact_id
   ORDER BY c.last_name, c.first_name, a.alias_last_name, a.alias_first_name
");
while ($row = $qa->fetch_assoc()) $aliases[] = $row;
$qa->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Driver Name Aliases</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { margin:0; font-family:sans-serif; }
    .page-shell { display:flex; min-height: calc(100vh - var(--banner-h)); }
    .sidebar { width:250px; background:#333; color:#fff; height:calc(100vh - var(--banner-h)); position:fixed; top:var(--banner-h); overflow:auto; transition:transform .3s; }
    .sidebar.collapsed { transform:translateX(-250px); }
    .sidebar a { display:block; color:#fff; padding:15px; text-decoration:none; }
    .sidebar a:hover { background:#444; }
    .main { margin-left:250px; padding:20px; flex:1; min-width:0; }
    .sidebar.collapsed + .main { margin-left:0; }
    @media(max-width:768px) { .sidebar { transform:translateX(-250px); } .sidebar.open { transform:translateX(0); } .main { margin:0; } }
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
    <div class="d-flex align-items-center justify-content-between">
      <h1 class="mb-0">Driver Name Aliases</h1>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger mt-3"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
    <?php elseif ($success): ?>
      <div class="alert alert-success mt-3">Saved.</div>
    <?php endif; ?>

    <div class="card mt-3">
      <div class="card-header">Add Alias</div>
      <div class="card-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="add">
          <div class="col-md-4">
            <label class="form-label">Driver Contact</label>
            <select name="driver_contact_id" class="form-select" required>
              <option value="">Select contact...</option>
              <?php foreach ($contacts as $c): ?>
                <option value="<?= (int)$c['id'] ?>">
                  <?= h(trim(($c['last_name'] ?? '').', '.($c['first_name'] ?? ''))) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Alias First Name</label>
            <input type="text" name="alias_first_name" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Alias Last Name</label>
            <input type="text" name="alias_last_name" class="form-control" required>
          </div>
          <div class="col-12">
            <button class="btn btn-primary" type="submit">Add Alias</button>
          </div>
        </form>
      </div>
    </div>

    <h2 class="mt-4">Existing Aliases</h2>
    <div class="table-responsive">
      <table class="table table-striped align-middle">
        <thead>
          <tr>
            <th>Contact</th>
            <th>Alias First</th>
            <th>Alias Last</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($aliases)): ?>
          <tr><td colspan="4" class="text-muted">No aliases yet.</td></tr>
        <?php else: foreach ($aliases as $a): ?>
          <tr>
            <td><?= h(trim(($a['last_name'] ?? '').', '.($a['first_name'] ?? ''))) ?> (ID: <?= (int)$a['driver_contact_id'] ?>)</td>
            <td><?= h($a['alias_first_name']) ?></td>
            <td><?= h($a['alias_last_name']) ?></td>
            <td>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this alias?')">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
