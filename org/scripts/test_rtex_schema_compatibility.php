<?php
// Disposable local DB only; never loads production configuration.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/rtex_fsc.php';
function lonestar_vendor_broker_fee_settings(mysqli $db, string $vendor): array {
    return ['fee_mode'=>'percentage','fee_value'=>10];
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('127.0.0.1','root','lonestar-local-test-only','',33079);
$name = 'rtex_compat_' . bin2hex(random_bytes(5));
$db->query("CREATE DATABASE {$name}");
$db->select_db($name);
$checks = 0;
function check($condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
try {
    check(lonestar_driver_rtex_week_fuel_surcharge_total($db,1,'2026-09-20','2026-09-26') === 0.0,'no RTEX table');
    $db->query('CREATE TABLE rtex_payout_rows (id INT PRIMARY KEY)');
    check(lonestar_driver_rtex_week_fuel_surcharge_total($db,1,'2026-09-20','2026-09-26') === 0.0,'hourly-only schema');
    $db->query("ALTER TABLE rtex_payout_rows ADD total_amount DECIMAL(12,2), ADD driver_payout_id INT, ADD billing_mode VARCHAR(10), ADD matched_contact_id INT, ADD work_date DATE, ADD ticket_number VARCHAR(80)");
    $db->query('CREATE TABLE driver_payouts (id INT PRIMARY KEY, vendor_name VARCHAR(80))');
    $db->query("INSERT INTO driver_payouts VALUES (1,'RTEX')");
    $db->query("INSERT INTO rtex_payout_rows VALUES(1,1000,1,'load',1,'2026-09-23','TEST')");
    $rows = [['payout_date'=>'2026-09-23','ticket_number'=>'TEST']];
    check(lonestar_driver_rtex_week_fuel_surcharge_total($db,1,'2026-09-20','2026-09-26') === 0.0,'no weekly settings');
    check(rtex_driver_statement_fsc($db,$rows)['total'] === 0.0,'statement before weekly settings');
    rtex_fsc_ensure_schema($db);
    rtex_save_fsc_settings($db,'2026-09-20',15,25);
    rtex_save_fsc_settings($db,'2026-09-27',30,40);
    check(lonestar_driver_rtex_week_fuel_surcharge_total($db,1,'2026-09-20','2026-09-26') === 150.0,'legacy weekly FSC retained before migration');
    $details = rtex_driver_statement_fsc($db,$rows);
    check($details['total'] === 150.0 && $details['broker_fee'] === 100.0,'legacy statement retains FSC and base-only brokerage');
    check($details['lines'][0]['label'] === 'Driver FSC (15%)','legacy statement rate follows work date');
    $db->query('ALTER TABLE rtex_payout_rows ADD driver_fsc_rate DECIMAL(5,2) NULL');
    check(lonestar_driver_rtex_week_fuel_surcharge_total($db,1,'2026-09-20','2026-09-26') === 150.0,'incomplete backfill uses weekly rate');
    check(rtex_driver_statement_fsc($db,$rows)['total'] === 150.0,'statement handles nullable snapshot');
    $db->query('UPDATE rtex_payout_rows SET driver_fsc_rate=5');
    check(lonestar_driver_rtex_week_fuel_surcharge_total($db,1,'2026-09-20','2026-09-26') === 50.0,'snapshot overrides weekly rate');
    check(rtex_driver_statement_fsc($db,$rows)['total'] === 50.0,'statement uses snapshot');
    $db->query('UPDATE rtex_payout_rows SET driver_fsc_rate=0');
    check(lonestar_driver_rtex_week_fuel_surcharge_total($db,1,'2026-09-20','2026-09-26') === 0.0,'explicit zero never falls back');
    check(rtex_driver_statement_fsc($db,$rows)['total'] === 0.0,'statement preserves zero');
    $db->query('UPDATE rtex_payout_rows SET driver_fsc_rate=5');
    $db->query('DROP TABLE rtex_fsc_settings');
    check(lonestar_driver_rtex_week_fuel_surcharge_total($db,1,'2026-09-20','2026-09-26') === 50.0,'snapshot works without legacy settings');
    $db->query('DELETE FROM driver_payouts');
    check(lonestar_driver_rtex_week_fuel_surcharge_total($db,1,'2026-09-20','2026-09-26') === 0.0,'unlinked loads excluded');
    echo "PASS: {$checks} RTEX schema compatibility checks\n";
} finally {
    $db->query("DROP DATABASE {$name}");
    $db->close();
}
