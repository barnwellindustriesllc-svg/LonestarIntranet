<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/two_factor_auth.php';

$currentUser = $_SESSION['username'] ?? '';
$currentUserType = $_SESSION['user_type'] ?? '';
if ($currentUser === 'admin' && $currentUserType === '') {
    $currentUserType = 'admin';
}
if (!in_array($currentUserType, ['admin', 'owner'], true)) {
    header('Location: /lonestar/index.php');
    exit;
}

$errors = [];
$success = '';

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES);
}

function users_table_has_column(mysqli $db, string $column): bool {
    $col = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM users LIKE '{$col}'");
    return $res && $res->num_rows > 0;
}

function ensure_user_management_columns(mysqli $db, array &$errors): bool {
    $twoFactorError = '';
    if (!lonestar_2fa_ensure_schema($db, $twoFactorError)) {
        $errors[] = $twoFactorError !== '' ? $twoFactorError : 'Unable to configure SMS two-factor authentication.';
        return false;
    }
    if (!users_table_has_column($db, 'is_active')) {
        if (!$db->query('ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1')) {
            $errors[] = 'Unable to add user access status column: ' . $db->error;
            return false;
        }
    }
    if (!users_table_has_column($db, 'user_type')) {
        if (!$db->query("ALTER TABLE users ADD COLUMN user_type ENUM('standard','admin','owner') NOT NULL DEFAULT 'standard'")) {
            $errors[] = 'Unable to add user type column: ' . $db->error;
            return false;
        }
        $db->query("UPDATE users SET user_type='admin' WHERE username='admin'");
    }
    return true;
}

function manageable_user_types(string $managerType): array {
    return $managerType === 'owner'
        ? ['standard', 'admin', 'owner']
        : ['standard', 'admin'];
}

function user_type_label(string $userType): string {
    return $userType === 'standard' ? 'Standard User' : ucfirst($userType);
}

function manager_can_manage_user_type(string $managerType, string $targetType): bool {
    return in_array($targetType, manageable_user_types($managerType), true);
}

function get_user_type_by_id(mysqli $db, int $userId): ?string {
    $stmt = $db->prepare('SELECT user_type FROM users WHERE id=? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($userType);
    $found = $stmt->fetch();
    $stmt->close();
    return $found ? (string)$userType : null;
}

function user_mobile_phone_in_use(mysqli $db, string $mobilePhone, int $exceptUserId = 0): bool {
    $stmt = $db->prepare('SELECT id FROM users WHERE mobile_phone=? AND id<>? LIMIT 1');
    if (!$stmt) {
        return true;
    }
    $stmt->bind_param('si', $mobilePhone, $exceptUserId);
    $stmt->execute();
    $stmt->store_result();
    $found = $stmt->num_rows > 0;
    $stmt->close();
    return $found;
}

function load_phpmailer(): bool {
    $phpMailerBase = __DIR__ . '/includes/PHPMailer/src';
    if (!file_exists($phpMailerBase . '/PHPMailer.php')) {
        return false;
    }
    require_once $phpMailerBase . '/Exception.php';
    require_once $phpMailerBase . '/PHPMailer.php';
    require_once $phpMailerBase . '/SMTP.php';
    return class_exists('\\PHPMailer\\PHPMailer\\PHPMailer');
}

function build_password_reset_link(string $token): string {
    require_once __DIR__ . '/includes/paths.php';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . lonestar_base_path() . '/password_reset.php?token=' . urlencode($token);
}

function send_user_password_reset_email(string $email, string $name, string $resetLink, string &$error): bool {
    if (!load_phpmailer()) {
        $error = 'PHPMailer is not configured on this server.';
        return false;
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.ionos.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'info@chalweb.com';
        $mail->Password = getenv('LONESTAR_SMTP_PASSWORD') ?: '';
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('info@chalweb.com', 'Lonestar Roadside');
        $mail->addAddress($email, $name !== '' ? $name : $email);
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Request';
        $mail->Body = 'Hello,<br><br>An administrator requested a password reset for your Lonestar Roadside account. '
            . 'Click the link below to reset your password:<br>'
            . '<a href="' . h($resetLink) . '">Reset Password</a><br><br>This link expires in 1 hour.';

        $mail->send();
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        $error = 'Email could not be sent. Mailer Error: ' . $mail->ErrorInfo;
        return false;
    }
}

ensure_user_management_columns($mysqli, $errors);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create_user';

    if ($action === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $mobilePhoneInput = trim($_POST['mobile_phone'] ?? '');
        $mobilePhone = lonestar_2fa_normalize_phone($mobilePhoneInput);
        $password = $_POST['password'] ?? '';
        $userType = $_POST['user_type'] ?? 'standard';

        if ($username === '' || $firstName === '' || $lastName === '' || $email === '' || $mobilePhoneInput === '' || $password === '') {
            $errors[] = 'First name, last name, username, email, mobile phone, and password are required.';
        } elseif (!in_array($userType, ['standard', 'admin', 'owner'], true)) {
            $errors[] = 'Invalid user type selected.';
        } elseif (!in_array($userType, manageable_user_types($currentUserType), true)) {
            $errors[] = 'You do not have permission to create that user type.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        } elseif ($mobilePhone === '') {
            $errors[] = 'Enter a valid mobile phone number, including the country code for non-U.S. numbers.';
        } elseif (user_mobile_phone_in_use($mysqli, $mobilePhone)) {
            $errors[] = 'That mobile phone is already assigned to another login.';
        } else {
            $requiredCols = ['username', 'first_name', 'last_name', 'email', 'mobile_phone', 'password_hash', 'is_active', 'user_type'];
            $missingCols = [];
            foreach ($requiredCols as $col) {
                if (!users_table_has_column($mysqli, $col)) $missingCols[] = $col;
            }
            if (!empty($missingCols)) {
                $errors[] = 'Users table is missing required columns: ' . implode(', ', $missingCols) . '. Please run migrations 002, 003, 005, and 006.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare('INSERT INTO users (username, first_name, last_name, email, mobile_phone, password_hash, is_active, user_type) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
                if (!$stmt) {
                    $errors[] = 'Unable to prepare user insert: ' . $mysqli->error;
                } else {
                    $stmt->bind_param('sssssss', $username, $firstName, $lastName, $email, $mobilePhone, $hash, $userType);
                    if ($stmt->execute()) {
                        $newUserId = $stmt->insert_id;
                        $success = 'User created successfully.';
                        audit_log_change($mysqli, 'create', 'user', $newUserId, 'Created user ' . $username, null, [
                            'username' => $username,
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'email' => $email,
                            'mobile_phone' => $mobilePhone,
                            'user_type' => $userType,
                        ]);
                    } else {
                        $errors[] = 'Unable to create user: ' . $stmt->error;
                    }
                    $stmt->close();
                }
            }
        }
    } elseif ($action === 'update_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $mobilePhoneInput = trim($_POST['mobile_phone'] ?? '');
        $mobilePhone = lonestar_2fa_normalize_phone($mobilePhoneInput);
        $password = $_POST['password'] ?? '';
        $userType = $_POST['user_type'] ?? 'standard';
        $currentUserId = (int)($_SESSION['user_id'] ?? 0);
        $targetUserType = $userId > 0 ? get_user_type_by_id($mysqli, $userId) : null;

        if ($userId <= 0 || $username === '' || $firstName === '' || $lastName === '' || $email === '' || $mobilePhoneInput === '') {
            $errors[] = 'User ID, first name, last name, username, email, and mobile phone are required for updates.';
        } elseif ($targetUserType === null) {
            $errors[] = 'User not found.';
        } elseif (!manager_can_manage_user_type($currentUserType, $targetUserType)) {
            $errors[] = 'You do not have permission to edit that user type.';
        } elseif (!in_array($userType, ['standard', 'admin', 'owner'], true)) {
            $errors[] = 'Invalid user type selected.';
        } elseif (!in_array($userType, manageable_user_types($currentUserType), true)) {
            $errors[] = 'You do not have permission to assign that user type.';
        } elseif ($userId === $currentUserId && $username !== $currentUser) {
            $errors[] = 'You cannot change your own username from this page.';
        } elseif ($userId === $currentUserId && $userType !== $currentUserType) {
            $errors[] = 'You cannot change your own user type while logged in.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        } elseif ($mobilePhone === '') {
            $errors[] = 'Enter a valid mobile phone number, including the country code for non-U.S. numbers.';
        } elseif (user_mobile_phone_in_use($mysqli, $mobilePhone, $userId)) {
            $errors[] = 'That mobile phone is already assigned to another login.';
        } else {
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare('UPDATE users SET username=?, first_name=?, last_name=?, email=?, mobile_phone=?, user_type=?, password_hash=? WHERE id=?');
                if ($stmt) {
                    $stmt->bind_param('sssssssi', $username, $firstName, $lastName, $email, $mobilePhone, $userType, $hash, $userId);
                }
            } else {
                $stmt = $mysqli->prepare('UPDATE users SET username=?, first_name=?, last_name=?, email=?, mobile_phone=?, user_type=? WHERE id=?');
                if ($stmt) {
                    $stmt->bind_param('ssssssi', $username, $firstName, $lastName, $email, $mobilePhone, $userType, $userId);
                }
            }

            if (!$stmt) {
                $errors[] = 'Unable to prepare user update: ' . $mysqli->error;
            } elseif ($stmt->execute()) {
                $success = 'User details updated successfully.';
                audit_log_change($mysqli, 'update', 'user', $userId, 'Updated user ' . $username, null, [
                    'username' => $username,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'mobile_phone' => $mobilePhone,
                    'user_type' => $userType,
                    'password_changed' => $password !== '',
                ]);
                $stmt->close();
            } else {
                $errors[] = 'Unable to update user: ' . $stmt->error;
                $stmt->close();
            }
        }
    } elseif ($action === 'toggle_access') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
        $currentUserId = (int)($_SESSION['user_id'] ?? 0);
        $targetUserType = $userId > 0 ? get_user_type_by_id($mysqli, $userId) : null;

        if ($userId <= 0) {
            $errors[] = 'Invalid user selected.';
        } elseif ($targetUserType === null) {
            $errors[] = 'User not found.';
        } elseif (!manager_can_manage_user_type($currentUserType, $targetUserType)) {
            $errors[] = 'You do not have permission to update access for that user type.';
        } elseif ($userId === $currentUserId && $isActive === 0) {
            $errors[] = 'You cannot suspend your own account while logged in.';
        } else {
            $stmt = $mysqli->prepare('UPDATE users SET is_active=? WHERE id=?');
            if (!$stmt) {
                $errors[] = 'Unable to prepare access update: ' . $mysqli->error;
            } else {
                $stmt->bind_param('ii', $isActive, $userId);
                if ($stmt->execute()) {
                    $success = $isActive ? 'User access restored.' : 'User access suspended.';
                    audit_log_change($mysqli, $isActive ? 'reactivate' : 'suspend', 'user', $userId, $success, null, [
                        'is_active' => $isActive,
                    ]);
                } else {
                    $errors[] = 'Unable to update user access: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'send_password_reset') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $targetUserType = $userId > 0 ? get_user_type_by_id($mysqli, $userId) : null;

        if ($userId <= 0) {
            $errors[] = 'Invalid user selected.';
        } elseif ($targetUserType === null) {
            $errors[] = 'User not found.';
        } elseif (!manager_can_manage_user_type($currentUserType, $targetUserType)) {
            $errors[] = 'You do not have permission to send password resets for that user type.';
        } elseif (!users_table_has_column($mysqli, 'reset_token') || !users_table_has_column($mysqli, 'reset_token_expires')) {
            $errors[] = 'Users table is missing password reset columns. Please run migration 002.';
        } else {
            $stmt = $mysqli->prepare('SELECT username, first_name, last_name, email FROM users WHERE id=? LIMIT 1');
            if (!$stmt) {
                $errors[] = 'Unable to prepare password reset lookup: ' . $mysqli->error;
            } else {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $stmt->bind_result($resetUsername, $resetFirstName, $resetLastName, $resetEmail);
                $userFound = $stmt->fetch();
                $stmt->close();

                $resetEmail = trim((string)$resetEmail);
                $resetName = trim((string)$resetFirstName . ' ' . (string)$resetLastName);
                if (!$userFound) {
                    $errors[] = 'User not found.';
                } elseif ($resetEmail === '' || !filter_var($resetEmail, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'This user does not have a valid email address for password reset.';
                } else {
                    $token = bin2hex(random_bytes(16));
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    $update = $mysqli->prepare('UPDATE users SET reset_token=?, reset_token_expires=? WHERE id=?');
                    if (!$update) {
                        $errors[] = 'Unable to prepare password reset token: ' . $mysqli->error;
                    } else {
                        $update->bind_param('ssi', $token, $expiresAt, $userId);
                        if ($update->execute()) {
                            $mailError = '';
                            $resetLink = build_password_reset_link($token);
                            if (send_user_password_reset_email($resetEmail, $resetName !== '' ? $resetName : $resetUsername, $resetLink, $mailError)) {
                                $success = 'Password reset email sent to ' . $resetEmail . '.';
                                audit_log_change($mysqli, 'send_password_reset', 'user', $userId, 'Sent password reset email to ' . $resetEmail);
                            } else {
                                $errors[] = $mailError;
                            }
                        } else {
                            $errors[] = 'Unable to save password reset token: ' . $update->error;
                        }
                        $update->close();
                    }
                }
            }
        }
    } elseif ($action === 'delete_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $currentUserId = (int)($_SESSION['user_id'] ?? 0);
        $targetUserType = $userId > 0 ? get_user_type_by_id($mysqli, $userId) : null;

        if ($userId <= 0) {
            $errors[] = 'Invalid user selected.';
        } elseif ($targetUserType === null) {
            $errors[] = 'User not found.';
        } elseif (!manager_can_manage_user_type($currentUserType, $targetUserType)) {
            $errors[] = 'You do not have permission to delete that user type.';
        } elseif ($userId === $currentUserId) {
            $errors[] = 'You cannot delete your own account while logged in.';
        } else {
            $stmt = $mysqli->prepare('DELETE FROM users WHERE id=?');
            if (!$stmt) {
                $errors[] = 'Unable to prepare user delete: ' . $mysqli->error;
            } else {
                $stmt->bind_param('i', $userId);
                if ($stmt->execute()) {
                    $success = 'User deleted successfully.';
                    audit_log_change($mysqli, 'delete', 'user', $userId, 'Deleted user id ' . $userId);
                } else {
                    $errors[] = 'Unable to delete user: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

$users = [];
$requiredCols = ['id', 'username', 'first_name', 'last_name', 'email', 'mobile_phone', 'created_at', 'is_active', 'user_type'];
$missingCols = [];
foreach ($requiredCols as $col) {
    if (!users_table_has_column($mysqli, $col)) $missingCols[] = $col;
}
if (!empty($missingCols)) {
    $errors[] = 'Users table is missing required columns: ' . implode(', ', $missingCols) . '. Please run migrations 002, 003, 005, and 006.';
} else {
    $res = $mysqli->query('SELECT id, username, first_name, last_name, email, mobile_phone, created_at, is_active, user_type FROM users ORDER BY last_name, first_name, username');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $users[] = $row;
        }
        $res->free();
    } else {
        $errors[] = 'Unable to load users: ' . $mysqli->error;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create User</title>
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
    @media(max-width:768px) { .sidebar { transform:translateX(-250px); } .sidebar.open { transform: translateX(0); } .main { margin:0; } }
    .card-shadow { box-shadow:0 12px 26px rgba(0,0,0,.08); border:1px solid #e5e9f0; }
    .user-edit-grid { display:grid; grid-template-columns: repeat(6, minmax(140px, 1fr)); gap:.75rem; align-items:end; }
    .table-actions { display:flex; flex-wrap:wrap; gap:.4rem; }
    .table-actions form { margin:0; }
    .status-pill { display:inline-flex; align-items:center; border-radius:999px; padding:.25rem .55rem; font-size:.78rem; font-weight:700; }
    .status-pill.active { color:#0f5132; background:#d1e7dd; }
    .status-pill.suspended { color:#842029; background:#f8d7da; }
    @media(max-width:1200px) { .user-edit-grid { grid-template-columns: repeat(2, minmax(150px, 1fr)); } }
    @media(max-width:700px) { .user-edit-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main">
      <div class="card card-shadow p-4 mb-4" style="max-width:600px;">
        <h1 class="h4 mb-3">Create User</h1>
        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger">
            <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
          </div>
        <?php elseif ($success): ?>
          <div class="alert alert-success"><?= h($success) ?></div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="action" value="create_user">
          <div class="mb-3">
            <label class="form-label">First Name</label>
            <input type="text" name="first_name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Last Name</label>
            <input type="text" name="last_name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text" name="username" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Mobile Phone for Login Codes</label>
            <input type="tel" name="mobile_phone" class="form-control" placeholder="(555) 555-1234" required>
            <div class="form-text">U.S. numbers may be entered with 10 digits. International numbers must include + and the country code.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">User Type</label>
            <select name="user_type" class="form-select" required>
              <?php foreach (manageable_user_types($currentUserType) as $type): ?>
                <option value="<?= h($type) ?>" <?= $type === 'standard' ? 'selected' : '' ?>><?= h(user_type_label($type)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" required>
          </div>
          <button type="submit" class="btn btn-primary">Create User</button>
        </form>
      </div>

      <div class="card card-shadow p-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
          <h2 class="h4 mb-0">Manage Users</h2>
          <span class="text-muted small"><?= count($users) ?> user<?= count($users) === 1 ? '' : 's' ?></span>
        </div>
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>User</th>
                <th>Email</th>
                <th>Mobile Phone</th>
                <th>User Type</th>
                <th>Status</th>
                <th>Created</th>
                <th style="min-width:220px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($users)): ?>
                <tr>
                  <td colspan="7" class="text-center text-muted py-4">No users found.</td>
                </tr>
              <?php endif; ?>
              <?php foreach ($users as $user): ?>
                <?php
                  $userId = (int)$user['id'];
                  $isActive = (int)($user['is_active'] ?? 1) === 1;
                  $isCurrentUser = $userId === (int)($_SESSION['user_id'] ?? 0);
                  $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                  $rowUserType = $user['user_type'] ?? 'standard';
                  $canManageRow = manager_can_manage_user_type($currentUserType, $rowUserType);
                ?>
                <tr>
                  <td>
                    <strong><?= h($fullName !== '' ? $fullName : $user['username']) ?></strong>
                    <div class="text-muted small">@<?= h($user['username']) ?><?= $isCurrentUser ? ' - current user' : '' ?></div>
                  </td>
                  <td><?= h($user['email']) ?></td>
                  <td>
                    <?php if (trim((string)($user['mobile_phone'] ?? '')) !== ''): ?>
                      <?= h($user['mobile_phone']) ?>
                    <?php else: ?>
                      <span class="text-danger small">2FA setup required</span>
                    <?php endif; ?>
                  </td>
                  <td><?= h(user_type_label($rowUserType)) ?></td>
                  <td>
                    <span class="status-pill <?= $isActive ? 'active' : 'suspended' ?>">
                      <?= $isActive ? 'Active' : 'Suspended' ?>
                    </span>
                  </td>
                  <td><?= h($user['created_at']) ?></td>
                  <td>
                    <div class="table-actions">
                      <?php if ($canManageRow): ?>
                        <form method="post">
                          <input type="hidden" name="action" value="toggle_access">
                          <input type="hidden" name="user_id" value="<?= $userId ?>">
                          <input type="hidden" name="is_active" value="<?= $isActive ? '0' : '1' ?>">
                          <button type="submit" class="btn btn-sm <?= $isActive ? 'btn-warning' : 'btn-success' ?>" <?= $isCurrentUser && $isActive ? 'disabled' : '' ?>>
                            <?= $isActive ? 'Suspend' : 'Reactivate' ?>
                          </button>
                        </form>
                        <form method="post" onsubmit="return confirm(<?= h(json_encode('Send a password reset email to ' . ($user['email'] ?? '') . '?')) ?>);">
                          <input type="hidden" name="action" value="send_password_reset">
                          <input type="hidden" name="user_id" value="<?= $userId ?>">
                          <button type="submit" class="btn btn-sm btn-secondary">Reset Password</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Delete this user permanently?');">
                          <input type="hidden" name="action" value="delete_user">
                          <input type="hidden" name="user_id" value="<?= $userId ?>">
                          <button type="submit" class="btn btn-sm btn-danger" <?= $isCurrentUser ? 'disabled' : '' ?>>Delete</button>
                        </form>
                      <?php else: ?>
                        <span class="text-muted small">Owner access required</span>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php if ($canManageRow): ?>
                  <tr>
                  <td colspan="7" class="bg-light">
                    <form method="post" class="user-edit-grid">
                      <input type="hidden" name="action" value="update_user">
                      <input type="hidden" name="user_id" value="<?= $userId ?>">
                      <div>
                        <label class="form-label small">First Name</label>
                        <input type="text" name="first_name" class="form-control form-control-sm" value="<?= h($user['first_name']) ?>" required>
                      </div>
                      <div>
                        <label class="form-label small">Last Name</label>
                        <input type="text" name="last_name" class="form-control form-control-sm" value="<?= h($user['last_name']) ?>" required>
                      </div>
                      <div>
                        <label class="form-label small">Username</label>
                        <input type="text" name="username" class="form-control form-control-sm" value="<?= h($user['username']) ?>" <?= $isCurrentUser ? 'readonly' : '' ?> required>
                      </div>
                      <div>
                        <label class="form-label small">Email</label>
                        <input type="email" name="email" class="form-control form-control-sm" value="<?= h($user['email']) ?>" required>
                      </div>
                      <div>
                        <label class="form-label small">Mobile Phone</label>
                        <input type="tel" name="mobile_phone" class="form-control form-control-sm" value="<?= h($user['mobile_phone'] ?? '') ?>" required>
                      </div>
                      <div>
                        <label class="form-label small">User Type</label>
                        <select name="user_type" class="form-select form-select-sm" <?= $isCurrentUser ? 'disabled' : '' ?> required>
                          <?php foreach (manageable_user_types($currentUserType) as $type): ?>
                            <option value="<?= h($type) ?>" <?= $rowUserType === $type ? 'selected' : '' ?>><?= h(user_type_label($type)) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <?php if ($isCurrentUser): ?>
                          <input type="hidden" name="user_type" value="<?= h($rowUserType) ?>">
                        <?php endif; ?>
                      </div>
                      <div>
                        <label class="form-label small">New Password</label>
                        <input type="password" name="password" class="form-control form-control-sm" placeholder="Leave blank">
                      </div>
                      <div>
                        <button type="submit" class="btn btn-sm btn-primary">Save Changes</button>
                      </div>
                    </form>
                  </td>
                </tr>
                <?php endif; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
