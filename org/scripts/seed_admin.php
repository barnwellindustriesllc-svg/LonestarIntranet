<?php
require '../includes/config.php';

$username = $argv[1] ?? '';
$password = $argv[2] ?? '';
$email = $argv[3] ?? '';
$firstName = $argv[4] ?? '';
$lastName = $argv[5] ?? '';

if ($username === '' || $password === '' || $firstName === '' || $lastName === '') {
    fwrite(STDERR, "Usage: php scripts/seed_admin.php <username> <password> [email] <first_name> <last_name>\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $mysqli->prepare('INSERT INTO users (username, first_name, last_name, email, password_hash) VALUES (?, ?, ?, ?, ?)');
$stmt->bind_param('sssss', $username, $firstName, $lastName, $email, $hash);

if ($stmt->execute()) {
    echo "Admin user created: {$username}\n";
} else {
    echo "Failed to create user: " . $stmt->error . "\n";
}
$stmt->close();
