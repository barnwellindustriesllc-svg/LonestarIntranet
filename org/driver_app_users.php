<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');

$currentUser = $_SESSION['username'] ?? '';
if ($currentUser !== 'admin') {
    header('Location: /lonestar/index.php');
    exit;
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES);
}

function table_exists(mysqli $db, string $table): bool {
    $table = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}

function table_has_column(mysqli $db, string $table, string $column): bool {
    $table = $db->real_escape_string($table);
    $column = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

$setupErrors = [];
$errors = [];
$success = '';
$driversTableReady = table_exists($mysqli, 'drivers');
$hasName = $driversTableReady && table_has_column($mysqli, 'drivers', 'name');
$hasCreatedAt = $driversTableReady && table_has_column($mysqli, 'drivers', 'created_at');
$hasUpdatedAt = $driversTableReady && table_has_column($mysqli, 'drivers', 'updated_at');

$requiredColumns = ['id', 'email', 'password_hash', 'active'];
if ($driversTableReady) {
    foreach ($requiredColumns as $column) {
        if (!table_has_column($mysqli, 'drivers', $column)) {
            $setupErrors[] = "The drivers table is missing required column: {$column}.";
        }
    }
} else {
    $setupErrors[] = 'The drivers table does not exist yet.';
}

if (!table_exists($mysqli, 'driver_contacts')) {
    $setupErrors[] = 'The driver_contacts table does not exist.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $driversTableReady && empty($setupErrors)) {
    $action = $_POST['action'] ?? 'create';
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $active = isset($_POST['active']) ? 1 : 0;
    $driverContactId = (int)($_POST['driver_contact_id'] ?? 0);

    if ($action === 'reset_password') {
        $driverId = (int)($_POST['driver_id'] ?? 0);

        if ($driverId <= 0 || $password === '') {
            $errors[] = 'Choose a driver login and enter a new password.';
        } elseif (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $mysqli->prepare('UPDATE drivers SET password_hash = ?, active = 1 WHERE id = ?');
            $stmt->bind_param('si', $hash, $driverId);
            $stmt->execute();

            if ($stmt->affected_rows >= 0) {
                $success = 'Driver app password reset and login activated.';
                $_POST = [];
            } else {
                $errors[] = 'Unable to reset password.';
            }
            $stmt->close();
        }
    } elseif ($email === '' || $password === '') {
        $errors[] = 'Email and password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif ($driverContactId <= 0) {
        $errors[] = 'Choose a driver from Driver Details.';
    } else {
        $contactStmt = $mysqli->prepare(
            'SELECT id FROM driver_contacts WHERE id = ? AND COALESCE(is_disabled, 0) = 0 LIMIT 1'
        );
        $contactStmt->bind_param('i', $driverContactId);
        $contactStmt->execute();
        $contact = $contactStmt->get_result()->fetch_assoc();
        $contactStmt->close();

        if (!$contact) {
            $errors[] = 'Choose an active driver from Driver Details.';
        }

        $stmt = $mysqli->prepare('SELECT id FROM drivers WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            $errors[] = 'A driver login already exists for that email.';
        }

        $stmt = $mysqli->prepare('SELECT id FROM drivers WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $driverContactId);
        $stmt->execute();
        $existingContactLogin = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existingContactLogin) {
            $errors[] = 'That driver already has an app login.';
        }

        if (empty($errors)) {
            $columns = ['id', 'email', 'password_hash', 'active'];
            $values = ['?', '?', '?', '?'];
            $types = 'issi';
            $params = [$driverContactId, $email, password_hash($password, PASSWORD_DEFAULT), $active];

            if ($hasName) {
                $columns[] = 'name';
                $values[] = '?';
                $types .= 's';
                $params[] = $name;
            }

            if ($hasCreatedAt) {
                $columns[] = 'created_at';
                $values[] = 'NOW()';
            }

            if ($hasUpdatedAt) {
                $columns[] = 'updated_at';
                $values[] = 'NOW()';
            }

            $sql = 'INSERT INTO drivers (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $values) . ')';
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();

            $success = 'Driver app login created.';
            $_POST = [];
        }
    }
}

$contacts = [];
if (table_exists($mysqli, 'driver_contacts')) {
    $res = $mysqli->query(
        "SELECT dc.id, dc.first_name, dc.last_name, dc.email
           FROM driver_contacts dc
           LEFT JOIN drivers d ON d.id = dc.id
          WHERE COALESCE(dc.is_disabled, 0) = 0
            AND d.id IS NULL
          ORDER BY dc.first_name, dc.last_name"
    );
    while ($row = $res->fetch_assoc()) {
        $contacts[] = $row;
    }
    $res->close();
}

$driverLogins = [];
if ($driversTableReady && empty($setupErrors)) {
    $selectName = $hasName ? 'name' : "'' AS name";
    $res = $mysqli->query(
        "SELECT id, email, active, {$selectName}, CHAR_LENGTH(password_hash) AS password_hash_length
           FROM drivers
          ORDER BY email"
    );
    while ($row = $res->fetch_assoc()) {
        $driverLogins[] = $row;
    }
    $res->close();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Driver App Users</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { margin:0; font-family:sans-serif; background:#f6f7fb; }
    .page-shell { display:flex; min-height: calc(100vh - var(--banner-h)); }
    .sidebar { width:250px; background:#333; color:#fff; height:calc(100vh - var(--banner-h)); position:fixed; top:var(--banner-h); overflow:auto; transition:transform .3s ease; }
    .sidebar.collapsed { transform: translateX(-250px); }
    .sidebar a { display:block; color:#fff; padding:15px; text-decoration:none; }
    .sidebar a:hover { background:#444; }
    .main { margin-left:250px; padding:20px; flex:1; min-width:0; }
    .sidebar.collapsed + .main { margin-left:0; }
    @media(max-width:768px) { .sidebar { transform:translateX(-250px); } .sidebar.open { transform: translateX(0); } .main { margin:0; } }
    .card-shadow { box-shadow:0 12px 26px rgba(0,0,0,.08); border:1px solid #e5e9f0; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main class="main">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
          <h1 class="h4 mb-1">Driver App Users</h1>
          <div class="text-muted">Create mobile app logins for drivers already listed in Driver Details.</div>
        </div>
      </div>

      <?php if ($success): ?>
        <div class="alert alert-success"><?= h($success) ?></div>
      <?php endif; ?>

      <?php if (!empty($setupErrors) || !empty($errors)): ?>
        <div class="alert alert-danger">
          <ul class="mb-0">
            <?php foreach ($setupErrors as $error): ?>
              <li><?= h($error) ?></li>
            <?php endforeach; ?>
            <?php foreach ($errors as $error): ?>
              <li><?= h($error) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div class="row g-4">
        <div class="col-lg-5">
          <div class="card card-shadow">
            <div class="card-body">
              <h2 class="h5 mb-3">Add Driver Login</h2>
              <form method="post">
                <input type="hidden" name="action" value="create">
                <div class="mb-3">
                  <label class="form-label">Driver Contact</label>
                  <select name="driver_contact_id" class="form-select" required>
                    <option value="">Choose a driver</option>
                    <?php foreach ($contacts as $contact): ?>
                      <?php
                        $selectedContactId = (int)($_POST['driver_contact_id'] ?? 0);
                        $contactName = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
                        $label = $contactName . (($contact['email'] ?? '') ? ' - ' . $contact['email'] : '');
                      ?>
                      <option value="<?= (int)$contact['id'] ?>" <?= $selectedContactId === (int)$contact['id'] ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?php if (!$contacts && empty($setupErrors)): ?>
                    <div class="form-text">Every active driver contact already has an app login.</div>
                  <?php endif; ?>
                </div>

                <?php if ($hasName): ?>
                  <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-control" value="<?= h($_POST['name'] ?? '') ?>">
                  </div>
                <?php endif; ?>

                <div class="mb-3">
                  <label class="form-label">Email</label>
                  <input type="email" name="email" class="form-control" value="<?= h($_POST['email'] ?? '') ?>" required>
                </div>

                <div class="mb-3">
                  <label class="form-label">Temporary Password</label>
                  <input type="password" name="password" class="form-control" minlength="8" required>
                </div>

                <div class="form-check form-switch mb-3">
                  <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" checked>
                  <label class="form-check-label" for="active">Active</label>
                </div>

                <button type="submit" class="btn btn-primary" <?= (!$driversTableReady || !empty($setupErrors) || !$contacts) ? 'disabled' : '' ?>>Create Login</button>
              </form>
            </div>
          </div>
        </div>

        <div class="col-lg-7">
          <div class="card card-shadow">
            <div class="card-body">
              <h2 class="h5 mb-3">Existing App Logins</h2>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead>
                    <tr>
                      <th>Email</th>
                      <?php if ($hasName): ?><th>Name</th><?php endif; ?>
                      <th>Status</th>
                      <th>Hash</th>
                      <th>Reset Password</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!$driverLogins): ?>
                      <tr><td colspan="<?= $hasName ? 5 : 4 ?>" class="text-muted">No driver app logins found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($driverLogins as $login): ?>
                      <tr>
                        <td><?= h($login['email']) ?></td>
                        <?php if ($hasName): ?><td><?= h($login['name']) ?></td><?php endif; ?>
                        <td>
                          <span class="badge <?= ((int)$login['active'] === 1) ? 'text-bg-success' : 'text-bg-secondary' ?>">
                            <?= ((int)$login['active'] === 1) ? 'Active' : 'Inactive' ?>
                          </span>
                        </td>
                        <td>
                          <?php if ((int)$login['password_hash_length'] >= 60): ?>
                            <span class="badge text-bg-success">OK</span>
                          <?php else: ?>
                            <span class="badge text-bg-danger">Too short</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <form method="post" class="d-flex gap-2">
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="driver_id" value="<?= (int)$login['id'] ?>">
                            <input type="password" name="password" class="form-control form-control-sm" minlength="8" required placeholder="New password">
                            <button type="submit" class="btn btn-sm btn-outline-primary">Reset</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
</body>
</html>
