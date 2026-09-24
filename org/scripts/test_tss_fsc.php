<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/tss_fsc.php';
$checks = 0;
function check($ok, $label) { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function rejects(callable $call, string $label) { try { $call(); } catch (InvalidArgumentException $e) { check(true,$label); return; } throw new RuntimeException($label); }
$input = ['start_date'=>'2026-09-20','end_date'=>'2026-09-26','fsc_type'=>'tonnage','mileage_match'=>'range','min_miles'=>0,'max_miles'=>50,'rate'=>2.5];
$ton = tss_fsc_rule($input);
$mile = tss_fsc_rule(array_merge($input,['fsc_type'=>'mileage','mileage_match'=>'exact','min_miles'=>50,'max_miles'=>'','rate'=>0.75]));
check($mile['max_miles'] === 50.0, 'exact ignores unused max');
check(!tss_fsc_overlaps($ton,$mile), 'mixed types allowed');
check(tss_fsc_overlaps($ton,array_merge($mile,['fsc_type'=>'tonnage'])), 'exact at inclusive range boundary conflicts');
check(!tss_fsc_overlaps($ton,array_merge($ton,['start_date'=>'2026-09-27','end_date'=>'2026-10-03'])), 'new week allowed');
foreach (['2026-09-20','2026-09-26'] as $date) {
 check(tss_fsc_resolve([$ton,$mile],$date,50,20,'Tonnage')['amount'] === 50.0, 'inclusive date and mileage boundaries');
 check(tss_fsc_resolve([$ton,$mile],$date,50,'','Mileage')['amount'] === 37.5, 'mileage never requires tons');
}
check(tss_fsc_resolve([], '', '', '', 'None')['amount'] === 0.0, 'None needs no rule');
check(tss_fsc_resolve([$ton], '2026-09-23', 0, 1.23, 'tons')['amount'] === 3.08, 'per-load cents rounding');
foreach (['','unknown'] as $type) rejects(fn()=>tss_fsc_resolve([$ton],'2026-09-23',20,20,$type), 'blank or invalid type');
rejects(fn()=>tss_fsc_resolve([$mile],'2026-09-23',50.01,20,'Mileage'), 'exact mismatch');
rejects(fn()=>tss_fsc_resolve([$ton],'2026-09-27',20,20,'Tonnage'), 'outside dates');
rejects(fn()=>tss_fsc_resolve([$ton,$ton],'2026-09-23',20,20,'Tonnage'), 'ambiguous match');
rejects(fn()=>tss_fsc_resolve([$ton],'2026-09-23',20,'','Tonnage'), 'missing tons');
foreach ([['start_date'=>'2026-02-30'],['end_date'=>'2026-09-19'],['max_miles'=>-1],['rate'=>'NaN'],['min_miles'=>60],['rate'=>-1],['fsc_type'=>'none']] as $bad) rejects(fn()=>tss_fsc_rule(array_merge($input,$bad)), 'invalid rule');
if (in_array('--database',$argv,true)) {
 mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
 $db = new mysqli('127.0.0.1','root','lonestar-local-test-only','',33079);
 $name = 'tss_fsc_test_' . bin2hex(random_bytes(5));
 $db->query("CREATE DATABASE {$name}");
 $db->select_db($name);
 try {
  $db->query('CREATE TABLE ls_detail_raw (id INT PRIMARY KEY, fuel_surcharge_rate DECIMAL(12,4), fuel_surcharge_type VARCHAR(20))');
  tss_fsc_schema($db); tss_fsc_schema($db);
  check($db->query("SHOW COLUMNS FROM ls_detail_raw LIKE 'fuel_surcharge_amount'")->num_rows === 1,'repeatable schema');
  tss_fsc_save_rule($db,$input);
  tss_fsc_save_rule($db,array_merge($mile,['rule_id'=>0]));
  rejects(fn()=>tss_fsc_save_rule($db,$input),'overlap rejected on insert');
  rejects(fn()=>tss_fsc_save_rule($db,array_merge($input,['rule_id'=>2])),'overlap rejected on edit');
  $snapshot = tss_fsc_resolve(tss_fsc_rules($db),'2026-09-23',50,20,'Tonnage');
  $stmt=$db->prepare('INSERT INTO ls_detail_raw VALUES(1,?,?,?)');
  $stmt->bind_param('dsd',$snapshot['rate'],$snapshot['type'],$snapshot['amount']); $stmt->execute(); $stmt->close();
  tss_fsc_save_rule($db,array_merge($input,['rule_id'=>1,'rate'=>9]));
  check((float)$db->query('SELECT fuel_surcharge_amount FROM ls_detail_raw WHERE id=1')->fetch_row()[0] === 50.0,'editing key preserves saved amount');
  check(tss_fsc_resolve(tss_fsc_rules($db),'2026-09-23',50,20,'Tonnage')['amount'] === 180.0,'new loads use changed rule');
  tss_fsc_save_rule($db,array_merge($input,['start_date'=>'2026-09-27','end_date'=>'2026-10-03','rate'=>3]));
  check(tss_fsc_resolve(tss_fsc_rules($db),'2026-09-27',50,20,'Tonnage')['amount'] === 60.0,'next week different rate');
  require_once __DIR__ . '/../includes/payout_net_helpers.php';
  $db->query("ALTER TABLE ls_detail_raw ADD COLUMN Mileage VARCHAR(30) DEFAULT '50', ADD COLUMN `Net Weight (Tons)` VARCHAR(30) DEFAULT '20', ADD COLUMN matched_contact_id INT DEFAULT 1, ADD COLUMN upload_date DATE DEFAULT '2026-09-23', ADD COLUMN `Truckload ID` VARCHAR(30) DEFAULT 'TEST', ADD COLUMN `Delivery Date` VARCHAR(30) DEFAULT '2026-09-23'");
  $db->query('CREATE TABLE driver_payouts (upload_date DATE, ticket_number VARCHAR(30), driver_contact_id INT, payout_date DATE)');
  check(lonestar_driver_week_fuel_surcharge_total($db,1,'2026-09-20','2026-09-26') === 50.0,'payroll reads saved amount');
  $db->query("INSERT INTO ls_detail_raw(id,fuel_surcharge_rate,fuel_surcharge_type,fuel_surcharge_amount) VALUES (2,0.75,'mileage',37.5),(3,0,'none',0),(4,1,'tonnage',NULL)");
  check(lonestar_driver_week_fuel_surcharge_total($db,1,'2026-09-20','2026-09-26') === 107.5,'mixed week includes saved amounts plus legacy fallback');
  $db->query('DELETE FROM tss_fsc_rules WHERE id=1');
  check((float)$db->query('SELECT fuel_surcharge_rate FROM ls_detail_raw WHERE id=1')->fetch_row()[0] === 2.5,'deleted key preserves saved rate');
 } finally { $db->query("DROP DATABASE {$name}"); $db->close(); }
}
echo "PASS: {$checks} TSS FSC checks\n";
