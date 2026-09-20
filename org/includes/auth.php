<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/paths.php';
$basePath = lonestar_base_path();

if (empty($_SESSION['logged_in'])) {
    header('Location: ' . $basePath . '/index.php');
    exit;
}
