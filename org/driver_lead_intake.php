<?php
require __DIR__ . '/includes/config.php';

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES);
}

function ensure_driver_leads_onboarding_columns(mysqli $mysqli): void {
    $columns = [
        'is_owner_operator' => "ALTER TABLE driver_leads ADD COLUMN is_owner_operator TINYINT(1) NOT NULL DEFAULT 0 AFTER owner_name",
        'dot_number' => "ALTER TABLE driver_leads ADD COLUMN dot_number VARCHAR(80) NULL AFTER is_owner_operator",
        'onboarding_status' => "ALTER TABLE driver_leads ADD COLUMN onboarding_status VARCHAR(40) NOT NULL DEFAULT 'manual' AFTER broker_has_trailer",
        'recruiting_email_sent_at' => "ALTER TABLE driver_leads ADD COLUMN recruiting_email_sent_at DATETIME NULL AFTER onboarding_status",
        'onboarding_email_sent_at' => "ALTER TABLE driver_leads ADD COLUMN onboarding_email_sent_at DATETIME NULL AFTER recruiting_email_sent_at",
        'completed_at' => "ALTER TABLE driver_leads ADD COLUMN completed_at DATETIME NULL AFTER onboarding_email_sent_at",
        'updated_at' => "ALTER TABLE driver_leads ADD COLUMN updated_at DATETIME NULL AFTER completed_at",
    ];

    foreach ($columns as $column => $sql) {
        $stmt = $mysqli->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'driver_leads' AND COLUMN_NAME = ?"
        );
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('s', $column);
        $stmt->execute();
        $stmt->bind_result($exists);
        $stmt->fetch();
        $stmt->close();
        if ((int)$exists === 0) {
            $mysqli->query($sql);
        }
    }

    foreach (['broker_has_insurance', 'broker_has_trailer'] as $column) {
        $stmt = $mysqli->prepare(
            "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'driver_leads' AND COLUMN_NAME = ?"
        );
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('s', $column);
        $stmt->execute();
        $stmt->bind_result($nullable);
        $stmt->fetch();
        $stmt->close();
        if ($nullable === 'NO') {
            $mysqli->query("ALTER TABLE driver_leads MODIFY COLUMN {$column} TINYINT(1) NULL DEFAULT NULL");
        }
    }
}

function split_driver_name(string $name): array {
    $parts = preg_split('/\s+/', trim($name));
    if (!$parts || count($parts) === 0) {
        return ['', ''];
    }
    if (count($parts) === 1) {
        return [$parts[0], ''];
    }
    $last = array_pop($parts);
    return [implode(' ', $parts), $last];
}

ensure_driver_leads_onboarding_columns($mysqli);

$errors = [];
$submitted = false;
$name = '';
$email = '';
$phone = '';
$isOwnerOperator = '';
$hasInsurance = '';
$hasTrailer = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $isOwnerOperator = $_POST['is_owner_operator'] ?? '';
    $hasInsurance = $_POST['has_insurance'] ?? '';
    $hasTrailer = $_POST['has_trailer'] ?? '';

    if ($name === '') {
        $errors[] = 'Name is required.';
    }
    if ($email === '') {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is invalid.';
    }
    if ($phone === '') {
        $errors[] = 'Phone number is required.';
    }
    if (!in_array($isOwnerOperator, ['yes', 'no'], true)) {
        $errors[] = 'Please select whether you are an owner operator.';
    }
    if ($hasInsurance !== '' && !in_array($hasInsurance, ['yes', 'no'], true)) {
        $errors[] = 'Insurance answer is invalid.';
    }
    if ($hasTrailer !== '' && !in_array($hasTrailer, ['yes', 'no'], true)) {
        $errors[] = 'Trailer answer is invalid.';
    }

    if (empty($errors)) {
        [$firstName, $lastName] = split_driver_name($name);
        if ($lastName === '') {
            $lastName = '(not provided)';
        }
        $ownerOperatorValue = $isOwnerOperator === 'yes' ? 1 : 0;
        $hasInsuranceValue = $hasInsurance === '' ? null : ($hasInsurance === 'yes' ? 1 : 0);
        $hasTrailerValue = $hasTrailer === '' ? null : ($hasTrailer === 'yes' ? 1 : 0);

        $stmt = $mysqli->prepare(
            'INSERT INTO driver_leads
                (first_name, last_name, owner_name, is_owner_operator, email, phone, lead_type, lead_comments, broker_has_insurance, broker_has_trailer, onboarding_status, updated_at)
             VALUES (?, ?, "", ?, ?, ?, "", "Submitted from driver intake form.", ?, ?, "submitted", NOW())'
        );
        if (!$stmt) {
            $errors[] = 'Unable to save your information right now.';
        } else {
            $stmt->bind_param('ssissii', $firstName, $lastName, $ownerOperatorValue, $email, $phone, $hasInsuranceValue, $hasTrailerValue);
            if ($stmt->execute()) {
                $submitted = true;
                $name = $email = $phone = '';
                $isOwnerOperator = $hasInsurance = $hasTrailer = '';
            } else {
                $errors[] = 'Unable to save your information right now.';
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Driver Intake Form</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { margin:0; font-family:sans-serif; background:#f5f7fb; color:#1f2933; }
    .intake-shell { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:32px 16px; }
    .intake-panel { width:min(100%, 680px); background:#fff; border:1px solid #d7dee8; border-radius:8px; box-shadow:0 12px 36px rgba(15,23,42,.08); }
    .intake-header { padding:28px 32px 16px; border-bottom:1px solid #e5e9f0; }
    .intake-body { padding:28px 32px 32px; }
    .brand-mark { display:flex; align-items:center; gap:12px; font-weight:700; color:#0f3b66; }
    .brand-mark img { width:48px; height:48px; object-fit:contain; }
    h1 { margin:18px 0 6px; font-size:1.75rem; }
    .form-label { font-weight:600; }
  </style>
</head>
<body>
  <main class="intake-shell">
    <section class="intake-panel">
      <div class="intake-header">
        <div class="brand-mark">
          <img src="img/logo.png" alt="Lone Star Roadside">
          <span>Lone Star Roadside</span>
        </div>
        <h1>Driver Intake Form</h1>
      </div>
      <div class="intake-body">
        <?php if ($submitted): ?>
          <div class="alert alert-success">Thank you. Your information has been submitted.</div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger">
            <strong>Please fix the following:</strong>
            <ul class="mb-0">
              <?php foreach ($errors as $error): ?>
                <li><?= h($error) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="post" class="row g-3">
          <div class="col-12">
            <label class="form-label" for="name">Name <span class="text-danger">required</span></label>
            <input class="form-control" type="text" name="name" id="name" value="<?= h($name) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="email">Email Address <span class="text-danger">required</span></label>
            <input class="form-control" type="email" name="email" id="email" value="<?= h($email) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="phone">Phone Number <span class="text-danger">required</span></label>
            <input class="form-control" type="tel" name="phone" id="phone" value="<?= h($phone) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label d-block">Are you an Owner Operator? <span class="text-danger">required</span></label>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="is_owner_operator" id="owner_operator_yes" value="yes" <?= $isOwnerOperator === 'yes' ? 'checked' : '' ?> required>
              <label class="form-check-label" for="owner_operator_yes">Yes</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="is_owner_operator" id="owner_operator_no" value="no" <?= $isOwnerOperator === 'no' ? 'checked' : '' ?> required>
              <label class="form-check-label" for="owner_operator_no">No</label>
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label d-block">Do you have your own insurance?</label>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="has_insurance" id="has_insurance_yes" value="yes" <?= $hasInsurance === 'yes' ? 'checked' : '' ?>>
              <label class="form-check-label" for="has_insurance_yes">Yes</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="has_insurance" id="has_insurance_no" value="no" <?= $hasInsurance === 'no' ? 'checked' : '' ?>>
              <label class="form-check-label" for="has_insurance_no">No</label>
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label d-block">Do you own your own trailer?</label>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="has_trailer" id="has_trailer_yes" value="yes" <?= $hasTrailer === 'yes' ? 'checked' : '' ?>>
              <label class="form-check-label" for="has_trailer_yes">Yes</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="has_trailer" id="has_trailer_no" value="no" <?= $hasTrailer === 'no' ? 'checked' : '' ?>>
              <label class="form-check-label" for="has_trailer_no">No</label>
            </div>
          </div>
          <div class="col-12 pt-2">
            <button class="btn btn-primary" type="submit">Submit</button>
          </div>
        </form>
      </div>
    </section>
  </main>
</body>
</html>
