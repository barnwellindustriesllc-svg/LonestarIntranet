<?php
// Basic contact / careers form handler
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /contact.php');
    exit;
}

$formType = isset($_POST['form_type']) ? $_POST['form_type'] : 'contact';

$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$phone   = trim($_POST['phone'] ?? '');
$company = trim($_POST['company'] ?? '');
$position = trim($_POST['position'] ?? '');
$requestType = trim($_POST['request_type'] ?? '');
$message = trim($_POST['message'] ?? '');

if ($name === '' || $email === '') {
    header('Location: /contact.php?status=error');
    exit;
}

$to      = "info@lonestarroadsidetx.com";
$subject = "New {$formType} submission from {$name} – Lonestar Roadside LLC";

$body = "Form Type: {$formType}\n"
      . "Name: {$name}\n"
      . "Email: {$email}\n"
      . "Phone: {$phone}\n";

if ($company !== '') {
    $body .= "Company: {$company}\n";
}
if ($position !== '') {
    $body .= "Position: {$position}\n";
}
if ($requestType !== '') {
    $body .= "Request Type: {$requestType}\n";
}

$body .= "\nMessage:\n{$message}\n";

$headers = "From: info@lonestarroadsidetx.com\r\n"
         . "Reply-To: {$email}\r\n";

@mail($to, $subject, $body, $headers);

if ($formType === 'careers') {
    header('Location: /careers.php?status=thanks');
} else {
    header('Location: /contact.php?status=thanks');
}
exit;
