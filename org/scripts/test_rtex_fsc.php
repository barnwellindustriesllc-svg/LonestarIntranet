<?php
// Extends the RTEX regression suite. --database uses only the disposable localhost test server.
require __DIR__ . '/test_rtex_loads.php';
import_test_functions($root . '/reports.php',['rtex_append_fsc_statement_rows']);
$initialChecks = $checks;
check(rtex_fsc_amount(1000,15) === 150.0, '15 percent driver FSC');
check(rtex_fsc_amount(1000,25) === 250.0, '25 percent invoice FSC');
check(rtex_fsc_amount(203.94,15) === 30.59, 'FSC rounds separately for each load');
check(rtex_fsc_week('2026-09-10') === '2026-09-06', 'FSC uses Sunday work week');
rejects(static fn()=>rtex_fsc_percent(-1),'negative FSC rejected');
rejects(static fn()=>rtex_fsc_percent(101),'FSC above 100 rejected');
rejects(static fn()=>rtex_fsc_percent('not-a-rate'),'invalid FSC rejected');
rejects(static fn()=>rtex_fsc_week('2026-02-30'),'invalid FSC date rejected');

function fsc_invoice_sheet(array $rows, float $rate): SimpleXMLElement {
    $file = tempnam(sys_get_temp_dir(),'rtex_fsc_invoice_');
    try {
        file_put_contents($file,build_rtex_load_invoice_xlsx($rows,$rate));
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) throw new RuntimeException('Invalid invoice ZIP');
        $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        $xml->registerXPathNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        return $xml;
    } finally { unlink($file); }
}
function fsc_cell(SimpleXMLElement $sheet,string $cell,string $field='v'): string {
    return (string)$sheet->xpath('//s:c[@r="' . $cell . '"]/s:' . $field)[0];
}
$example = array_merge($invoiceRow,['ticket_number'=>'FSC1000','tons'=>100,'rate'=>10,'total_amount'=>1000]);
$sheet = fsc_invoice_sheet([$example],25);
check(fsc_cell($sheet,'I12') === '1000','invoice base is unchanged');
check(fsc_cell($sheet,'K12') === '25','invoice rate is 25 percent');
check(fsc_cell($sheet,'L12') === '250','invoice FSC is 250');
check(fsc_cell($sheet,'M12') === '1250','invoice line total includes only invoice FSC');
check(fsc_cell($sheet,'L12','f') === 'ROUND(I12*K12/100,2)','invoice FSC formula uses base freight');
check(fsc_cell($sheet,'M13') === '1250','invoice grand total includes FSC');
check((string)$sheet->xpath('//s:c[@r="J7"]/s:is/s:t')[0] === 'LS20260906-1','invoice number follows example format');
check((string)$sheet->xpath('//s:c[@r="B9"]/s:is/s:t')[0] === 'Fernleaf','invoice job comes from current export');
check(count($sheet->xpath('//s:mergeCell[@ref="E3:G5"]')) === 1,'company address merge preserved');
$manyRows = array_fill(0,25,$example);
$manySheet = fsc_invoice_sheet($manyRows,25);
check(fsc_cell($manySheet,'M37') === '31250' && fsc_cell($manySheet,'M37','f') === 'SUM(M12:M36)',
    'template expands to every load and totals the complete range');
$zeroSheet = fsc_invoice_sheet([$example],0);
check(fsc_cell($zeroSheet,'L12') === '0' && fsc_cell($zeroSheet,'M12') === '1000','zero FSC leaves invoice base unchanged');

if (in_array('--database',$argv,true)) {
    import_test_functions($root . '/includes/payout_net_helpers.php',[
        'lonestar_vendor_broker_fee_defaults','lonestar_vendor_broker_fees_ensure_table',
        'lonestar_vendor_broker_fee_settings','lonestar_payout_normalize_vendor_scope',
        'lonestar_vendor_broker_fee_amount','lonestar_payout_table_exists','lonestar_payout_column_exists',
        'lonestar_rtex_driver_week_hours_total','lonestar_driver_week_net_breakdown',
        'lonestar_driver_vendor_pre_fuel_balance','lonestar_driver_vendor_pre_insurance_balance',
        'lonestar_driver_week_allocated_fuel','lonestar_driver_week_total_fuel',
        'lonestar_nextier_week_bonus_total','lonestar_driver_nextier_week_fuel_surcharge_total',
    ]);
    // Exercise real fuel reads/allocation; isolate insurance, prior balances, and other vendors.
    function lonestar_driver_week_misc_adjustment_total(...$args): float { return 0.0; }
    function lonestar_driver_week_allocated_insurance(...$args): array { return ['insurance'=>0.0]; }
    function lonestar_driver_open_fuel_balance(...$args): float { return 0.0; }
    function lonestar_payout_vendor_order(): array { return $GLOBALS['test_vendor_order'] ?? ['rtex']; }
    require $root . '/includes/audit.php';

    $db = new mysqli('127.0.0.1','root','lonestar-local-test-only','',33079);
    $database = 'rtex_fsc_test_' . bin2hex(random_bytes(5));
    $db->query('CREATE DATABASE ' . $database);
    $db->select_db($database);
    try {
        $db->query("CREATE TABLE driver_contacts (id INT PRIMARY KEY,first_name VARCHAR(80),last_name VARCHAR(80)) ENGINE=InnoDB");
        $db->query("INSERT INTO driver_contacts VALUES (1,'FSC','Driver')");
        $db->query("CREATE TABLE driver_gas_costs (driver_id INT,cost_date DATE,amount DECIMAL(12,2)) ENGINE=InnoDB");
        $db->query("CREATE TABLE driver_insurance_costs (driver_id INT PRIMARY KEY,amount DECIMAL(12,2)) ENGINE=InnoDB");
        $db->query("CREATE TABLE driver_payouts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,payout_date DATE NOT NULL,
            ticket_number VARCHAR(80) NOT NULL,driver_name VARCHAR(255) NOT NULL,vendor_name VARCHAR(80) NOT NULL,
            tss_pay DECIMAL(12,2),upload_date DATE NOT NULL,driver_contact_id INT,
            UNIQUE KEY uniq_ticket_date_driver_vendor(ticket_number,payout_date,vendor_name,driver_name)) ENGINE=InnoDB");
        check(rtex_fsc_settings($db,'2026-09-06') === ['driver_fsc_rate'=>0.0,'invoice_fsc_rate'=>0.0],'missing settings table safely defaults to zero');
        ensure_rtex_payout_rows_table($db);
        ensure_rtex_load_schema($db);
        ensure_rtex_load_schema($db);
        check(rtex_fsc_settings($db,'2026-09-06') === ['driver_fsc_rate'=>0.0,'invoice_fsc_rate'=>0.0],'new settings default to zero');
        $db->query("INSERT INTO rtex_job_rates(job_name,rate_basis,rate) VALUES ('FSC Job','tonnage',10)");
        $input = array_merge($parsed,['ticket_number'=>'FSC1000','job_rate_id'=>1,'matched_contact_id'=>1,'tons'=>100]);
        $id = rtex_save_load($db,$input);
        lonestar_vendor_broker_fees_ensure_table($db);

        $mysqli = $db;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION = ['rtex_csrf'=>'fsc-test-token'];
        $_GET = [];
        $_POST = ['action'=>'save_rtex_load_settings','rtex_csrf'=>'fsc-test-token','rtex_week_start'=>'2026-09-06',
            'fee_mode'=>'percentage','fee_value'=>'10','driver_fsc_rate'=>'15','invoice_fsc_rate'=>'25'];
        $errors = [];
        $success = false;
        require $root . '/includes/rtex_load_actions.php';
        check($success && !$errors,'settings POST saves both rates and broker fee');
        check(rtex_fsc_settings($db,'2026-09-06') === ['driver_fsc_rate'=>15.0,'invoice_fsc_rate'=>25.0],'independent rates persisted');
        check(rtex_fsc_settings($db,'2026-09-13') === ['driver_fsc_rate'=>0.0,'invoice_fsc_rate'=>0.0],'new week starts at zero');
        check((int)$db->query("SELECT COUNT(*) FROM change_logs WHERE entity_type='upload_rtex_fsc_settings'")->fetch_row()[0] === 1,'FSC changes are audited');

        $actualRows = $db->query('SELECT * FROM driver_payouts')->fetch_all(MYSQLI_ASSOC);
        $details = rtex_driver_statement_fsc($db,$actualRows);
        check($details['total'] === 150.0 && $details['broker_fee'] === 100.0,'driver FSC excluded from brokerage');
        check($details['load_gross'] === 1000.0 && count($details['lines']) === 1,'one FSC line per load');
        check($details['lines'][0]['label'] === 'Driver FSC (15%)','driver line identifies own rate');
        check((float)$actualRows[0]['tss_pay'] === 1000.0,'stored payout base excludes both FSC amounts');
        check(lonestar_driver_rtex_week_fuel_surcharge_total($db,1,'2026-09-06','2026-09-12') === 150.0,'shared weekly driver FSC');
        $net = lonestar_driver_week_net_breakdown($db,1,1000,0,'2026-09-06','2026-09-12','rtex');
        check($net['broker_amt'] === 100.0 && $net['fuel_surcharge_total'] === 150.0 && $net['net_total'] === 1050.0,'actual net breakdown: 1000 - 100 + 150 = 1050');
        check(lonestar_driver_vendor_pre_fuel_balance($db,1,'2026-09-06','2026-09-12','rtex',0.1,0,1000,0) === 1050.0,'FSC included in pre-fuel balance');
        check(lonestar_driver_vendor_pre_insurance_balance($db,1,'2026-09-06','2026-09-12','rtex',0.1,1000,0) === 1050.0,'FSC included in pre-insurance balance');
        $displayRows = [];
        $pdfRows = [];
        rtex_append_fsc_statement_rows($displayRows,$details,$pdfRows);
        check($displayRows[0] === ['2026-09-10','FSC1000','Driver FSC (15%)','','','$150.00'],'CSV/XLSX separate FSC row');
        check($pdfRows[0]['pay'] === '$150.00' && $pdfRows[0]['ticket'] === 'FSC1000','PDF separate FSC row');
        $sheet = fsc_invoice_sheet([rtex_load_row($db,$id)],rtex_fsc_settings($db,'2026-09-06')['invoice_fsc_rate']);
        check(fsc_cell($sheet,'M12') === '1250','saved invoice settings used for export');

        rtex_save_fsc_settings($db,'2026-09-06',15,40);
        check(rtex_driver_statement_fsc($db,$actualRows)['total'] === 150.0,'invoice rate changes do not affect driver FSC');
        check(lonestar_driver_week_net_breakdown($db,1,1000,0,'2026-09-06','2026-09-12','rtex')['net_total'] === 1050.0,'invoice FSC never enters driver net pay');
        rtex_save_fsc_settings($db,'2026-09-13',20,30);
        check(rtex_driver_statement_fsc($db,$actualRows)['total'] === 150.0,'off-cycle rows use their original work-week rate');
        check(rtex_fsc_settings($db,'2026-09-06')['driver_fsc_rate'] === 15.0,'later-week rate does not rewrite earlier week');

        $db->query("INSERT INTO rtex_payout_rows(upload_date,sheet_name,source_line_no,work_date,ticket_number,hours,rate,total_amount,matched_contact_id)
            VALUES('2026-09-10','Hourly',1,'2026-09-10','HOUR',8,100,800,1)");
        $hourlyRows = [['payout_date'=>'2026-09-10','ticket_number'=>'HOUR','tss_pay'=>800]];
        check(rtex_driver_statement_fsc($db,$hourlyRows)['total'] === 0.0,'hourly work receives no load FSC');
        check(lonestar_driver_rtex_week_fuel_surcharge_total($db,1,'2026-09-06','2026-09-12') === 150.0,'hourly rows excluded from weekly FSC');

        $_POST['invoice_fsc_rate'] = '101';
        $_POST['fee_value'] = '99';
        $errors = [];
        $success = false;
        require $root . '/includes/rtex_load_actions.php';
        check(!$success && count($errors) === 1,'invalid FSC settings rejected');
        check(lonestar_vendor_broker_fee_settings($db,'rtex')['fee_value'] === 10.0,'invalid FSC does not partially change broker fee');

        $input['tons'] = 200;
        rtex_save_load($db,$input,$id);
        check(lonestar_driver_rtex_week_fuel_surcharge_total($db,1,'2026-09-06','2026-09-12') === 300.0,'edited load quantity updates driver FSC');
        rtex_delete_loads($db,[$id]);
        check(lonestar_driver_rtex_week_fuel_surcharge_total($db,1,'2026-09-06','2026-09-12') === 0.0,'deleted load no longer earns FSC');
        $db->query("INSERT INTO driver_contacts VALUES (99,'Fuel','Driver')");
        $fuelInput = array_merge($input,['ticket_number'=>'FUEL-CHECK','matched_contact_id'=>99,'tons'=>100]);
        rtex_save_load($db,$fuelInput);
        rtex_save_fsc_settings($db,'2026-09-06',15,25);
        $db->query("UPDATE vendor_broker_fees SET fee_mode='percentage',fee_value=10 WHERE vendor_scope='rtex'");
        $db->query("INSERT INTO driver_gas_costs VALUES (99,'2026-09-11',200)");
        $fuelNet = lonestar_driver_week_net_breakdown($db,99,1000,0,'2026-09-06','2026-09-12','rtex');
        check($fuelNet['fuel'] === 200.0 && $fuelNet['net_total'] === 850.0,
            'actual fuel-card deduction: 1000 - 100 broker + 150 driver FSC - 200 fuel = 850');
        // Execute the owner page's RTEX branch with actual load/FSC data.
        $ownerSource = file_get_contents($root . '/owner_payout_report.php');
        $ownerStart = strpos($ownerSource, '$rtexDetails = [');
        $ownerEnd = strpos($ownerSource, "\n    }", $ownerStart);
        if ($ownerStart === false || $ownerEnd === false) throw new RuntimeException('Owner RTEX branch not found');
        $mysqli = $db;
        $dataRows = $db->query('SELECT * FROM driver_payouts WHERE driver_contact_id=99')->fetch_all(MYSQLI_ASSOC);
        $totalGross = 1000.0;
        $insurance = 50.0;
        $netBreakdown = $fuelNet;
        $netBreakdown['misc_adjustment_total'] = -25.0;
        $netBreakdown['net_total'] = $fuelNet['net_total'] - $insurance - 25.0;
        eval(substr($ownerSource,$ownerStart,$ownerEnd-$ownerStart));
        check($net === 775.0 && $brokerageFee === 100.0 && $driverFuelSurchargeTotal === 150.0,
            'owner RTEX net includes broker, FSC, insurance, fuel and misc: 1000-100+150-50-200-25');
        $GLOBALS['test_vendor_order'] = ['nextier'];
        $db->query("INSERT INTO driver_contacts VALUES (100,'Bonus','Driver')");
        $db->query("CREATE TABLE nextier_payout_rows (matched_contact_id INT,work_date DATE,bonus DECIMAL(12,2),fsc_total DECIMAL(12,2))");
        $db->query("INSERT INTO nextier_payout_rows VALUES (100,'2026-09-10',200,0)");
        $db->query("UPDATE vendor_broker_fees SET fee_mode='percentage',fee_value=10 WHERE vendor_scope='nextier'");
        $bonusNet = lonestar_driver_week_net_breakdown($db,100,1200,0,'2026-09-06','2026-09-12','nextier');
        check($bonusNet['broker_amt'] === 100.0 && $bonusNet['net_total'] === 1100.0,
            'NexTier 200 bonus remains in pay but is exempt from 10 percent brokerage');
        $subsetNet = lonestar_driver_week_net_breakdown($db,100,600,0,'2026-09-06','2026-09-12','nextier',100);
        check($subsetNet['broker_amt'] === 50.0 && $subsetNet['net_total'] === 550.0,
            'statement bonus override uses only bonuses included in selected rows');
        check(lonestar_driver_vendor_pre_fuel_balance($db,100,'2026-09-06','2026-09-12','nextier',0.1,0,1200,0) === 1100.0,
            'bonus exemption also applies before fuel allocation');
        unset($GLOBALS['test_vendor_order']);
    } finally {
        $db->query('DROP DATABASE ' . $database);
        $db->close();
    }
}
echo 'PASS: ' . ($checks - $initialChecks) . " FSC checks\n";
