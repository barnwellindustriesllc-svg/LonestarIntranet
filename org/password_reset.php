<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require __DIR__ . '/includes/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$phpMailerBase = __DIR__ . '/includes/PHPMailer/src';
if (!file_exists($phpMailerBase . '/PHPMailer.php')) {
    $missingMailer = true;
} else {
    require $phpMailerBase . '/Exception.php';
    require $phpMailerBase . '/PHPMailer.php';
    require $phpMailerBase . '/SMTP.php';
    $missingMailer = false;
}

$throttle_limit = 15 * 60;
$message = '';
$messageType = 'info';
$debugLog = [];
$debugLog[] = 'Mailer present: ' . ($missingMailer ? 'no' : 'yes');
$debugLog[] = 'Request method: ' . ($_SERVER['REQUEST_METHOD'] ?? 'unknown');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $debugLog[] = 'POST payload: ' . json_encode($_POST);
}
$step = 1;
$token = '';

if (isset($_GET['token'])) {
    $step = 2;
    $token = trim($_GET['token']);
    $stmt = $mysqli->prepare("SELECT id, email FROM users WHERE reset_token = ? AND reset_token_expires > NOW()");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows === 0) {
        $message = 'Invalid or expired token.';
        $messageType = 'error';
        $step = 1;
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['request_reset'])) {
        $email = trim($_POST['email'] ?? '');
        if ($missingMailer) {
            $message = 'PHPMailer is not configured on this server.';
            $messageType = 'error';
            $debugLog[] = 'Missing PHPMailer classes at includes/PHPMailer/src';
        } elseif ($email === '') {
            $message = 'Please enter your email address.';
            $messageType = 'error';
            $debugLog[] = 'Empty email submitted';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Invalid email address.';
            $messageType = 'error';
            $debugLog[] = 'Email failed validation: ' . $email;
        } else {
            $stmt = $mysqli->prepare("SELECT id, username, reset_token_expires FROM users WHERE email = ?");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $stmt->bind_result($uid, $uname, $expires);
            $userFound = $stmt->fetch();
            $stmt->close();

            if ($userFound) {
                $debugLog[] = 'User found for email: ' . $email . ' (id=' . $uid . ')';
                if (!empty($expires)) {
                    $lastRequestTime = strtotime($expires) - 3600;
                    if (time() - $lastRequestTime < $throttle_limit) {
                        $message = 'Please wait before requesting another password reset.';
                        $messageType = 'error';
                        $debugLog[] = 'Throttle hit; last request within limit';
                    }
                }

                if ($message === '') {
                    $token = bin2hex(random_bytes(16));
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    $u = $mysqli->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?");
                    $u->bind_param('ssi', $token, $expiresAt, $uid);
                    $u->execute();
                    $debugLog[] = 'Reset token set, rows affected: ' . $u->affected_rows;
                    $u->close();

$resetLink = "https://chalweb.com/lonestar/password_reset.php?token=" . urlencode($token);
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $basePath = '';
                    if (file_exists(__DIR__ . '/includes/paths.php')) {
                        require_once __DIR__ . '/includes/paths.php';
                        $basePath = lonestar_base_path();
                    }
                    $resetLink = $scheme . '://' . $host . $basePath . '/password_reset.php?token=' . urlencode($token);
                    $debugLog[] = 'Reset link: ' . $resetLink;

                    $mail = new PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        $mail->Host       = 'smtp.ionos.com';
                        $mail->SMTPAuth   = true;
                        $mail->Username   = 'info@chalweb.com';
                        $mail->Password = getenv('LONESTAR_SMTP_PASSWORD') ?: '';
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port       = 587;

                        $mail->setFrom('info@chalweb.com', 'Lonestar Roadside');
                        $mail->addAddress($email, $uname);
                        $mail->isHTML(true);
                        $mail->Subject = 'Password Reset Request';
                        $mail->Body = "Hello,<br><br>To reset your password, click the link below:<br><a href=\"$resetLink\">Reset Password</a><br><br>This link expires in 1 hour.";

                        $mail->send();
                        $message = 'A password reset link has been sent to your email address.';
                        $messageType = 'success';
                        $debugLog[] = 'Mailer send() returned success';
                    } catch (Exception $e) {
                        $message = 'Email could not be sent. Mailer Error: ' . $mail->ErrorInfo;
                        $messageType = 'error';
                        $debugLog[] = 'Mailer error: ' . $mail->ErrorInfo;
                    }
                }
            } else {
                $message = 'No user found with that email address.';
                $messageType = 'error';
                $debugLog[] = 'No user found for email: ' . $email;
            }
        }
    } elseif (isset($_POST['reset_password'])) {
        $token = trim($_POST['token'] ?? '');
        $newPassword = trim($_POST['new_password'] ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');

        if ($newPassword === '' || $confirmPassword === '') {
            $message = 'Please fill in all fields.';
            $step = 2;
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'Passwords do not match.';
            $step = 2;
            $messageType = 'error';
        } else {
            $stmt = $mysqli->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > NOW()");
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $stmt->bind_result($uid);
            $tokenValid = $stmt->fetch();
            $stmt->close();

            if ($tokenValid) {
                $debugLog[] = 'Reset token valid for user id=' . $uid;
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $u = $mysqli->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?");
                $u->bind_param('si', $hashedPassword, $uid);
                $u->execute();
                $debugLog[] = 'Password updated, rows affected: ' . $u->affected_rows;
                $u->close();
                $message = "Password updated successfully! You can now <a href='index.php'>login</a>.";
                $step = 3;
                $messageType = 'success';
            } else {
                $message = 'Invalid or expired token.';
                $step = 1;
                $messageType = 'error';
                $debugLog[] = 'Reset token invalid or expired';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Password Reset</title>
  <style>
      body { margin:0; padding:0; font-family: sans-serif; background:#f2f4f8; }
      .page-shell { display:flex; min-height: calc(100vh - var(--banner-h)); }
      .sidebar { width:250px; background:#333; color:#fff; height:calc(100vh - var(--banner-h)); position:fixed; top:var(--banner-h); overflow:auto; transition:transform .3s ease; }
      .sidebar.collapsed { transform: translateX(-250px); }
      .sidebar a { display:block; color:#fff; padding:15px; text-decoration:none; }
      .sidebar a:hover { background:#444; }
      .main { margin-left:250px; padding:20px; flex:1; min-width:0; }
      .sidebar.collapsed + .main { margin-left:0; }
      @media(max-width:768px) { .sidebar { transform:translateX(-250px); } .sidebar.open { transform: translateX(0); } .main { margin:0; } }
      .reset-container { max-width: 520px; margin: 40px auto; background:#fff; padding: 28px; border-radius: 12px; border: 1px solid #e0e4ec; box-shadow:0 10px 24px rgba(0,0,0,.08); }
      h1 { text-align: center; margin-top:0; }
      label { display: block; margin-top: 15px; font-weight: 600; }
      input[type="email"], input[type="password"] { width: 100%; padding: 10px; margin-top: 5px; box-sizing: border-box; border:1px solid #cfd6e0; border-radius:6px; }
      .message { margin: 15px 0 0; text-align: center; padding: 10px; border-radius:6px; }
      .message.success { background:#e6f4ea; color:#1e7e34; border:1px solid #cfead6; }
      .message.error { background:#fdecea; color:#b00020; border:1px solid #f6c9c3; }
      .message.info { background:#eef2ff; color:#1f2a6b; border:1px solid #dbe3ff; }
      .btn-submit { margin-top: 20px; width: 100%; padding: 12px; background: #002A5C; color: #fff; border: none; font-size: 16px; cursor: pointer; border-radius:6px; }
      .btn-submit:hover { background: #001f44; }
      .info { font-size: 0.9em; color: #666; text-align: center; margin-top: 20px; }
  </style>
</head>
<body>
<?php include __DIR__ . '/includes/ls_title.php'; ?>
<div class="page-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main">
    <div class="reset-container">
      <h1>Password Reset</h1>

      <?php if (!empty($message)): ?>
          <div class="message <?= htmlspecialchars($messageType, ENT_QUOTES) ?>"><?= $message; ?></div>
      <?php endif; ?>
      <?php if (!empty($debugLog)): ?>
          <div class="message info" style="text-align:left;">
            <strong>Debug</strong><br>
            <?php foreach ($debugLog as $line): ?>
              <?= htmlspecialchars($line, ENT_QUOTES) ?><br>
            <?php endforeach; ?>
          </div>
      <?php endif; ?>

      <?php if ($step === 1): ?>
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES); ?>">
            <input type="hidden" name="debug_ping" value="1">
            <label for="email">Enter your account email</label>
            <input type="email" name="email" id="email" placeholder="yourname@example.com" required>
            <button class="btn-submit" type="submit" name="request_reset">Request Password Reset</button>
        </form>
    <?php elseif ($step === 2): ?>
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES); ?>">
            <input type="hidden" name="debug_ping" value="1">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <label for="new_password">New Password</label>
            <input type="password" name="new_password" id="new_password" required>
              <label for="confirm_password">Confirm Password</label>
              <input type="password" name="confirm_password" id="confirm_password" required>
              <button class="btn-submit" type="submit" name="reset_password">Reset Password</button>
          </form>
      <?php elseif ($step === 3): ?>
          <p class="info">Your password has been reset. <a href="index.php">Click here to login</a>.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
