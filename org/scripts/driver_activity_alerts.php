#!/usr/bin/env php
<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/driver_load_tools.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');

try {
    dlt_ensure_tables($mysqli);
    $rows = dlt_get_activity_monitor_rows($mysqli, 120, '');
    $stats = dlt_send_out_of_compliance_alerts($mysqli, $rows);

    echo 'Driver Activity Alert Run: ' . date('Y-m-d H:i:s') . PHP_EOL;
    echo 'Eligible: ' . (int)$stats['eligible'] . PHP_EOL;
    echo 'Email sent: ' . (int)$stats['sent_email'] . PHP_EOL;
    echo 'Text sent: ' . (int)$stats['sent_text'] . PHP_EOL;
    echo 'Already sent today: ' . (int)$stats['skipped_already_sent'] . PHP_EOL;
    echo 'Missing contact match: ' . (int)$stats['skipped_missing_contact'] . PHP_EOL;

    if (!empty($stats['errors'])) {
        echo 'Errors:' . PHP_EOL;
        foreach ($stats['errors'] as $error) {
            echo ' - ' . $error . PHP_EOL;
        }
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
