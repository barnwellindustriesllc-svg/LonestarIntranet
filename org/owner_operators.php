<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/paths.php';
require_once __DIR__ . '/includes/audit.php';

$errors = [];
$success = '';
$basePath = lonestar_base_path();

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES);
}

function ensure_owner_operators_table(mysqli $mysqli): void {
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS owner_operators (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_name VARCHAR(191) NOT NULL,
            dot_number VARCHAR(80) NULL,
            email VARCHAR(191) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_owner_name (owner_name),
            KEY idx_dot_number (dot_number),
            KEY idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $mysqli->query(
        "INSERT INTO owner_operators (owner_name, dot_number, email)
         SELECT owner_name,
                NULLIF(MAX(NULLIF(owner_dot_number, '')), ''),
                NULLIF(MAX(NULLIF(email, '')), '')
           FROM driver_contacts
          WHERE owner_name IS NOT NULL
            AND TRIM(owner_name) <> ''
          GROUP BY owner_name
         ON DUPLICATE KEY UPDATE
             dot_number = COALESCE(owner_operators.dot_number, VALUES(dot_number)),
             email = COALESCE(owner_operators.email, VALUES(email))"
    );
}

function sync_driver_contacts_owner(mysqli $mysqli, array $old, string $ownerName, string $dotNumber, string $email): void {
    $oldOwnerName = trim((string)$old['owner_name']);
    $oldDotNumber = trim((string)$old['dot_number']);

    if ($oldOwnerName === '' && $oldDotNumber === '') {
        return;
    }

    $sql = 'UPDATE driver_contacts
               SET owner_name = ?,
                   owner_dot_number = NULLIF(?, ""),
                   email = NULLIF(?, "")
             WHERE ';
    if ($oldOwnerName !== '' && $oldDotNumber !== '') {
        $sql .= "TRIM(COALESCE(owner_name, '')) = TRIM(?) OR TRIM(COALESCE(owner_dot_number, '')) = TRIM(?)";
    } elseif ($oldOwnerName !== '') {
        $sql .= "TRIM(COALESCE(owner_name, '')) = TRIM(?)";
    } else {
        $sql .= "TRIM(COALESCE(owner_dot_number, '')) = TRIM(?)";
    }

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return;
    }
    if ($oldOwnerName !== '' && $oldDotNumber !== '') {
        $stmt->bind_param('sssss', $ownerName, $dotNumber, $email, $oldOwnerName, $oldDotNumber);
    } else {
        $matchValue = $oldOwnerName !== '' ? $oldOwnerName : $oldDotNumber;
        $stmt->bind_param('ssss', $ownerName, $dotNumber, $email, $matchValue);
    }
    $stmt->execute();
    $stmt->close();
}

try {
    ensure_owner_operators_table($mysqli);
} catch (Throwable $e) {
    $errors[] = 'Unable to prepare owner operator storage: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $action = $_POST['action'] ?? '';
    $ownerName = trim($_POST['owner_name'] ?? '');
    $dotNumber = trim($_POST['dot_number'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if (in_array($action, ['add_owner', 'edit_owner'], true)) {
        if ($ownerName === '') {
            $errors[] = 'Owner operator name is required.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email address is invalid.';
        }
    }

    if (empty($errors)) {
        try {
            if ($action === 'add_owner') {
                $stmt = $mysqli->prepare(
                    'INSERT INTO owner_operators (owner_name, dot_number, email)
                     VALUES (?, NULLIF(?, ""), NULLIF(?, ""))'
                );
                $stmt->bind_param('sss', $ownerName, $dotNumber, $email);
                $stmt->execute();
                $ownerId = $stmt->insert_id;
                $stmt->close();
                $success = 'Owner operator added.';
                audit_log_change($mysqli, 'create', 'owner_operator', $ownerId, 'Created owner operator ' . $ownerName, null, [
                    'owner_name' => $ownerName,
                    'dot_number' => $dotNumber,
                    'email' => $email,
                ]);
            } elseif ($action === 'edit_owner') {
                $ownerId = (int)($_POST['owner_id'] ?? 0);
                if ($ownerId <= 0) {
                    $errors[] = 'Invalid owner operator selected.';
                } else {
                    $lookup = $mysqli->prepare('SELECT owner_name, dot_number, email FROM owner_operators WHERE id=? LIMIT 1');
                    $lookup->bind_param('i', $ownerId);
                    $lookup->execute();
                    $lookup->bind_result($oldOwnerName, $oldDotNumber, $oldEmail);
                    $found = $lookup->fetch();
                    $lookup->close();

                    if (!$found) {
                        $errors[] = 'Owner operator not found.';
                    } else {
                        $stmt = $mysqli->prepare(
                            'UPDATE owner_operators
                                SET owner_name = ?, dot_number = NULLIF(?, ""), email = NULLIF(?, "")
                              WHERE id = ?
                              LIMIT 1'
                        );
                        $stmt->bind_param('sssi', $ownerName, $dotNumber, $email, $ownerId);
                        $stmt->execute();
                        $stmt->close();

                        sync_driver_contacts_owner($mysqli, [
                            'owner_name' => $oldOwnerName,
                            'dot_number' => $oldDotNumber,
                            'email' => $oldEmail,
                        ], $ownerName, $dotNumber, $email);

                        $success = 'Owner operator updated.';
                        audit_log_change($mysqli, 'update', 'owner_operator', $ownerId, 'Updated owner operator ' . $ownerName, [
                            'owner_name' => $oldOwnerName,
                            'dot_number' => $oldDotNumber,
                            'email' => $oldEmail,
                        ], [
                            'owner_name' => $ownerName,
                            'dot_number' => $dotNumber,
                            'email' => $email,
                        ]);
                    }
                }
            } elseif ($action === 'delete_owner') {
                $ownerId = (int)($_POST['owner_id'] ?? 0);
                if ($ownerId <= 0) {
                    $errors[] = 'Invalid owner operator selected.';
                } else {
                    $stmt = $mysqli->prepare('DELETE FROM owner_operators WHERE id=? LIMIT 1');
                    $stmt->bind_param('i', $ownerId);
                    $stmt->execute();
                    $stmt->close();
                    $success = 'Owner operator deleted.';
                    audit_log_change($mysqli, 'delete', 'owner_operator', $ownerId, 'Deleted owner operator id ' . $ownerId);
                }
            }
        } catch (mysqli_sql_exception $e) {
            $errors[] = stripos($e->getMessage(), 'Duplicate entry') !== false
                ? 'That owner operator already exists.'
                : 'Owner operator action failed: ' . $e->getMessage();
        }
    }
}

$owners = [];
if (empty($errors) || $success !== '') {
    $res = $mysqli->query('SELECT id, owner_name, dot_number, email, updated_at FROM owner_operators ORDER BY owner_name, dot_number');
    while ($row = $res->fetch_assoc()) {
        $owners[] = $row;
    }
    $res->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Owner Operators</title>
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
    .panel { background:#fff; border:1px solid #dde4ee; border-radius:8px; padding:18px; box-shadow:0 10px 24px rgba(17,24,39,.05); }
  </style>
</head>
<body>
<?php include __DIR__ . '/includes/ls_title.php'; ?>
<div class="page-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
      <div>
        <h1 class="mb-1">Owner Operators</h1>
      </div>
      <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addOwnerModal">Add Owner Operator</button>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div>
    <?php elseif ($success): ?>
      <div class="alert alert-success"><?= h($success) ?></div>
    <?php endif; ?>

    <div class="panel">
      <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Owner Operator</th>
              <th>DOT Number</th>
              <th>Email</th>
              <th>Updated</th>
              <th style="min-width:170px;">Actions</th>
            </tr>
            <tr>
              <th><input type="search" class="form-control form-control-sm owner-filter" data-column="0" placeholder="Filter owner"></th>
              <th><input type="search" class="form-control form-control-sm owner-filter" data-column="1" placeholder="Filter DOT"></th>
              <th><input type="search" class="form-control form-control-sm owner-filter" data-column="2" placeholder="Filter email"></th>
              <th><input type="search" class="form-control form-control-sm owner-filter" data-column="3" placeholder="Filter updated"></th>
              <th></th>
            </tr>
          </thead>
          <tbody id="ownerOperatorsTableBody">
            <?php if (!$owners): ?>
              <tr><td colspan="5" class="text-center text-muted py-4">No owner operators found.</td></tr>
            <?php endif; ?>
            <?php foreach ($owners as $owner): ?>
              <tr>
                <td><strong><?= h($owner['owner_name']) ?></strong></td>
                <td>
                  <?php $dotNumber = preg_replace('/\D+/', '', (string)($owner['dot_number'] ?? '')); ?>
                  <?php if ($dotNumber !== ''): ?>
                    <a href="<?= h($basePath ?? '') ?>/dot/carrier.php?usdot=<?= h($dotNumber) ?>">
                      <?= h($owner['dot_number']) ?>
                    </a>
                  <?php else: ?>
                    <?= h($owner['dot_number']) ?>
                  <?php endif; ?>
                </td>
                <td><?= h($owner['email']) ?></td>
                <td><?= h($owner['updated_at']) ?></td>
                <td>
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-primary edit-owner-btn"
                    data-bs-toggle="modal"
                    data-bs-target="#editOwnerModal"
                    data-owner-id="<?= (int)$owner['id'] ?>"
                    data-owner-name="<?= h($owner['owner_name']) ?>"
                    data-dot-number="<?= h($owner['dot_number']) ?>"
                    data-email="<?= h($owner['email']) ?>"
                  >Edit</button>
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this owner operator? Driver contacts will keep their current values.');">
                    <input type="hidden" name="action" value="delete_owner">
                    <input type="hidden" name="owner_id" value="<?= (int)$owner['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<div class="modal fade" id="editOwnerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="edit_owner">
        <input type="hidden" name="owner_id" id="editOwnerId">
        <div class="modal-header">
          <h5 class="modal-title">Edit Owner Operator</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Owner Operator</label>
            <input type="text" name="owner_name" id="editOwnerName" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">DOT Number</label>
            <input type="text" name="dot_number" id="editDotNumber" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" id="editOwnerEmail" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="addOwnerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="add_owner">
        <div class="modal-header">
          <h5 class="modal-title">Add Owner Operator</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Owner Operator</label>
            <input type="text" name="owner_name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">DOT Number</label>
            <input type="text" name="dot_number" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Owner Operator</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.edit-owner-btn').forEach(function (button) {
  button.addEventListener('click', function () {
    document.getElementById('editOwnerId').value = this.dataset.ownerId || '';
    document.getElementById('editOwnerName').value = this.dataset.ownerName || '';
    document.getElementById('editDotNumber').value = this.dataset.dotNumber || '';
    document.getElementById('editOwnerEmail').value = this.dataset.email || '';
  });
});

(function () {
  var filters = Array.prototype.slice.call(document.querySelectorAll('.owner-filter'));
  var tbody = document.getElementById('ownerOperatorsTableBody');
  if (!filters.length || !tbody) return;

  function normalize(value) {
    return (value || '').toString().trim().toLowerCase();
  }

  function applyFilters() {
    var activeFilters = filters.map(function (input) {
      return {
        column: parseInt(input.dataset.column || '0', 10),
        value: normalize(input.value)
      };
    }).filter(function (filter) {
      return filter.value !== '';
    });

    Array.prototype.slice.call(tbody.querySelectorAll('tr')).forEach(function (row) {
      var visible = activeFilters.every(function (filter) {
        var cell = row.children[filter.column];
        return cell && normalize(cell.textContent).indexOf(filter.value) !== -1;
      });
      row.style.display = visible ? '' : 'none';
    });
  }

  filters.forEach(function (input) {
    input.addEventListener('input', applyFilters);
  });
})();
</script>
</body>
</html>
