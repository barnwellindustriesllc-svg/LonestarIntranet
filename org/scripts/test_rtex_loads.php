<?php
// Run with PHP + zip. Add --database for disposable localhost MariaDB integration tests.
// Never loads includes/config.php or connects to the production database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
const TSS_SOURCE_TIMEZONE = 'America/Chicago';
function import_test_functions(string $file, array $wanted): void {
    $tokens = token_get_all(file_get_contents($file));
    for ($i = 0; $i < count($tokens); $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || !in_array($tokens[$j][1], $wanted, true)) continue;
        $source = '';
        $depth = 0;
        $started = false;
        for ($k = $i; $k < count($tokens); $k++) {
            $token = $tokens[$k];
            $source .= is_array($token) ? $token[1] : $token;
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES], true))) { $started = true; $depth++; }
            if ($token === '}') $depth--;
            if ($started && $depth === 0) break;
        }
        eval($source);
        $i = $k;
    }
    foreach ($wanted as $name) if (!function_exists($name)) throw new RuntimeException('Test could not load ' . $name);
}
$root = dirname(__DIR__);
import_test_functions($root . '/upload.php', ['h','parse_money','digits_only','tss_today','tss_tz','driver_contact_name',
    'ensure_rtex_payout_rows_table','nickelrock_xlsx_col','nickelrock_build_xlsx','build_rtex_invoice_xlsx',
    'rtex_excel_time','rtex_apply_base_rate_reduction']);
import_test_functions($root . '/reports.php', ['money_format_display','rtex_driver_report_values']);
require $root . '/includes/rtex_load_helpers.php';
$checks = 0;
function check($condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
}
function rejects(callable $action, string $message): void {
    $thrown = false;
    try { $action(); } catch (Throwable $e) { $thrown = true; }
    check($thrown, $message);
}
check(rtex_load_total('tonnage',22.66,100,9) === 203.94, 'tonnage ignores miles and uses net US tons');
check(rtex_load_total('mileage',22.66,100,3.25) === 325.0, 'mileage ignores tons');
rejects(static fn() => rtex_load_total('mileage',22.66,0,9), 'missing miles rejected');
rejects(static fn() => rtex_load_total('tonnage',0,100,9), 'missing tons rejected');
rejects(static fn() => rtex_load_total('hourly',2,2,9), 'invalid rate basis rejected');
rejects(static fn() => rtex_load_total('tonnage',2,0,-1), 'negative rate rejected');
rejects(static fn() => rtex_load_total('tonnage',99999999,0,99999999), 'overflow rejected');
check(!rtex_valid_date('2026-02-30') && rtex_valid_date('2026-09-10'), 'calendar dates validated');

$sample = <<<'BOL'
HMA CHRISTMAS QUARRY 228820
PO BOX 456 9/10/2026
903-907-1560 2:09:04PM
Location: 10-CHRISTMAS QUARRY
Customer: 21021-GRANBURY EXCAVATING INC
Order: 1000-FERNLEAF SUBSTATION
P.O.: EK10524
Product: CH-1002-247A1 GRADE 1-2
Carrier: -
Vehicle: R&E03-R & E EXPRESS SERVICES
Pounds Tons Metric
Gross 74360 37.18 33.73
Tare 29040 * 14.52 * 13.17 *
Net 45320 22.66 20.56
22.66 Ton
Ordered 0.00
Received 676.18
Remaining -676.18
Today: 193.98 Loads: 8
BOL;
$parsed = rtex_parse_bol_text($sample);
check($parsed['ticket_number'] === '228820', 'unlabeled HMA ticket parsed');
check($parsed['work_date'] === '2026-09-10', 'BOL date parsed');
check($parsed['tons'] === '22.66', 'net tons not pounds, metric tons, or daily totals');
check($parsed['truck_raw'] === 'R&E03', 'vehicle identifier separated from carrier');
check($parsed['work_order'] === 'EK10524', 'P.O. parsed');
check($parsed['provider_name'] === '10-CHRISTMAS QUARRY', 'quarry parsed');
check($parsed['job_number'] === '1000-FERNLEAF SUBSTATION', 'order parsed');
check(rtex_parse_bol_text("Ticket # 12345\nNet: 45320")['tons'] === '', 'ambiguous net number is not guessed');
check(rtex_parse_bol_text("Net: 45,320 lbs")['tons'] === '22.66', 'explicit pounds converted to US tons');
check(rtex_parse_bol_text("22.66 Ton\nToday: 193.98 Loads: 8")['tons'] === '22.66', 'standalone net tons parsed');
check(rtex_parse_bol_text("Ticket 12345\nMiles: 82.5")['miles'] === '82.5', 'explicit mileage parsed');
check(rtex_parse_bol_text("903-907-1560\nPO BOX 456\n9/10/2026")['ticket_number'] === '', 'phone and PO box are not ticket numbers');

$hourly = rtex_driver_report_values(['rate_raw'=>100,'hours_raw'=>8],800);
check($hourly['pay'] === 720.0 && $hourly['rate'] === '$90.00', 'hourly statement behavior retained');
$tonReport = rtex_driver_report_values(['billing_mode'=>'load','rate_basis'=>'tonnage','tons'=>22.66,'rate_raw'=>9],203.94);
check($tonReport['pay'] === 203.94 && $tonReport['rate'] === '$9.00/ton' && $tonReport['hours'] === '22.66 tons', 'load statement has correct units and no hourly deduction');
$mileReport = rtex_driver_report_values(['billing_mode'=>'load','rate_basis'=>'mileage','miles'=>100,'rate_raw'=>3.25],325);
check($mileReport['hours'] === '100 mi' && $mileReport['pay'] === 325.0, 'mileage statement');
check(rtex_apply_base_rate_reduction(100,8,800,10) === [91.0,728.0], 'hourly base reduction unchanged');

$invoiceRow = array_merge($parsed, ['job_name'=>'Fernleaf','driver_name'=>'Test Driver','rate_basis'=>'tonnage','rate'=>9,'total_amount'=>203.94,'miles'=>0]);
$otherRow = array_merge($invoiceRow, ['rate_basis'=>'mileage','rate'=>3.25,'miles'=>100,'total_amount'=>325]);
$xlsx = build_rtex_load_invoice_xlsx([$invoiceRow,$otherRow]);
$path = tempnam(sys_get_temp_dir(),'rtex_test_xlsx_');
try {
    file_put_contents($path,$xlsx);
    $zip = new ZipArchive();
    check($zip->open($path) === true, 'invoice is a valid XLSX archive');
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    check(strpos($sheet,'<f>ROUND(G12*H12,2)</f>') !== false && strpos($sheet,'<f>ROUND(100.00*H13,2)</f>') !== false, 'invoice formulas use the correct unit column');
    check(strpos($sheet,'<v>528.94</v>') !== false, 'invoice cached grand total correct');
    check(simplexml_load_string($sheet) !== false, 'invoice worksheet XML valid');
    $zip->close();
} finally { unlink($path); }

if (in_array('--database', $argv, true)) {
    // This port and credential are exclusively for the disposable test instance.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli('127.0.0.1','root','lonestar-local-test-only','',33079);
    $database = 'rtex_test_' . bin2hex(random_bytes(5));
    $db->query('CREATE DATABASE ' . $database);
    $db->select_db($database);
    $db->set_charset('utf8mb4');
    try {
        $db->query("CREATE TABLE driver_contacts (id INT PRIMARY KEY, first_name VARCHAR(80), last_name VARCHAR(80)) ENGINE=InnoDB");
        $db->query("INSERT INTO driver_contacts VALUES (1,'First','Driver'),(2,'Second','Driver')");
        $db->query("CREATE TABLE driver_payouts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, payout_date DATE NOT NULL,
            ticket_number VARCHAR(80) NOT NULL,driver_name VARCHAR(255) NOT NULL,vendor_name VARCHAR(80) NOT NULL,
            tss_pay DECIMAL(12,2),upload_date DATE NOT NULL,driver_contact_id INT,
            UNIQUE KEY uniq_ticket_date_driver_vendor (ticket_number,payout_date,vendor_name,driver_name)) ENGINE=InnoDB");
        ensure_rtex_payout_rows_table($db);
        $db->query("INSERT INTO rtex_payout_rows (upload_date,sheet_name,source_line_no,work_date,ticket_number,hours,rate,total_amount)
            VALUES ('2026-09-10','Existing Hourly',1,'2026-09-10','OLD-HOURLY',8,100,800)");
        // Reproduce an older schema that defaults every new primary key to zero.
        $db->query("ALTER TABLE rtex_payout_rows MODIFY id BIGINT UNSIGNED NOT NULL DEFAULT 0");
        $db->query("UPDATE rtex_payout_rows SET id=0 WHERE ticket_number='OLD-HOURLY'");
        $db->query("ALTER TABLE driver_payouts MODIFY id BIGINT UNSIGNED NOT NULL DEFAULT 0");
        ensure_rtex_load_schema($db);
        $db->query("ALTER TABLE rtex_job_rates MODIFY id INT UNSIGNED NOT NULL DEFAULT 0");
        ensure_rtex_load_schema($db);
        check((int)$db->query("SELECT id FROM rtex_payout_rows WHERE ticket_number='OLD-HOURLY'")->fetch_row()[0] === 0,
            'auto-increment repair preserves existing zero ID');
        foreach (['rtex_payout_rows','rtex_job_rates','driver_payouts'] as $table) {
            check(str_contains($db->query("SHOW COLUMNS FROM {$table} LIKE 'id'")->fetch_assoc()['Extra'], 'auto_increment'),
                'auto-increment restored for ' . $table);
        }
        ensure_rtex_load_schema($db);
        ensure_rtex_load_schema($db);
        $legacy = $db->query("SELECT * FROM rtex_payout_rows WHERE ticket_number='OLD-HOURLY'")->fetch_assoc();
        check($legacy['billing_mode'] === 'hourly' && (float)$legacy['total_amount'] === 800.0, 'migration repeatable and preserves historical hourly rows');
        $db->query("INSERT INTO rtex_job_rates (job_name,rate_basis,rate,work_order) VALUES ('Fernleaf','tonnage',9,'DEFAULT'),('Haul Route','mileage',3.25,'WO2')");
        foreach ([1=>['22.66',203.94,'tons'], 2=>['100',325.0,'miles']] as $jobId => [$quantity,$expected,$unit]) {
            $manualId = rtex_save_load($db, ['compact_entry'=>1,'quantity'=>$quantity,'job_rate_id'=>$jobId,
                'matched_contact_id'=>1,'work_date'=>'2026-09-10','ticket_number'=>'MANUAL-'.$jobId]);
            $manual = rtex_load_row($db,$manualId);
            check((float)$manual['total_amount'] === $expected && (float)$manual[$unit] === (float)$quantity &&
                $manual['provider_name'] === '' && $manual['truck_raw'] === '', 'compact manual save uses job units without removed metadata');
            rtex_delete_loads($db,[$manualId]);
        }
        $input = array_merge($parsed,['job_rate_id'=>1,'matched_contact_id'=>1]);
        $id = rtex_save_load($db,$input);
        $db->query("UPDATE rtex_payout_rows SET id=900000 WHERE ticket_number='OLD-HOURLY'");
        $db->query("UPDATE rtex_payout_rows SET id=0 WHERE id={$id}");
        check(rtex_save_load($db,$input,0) === 0, 'editing legacy zero ID updates the same load');
        $db->query("UPDATE rtex_payout_rows SET id={$id} WHERE id=0");
        $db->query("UPDATE rtex_payout_rows SET id=0 WHERE ticket_number='OLD-HOURLY'");
        $stored = rtex_load_row($db,$id);
        check((float)$stored['total_amount'] === 203.94 && (float)$stored['hours'] === 0.0, 'save derives load total and zero hourly fee units');
        check($stored['work_order'] === 'EK10524', 'BOL P.O. survives the job default');
        check($stored['billing_mode'] === 'load' && $stored['rate_basis'] === 'tonnage', 'billing mode and rate basis stored');
        $payoutId = (int)$stored['driver_payout_id'];
        check((float)$db->query('SELECT tss_pay FROM driver_payouts WHERE id=' . $payoutId)->fetch_row()[0] === 203.94, 'payout synchronized');
        rejects(static fn() => rtex_save_load($db,$input), 'duplicate BOL rejected');
        check((int)$db->query('SELECT COUNT(*) FROM driver_payouts')->fetch_row()[0] === 1, 'duplicate creates no extra payout');
        $db->query("UPDATE rtex_job_rates SET rate=12,rate_basis='mileage' WHERE id=1");
        $input['tons'] = 20;
        rtex_save_load($db,$input,$id);
        check((float)rtex_load_row($db,$id)['total_amount'] === 180.0, 'ordinary edits preserve historical tonnage rate and basis');
        $input['apply_current_rate'] = 1;
        $input['miles'] = 100;
        $input['matched_contact_id'] = 2;
        $input['work_date'] = '2026-09-11';
        $input['ticket_number'] = '228821';
        rtex_save_load($db,$input,$id);
        $stored = rtex_load_row($db,$id);
        check((float)$stored['total_amount'] === 1200.0 && $stored['rate_basis'] === 'mileage', 'explicit reprice uses new basis and rate');
        $payout = $db->query('SELECT * FROM driver_payouts WHERE id=' . $payoutId)->fetch_assoc();
        check($payout['driver_name'] === 'Second Driver' && $payout['ticket_number'] === '228821' && $payout['payout_date'] === '2026-09-11', 'driver/date/ticket edits update the same payout');
        check((int)$db->query('SELECT COUNT(*) FROM driver_payouts')->fetch_row()[0] === 1, 'no stale payout after reassignment');
        $db->query("DELETE FROM rtex_job_rates WHERE id=1");
        unset($input['apply_current_rate']);
        $input['miles'] = 90;
        rtex_save_load($db,$input,$id);
        check((float)rtex_load_row($db,$id)['total_amount'] === 1080.0, 'saved load remains editable after job key deletion');
        $new = array_merge($parsed,['ticket_number'=>'MILE-2','job_rate_id'=>2,'matched_contact_id'=>1,'miles'=>80]);
        $id2 = rtex_save_load($db,$new);
        check((float)rtex_load_row($db,$id2)['total_amount'] === 260.0, 'mileage job creation');
        $missingMiles = array_merge($new,['ticket_number'=>'NO-MILES','miles'=>0]);
        rejects(static fn() => rtex_save_load($db,$missingMiles), 'mileage import cannot save without miles');
        $hourlyDuplicate = array_merge($new,['ticket_number'=>'OLD-HOURLY']);
        rejects(static fn() => rtex_save_load($db,$hourlyDuplicate), 'existing hourly identity cannot be reused for load');
        $db->query("INSERT INTO driver_payouts (payout_date,ticket_number,driver_name,vendor_name,tss_pay,upload_date,driver_contact_id)
            VALUES ('2026-09-10','CONFLICT','First Driver','RTEX',1,'2026-09-10',1)");
        $conflictInput = array_merge($new,['ticket_number'=>'CONFLICT']);
        rejects(static fn() => rtex_save_load($db,$conflictInput), 'payout conflict rolls back');
        check((int)$db->query("SELECT COUNT(*) FROM rtex_payout_rows WHERE ticket_number='CONFLICT'")->fetch_row()[0] === 0, 'failed payout insert leaves no invoice row');
        // Ensure a deleted external payout can be recreated when the invoice is edited.
        $id2Payout = (int)rtex_load_row($db,$id2)['driver_payout_id'];
        $db->query('DELETE FROM driver_payouts WHERE id=' . $id2Payout);
        rtex_save_load($db,$new,$id2);
        check((int)rtex_load_row($db,$id2)['driver_payout_id'] !== $id2Payout, 'missing linked payout recreated');
        rejects(static fn() => rtex_delete_loads($db,[$id,999999]), 'invalid bulk delete rolls back all changes');
        check(rtex_load_row($db,$id) !== null, 'rolled-back bulk delete preserves earlier rows');
        rejects(static fn() => rtex_delete_loads($db,['invalid']), 'invalid selection cannot become ID zero');
        $db->query("UPDATE rtex_payout_rows SET id=900000 WHERE ticket_number='OLD-HOURLY'");
        $db->query("UPDATE rtex_payout_rows SET id=0 WHERE id={$id2}");
        $zeroPayoutId = (int)rtex_load_row($db,0)['driver_payout_id'];
        $db->query("UPDATE driver_payouts SET id=0 WHERE id={$zeroPayoutId}");
        $db->query("UPDATE rtex_payout_rows SET driver_payout_id=0 WHERE id=0");
        check(rtex_delete_loads($db,[$id,'0']) === 2, 'bulk delete includes zero ID row and payout');
        check((int)$db->query("SELECT COUNT(*) FROM driver_payouts WHERE ticket_number<>'CONFLICT'")->fetch_row()[0] === 0, 'delete removes linked payouts only');
        check((int)$db->query("SELECT COUNT(*) FROM rtex_payout_rows WHERE billing_mode='hourly'")->fetch_row()[0] === 1, 'load deletion preserves hourly rows');

        // Exercise the actual POST controller, including failed form recovery and CSRF.
        import_test_functions($root . '/upload.php', ['business_sunday_week_start','business_week_end']);
        import_test_functions($root . '/upload.php', ['get_rtex_review_rows']);
        $exportInput = array_merge($new,['ticket_number'=>'EXPORT-EARLY']);
        $exportId = rtex_save_load($db,$exportInput);
        $beforeExport = array_values(array_filter(get_rtex_review_rows($db,'2026-09-06'), static fn($r) => $r['billing_mode'] === 'load'));
        $exportInput['ticket_number'] = 'EXPORT-LATER';
        $laterId = rtex_save_load($db,$exportInput);
        $afterExport = array_values(array_filter(get_rtex_review_rows($db,'2026-09-06'), static fn($r) => $r['billing_mode'] === 'load'));
        check(count($afterExport) === count($beforeExport) + 1, 'repeat export query includes newly saved loads');
        $exportPath = tempnam(sys_get_temp_dir(),'rtex_repeat_export_');
        try {
            file_put_contents($exportPath,build_rtex_load_invoice_xlsx($afterExport));
            $exportZip = new ZipArchive();
            $exportZip->open($exportPath);
            $exportXml = $exportZip->getFromName('xl/worksheets/sheet1.xml') . $exportZip->getFromName('xl/sharedStrings.xml');
            $exportZip->close();
            check(str_contains($exportXml,'EXPORT-EARLY') && str_contains($exportXml,'EXPORT-LATER'), 'fresh spreadsheet contains early and later loads');
        } finally { unlink($exportPath); }
        rtex_delete_loads($db,[$exportId,$laterId]);
        $mysqli = $db;
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION = ['rtex_csrf'=>'test-csrf'];
        $_POST = array_merge($new,['action'=>'save_rtex_load','ticket_number'=>'ACTION','rtex_csrf'=>'test-csrf']);
        $errors = [];
        $success = false;
        require $root . '/includes/rtex_load_actions.php';
        check($success && !$errors && $_POST['rtex_week_start'] === '2026-09-06', 'POST controller saves and selects BOL work week');
        $_POST = array_merge($new,['action'=>'save_rtex_load','ticket_number'=>'BAD-CSRF','rtex_csrf'=>'wrong']);
        $errors = [];
        $success = false;
        require $root . '/includes/rtex_load_actions.php';
        check(!$success && count($errors) === 1, 'CSRF failure blocks save');
        $_POST = array_merge($new,['action'=>'save_rtex_load','ticket_number'=>'FIX-ME','miles'=>0,'rtex_csrf'=>'test-csrf']);
        $errors = [];
        require $root . '/includes/rtex_load_actions.php';
        check($rtexLoadForm['ticket_number'] === 'FIX-ME' && count($errors) === 1, 'failed save retains form input');
    } finally {
        $db->query('DROP DATABASE ' . $database);
        $db->close();
    }
}
echo "PASS: {$checks} RTEX checks\n";

if (in_array('--ui', $argv, true)) {
    import_test_functions($root . '/upload.php',['render_vendor_broker_fee_form']);
    if (!function_exists('business_week_end')) import_test_functions($root . '/upload.php',['business_week_end']);
    $_SESSION = ['rtex_csrf'=>'ui-test'];
    $todayDate = '2026-09-10';
    $rtexReviewWeekStart = '2026-09-06';
    $rtexJobRates = [
        ['id'=>1,'job_name'=>'Fernleaf','rate_basis'=>'tonnage','rate'=>'12.00','work_order'=>'EK10524','driver_fsc_rate'=>15,'invoice_fsc_rate'=>25],
        ['id'=>2,'job_name'=>'Haul Route','rate_basis'=>'mileage','rate'=>'3.25','work_order'=>'','driver_fsc_rate'=>5,'invoice_fsc_rate'=>10],
    ];
    $driverOptions = [['id'=>1,'name'=>'First Driver'],['id'=>2,'name'=>'Second Driver']];
    $rtexLoadForm = null;
    $rtexLoadWarnings = [];
    $rtexReviewRows = [array_merge($invoiceRow,['id'=>1,'job_rate_id'=>1,'matched_contact_id'=>1,'matched_driver_name'=>'First Driver','source_file_name'=>'test.JPG'])];
    ob_start();
    echo '<!doctype html><html><head><meta charset="utf-8"><title>RTEX UI Test</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="p-4"><h1>RTEX Load Invoicing</h1><pre id="rtex-ui-result">Testing</pre><div id="rtex-section" data-mode="load">';
    render_vendor_broker_fee_form(['vendor_scope'=>'rtex','label'=>'RTEX','fee_mode'=>'percentage','fee_value'=>10]);
    require $root . '/includes/rtex_load_form.php';
    require $root . '/includes/rtex_load_review.php';
    echo '</div><script>' . file_get_contents($root . '/includes/rtex_load_ui.js') . '</script>';
    echo <<<'JS'
<script>
try {
  let checks = 0;
  const ok = (condition, label) => { if (!condition) throw new Error(label); checks++; };
  const change = (input, value) => { input.value = value; input.dispatchEvent(new Event('change', {bubbles:true})); };
  const driverFsc = document.getElementById('rtexNewdriver_fsc_rate');
  const invoiceFsc = document.getElementById('rtexNewinvoice_fsc_rate');
  ok(Number(driverFsc.value) === 0 && Number(invoiceFsc.value) === 0, 'FSC fields default to zero');
  ok(driverFsc.form === invoiceFsc.form && driverFsc.form.querySelector('[name="job_name"]'), 'FSC settings share job form');
  ok(driverFsc.form.querySelector('[name="action"]').value === 'save_rtex_job_rate', 'combined settings save action');
  ok(document.querySelector('[form="rtexJob1"][name="driver_fsc_rate"]').value === '15', 'existing job FSC displayed');
  change(driverFsc, '15'); change(invoiceFsc, '25');
  ok(driverFsc.checkValidity() && invoiceFsc.checkValidity(), '15 and 25 percent accepted');
  change(invoiceFsc, '101');
  ok(!invoiceFsc.checkValidity(), 'FSC above 100 rejected by form');
  change(invoiceFsc, '25');
  ok(Math.abs(driverFsc.getBoundingClientRect().top - invoiceFsc.getBoundingClientRect().top) < 2, 'FSC controls on the same row');
  const add = document.querySelector('#rtexLoadEntryModal .rtex-load-fields');
  ok(!add.querySelector('[name=provider_name], [name=customer_name], [name=job_number], [name=product_name], [name=miles]'), 'unneeded manual fields removed');
  change(add.querySelector('[name="job_rate_id"]'), '2');
  change(add.querySelector('[name="quantity"]'), '100');
  add.querySelector('[name="quantity"]').dispatchEvent(new Event('input'));
  ok(add.querySelector('.rtex-total').value === '$325.00', 'mileage live total');
  ok(add.querySelector('[name="quantity"]').required && add.querySelector('.rtex-quantity-label').textContent === 'Quantity (miles)', 'mileage required state');
  change(add.querySelector('[name="job_rate_id"]'), '1');
  change(add.querySelector('[name="quantity"]'), '22.66');
  add.querySelector('[name="quantity"]').dispatchEvent(new Event('input'));
  ok(add.querySelector('.rtex-total').value === '$271.92', 'tonnage live total');
  ok(add.querySelector('[name="quantity"]').required && add.querySelector('.rtex-quantity-label').textContent === 'Quantity (net US tons)', 'tonnage required state');
  const saved = document.querySelector('#rtexLoadEdit1 .rtex-load-fields');
  ok(saved.querySelector('.rtex-rate').value === '9.00', 'historical rate displayed');
  const reprice = saved.querySelector('[name="apply_current_rate"]');
  reprice.checked = true; reprice.dispatchEvent(new Event('change'));
  ok(saved.querySelector('.rtex-rate').value === '12.00', 'explicit reprice preview');
  reprice.checked = false; reprice.dispatchEvent(new Event('change'));
  ok(saved.querySelector('.rtex-rate').value === '9.00', 'saved rate restored');
  const all = document.getElementById('rtexSelectAllLoads');
  all.checked = true; all.dispatchEvent(new Event('change'));
  ok(document.querySelector('.rtex-load-checkbox').checked, 'bulk selection');
  const ids = [...document.querySelectorAll('[id]')].map(el => el.id);
  ok(new Set(ids).size === ids.length, 'unique DOM IDs');
  ok([...document.querySelectorAll('#rtex-section form')].every(form => form.querySelector('[name="rtex_mode"]')), 'mode retained on all forms');
  ok([...document.querySelectorAll('[form]')].every(input => input.form), 'form-associated controls connected');
  document.getElementById('rtex-ui-result').textContent = 'PASS: ' + checks + ' browser UI checks';
} catch (error) {
  document.getElementById('rtex-ui-result').textContent = 'FAIL: ' + error.message;
}
</script></body></html>
JS;
    $html = ob_get_clean();
    $path = sys_get_temp_dir() . '/lonestar-rtex-ui.html';
    file_put_contents($path,$html);
    echo "UI fixture: {$path}\n";
}
