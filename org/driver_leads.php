<?php
// driver_leads.php
// Enable error reporting
//ini_set('display_errors',1);
//ini_set('display_startup_errors',1);
//error_reporting(E_ALL);

require __DIR__ . '/includes/auth.php';
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

function driver_lead_intake_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $host ? $scheme . '://' . $host . ($path === '' ? '' : $path) . '/driver_lead_intake.php' : 'driver_lead_intake.php';
}

function yes_no_na($value): string {
    if ($value === null || $value === '') {
        return 'N/A';
    }
    return (int)$value === 1 ? 'Yes' : 'No';
}

function driver_lead_step_state(array $row, string $step): array {
    $status = $row['onboarding_status'] ?? 'manual';
    $states = [
        'recruiting' => [
            'enabled' => in_array($status, ['submitted', 'manual'], true) && empty($row['recruiting_email_sent_at']),
            'done' => !empty($row['recruiting_email_sent_at']) || in_array($status, ['recruiting_sent', 'onboarding_sent', 'complete'], true),
        ],
        'onboarding' => [
            'enabled' => $status === 'recruiting_sent' && empty($row['onboarding_email_sent_at']),
            'done' => !empty($row['onboarding_email_sent_at']) || in_array($status, ['onboarding_sent', 'complete'], true),
        ],
        'complete' => [
            'enabled' => $status === 'onboarding_sent' && empty($row['completed_at']),
            'done' => !empty($row['completed_at']) || $status === 'complete',
        ],
    ];
    return $states[$step] ?? ['enabled' => false, 'done' => false];
}

ensure_driver_leads_onboarding_columns($mysqli);

function pdf_escape_text(string $text): string {
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace('(', '\\(', $text);
    $text = str_replace(')', '\\)', $text);
    return $text;
}

function build_leads_pdf(array $rows): string {
    $lines = [];
    $header = [
        'First Name', 'Last Name', 'Owner Name', 'Owner Operator', 'DOT Number', 'Email', 'Phone', 'Type',
        'Has Insurance', 'Has Trailer', 'Onboarding Status', 'Comments', 'Created'
    ];
    $lines[] = implode(' | ', $header);
    foreach ($rows as $row) {
        $lines[] = implode(' | ', [
            $row['first_name'] ?? '',
            $row['last_name'] ?? '',
            $row['owner_name'] ?? '',
            !empty($row['is_owner_operator']) ? 'Yes' : 'No',
            $row['dot_number'] ?? '',
            $row['email'] ?? '',
            $row['phone'] ?? '',
            $row['lead_type'] ?? '',
            yes_no_na($row['broker_has_insurance'] ?? null),
            yes_no_na($row['broker_has_trailer'] ?? null),
            $row['onboarding_status'] ?? '',
            $row['lead_comments'] ?? '',
            $row['created_at'] ?? '',
        ]);
    }

    $pages = [];
    $pageLines = [];
    $maxLines = 48;
    foreach ($lines as $line) {
        if (count($pageLines) >= $maxLines) {
            $pages[] = $pageLines;
            $pageLines = [];
        }
        $pageLines[] = $line;
    }
    if ($pageLines) {
        $pages[] = $pageLines;
    }

    $offsets = [];
    $pdf = "%PDF-1.4\n";

    $addObject = function (string $content) use (&$pdf, &$offsets) {
        $offsets[] = strlen($pdf);
        $pdf .= $content . "\n";
    };

    $addObject("1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj");
    $kids = [];
    $pageObjStart = 4;
    $contentObjStart = 5;
    for ($i = 0; $i < count($pages); $i++) {
        $pageObj = $pageObjStart + ($i * 2);
        $contentObj = $contentObjStart + ($i * 2);
        $kids[] = $pageObj . " 0 R";
        $addObject($pageObj . " 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents " . $contentObj . " 0 R >>\nendobj");

        $stream = "BT /F1 10 Tf 40 760 Td\n";
        foreach ($pages[$i] as $line) {
            $trimmed = substr($line, 0, 220);
            $stream .= "(" . pdf_escape_text($trimmed) . ") Tj\n0 -14 Td\n";
        }
        $stream .= "ET";
        $streamLen = strlen($stream);
        $addObject($contentObj . " 0 obj\n<< /Length {$streamLen} >>\nstream\n{$stream}\nendstream\nendobj");
    }
    $addObject("2 0 obj\n<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . count($kids) . " >>\nendobj");
    $addObject("3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj");

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($offsets) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= str_pad((string)$offset, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . (count($offsets) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n{$xrefOffset}\n%%EOF";
    return $pdf;
}

// AJAX for cost lookups
if (isset($_GET['ajax']) && in_array($_GET['ajax'], ['gas', 'insurance'], true)) {
    $driver_id = intval($_GET['driver_id'] ?? 0);
    $date = $_GET['date'] ?? '';
    header('Content-Type: application/json');
    if ($_GET['ajax'] === 'gas') {
        if ($driver_id && $date) {
            $stmt = $mysqli->prepare(
                "SELECT amount FROM driver_gas_costs WHERE driver_id=? AND cost_date=?"
            );
            $stmt->bind_param('is', $driver_id, $date);
            $stmt->execute();
            $stmt->bind_result($amount);
            echo json_encode(['amount' => $stmt->fetch() ? $amount : '']);
            $stmt->close();
        } else {
            echo json_encode(['amount' => '']);
        }
    } else {
        if ($driver_id) {
            $stmt = $mysqli->prepare(
                "SELECT amount FROM driver_insurance_costs WHERE driver_id=?"
            );
            $stmt->bind_param('i', $driver_id);
            $stmt->execute();
            $stmt->bind_result($amount);
            echo json_encode(['amount' => $stmt->fetch() ? $amount : '']);
            $stmt->close();
        } else {
            echo json_encode(['amount' => '']);
        }
    }
    exit;
}

// --- Helpers: server-side sort mapping ---
$allowedSorts = [
  'driver_name'       => 'driver_name',       // maps to last_name, first_name
  'owner_name'        => 'owner_name',
  'email'             => 'email',
  'phone'             => 'phone',
];
$allowedDirs = ['asc','desc'];

// Read sort params (GET)
$sort = $_GET['sort'] ?? 'driver_name';
$dir  = strtolower($_GET['dir'] ?? 'asc');
if (!isset($allowedSorts[$sort])) $sort = 'driver_name';
if (!in_array($dir, $allowedDirs, true)) $dir = 'asc';
$dirSql = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';

// Build ORDER BY SQL safely via whitelist
// Handle each sort key; push NULLs last where sensible.
switch ($sort) {
  case 'driver_name':
    // Sort by last_name, then first_name
    $orderBy = "last_name $dirSql, first_name $dirSql";
    break;
  default:
    // Generic: keep NULLs last
    $col = $allowedSorts[$sort];
    $orderBy = "$col IS NULL, $col $dirSql";
}

// Initialize form vars
$errors = [];
$gasErrors = [];
$success = false;
$successGas = false;
$successIns = false;
$successMessage = '';
$openGasModal = false;
$openInsModal = false;
$openContactLeadsModal = false;
$openReportModal = false;
$contactSubmitted = false;
$gas_driver_id = '';
$ins_driver_id = '';

$first = $last = $owner = $email = $phone = '';
$is_owner_operator = 0;
$dot_number = '';
$lead_type = '';
$lead_comments = '';
$broker_has_insurance = 0;
$broker_has_trailer = 0;
$percent = ''; // UI string ('' or '12.34'); use $percent_val internally
$id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $deleteId = intval($_POST['id'] ?? 0);
    if ($deleteId > 0) {
        $stmt = $mysqli->prepare('DELETE FROM driver_leads WHERE id=?');
        $stmt->bind_param('i', $deleteId);
        $stmt->execute();
        $stmt->close();
        $successMessage = 'Lead deleted.';
    } else {
        $errors[] = 'Invalid lead selected for deletion.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'onboarding_step') {
    $leadId = intval($_POST['lead_id'] ?? 0);
    $step = $_POST['step'] ?? '';
    $allowedSteps = ['recruiting', 'onboarding', 'complete'];

    if ($leadId <= 0 || !in_array($step, $allowedSteps, true)) {
        $errors[] = 'Invalid onboarding action.';
    } else {
        $stmt = $mysqli->prepare(
            'SELECT onboarding_status, recruiting_email_sent_at, onboarding_email_sent_at, completed_at FROM driver_leads WHERE id=?'
        );
        $stmt->bind_param('i', $leadId);
        $stmt->execute();
        $stmt->bind_result($status, $recruitingSentAt, $onboardingSentAt, $completedAt);
        $lead = $stmt->fetch() ? [
            'onboarding_status' => $status,
            'recruiting_email_sent_at' => $recruitingSentAt,
            'onboarding_email_sent_at' => $onboardingSentAt,
            'completed_at' => $completedAt,
        ] : null;
        $stmt->close();

        if (!$lead) {
            $errors[] = 'Lead was not found.';
        } else {
            $state = driver_lead_step_state($lead, $step);
            if (empty($state['enabled'])) {
                $errors[] = 'That onboarding step is not ready yet.';
            } elseif ($step === 'recruiting') {
                $stmt = $mysqli->prepare(
                    "UPDATE driver_leads
                        SET onboarding_status='recruiting_sent',
                            recruiting_email_sent_at=NOW(),
                            updated_at=NOW()
                      WHERE id=?"
                );
                $stmt->bind_param('i', $leadId);
                $stmt->execute();
                $stmt->close();
                $successMessage = 'Recruiting email step completed.';
            } elseif ($step === 'onboarding') {
                $stmt = $mysqli->prepare(
                    "UPDATE driver_leads
                        SET onboarding_status='onboarding_sent',
                            onboarding_email_sent_at=NOW(),
                            updated_at=NOW()
                      WHERE id=?"
                );
                $stmt->bind_param('i', $leadId);
                $stmt->execute();
                $stmt->close();
                $successMessage = 'Onboarding email step completed.';
            } else {
                $stmt = $mysqli->prepare(
                    "UPDATE driver_leads
                        SET onboarding_status='complete',
                            completed_at=NOW(),
                            updated_at=NOW()
                      WHERE id=?"
                );
                $stmt->bind_param('i', $leadId);
                $stmt->execute();
                $stmt->close();
                $successMessage = 'Driver onboarding marked complete.';
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'gas') {
    $driver_id  = $_POST['driver_id'] ?? '';
    $gas_driver_id = $driver_id;
    $dates      = $_POST['dates'] ?? [];
    $amounts    = $_POST['amounts'] ?? [];

    if (!$driver_id) {
        $gasErrors[] = 'Select a driver for gas costs.';
    } else {
        for ($i = 0; $i < 7; $i++) {
            if (!empty($dates[$i])) {
                $d = $dates[$i];
                $a = floatval($amounts[$i]);
                $stmt = $mysqli->prepare(
                    "SELECT id FROM driver_gas_costs WHERE driver_id=? AND cost_date=?"
                );
                $stmt->bind_param('is', $driver_id, $d);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows) {
                    $u = $mysqli->prepare(
                        "UPDATE driver_gas_costs SET amount=?, updated_at=NOW() WHERE driver_id=? AND cost_date=?"
                    );
                    $u->bind_param('dis', $a, $driver_id, $d);
                    $u->execute();
                    $u->close();
                } else {
                    $i2 = $mysqli->prepare(
                        "INSERT INTO driver_gas_costs (driver_id, cost_date, amount) VALUES (?, ?, ?)"
                    );
                    $i2->bind_param('isd', $driver_id, $d, $a);
                    $i2->execute();
                    $i2->close();
                }
                $stmt->close();
            }
        }
        $successGas = true;
    }
    $openGasModal = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'insurance') {
    $ins_driver_id = $_POST['ins_driver_id'] ?? '';
    $ins_amt = $_POST['ins_amount'] ?? '';

    if (!$ins_driver_id) {
        $gasErrors[] = 'Select a driver for insurance.';
    } else {
        $a = floatval($ins_amt);
        $stmt = $mysqli->prepare(
            "SELECT id FROM driver_insurance_costs WHERE driver_id=?"
        );
        $stmt->bind_param('i', $ins_driver_id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows) {
            $u = $mysqli->prepare(
                "UPDATE driver_insurance_costs SET amount=?, updated_at=NOW() WHERE driver_id=?"
            );
            $u->bind_param('di', $a, $ins_driver_id);
            $u->execute();
            $u->close();
        } else {
            $i2 = $mysqli->prepare(
                "INSERT INTO driver_insurance_costs (driver_id, amount) VALUES (?, ?)"
            );
            $i2->bind_param('id', $ins_driver_id, $a);
            $i2->execute();
            $i2->close();
        }
        $stmt->close();
        $successIns = true;
    }
    $openInsModal = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'contact_leads') {
    $selectedIds = array_filter(array_map('intval', explode(',', $_POST['lead_ids'] ?? '')));
    $channels = $_POST['channels'] ?? [];
    $message = trim($_POST['message'] ?? '');
    $smsMessage = trim($_POST['sms_message'] ?? '');

    if (!$selectedIds) {
        $errors[] = 'Select at least one lead to contact.';
    } elseif (empty($channels)) {
        $errors[] = 'Select at least one contact method.';
    }

    if (empty($errors)) {
        $mailchimpKey = defined('MAILCHIMP_API_KEY') ? MAILCHIMP_API_KEY : '';
        $mailchimpServer = defined('MAILCHIMP_SERVER_PREFIX') ? MAILCHIMP_SERVER_PREFIX : '';
        $mailchimpSmsKey = defined('MAILCHIMP_SMS_API_KEY') ? MAILCHIMP_SMS_API_KEY : '';

        if (in_array('email', $channels, true) && ($mailchimpKey === '' || $mailchimpServer === '')) {
            $errors[] = 'Mailchimp email credentials are not configured.';
        }
        if (in_array('sms', $channels, true) && $mailchimpSmsKey === '') {
            $errors[] = 'Mailchimp SMS credentials are not configured.';
        }
    }

    $openContactLeadsModal = true;
    if (empty($errors)) {
        $successMessage = 'Contact request prepared. API send is not yet configured.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'lead_report') {
    $format = $_POST['export_format'] ?? 'excel';
    $rows = [];
    $resReport = $mysqli->query(
        "SELECT first_name, last_name, owner_name, is_owner_operator, dot_number, email, phone, lead_type, broker_has_insurance, broker_has_trailer, onboarding_status, lead_comments, created_at
           FROM driver_leads
       ORDER BY last_name, first_name"
    );
    while ($row = $resReport->fetch_assoc()) {
        $rows[] = $row;
    }

    if ($format === 'pdf') {
        $pdf = build_leads_pdf($rows);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="leads_report_' . date('Ymd') . '.pdf"');
        echo $pdf;
        exit;
    }

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="leads_report_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['First Name','Last Name','Owner Name','Owner Operator','DOT Number','Email','Phone','Type','Has Insurance','Has Trailer','Onboarding Status','Comments','Created']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['first_name'] ?? '',
            $row['last_name'] ?? '',
            $row['owner_name'] ?? '',
            !empty($row['is_owner_operator']) ? 'Yes' : 'No',
            $row['dot_number'] ?? '',
            $row['email'] ?? '',
            $row['phone'] ?? '',
            $row['lead_type'] ?? '',
            yes_no_na($row['broker_has_insurance'] ?? null),
            yes_no_na($row['broker_has_trailer'] ?? null),
            $row['onboarding_status'] ?? '',
            $row['lead_comments'] ?? '',
            $row['created_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contactSubmitted = true;
    $first     = trim($_POST['first_name'] ?? '');
    $last      = trim($_POST['last_name'] ?? '');
    $owner     = trim($_POST['owner_name'] ?? '');
    $is_owner_operator = isset($_POST['is_owner_operator']) ? 1 : 0;
    $dot_number = trim($_POST['dot_number'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $lead_type = trim($_POST['lead_type'] ?? '');
    $lead_comments = trim($_POST['lead_comments'] ?? '');
    $broker_has_insurance = isset($_POST['broker_has_insurance']) ? 1 : 0;
    $broker_has_trailer = isset($_POST['broker_has_trailer']) ? 1 : 0;
    $id        = intval($_POST['id'] ?? 0);

    // Validate required fields
    if (!$first || !$last || !$email) {
        $errors[] = 'Driver first name, last name, and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }
    if ($lead_type !== '' && !in_array($lead_type, ['Leased', 'Brokered'], true)) {
        $errors[] = 'Type must be Leased or Brokered.';
    }
    if ($lead_type !== 'Brokered') {
        $broker_has_insurance = 0;
        $broker_has_trailer = 0;
    }

    if (empty($errors)) {
        if ($id) {
            $stmt = $mysqli->prepare(
                'UPDATE driver_leads 
                 SET first_name=?, last_name=?, owner_name=?, is_owner_operator=?, dot_number=?, email=?, phone=?, lead_type=?, lead_comments=?, broker_has_insurance=?, broker_has_trailer=?, updated_at=NOW()
                 WHERE id=?'
            );
            $stmt->bind_param(
                'sssisssssiii',
                $first,
                $last,
                $owner,
                $is_owner_operator,
                $dot_number,
                $email,
                $phone,
                $lead_type,
                $lead_comments,
                $broker_has_insurance,
                $broker_has_trailer,
                $id
            );
        } else {
            $stmt = $mysqli->prepare(
                'INSERT INTO driver_leads 
                 (first_name, last_name, owner_name, is_owner_operator, dot_number, email, phone, lead_type, lead_comments, broker_has_insurance, broker_has_trailer, onboarding_status, updated_at) 
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,"manual",NOW())'
            );
            $stmt->bind_param(
                'sssisssssii',
                $first,
                $last,
                $owner,
                $is_owner_operator,
                $dot_number,
                $email,
                $phone,
                $lead_type,
                $lead_comments,
                $broker_has_insurance,
                $broker_has_trailer
            );
        }

        if ($stmt->execute()) {
            $success = true;
            $successMessage = $id ? 'Contact updated.' : 'Contact added.';
        } else {
            $errors[] = $stmt->error;
        }
        $stmt->close();
    }
}

// If editing, load data
if ($id && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $mysqli->prepare('SELECT first_name,last_name,owner_name,is_owner_operator,dot_number,email,phone,lead_type,lead_comments,broker_has_insurance,broker_has_trailer FROM driver_leads WHERE id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->bind_result($first, $last, $owner, $is_owner_operator, $dot_number, $email, $phone, $lead_type, $lead_comments, $broker_has_insurance, $broker_has_trailer);
    $stmt->fetch();
    $stmt->close();
}

// Fetch contacts (server-side order)
$query = "SELECT id, first_name, last_name, owner_name, is_owner_operator, dot_number, email, phone, lead_type, broker_has_insurance, broker_has_trailer, onboarding_status, recruiting_email_sent_at, onboarding_email_sent_at, completed_at, lead_comments
          FROM driver_leads 
          ORDER BY $orderBy";
$res = $mysqli->query($query);

$drivers = [];
$resDrivers = $mysqli->query(
    "SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM driver_leads ORDER BY last_name, first_name"
);
while ($r = $resDrivers->fetch_assoc()) {
    $drivers[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Driver Leads Management Tool</title>
  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { margin:0; font-family:sans-serif; }
    .page-shell { display:flex; min-height: calc(100vh - var(--banner-h)); }
    .sidebar { width:250px; background:#333; color:#fff; height:calc(100vh - var(--banner-h)); position:fixed; top:var(--banner-h); overflow:auto; transition:transform .3s ease; }
    .sidebar.collapsed { transform: translateX(-250px); }
    .sidebar a { display:block; color:#fff; padding:15px; text-decoration:none; }
    .sidebar a:hover { background:#444; }
    .main { margin-left:250px; padding:20px; flex:1; min-width:0; }
    .sidebar.collapsed + .main { margin-left:0; }
    @media(max-width:768px) {
      .sidebar { transform:translateX(-250px); }
      .sidebar.open { transform: translateX(0); }
      .main { margin:0; }
    }

    /* Sortable table cues (client-side) */
    .sortable th { cursor: pointer; user-select: none; }
    .sortable th .sort-indicator { opacity: .4; margin-left:.35rem; }
    .sortable th.sorted-asc .sort-indicator::after { content: "▲"; }
    .sortable th.sorted-desc .sort-indicator::after { content: "▼"; }
    .onboarding-actions { min-width: 360px; }
    .onboarding-actions form { display:inline-block; margin:0 .25rem .25rem 0; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
    <h1>Driver Leads Management Tool</h1>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <strong>Errors:</strong>
        <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e ?? '', ENT_QUOTES) ?></li><?php endforeach; ?></ul>
      </div>
    <?php elseif ($success || $successMessage): ?>
      <div class="alert alert-success"><?= htmlspecialchars($successMessage ?: ($id ? 'Contact updated.' : 'Contact added.'), ENT_QUOTES) ?></div>
    <?php endif; ?>
    <?php if (!empty($gasErrors)): ?>
      <div class="alert alert-danger">
        <strong>Gas Cost Errors:</strong>
        <ul><?php foreach ($gasErrors as $e): ?><li><?= htmlspecialchars($e ?? '', ENT_QUOTES) ?></li><?php endforeach; ?></ul>
      </div>
    <?php elseif ($successGas): ?>
      <div class="alert alert-success">Gas costs saved.</div>
    <?php endif; ?>
    <?php if ($successIns): ?>
      <div class="alert alert-success">Insurance cost saved.</div>
    <?php endif; ?>

    <div class="d-flex flex-wrap gap-2 mb-4">
      <button type="button" class="btn btn-primary" id="addcontactbtn" data-bs-toggle="modal" data-bs-target="#addcontactModal">
        Add/Edit Lead Details
      </button>
      <a class="btn btn-outline-success" href="<?= h(driver_lead_intake_url()) ?>" target="_blank" rel="noopener">
        Driver Intake Form
      </a>
      <button type="button" class="btn btn-outline-primary" id="emailcontactbtn" data-bs-toggle="modal" data-bs-target="#contactLeadsModal">
        Contact Leads
      </button>
      <button type="button" class="btn btn-outline-primary" id="reportbtn" data-bs-toggle="modal" data-bs-target="#reporttModal">
        Leads Report Extract
      </button>
    </div>

    <h2>Existing Leads</h2>
    <!-- Search Field -->
    <div class="row g-2 align-items-end mb-3">
      <div class="col-auto">
        <label for="contactSearch" class="form-label mb-0">Search</label>
        <input type="text" id="contactSearch" class="form-control" placeholder="Search contacts...">
      </div>
    </div>
    <!-- Server-side sorting controls -->
    <form method="get" class="row g-2 align-items-end mb-3">
      <div class="col-auto">
        <label for="sort" class="form-label mb-0">Sort by</label>
        <select id="sort" name="sort" class="form-select">
          <option value="driver_name"       <?= $sort==='driver_name'?'selected':'' ?>>Driver Name</option>
          <option value="owner_name"        <?= $sort==='owner_name'?'selected':'' ?>>Owner Name</option>
          <option value="email"             <?= $sort==='email'?'selected':'' ?>>Email</option>
          <option value="phone"             <?= $sort==='phone'?'selected':'' ?>>Phone</option>
          <!--<option value="truck_no"          <?= $sort==='truck_no'?'selected':'' ?>>Truck No.</option>
          <option value="alt_truck_no"      <?= $sort==='alt_truck_no'?'selected':'' ?>>Alt Truck No.</option>
          <option value="trailer_no"        <?= $sort==='trailer_no'?'selected':'' ?>>Trailer No.</option>
          <option value="payout_percentage" <?= $sort==='payout_percentage'?'selected':'' ?>>Percentage</option>-->
        </select>
      </div>
      <div class="col-auto">
        <label for="dir" class="form-label mb-0">Direction</label>
        <select id="dir" name="dir" class="form-select">
          <option value="asc"  <?= $dir==='asc'?'selected':''  ?>>Ascending</option>
          <option value="desc" <?= $dir==='desc'?'selected':'' ?>>Descending</option>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-outline-primary" type="submit">Apply</button>
        <a class="btn btn-outline-secondary" href="driver_leads.php">Reset</a>
      </div>
    </form>
    

    <table class="table table-striped sortable" id="contactsTable">
      <thead>
        <tr>
          <th data-type="none"><input type="checkbox" id="selectAllLeads"></th>
          <th data-type="string">Driver Name <span class="sort-indicator"></span></th>
          <th data-type="string">Owner Name <span class="sort-indicator"></span></th>
          <th data-type="string">Owner Operator <span class="sort-indicator"></span></th>
          <th data-type="string">DOT Number <span class="sort-indicator"></span></th>
          <th data-type="string">Email <span class="sort-indicator"></span></th>
          <th data-type="string">Phone <span class="sort-indicator"></span></th>
          <th data-type="string">Type <span class="sort-indicator"></span></th>
          <th data-type="string">Has Insurance <span class="sort-indicator"></span></th>
          <th data-type="string">Has Trailer <span class="sort-indicator"></span></th>
          <th data-type="string">Status <span class="sort-indicator"></span></th>
          <th data-type="string">Comments <span class="sort-indicator"></span></th>
          <!--<th data-type="string">Truck No. <span class="sort-indicator"></span></th>
          <th data-type="string">Alt Truck No. <span class="sort-indicator"></span></th>
          <th data-type="string">Trailer No. <span class="sort-indicator"></span></th>
          <th data-type="number">Percentage <span class="sort-indicator"></span></th>-->
          <th data-type="none">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($row = $res->fetch_assoc()): ?>
          <tr>
            <td>
              <input
                type="checkbox"
                class="lead-select"
                value="<?= (int)$row['id'] ?>"
                data-name="<?= htmlspecialchars(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''), ENT_QUOTES) ?>"
                data-email="<?= htmlspecialchars($row['email'] ?? '', ENT_QUOTES) ?>"
                data-phone="<?= htmlspecialchars($row['phone'] ?? '', ENT_QUOTES) ?>"
              >
            </td>
            <td><?= htmlspecialchars(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''), ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($row['owner_name'] ?? '', ENT_QUOTES) ?></td>
            <td><?= !empty($row['is_owner_operator']) ? 'Yes' : 'No' ?></td>
            <td><?= htmlspecialchars($row['dot_number'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($row['email'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($row['phone'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($row['lead_type'] ?? '', ENT_QUOTES) ?></td>
            <td><?= h(yes_no_na($row['broker_has_insurance'] ?? null)) ?></td>
            <td><?= h(yes_no_na($row['broker_has_trailer'] ?? null)) ?></td>
            <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['onboarding_status'] ?? '')), ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($row['lead_comments'] ?? '', ENT_QUOTES) ?></td>
            <!--<td><?= htmlspecialchars($row['truck_no'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($row['alt_truck_no'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($row['trailer_no'] ?? '', ENT_QUOTES) ?></td>
            <td>
              <?php
                $p = $row['payout_percentage'];
                echo $p === null ? '' : htmlspecialchars(number_format((float)$p, 2), ENT_QUOTES) . '%';
              ?>
            </td>-->
            <td>
              <div class="d-flex flex-wrap gap-2 onboarding-actions">
                <?php
                  $recruitingState = driver_lead_step_state($row, 'recruiting');
                  $onboardingState = driver_lead_step_state($row, 'onboarding');
                  $completeState = driver_lead_step_state($row, 'complete');
                ?>
                <form method="post">
                  <input type="hidden" name="form_type" value="onboarding_step">
                  <input type="hidden" name="lead_id" value="<?= (int)$row['id'] ?>">
                  <input type="hidden" name="step" value="recruiting">
                  <button type="submit" class="btn btn-sm <?= !empty($recruitingState['done']) ? 'btn-success' : 'btn-outline-success' ?>" <?= empty($recruitingState['enabled']) ? 'disabled' : '' ?>>Recruiting Email</button>
                </form>
                <form method="post">
                  <input type="hidden" name="form_type" value="onboarding_step">
                  <input type="hidden" name="lead_id" value="<?= (int)$row['id'] ?>">
                  <input type="hidden" name="step" value="onboarding">
                  <button type="submit" class="btn btn-sm <?= !empty($onboardingState['done']) ? 'btn-success' : 'btn-outline-primary' ?>" <?= empty($onboardingState['enabled']) ? 'disabled' : '' ?>>Onboarding Email</button>
                </form>
                <form method="post">
                  <input type="hidden" name="form_type" value="onboarding_step">
                  <input type="hidden" name="lead_id" value="<?= (int)$row['id'] ?>">
                  <input type="hidden" name="step" value="complete">
                  <button type="submit" class="btn btn-sm <?= !empty($completeState['done']) ? 'btn-success' : 'btn-outline-dark' ?>" <?= empty($completeState['enabled']) ? 'disabled' : '' ?>>Complete</button>
                </form>
                <a href="driver_leads.php?id=<?= (int)$row['id']?>" class="btn btn-sm btn-outline-primary">Edit</a>
                <form method="post" class="m-0">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this contact?');">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
    </div>
  </div>

  <div class="modal fade" id="addcontactModal" tabindex="-1" aria-labelledby="contactModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="contactModalLabel"><?= $id ? 'Edit Lead Contact' : 'Add Lead Contact' ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form method="post" class="mb-0" id="contactForm">
            <input type="hidden" name="id" id="contactId" value="<?= (int)$id ?>">

            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">First Name</label>
                <input type="text" name="first_name" id="leadFirstName" class="form-control" value="<?= htmlspecialchars($first ?? '', ENT_QUOTES) ?>" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Last Name</label>
                <input type="text" name="last_name" id="leadLastName" class="form-control" value="<?= htmlspecialchars($last ?? '', ENT_QUOTES) ?>" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Owner Name</label>
                <input type="text" name="owner_name" id="leadOwnerName" class="form-control" value="<?= htmlspecialchars($owner ?? '', ENT_QUOTES) ?>">
              </div>

              <div class="col-md-4">
                <label class="form-label">Email</label>
                <input type="email" name="email" id="leadEmail" class="form-control" value="<?= htmlspecialchars($email ?? '', ENT_QUOTES) ?>" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" id="leadPhone" class="form-control" value="<?= htmlspecialchars($phone ?? '', ENT_QUOTES) ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">DOT Number</label>
                <input type="text" name="dot_number" id="leadDotNumber" class="form-control" value="<?= htmlspecialchars($dot_number ?? '', ENT_QUOTES) ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">Owner Operator</label>
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" name="is_owner_operator" id="leadIsOwnerOperator" <?= !empty($is_owner_operator) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="leadIsOwnerOperator">Driver is an owner operator</label>
                </div>
              </div>
              <div class="col-md-4">
                <label class="form-label">Type</label>
                <select name="lead_type" id="leadType" class="form-select">
                  <option value="">Select type</option>
                  <option value="Leased" <?= $lead_type === 'Leased' ? 'selected' : '' ?>>Leased</option>
                  <option value="Brokered" <?= $lead_type === 'Brokered' ? 'selected' : '' ?>>Brokered</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Broker Requirements</label>
                <div class="form-check">
                  <input class="form-check-input broker-only" type="checkbox" name="broker_has_insurance" id="brokerHasInsurance" <?= !empty($broker_has_insurance) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="brokerHasInsurance">Has insurance</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input broker-only" type="checkbox" name="broker_has_trailer" id="brokerHasTrailer" <?= !empty($broker_has_trailer) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="brokerHasTrailer">Has trailer</label>
                </div>
              </div>
              <div class="col-12">
                <label class="form-label">Comments</label>
                <textarea name="lead_comments" id="leadComments" class="form-control" rows="3"><?= htmlspecialchars($lead_comments ?? '', ENT_QUOTES) ?></textarea>
              </div>
            </div>

            <div class="modal-footer px-0">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <?php if ($id): ?>
                <a href="driver_leads.php" class="btn btn-secondary">New Contact</a>
              <?php endif; ?>
              <button type="submit" class="btn btn-primary" id="contactSubmitBtn"><?= $id ? 'Update Lead Details' : 'Add Lead Details' ?></button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="contactLeadsModal" tabindex="-1" aria-labelledby="contactLeadsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="contactLeadsModalLabel">Contact Leads</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form method="post" class="mb-0">
            <input type="hidden" name="form_type" value="contact_leads">
            <input type="hidden" name="lead_ids" id="contactLeadIds">

            <div class="alert alert-warning mb-3">
              Mailchimp API credentials are required to send messages.
            </div>

            <div class="mb-3">
              <label class="form-label">Selected Leads</label>
              <div id="selectedLeadsList" class="text-muted">No leads selected.</div>
            </div>

            <div class="mb-3">
              <label class="form-label">Contact Method</label>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="channels[]" value="email" id="contactEmail">
                <label class="form-check-label" for="contactEmail">Email</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="channels[]" value="sms" id="contactSms">
                <label class="form-check-label" for="contactSms">Text (SMS)</label>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label">Email Message</label>
              <textarea name="message" class="form-control" rows="4">Hi {{first_name}}, we have a new opportunity at Lone Star Roadside. Let us know if you're interested and available.</textarea>
            </div>

            <div class="mb-3">
              <label class="form-label">SMS Message</label>
              <textarea name="sms_message" class="form-control" rows="3">Hi {{first_name}} — Lone Star Roadside here. Are you available for work? Reply YES if interested.</textarea>
            </div>

            <div class="modal-footer px-0">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary" id="contactLeadsSubmit">Send</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="insuranceModal" tabindex="-1" aria-labelledby="insuranceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="insuranceModalLabel">Add Insurance Cost</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form method="post" class="mb-0">
            <input type="hidden" name="form_type" value="insurance">
            <label class="form-label">Driver</label>
            <select name="ins_driver_id" id="driver-insurance" class="form-select" required>
              <option value="">--Select Driver--</option>
              <?php foreach ($drivers as $d): ?>
                <option value="<?= (int)$d['id'] ?>" <?= ((int)$ins_driver_id === (int)$d['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($d['name'], ENT_QUOTES) ?>
                </option>
              <?php endforeach; ?>
            </select>

            <label class="form-label mt-3">Amount</label>
            <input type="number" step="0.01" name="ins_amount" id="ins-amt" class="form-control" required>

            <div class="modal-footer px-0">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary">Save Insurance Cost</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="reporttModal" tabindex="-1" aria-labelledby="reportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="reportModalLabel">Leads Report Extract</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form method="post" class="mb-0">
            <input type="hidden" name="form_type" value="lead_report">
            <div class="mb-3">
              <label class="form-label">Export Format</label>
              <select name="export_format" class="form-select" required>
                <option value="excel">Excel (CSV)</option>
                <option value="pdf">PDF</option>
              </select>
            </div>
            <div class="modal-footer px-0">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" class="btn btn-primary">Download</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <script>
    <?php $openModal = (($contactSubmitted && !empty($errors)) || ($id && $_SERVER['REQUEST_METHOD'] !== 'POST')); ?>
    <?php if ($openModal): ?>
    window.addEventListener('load', () => {
      const modalEl = document.getElementById('addcontactModal');
      if (modalEl && window.bootstrap) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
      }
    });
    <?php endif; ?>
    <?php if ($openGasModal): ?>
    window.addEventListener('load', () => {
      const modalEl = document.getElementById('contactLeadsModal');
      if (modalEl && window.bootstrap) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
      }
    });
    <?php endif; ?>
    <?php if ($openContactLeadsModal): ?>
    window.addEventListener('load', () => {
      const modalEl = document.getElementById('contactLeadsModal');
      if (modalEl && window.bootstrap) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
      }
    });
    <?php endif; ?>
    <?php if ($openInsModal): ?>
    window.addEventListener('load', () => {
      const modalEl = document.getElementById('insuranceModal');
      if (modalEl && window.bootstrap) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
      }
    });
    <?php endif; ?>

    const addContactBtn = document.getElementById('addcontactbtn');
    const contactForm = document.getElementById('contactForm');
    const contactModalLabel = document.getElementById('contactModalLabel');
    const contactSubmitBtn = document.getElementById('contactSubmitBtn');
    if (addContactBtn && contactForm) {
      addContactBtn.addEventListener('click', () => {
        document.getElementById('contactId').value = '0';
        document.getElementById('leadFirstName').value = '';
        document.getElementById('leadLastName').value = '';
        document.getElementById('leadOwnerName').value = '';
        document.getElementById('leadDotNumber').value = '';
        document.getElementById('leadEmail').value = '';
        document.getElementById('leadPhone').value = '';
        const leadType = document.getElementById('leadType');
        const leadComments = document.getElementById('leadComments');
        if (leadType) leadType.value = '';
        if (leadComments) leadComments.value = '';
        document.getElementById('leadIsOwnerOperator').checked = false;
        document.getElementById('brokerHasInsurance').checked = false;
        document.getElementById('brokerHasTrailer').checked = false;
        if (contactModalLabel) contactModalLabel.textContent = 'Add Lead Contact';
        if (contactSubmitBtn) contactSubmitBtn.textContent = 'Add Lead Details';
      });
    }

    <?php if ($id): ?>
    window.addEventListener('load', () => {
      const modalEl = document.getElementById('addcontactModal');
      if (modalEl && window.bootstrap) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
      }
    });
    <?php endif; ?>

    document.querySelectorAll('.date-input').forEach(input => {
      input.addEventListener('change', e => {
        const idx = e.target.dataset.idx;
        const drv = document.getElementById('driver-gas').value;
        const d = e.target.value;
        if (drv && d) fetch(`?ajax=gas&driver_id=${drv}&date=${d}`)
          .then(r => r.json()).then(j => {
            const field = document.getElementById(`gas-${idx}`);
            if (field) field.value = j.amount;
          });
      });
    });

    const insSelect = document.getElementById('driver-insurance');
    if (insSelect) {
      insSelect.addEventListener('change', () => {
        const drv = insSelect.value;
        if (drv) fetch(`?ajax=insurance&driver_id=${drv}`)
          .then(r => r.json()).then(j => {
            const field = document.getElementById('ins-amt');
            if (field) field.value = j.amount;
          });
      });
    }

    // Client-side sortable table (optional convenience on current page)
    (function() {
      const table = document.getElementById('contactsTable');
      if (!table) return;

      const getCellValue = (row, idx) => {
        const cell = row.children[idx];
        return (cell ? (cell.textContent || '').trim() : '');
      };

      const parseByType = (val, type) => {
        if (type === 'number') {
          const n = parseFloat(val.replace('%','').replace(/,/g,''));
          return isNaN(n) ? -Infinity : n;
        }
        return val.toLowerCase();
      };

      const clearSortClasses = (ths) => ths.forEach(th => {
        th.classList.remove('sorted-asc','sorted-desc');
      });

      table.querySelectorAll('th').forEach((th, idx) => {
        const type = th.getAttribute('data-type') || 'string';
        if (type === 'none') return;

        th.addEventListener('click', () => {
          const tbody = table.tBodies[0];
          const rows = Array.from(tbody.querySelectorAll('tr'));
          const currentlyAsc = th.classList.contains('sorted-asc');
          const direction = currentlyAsc ? -1 : 1;

          clearSortClasses(Array.from(th.parentElement.children));

          rows.sort((a, b) => {
            const va = parseByType(getCellValue(a, idx), type);
            const vb = parseByType(getCellValue(b, idx), type);
            if (va < vb) return -1 * direction;
            if (va > vb) return 1 * direction;
            return 0;
          });

          rows.forEach(r => tbody.appendChild(r));
          th.classList.add(direction === 1 ? 'sorted-asc' : 'sorted-desc');
        });
      });
    })();

    const searchInput = document.getElementById('contactSearch');
    if (searchInput) {
      searchInput.addEventListener('input', () => {
        const query = searchInput.value.trim().toLowerCase();
        const rows = document.querySelectorAll('#contactsTable tbody tr');
        rows.forEach((row) => {
          const text = row.textContent.toLowerCase();
          row.style.display = text.includes(query) ? '' : 'none';
        });
      });
    }

    const leadTypeSelect = document.getElementById('leadType');
    const brokerFields = document.querySelectorAll('.broker-only');
    const updateBrokerFields = () => {
      if (!leadTypeSelect) return;
      const isBroker = leadTypeSelect.value === 'Brokered';
      brokerFields.forEach((field) => {
        field.disabled = !isBroker;
        if (!isBroker) {
          field.checked = false;
        }
      });
    };
    if (leadTypeSelect) {
      leadTypeSelect.addEventListener('change', updateBrokerFields);
      updateBrokerFields();
    }

    const selectAll = document.getElementById('selectAllLeads');
    const leadCheckboxes = () => Array.from(document.querySelectorAll('.lead-select'));
    if (selectAll) {
      selectAll.addEventListener('change', () => {
        leadCheckboxes().forEach(cb => { cb.checked = selectAll.checked; });
      });
    }

    const contactModalEl = document.getElementById('contactLeadsModal');
    const selectedLeadsList = document.getElementById('selectedLeadsList');
    const contactLeadIds = document.getElementById('contactLeadIds');
    const contactLeadsSubmit = document.getElementById('contactLeadsSubmit');
    const updateSelectedLeads = () => {
      const selected = leadCheckboxes().filter(cb => cb.checked);
      const names = selected.map(cb => cb.dataset.name || 'Lead');
      if (selectedLeadsList) {
        selectedLeadsList.textContent = selected.length ? names.join(', ') : 'No leads selected.';
      }
      if (contactLeadIds) {
        contactLeadIds.value = selected.map(cb => cb.value).join(',');
      }
      if (contactLeadsSubmit) {
        contactLeadsSubmit.disabled = selected.length === 0;
      }
    };
    leadCheckboxes().forEach(cb => cb.addEventListener('change', updateSelectedLeads));
    if (contactModalEl) {
      contactModalEl.addEventListener('show.bs.modal', updateSelectedLeads);
    }
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
