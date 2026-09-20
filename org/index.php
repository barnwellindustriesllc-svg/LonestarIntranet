<?php
session_start();
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/two_factor_auth.php';

if (!empty($_SESSION['logged_in'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$notice = '';
$schemaError = '';
$twoFactorEnabled = defined('LONESTAR_2FA_ENABLED') ? LONESTAR_2FA_ENABLED : true;
$twoFactorReady = !$twoFactorEnabled || lonestar_2fa_ensure_schema($mysqli, $schemaError);
$pending = isset($_SESSION['login_2fa_pending']) && is_array($_SESSION['login_2fa_pending'])
    ? $_SESSION['login_2fa_pending']
    : null;
if (!$twoFactorEnabled && $pending) {
    unset($_SESSION['login_2fa_pending'], $_SESSION['login_2fa_csrf']);
    $pending = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'signin');
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    $csrfValid = hash_equals(lonestar_2fa_csrf_token(), $postedCsrf);

    if (!$csrfValid) {
        $error = 'The login form expired. Refresh the page and try again.';
    } elseif ($action === 'cancel') {
        unset($_SESSION['login_2fa_pending']);
        session_regenerate_id(true);
        header('Location: index.php');
        exit;
    } elseif (!$twoFactorReady) {
        $error = $schemaError !== '' ? $schemaError : 'Two-factor authentication is not configured yet.';
    } elseif ($action === 'signin') {
        unset($_SESSION['login_2fa_pending']);
        $pending = null;
        $user = trim((string)($_POST['username'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        if ($user === '' || $pass === '') {
            $error = 'Username and password are required.';
        } else {
            try {
                $hasActiveColumn = false;
                if ($colRes = $mysqli->query("SHOW COLUMNS FROM users LIKE 'is_active'")) {
                    $hasActiveColumn = $colRes->num_rows > 0;
                    $colRes->free();
                }
                $hasUserTypeColumn = false;
                if ($colRes = $mysqli->query("SHOW COLUMNS FROM users LIKE 'user_type'")) {
                    $hasUserTypeColumn = $colRes->num_rows > 0;
                    $colRes->free();
                }

                $isActiveSelect = $hasActiveColumn ? 'is_active' : '1 AS is_active';
                $userTypeSelect = $hasUserTypeColumn
                    ? 'user_type'
                    : "CASE WHEN username='admin' THEN 'admin' ELSE 'standard' END AS user_type";
                $sql = "SELECT id, username, first_name, last_name, password_hash,
                               {$isActiveSelect}, {$userTypeSelect}, mobile_phone
                          FROM users
                         WHERE username=?
                         LIMIT 1";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) {
                    $error = 'Login system is not configured yet.';
                } else {
                    $stmt->bind_param('s', $user);
                    $stmt->execute();
                    $stmt->bind_result(
                        $uid,
                        $uname,
                        $firstName,
                        $lastName,
                        $hash,
                        $isActive,
                        $userType,
                        $mobilePhone
                    );
                    $found = $stmt->fetch();
                    $stmt->close();

                    if (!$found || !password_verify($pass, (string)$hash)) {
                        $error = 'Invalid username or password.';
                    } elseif ((int)$isActive !== 1) {
                        $error = 'This account has been suspended.';
                    } elseif (!$twoFactorEnabled) {
                        session_regenerate_id(true);
                        unset($_SESSION['login_2fa_pending'], $_SESSION['login_2fa_csrf']);
                        $_SESSION['logged_in'] = true;
                        $_SESSION['user_id'] = (int)$uid;
                        $_SESSION['username'] = $uname;
                        $_SESSION['first_name'] = $firstName;
                        $_SESSION['last_name'] = $lastName;
                        $_SESSION['user_type'] = $userType;
                        unset($_SESSION['two_factor_verified_at']);
                        header('Location: dashboard.php');
                        exit;
                    } else {
                        $normalizedPhone = lonestar_2fa_normalize_phone((string)$mobilePhone);
                        if ($normalizedPhone === '') {
                            $error = 'This account does not have a valid mobile phone for two-factor authentication. Contact an administrator.';
                        } else {
                            session_regenerate_id(true);
                            unset($_SESSION['login_2fa_csrf']);
                            $sendError = '';
                            $challenge = lonestar_2fa_create_challenge(
                                $mysqli,
                                (int)$uid,
                                $normalizedPhone,
                                $sendError
                            );
                            if ($challenge === null) {
                                $error = $sendError !== ''
                                    ? $sendError
                                    : 'The verification text could not be sent.';
                            } else {
                                $_SESSION['login_2fa_pending'] = $challenge;
                                lonestar_2fa_csrf_token();
                                header('Location: index.php?verify=1');
                                exit;
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('Login password step failed: ' . $e->getMessage());
                $error = 'Login system is not configured yet.';
            }
        }
    } elseif ($action === 'verify') {
        if (!$pending) {
            $error = 'Your verification session expired. Sign in again.';
        } else {
            $code = trim((string)($_POST['verification_code'] ?? ''));
            $verifyError = '';
            if (lonestar_2fa_verify_challenge($mysqli, $pending, $code, $verifyError)) {
                $uid = (int)$pending['user_id'];
                $stmt = $mysqli->prepare(
                    "SELECT username, first_name, last_name,
                            COALESCE(is_active,1), COALESCE(user_type,'standard'), mobile_phone
                       FROM users
                      WHERE id=?
                      LIMIT 1"
                );
                if ($stmt) {
                    $stmt->bind_param('i', $uid);
                    $stmt->execute();
                    $stmt->bind_result($uname, $firstName, $lastName, $isActive, $userType, $mobilePhone);
                    $found = $stmt->fetch();
                    $stmt->close();
                } else {
                    $found = false;
                }
                if (
                    !$found
                    || (int)$isActive !== 1
                    || lonestar_2fa_normalize_phone((string)$mobilePhone) !== (string)$pending['phone']
                ) {
                    unset($_SESSION['login_2fa_pending']);
                    $pending = null;
                    $error = 'This account changed during verification. Sign in again.';
                } else {
                    session_regenerate_id(true);
                    unset($_SESSION['login_2fa_pending'], $_SESSION['login_2fa_csrf']);
                    $_SESSION['logged_in'] = true;
                    $_SESSION['user_id'] = $uid;
                    $_SESSION['username'] = $uname;
                    $_SESSION['first_name'] = $firstName;
                    $_SESSION['last_name'] = $lastName;
                    $_SESSION['user_type'] = $userType;
                    $_SESSION['two_factor_verified_at'] = time();
                    header('Location: dashboard.php');
                    exit;
                }
            } else {
                $error = $verifyError !== '' ? $verifyError : 'The verification code is incorrect.';
            }
        }
    } elseif ($action === 'resend') {
        if (!$pending) {
            $error = 'Your verification session expired. Sign in again.';
        } else {
            $resendError = '';
            if (lonestar_2fa_resend_challenge($mysqli, $pending, $resendError)) {
                $notice = 'A new verification code was sent.';
            } else {
                $error = $resendError !== '' ? $resendError : 'A new code could not be sent.';
            }
        }
    }
}

$pending = isset($_SESSION['login_2fa_pending']) && is_array($_SESSION['login_2fa_pending'])
    ? $_SESSION['login_2fa_pending']
    : null;
$showVerification = $pending !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Login - LoneStar Roadside LLC</title>
  <style>
    * { box-sizing: border-box; }
    body {
      font-family: sans-serif;
      background: #f2f2f2;
      display: flex;
      min-height: 100vh;
      align-items: center;
      justify-content: center;
      margin: 0;
    }
    .login-box {
      background: #fff;
      padding: 2em;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      width: min(360px, calc(100vw - 30px));
    }
    .login-box h2 { margin-top: 0; margin-bottom: .5em; text-align: center; }
    .login-box p { color:#555; line-height:1.45; text-align:center; }
    .login-box input {
      width: 100%;
      padding: 0.75em;
      margin-bottom: 1em;
      border: 1px solid #ccc;
      border-radius: 4px;
      font-size: 1em;
    }
    .code-input { text-align:center; letter-spacing:.35em; font-size:1.35em !important; }
    .login-box button {
      width: 100%;
      padding: 0.75em;
      background: #007bff;
      color: #fff;
      border: none;
      border-radius: 4px;
      font-size: 1em;
      cursor: pointer;
    }
    .login-box button.secondary { margin-top:10px; background:#667085; }
    .login-box button.link-button { margin-top:10px; background:transparent; color:#285ea8; padding:.45em; }
    .message { margin-bottom: 1em; padding:10px; border-radius:4px; text-align:center; }
    .error { color:#a61b1b; background:#fff0f0; border:1px solid #f1c5c5; }
    .notice { color:#176b35; background:#edf9f1; border:1px solid #bce2c8; }
    .help { margin-top:12px; text-align:center; font-size:.92em; }
  </style>
</head>
<body>
  <?php $hideMenuToggle = true; ?>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="login-box">
    <?php if ($showVerification): ?>
      <h2>Verify Your Login</h2>
      <p>Enter the six-digit code sent to <?= htmlspecialchars(lonestar_2fa_mask_phone((string)$pending['phone']), ENT_QUOTES) ?>.</p>
    <?php else: ?>
      <h2>Login</h2>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="message error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
      <div class="message notice"><?= htmlspecialchars($notice, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <?php if ($showVerification): ?>
      <form method="post">
        <input type="hidden" name="action" value="verify">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(lonestar_2fa_csrf_token(), ENT_QUOTES) ?>">
        <input
          class="code-input"
          type="text"
          name="verification_code"
          inputmode="numeric"
          autocomplete="one-time-code"
          pattern="[0-9]{6}"
          maxlength="6"
          placeholder="000000"
          aria-label="Six-digit verification code"
          required
          autofocus
        >
        <button type="submit">Verify and Sign In</button>
      </form>
      <form method="post">
        <input type="hidden" name="action" value="resend">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(lonestar_2fa_csrf_token(), ENT_QUOTES) ?>">
        <button class="secondary" type="submit">Send a New Code</button>
      </form>
      <form method="post">
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(lonestar_2fa_csrf_token(), ENT_QUOTES) ?>">
        <button class="link-button" type="submit">Use a Different Account</button>
      </form>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="action" value="signin">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(lonestar_2fa_csrf_token(), ENT_QUOTES) ?>">
        <input type="text" name="username" placeholder="Username" autocomplete="username" required autofocus>
        <input type="password" name="password" placeholder="Password" autocomplete="current-password" required>
        <button type="submit">Sign In</button>
      </form>
      <div class="help">
        <a href="password_reset.php">Forgot password?</a>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
