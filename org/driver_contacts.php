<?php
// driver_contacts.php
// Enable error reporting
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/trailer_assignment_history.php';

function ensure_owner_operators_table(mysqli $mysqli): void {
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS owner_operators (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_name VARCHAR(191) NOT NULL,
            dot_number VARCHAR(80) NULL,
            email VARCHAR(191) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_owner_name (owner_name),
            KEY idx_dot_number (dot_number),
            KEY idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $mysqli->query(
        "INSERT INTO owner_operators (owner_name, dot_number, email)
         SELECT owner_name,
                NULLIF(MAX(NULLIF(owner_dot_number, '')), ''),
                NULLIF(MAX(NULLIF(email, '')), '')
           FROM driver_contacts
          WHERE owner_name IS NOT NULL
            AND TRIM(owner_name) <> ''
          GROUP BY owner_name
         ON DUPLICATE KEY UPDATE
             dot_number = COALESCE(owner_operators.dot_number, VALUES(dot_number)),
             email = COALESCE(owner_operators.email, VALUES(email))"
    );
}

try {
    ensure_owner_operators_table($mysqli);
} catch (Throwable $e) {
    // Keep Driver Contacts available even if owner-operator setup cannot run.
}

try {
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS trailer_assets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            trailer_number VARCHAR(80) NOT NULL,
            vendor VARCHAR(150) NOT NULL,
            trailer_type VARCHAR(120) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Owned',
            trailer_fee_mode VARCHAR(20) NOT NULL DEFAULT 'percentage',
            trailer_fee_value DECIMAL(12,2) NULL,
            comments TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_trailer_number (trailer_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    foreach ([
        'trailer_fee_mode' => "ALTER TABLE trailer_assets ADD COLUMN trailer_fee_mode VARCHAR(20) NOT NULL DEFAULT 'percentage' AFTER status",
        'trailer_fee_value' => "ALTER TABLE trailer_assets ADD COLUMN trailer_fee_value DECIMAL(12,2) NULL AFTER trailer_fee_mode",
    ] as $column => $sql) {
        $res = $mysqli->query(
            "SELECT COUNT(*) AS col_count
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'trailer_assets'
                AND COLUMN_NAME = '{$column}'"
        );
        $row = $res ? $res->fetch_assoc() : null;
        if ($res) $res->close();
        if ((int)($row['col_count'] ?? 0) === 0) {
            $mysqli->query($sql);
        }
    }
    $legacyFeeRes = $mysqli->query(
        "SELECT COUNT(*) AS col_count
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'trailer_assets'
            AND COLUMN_NAME = 'nextier_trailer_fee'"
    );
    $legacyFeeRow = $legacyFeeRes ? $legacyFeeRes->fetch_assoc() : null;
    if ($legacyFeeRes) $legacyFeeRes->close();
    if ((int)($legacyFeeRow['col_count'] ?? 0) > 0) {
        $mysqli->query(
            "UPDATE trailer_assets
                SET trailer_fee_mode = 'flat',
                    trailer_fee_value = nextier_trailer_fee
              WHERE nextier_trailer_fee IS NOT NULL
                AND trailer_fee_value IS NULL"
        );
    }
} catch (Throwable $e) {
    // Keep Driver Contacts available even if trailer-assets setup cannot run.
}

try {
    $receivedDateRes = $mysqli->query(
        "SELECT COUNT(*) AS col_count
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'driver_contacts'
            AND COLUMN_NAME = 'trailer_received_date'"
    );
    $receivedDateRow = $receivedDateRes ? $receivedDateRes->fetch_assoc() : null;
    if ($receivedDateRes) $receivedDateRes->close();
    if ((int)($receivedDateRow['col_count'] ?? 0) === 0) {
        $mysqli->query("ALTER TABLE driver_contacts ADD COLUMN trailer_received_date DATE NULL AFTER trailer_no");
    }
    $removedDateRes = $mysqli->query(
        "SELECT COUNT(*) AS col_count
          FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'driver_contacts'
           AND COLUMN_NAME = 'trailer_removed_date'"
    );
    $removedDateRow = $removedDateRes ? $removedDateRes->fetch_assoc() : null;
    if ($removedDateRes) $removedDateRes->close();
    if ((int)($removedDateRow['col_count'] ?? 0) === 0) {
        $mysqli->query("ALTER TABLE driver_contacts ADD COLUMN trailer_removed_date DATE NULL AFTER trailer_received_date");
    }
} catch (Throwable $e) {
    // Keep Driver Contacts available even if this optional field cannot be prepared.
}

try {
    lonestar_trailer_history_ensure_table($mysqli);
} catch (Throwable $e) {
    // Keep Driver Contacts available even if assignment history cannot be prepared.
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
  'truck_no'          => 'truck_no',
  'alt_truck_no'      => 'alt_truck_no',
  'trailer_no'        => 'trailer_no',
];
$allowedDirs = ['asc','desc'];

// Read sort params (GET)
$sort = $_GET['sort'] ?? 'driver_name';
$dir  = strtolower($_GET['dir'] ?? 'asc');
$showDisabled = isset($_GET['show_disabled']) && $_GET['show_disabled'] === '1';
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

function driver_contact_normalize_text(string $value): string {
    return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
}

function driver_contact_normalize_digits(string $value): string {
    return preg_replace('/\D+/', '', $value) ?? '';
}

function driver_contact_normalize_unit(string $value): string {
    return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $value) ?? '');
}

function driver_contact_possible_duplicates(mysqli $mysqli, int $currentId, string $first, string $last, string $email, string $phone, string $truck, string $altTruck): array {
    $matches = [];
    $inputFullName = driver_contact_normalize_text($first . ' ' . $last);
    $inputEmail = driver_contact_normalize_text($email);
    $inputPhone = driver_contact_normalize_digits($phone);
    $inputTrucks = array_values(array_filter(array_unique([
        driver_contact_normalize_unit($truck),
        driver_contact_normalize_unit($altTruck),
    ])));

    $stmt = $mysqli->prepare(
        'SELECT id, first_name, last_name, email, phone, truck_no, alt_truck_no, is_disabled
           FROM driver_contacts
          WHERE id <> ?
          ORDER BY is_disabled ASC, last_name ASC, first_name ASC
          LIMIT 1000'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $currentId);
    $stmt->execute();
    $stmt->bind_result($matchId, $matchFirst, $matchLast, $matchEmail, $matchPhone, $matchTruck, $matchAltTruck, $matchDisabled);
    while ($stmt->fetch()) {
        $rowFullName = driver_contact_normalize_text((string)$matchFirst . ' ' . (string)$matchLast);
        $rowEmail = driver_contact_normalize_text((string)$matchEmail);
        $rowPhone = driver_contact_normalize_digits((string)$matchPhone);
        $rowTrucks = array_values(array_filter(array_unique([
            driver_contact_normalize_unit((string)$matchTruck),
            driver_contact_normalize_unit((string)$matchAltTruck),
        ])));
        $sharedTruck = $inputTrucks && $rowTrucks && count(array_intersect($inputTrucks, $rowTrucks)) > 0;
        $sameName = $inputFullName !== '' && $inputFullName === $rowFullName;
        $sameEmail = $inputEmail !== '' && $inputEmail === $rowEmail;
        $samePhone = $inputPhone !== '' && strlen($inputPhone) >= 7 && $inputPhone === $rowPhone;

        $reasons = [];
        if ($sameName && $sameEmail) $reasons[] = 'same driver name and email';
        if ($sameName && $samePhone) $reasons[] = 'same driver name and phone';
        if ($sharedTruck && $samePhone) $reasons[] = 'same truck number and phone';
        if ($sharedTruck && $sameEmail) $reasons[] = 'same truck number and email';
        if ($sameName && $sharedTruck) $reasons[] = 'same driver name and truck number';
        if ($reasons) {
            $matches[] = [
                'id' => (int)$matchId,
                'name' => trim((string)$matchFirst . ' ' . (string)$matchLast),
                'email' => (string)$matchEmail,
                'phone' => (string)$matchPhone,
                'truck_no' => (string)$matchTruck,
                'status' => !empty($matchDisabled) ? 'Disabled' : 'Active',
                'reason' => implode(', ', array_unique($reasons)),
            ];
            if (count($matches) >= 8) {
                break;
            }
        }
    }
    $stmt->close();

    return $matches;
}

// Initialize form vars
$errors = [];
$gasErrors = [];
$duplicateWarnings = [];
$success = false;
$successGas = false;
$successIns = false;
$successMessage = '';
$openGasModal = false;
$openInsModal = false;
$openContactModal = false;
$contactSubmitted = false;
$gas_driver_id = '';
$ins_driver_id = '';

$first = $last = $owner = $owner_dot = $driver_type = $email = $phone = $truck = $alt_truck = $trailer = $trailer_received_date = $trailer_removed_date = $trailer_comments = '';
$id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;
$availableTrailers = [];
$vendorTrailerOptions = [];
$driverTrailerAssignments = [];

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $deleteId = intval($_POST['id'] ?? 0);
    if ($deleteId > 0) {
        try {
            $mysqli->begin_transaction();

            $deleteRemovedDate = date('Y-m-d');
            $dateLookup = $mysqli->prepare('SELECT trailer_removed_date FROM driver_contacts WHERE id=? LIMIT 1');
            if ($dateLookup) {
                $dateLookup->bind_param('i', $deleteId);
                $dateLookup->execute();
                $dateLookup->bind_result($rawRemovedDate);
                if ($dateLookup->fetch() && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$rawRemovedDate)) {
                    $deleteRemovedDate = (string)$rawRemovedDate;
                }
                $dateLookup->close();
            }
            lonestar_trailer_history_close_open($mysqli, $deleteId, $deleteRemovedDate);

            $stmt = $mysqli->prepare('DELETE FROM driver_gas_costs WHERE driver_id=?');
            $stmt->bind_param('i', $deleteId);
            $stmt->execute();
            $stmt->close();

            $stmt = $mysqli->prepare('DELETE FROM driver_insurance_costs WHERE driver_id=?');
            $stmt->bind_param('i', $deleteId);
            $stmt->execute();
            $stmt->close();

            $stmt = $mysqli->prepare('DELETE FROM driver_name_aliases WHERE driver_contact_id=?');
            $stmt->bind_param('i', $deleteId);
            $stmt->execute();
            $stmt->close();

            $hasDriverId = false;
            $check = $mysqli->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'driver_payouts'
                   AND COLUMN_NAME = 'driver_id'"
            );
            if ($check) {
                $check->execute();
                $check->bind_result($cnt);
                $check->fetch();
                $hasDriverId = $cnt > 0;
                $check->close();
            }

            if ($hasDriverId) {
                $stmt = $mysqli->prepare('DELETE FROM driver_payouts WHERE driver_id=?');
                $stmt->bind_param('i', $deleteId);
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $mysqli->prepare('DELETE FROM driver_contacts WHERE id=?');
            $stmt->bind_param('i', $deleteId);
            $stmt->execute();
            $stmt->close();

            $mysqli->commit();
            $successMessage = 'Contact deleted.';
            audit_log_change($mysqli, 'delete', 'driver_contact', $deleteId, 'Deleted driver contact id ' . $deleteId);
        } catch (mysqli_sql_exception $e) {
            $mysqli->rollback();
            $errors[] = 'Unable to delete contact because related records exist.';
        }
    } else {
        $errors[] = 'Invalid contact selected for deletion.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_disabled') {
    $toggleId = intval($_POST['id'] ?? 0);
    $newState = ($_POST['disabled'] ?? '') === '1' ? 1 : 0;
    if ($toggleId > 0) {
        if ($newState === 1) {
            $disableRemovedDate = date('Y-m-d');
            $dateLookup = $mysqli->prepare('SELECT trailer_removed_date FROM driver_contacts WHERE id=? LIMIT 1');
            if ($dateLookup) {
                $dateLookup->bind_param('i', $toggleId);
                $dateLookup->execute();
                $dateLookup->bind_result($rawRemovedDate);
                if ($dateLookup->fetch() && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$rawRemovedDate)) {
                    $disableRemovedDate = (string)$rawRemovedDate;
                }
                $dateLookup->close();
            }
            lonestar_trailer_history_close_open($mysqli, $toggleId, $disableRemovedDate);
            $stmt = $mysqli->prepare('UPDATE driver_contacts SET is_disabled = 1, trailer_no = NULL, trailer_received_date = NULL WHERE id = ?');
            $stmt->bind_param('i', $toggleId);
        } else {
            $stmt = $mysqli->prepare('UPDATE driver_contacts SET is_disabled = 0 WHERE id = ?');
            $stmt->bind_param('i', $toggleId);
        }
        $stmt->execute();
        $stmt->close();
        $successMessage = $newState ? 'Driver disabled.' : 'Driver re-enabled.';
        $success = true;
        audit_log_change($mysqli, $newState ? 'disable' : 'enable', 'driver_contact', $toggleId, $successMessage, null, [
            'is_disabled' => $newState,
        ]);
    } else {
        $errors[] = 'Invalid contact selected.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_vendor_trailer') {
    $assignDriverId = intval($_POST['driver_id'] ?? 0);
    $assignTrailer = trim((string)($_POST['trailer_number'] ?? ''));
    $assignDate = trim((string)($_POST['assigned_date'] ?? ''));
    $id = $assignDriverId;
    $openContactModal = true;
    if ($assignDriverId <= 0 || $assignTrailer === '') {
        $errors[] = 'Select a driver and trailer to assign.';
    } elseif ($assignDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $assignDate)) {
        $errors[] = 'Trailer received date must be a valid date.';
    } else {
        if ($assignDate === '') {
            $assignDate = date('Y-m-d');
        }
        try {
            $vendor = lonestar_trailer_history_trailer_vendor($mysqli, $assignTrailer);
            if ($vendor === '') {
                $errors[] = 'Please select a valid trailer from Trailer Manager.';
            } elseif (lonestar_trailer_history_driver_has_open_vendor($mysqli, $assignDriverId, $vendor)) {
                $errors[] = 'This driver already has an active trailer assigned for ' . $vendor . '.';
            } elseif (lonestar_trailer_history_trailer_is_open_assigned($mysqli, $assignTrailer)) {
                $errors[] = 'That trailer is already assigned to a driver.';
            } else {
                lonestar_trailer_history_open_assignment($mysqli, $assignDriverId, $assignTrailer, $assignDate);
                $assignmentCheck = $mysqli->prepare(
                    'SELECT id
                       FROM trailer_assignment_history
                      WHERE driver_contact_id = ?
                        AND removed_date IS NULL
                        AND TRIM(trailer_number) = TRIM(?)
                      LIMIT 1'
                );
                $assignmentCreated = false;
                if ($assignmentCheck) {
                    $assignmentCheck->bind_param('is', $assignDriverId, $assignTrailer);
                    $assignmentCheck->execute();
                    $assignmentCheck->store_result();
                    $assignmentCreated = $assignmentCheck->num_rows > 0;
                    $assignmentCheck->close();
                }
                if (!$assignmentCreated) {
                    throw new RuntimeException('The trailer assignment was not saved. Please refresh and try again.');
                }
                $legacyStmt = $mysqli->prepare(
                    'UPDATE driver_contacts
                        SET trailer_no = COALESCE(NULLIF(trailer_no, ""), ?),
                            trailer_received_date = COALESCE(trailer_received_date, ?)
                      WHERE id = ?
                      LIMIT 1'
                );
                if ($legacyStmt) {
                    $legacyStmt->bind_param('ssi', $assignTrailer, $assignDate, $assignDriverId);
                    $legacyStmt->execute();
                    $legacyStmt->close();
                }
                $success = true;
                $successMessage = 'Trailer assigned for ' . $vendor . '.';
                audit_log_change($mysqli, 'assign_trailer', 'driver_contact', $assignDriverId, $successMessage, null, [
                    'trailer_no' => $assignTrailer,
                    'vendor' => $vendor,
                    'assigned_date' => $assignDate,
                ]);
            }
        } catch (Throwable $e) {
            $errors[] = 'Unable to assign trailer: ' . $e->getMessage();
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_vendor_trailer') {
    $assignmentId = intval($_POST['assignment_id'] ?? 0);
    $removeDriverId = intval($_POST['driver_id'] ?? 0);
    $removedDate = trim((string)($_POST['removed_date'] ?? ''));
    $id = $removeDriverId;
    $openContactModal = true;
    if ($assignmentId <= 0 || $removeDriverId <= 0) {
        $errors[] = 'Select a valid trailer assignment to remove.';
    } elseif ($removedDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $removedDate)) {
        $errors[] = 'Trailer removed date is required and must be a valid date.';
    } else {
        try {
            $lookup = $mysqli->prepare(
                'SELECT trailer_number, assigned_date
                   FROM trailer_assignment_history
                  WHERE id = ?
                    AND driver_contact_id = ?
                    AND removed_date IS NULL
                  LIMIT 1'
            );
            $lookup->bind_param('ii', $assignmentId, $removeDriverId);
            $lookup->execute();
            $lookup->bind_result($removeTrailerNumber, $removeAssignedDate);
            $found = $lookup->fetch();
            $lookup->close();
            if (!$found) {
                $errors[] = 'The selected trailer assignment is no longer active.';
            } elseif ((string)$removeAssignedDate !== '' && $removedDate < (string)$removeAssignedDate) {
                $errors[] = 'Trailer removed date cannot be earlier than the received date.';
            } else {
                $closeStmt = $mysqli->prepare(
                    'UPDATE trailer_assignment_history
                        SET removed_date = ?,
                            updated_at = NOW()
                      WHERE id = ?
                        AND driver_contact_id = ?
                        AND removed_date IS NULL
                      LIMIT 1'
                );
                if (!$closeStmt) {
                    throw new RuntimeException('Unable to prepare trailer history update.');
                }
                $closeStmt->bind_param('sii', $removedDate, $assignmentId, $removeDriverId);
                $closeStmt->execute();
                $closedRows = $closeStmt->affected_rows;
                $closeStmt->close();
                if ($closedRows < 1) {
                    throw new RuntimeException('The selected trailer assignment was not closed. Please refresh and try again.');
                }
                $legacyStmt = $mysqli->prepare(
                    'UPDATE driver_contacts
                        SET trailer_no = NULL,
                            trailer_received_date = NULL,
                            trailer_removed_date = ?
                      WHERE id = ?
                        AND TRIM(COALESCE(trailer_no, "")) = TRIM(?)
                      LIMIT 1'
                );
                if ($legacyStmt) {
                    $legacyStmt->bind_param('sis', $removedDate, $removeDriverId, $removeTrailerNumber);
                    $legacyStmt->execute();
                    $legacyStmt->close();
                }
                $success = true;
                $successMessage = 'Trailer assignment removed.';
                audit_log_change($mysqli, 'remove_trailer_assignment', 'driver_contact', $removeDriverId, $successMessage, null, [
                    'assignment_id' => $assignmentId,
                    'trailer_no' => $removeTrailerNumber,
                    'removed_date' => $removedDate,
                ]);
            }
        } catch (Throwable $e) {
            $errors[] = 'Unable to remove trailer assignment: ' . $e->getMessage();
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
        audit_log_change($mysqli, 'save_gas_costs', 'driver_contact', $driver_id, 'Saved gas costs for driver id ' . $driver_id);
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
        audit_log_change($mysqli, 'save_insurance_cost', 'driver_contact', $ins_driver_id, 'Saved insurance cost for driver id ' . $ins_driver_id, null, [
            'amount' => $a,
        ]);
    }
    $openInsModal = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contactSubmitted = true;
    $first     = trim($_POST['first_name'] ?? '');
    $last      = trim($_POST['last_name'] ?? '');
    $owner_select = trim($_POST['owner_name_select'] ?? '');
    $owner_text = trim($_POST['owner_name'] ?? '');
    $owner = $owner_select === '__new__' ? $owner_text : $owner_select;
    $owner_dot_select = trim($_POST['owner_dot_number_select'] ?? '');
    $owner_dot_text = trim($_POST['owner_dot_number'] ?? '');
    $owner_dot = $owner_dot_select === '__new__' ? $owner_dot_text : $owner_dot_select;
    $driver_type = trim($_POST['driver_type'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $truck     = trim($_POST['truck_no'] ?? '');
    $alt_truck = trim($_POST['alt_truck_no'] ?? '');
    $trailer = '';
    $trailer_received_date = '';
    $trailer_removed_date = '';
    $trailer_comments = '';
    $id        = intval($_POST['id'] ?? 0);
    $duplicateConfirmed = ($_POST['duplicate_confirmed'] ?? '') === '1';

    $syncSql = '';
    $syncValue = '';
    if ($owner_dot !== '') {
        $syncSql = 'SELECT owner_name, dot_number, email FROM owner_operators WHERE TRIM(dot_number) = TRIM(?) ORDER BY id DESC LIMIT 1';
        $syncValue = $owner_dot;
    } elseif ($owner !== '') {
        $syncSql = 'SELECT owner_name, dot_number, email FROM owner_operators WHERE TRIM(owner_name) = TRIM(?) ORDER BY id DESC LIMIT 1';
        $syncValue = $owner;
    } elseif ($email !== '') {
        $syncSql = 'SELECT owner_name, dot_number, email FROM owner_operators WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) ORDER BY id DESC LIMIT 1';
        $syncValue = $email;
    }
    if ($syncSql !== '') {
        $syncStmt = $mysqli->prepare($syncSql);
        if ($syncStmt) {
            $syncStmt->bind_param('s', $syncValue);
            $syncStmt->execute();
            $syncStmt->bind_result($syncOwner, $syncDot, $syncEmail);
            if ($syncStmt->fetch()) {
                if (trim((string)$syncOwner) !== '') $owner = trim((string)$syncOwner);
                if (trim((string)$syncDot) !== '') $owner_dot = trim((string)$syncDot);
                if (trim((string)$syncEmail) !== '') $email = trim((string)$syncEmail);
            }
            $syncStmt->close();
        }
    }

    // Validate required fields
    if (!$first || !$last || !$email) {
        $errors[] = 'Driver first name, last name, and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }
    if ($driver_type !== '' && !in_array($driver_type, ['Leased', 'Brokered', 'Company Driver'], true)) {
        $errors[] = 'Driver type must be Leased, Brokered, or Company Driver.';
    }
    $storedTrailerRemovedDate = '';
    if (empty($errors) && $id === 0 && !$duplicateConfirmed) {
        $duplicateWarnings = driver_contact_possible_duplicates($mysqli, $id, $first, $last, $email, $phone, $truck, $alt_truck);
        if ($duplicateWarnings) {
            $errors[] = 'This looks like it may duplicate an existing driver. Review the possible matches below, then use Create Anyway only if this is truly a different driver.';
        }
    }

    if (empty($errors)) {
        if ($id) {
            $existingAltTruck = $alt_truck;
            if ($existingAltTruck === '') {
                $altLookup = $mysqli->prepare('SELECT alt_truck_no FROM driver_contacts WHERE id=? LIMIT 1');
                $altLookup->bind_param('i', $id);
                $altLookup->execute();
                $altLookup->bind_result($existingAltTruckValue);
                if ($altLookup->fetch()) {
                    $existingAltTruck = (string)$existingAltTruckValue;
                }
                $altLookup->close();
            }

            // Update
            $stmt = $mysqli->prepare(
                'UPDATE driver_contacts 
                 SET first_name=?, last_name=?, owner_name=?, owner_dot_number=?, driver_type=?, email=?, phone=?, truck_no=?, alt_truck_no=?
                 WHERE id=?'
            );
            $stmt->bind_param(
                'sssssssssi',
                $first,
                $last,
                $owner,
                $owner_dot,
                $driver_type,
                $email,
                $phone,
                $truck,
                $existingAltTruck,
                $id
            );
        } else {
            // Insert
            $stmt = $mysqli->prepare(
                'INSERT INTO driver_contacts 
                 (first_name, last_name, owner_name, owner_dot_number, driver_type, email, phone, truck_no, alt_truck_no, trailer_no, trailer_received_date, trailer_removed_date) 
                 VALUES (?,?,?,?,?,?,?,?,?,?, NULLIF(?, ""), NULLIF(?, ""))'
            );
            $stmt->bind_param(
                'ssssssssssss',
                $first,
                $last,
                $owner,
                $owner_dot,
                $driver_type,
                $email,
                $phone,
                $truck,
                $alt_truck,
                $trailer,
                $trailer_received_date,
                $storedTrailerRemovedDate
            );
        }

        if ($stmt->execute()) {
            $savedContactId = $id ?: $stmt->insert_id;
            if ($owner !== '') {
                $ownerStmt = $mysqli->prepare(
                    'INSERT INTO owner_operators (owner_name, dot_number, email)
                     VALUES (?, NULLIF(?, ""), NULLIF(?, ""))
                     ON DUPLICATE KEY UPDATE
                         dot_number = VALUES(dot_number),
                         email = VALUES(email)'
                );
                if ($ownerStmt) {
                    $ownerStmt->bind_param('sss', $owner, $owner_dot, $email);
                    $ownerStmt->execute();
                    $ownerStmt->close();
                }
            }
            $success = true;
            $successMessage = $id ? 'Contact updated.' : 'Contact added.';
            audit_log_change($mysqli, $id ? 'update' : 'create', 'driver_contact', $savedContactId, $successMessage, null, [
                'first_name' => $first,
                'last_name' => $last,
                'owner_name' => $owner,
                'owner_dot_number' => $owner_dot,
                'email' => $email,
                'truck_no' => $truck,
            ]);
        } else {
            $errors[] = $stmt->error;
        }
        $stmt->close();
    }
}

try {
    $assignedTrailerLookup = [];
    $assignedTrailerStmt = $mysqli->prepare(
        'SELECT id, TRIM(COALESCE(trailer_no, "")) AS trailer_no
           FROM driver_contacts
          WHERE TRIM(COALESCE(trailer_no, "")) <> ""
            AND COALESCE(is_disabled, 0) = 0'
    );
    if ($assignedTrailerStmt) {
        $assignedTrailerStmt->execute();
        $assignedTrailerRes = $assignedTrailerStmt->get_result();
        while ($assignedTrailerRow = $assignedTrailerRes->fetch_assoc()) {
            $assignedTrailerNumber = trim((string)($assignedTrailerRow['trailer_no'] ?? ''));
            if ($assignedTrailerNumber !== '') {
                $assignedTrailerLookup[$assignedTrailerNumber] = (int)$assignedTrailerRow['id'];
            }
        }
        $assignedTrailerStmt->close();
    }
    $assignedHistoryStmt = $mysqli->prepare(
        'SELECT driver_contact_id, TRIM(COALESCE(trailer_number, "")) AS trailer_no
           FROM trailer_assignment_history
          WHERE removed_date IS NULL
            AND TRIM(COALESCE(trailer_number, "")) <> ""'
    );
    if ($assignedHistoryStmt) {
        $assignedHistoryStmt->execute();
        $assignedHistoryRes = $assignedHistoryStmt->get_result();
        while ($assignedHistoryRow = $assignedHistoryRes->fetch_assoc()) {
            $assignedTrailerNumber = trim((string)($assignedHistoryRow['trailer_no'] ?? ''));
            if ($assignedTrailerNumber !== '') {
                $assignedTrailerLookup[$assignedTrailerNumber] = (int)$assignedHistoryRow['driver_contact_id'];
            }
        }
        $assignedHistoryStmt->close();
    }

    $trailerRes = $mysqli->query(
        'SELECT trailer_number
           FROM trailer_assets
          WHERE TRIM(COALESCE(trailer_number, "")) <> ""
       ORDER BY trailer_number ASC'
    );
    if ($trailerRes) {
        while ($trailerRow = $trailerRes->fetch_assoc()) {
            $trailerNumber = trim((string)($trailerRow['trailer_number'] ?? ''));
            if ($trailerNumber === '') {
                continue;
            }
            $assignedDriverId = $assignedTrailerLookup[$trailerNumber] ?? 0;
            if ($assignedDriverId !== 0 && $assignedDriverId !== $id) {
                continue;
            }
            $availableTrailers[] = $trailerNumber;
        }
        $trailerRes->close();
    }

    // Add "own" option for drivers who own their own trailer
    $availableTrailers[] = 'own';

    if ($trailer !== '' && !in_array($trailer, $availableTrailers, true)) {
        $availableTrailers[] = $trailer;
        sort($availableTrailers, SORT_NATURAL | SORT_FLAG_CASE);
    }
} catch (Throwable $e) {
    // Keep the page available if trailer options cannot be loaded.
}

// If editing, load data
if ($id && ($_SERVER['REQUEST_METHOD'] !== 'POST' || $openContactModal)) {
    $stmt = $mysqli->prepare('SELECT first_name,last_name,owner_name,owner_dot_number,driver_type,email,phone,truck_no,alt_truck_no,trailer_no,trailer_received_date,trailer_removed_date FROM driver_contacts WHERE id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->bind_result($first, $last, $owner, $owner_dot, $driver_type, $email, $phone, $truck, $alt_truck, $trailer, $trailer_received_date, $trailer_removed_date);
    $stmt->fetch();
    $stmt->close();

    if ($trailer !== '') {
        $commentStmt = $mysqli->prepare(
            'SELECT comments
               FROM trailer_assets
              WHERE TRIM(COALESCE(trailer_number, "")) = TRIM(?)
              LIMIT 1'
        );
        if ($commentStmt) {
            $commentStmt->bind_param('s', $trailer);
            $commentStmt->execute();
            $commentStmt->bind_result($trailerCommentsValue);
            if ($commentStmt->fetch()) {
                $trailer_comments = (string)($trailerCommentsValue ?? '');
            }
            $commentStmt->close();
        }
    }
}

try {
    $assignedOpenTrailers = [];
    $assignedOpenStmt = $mysqli->prepare(
        'SELECT TRIM(COALESCE(trailer_number, "")) AS trailer_number
           FROM trailer_assignment_history
          WHERE removed_date IS NULL
            AND TRIM(COALESCE(trailer_number, "")) <> ""'
    );
    if ($assignedOpenStmt) {
        $assignedOpenStmt->execute();
        $assignedOpenStmt->bind_result($openTrailerNumber);
        while ($assignedOpenStmt->fetch()) {
            $assignedOpenTrailers[trim((string)$openTrailerNumber)] = true;
        }
        $assignedOpenStmt->close();
    }

    $vendorTrailerStmt = $mysqli->prepare(
        'SELECT trailer_number, vendor
           FROM trailer_assets
          WHERE TRIM(COALESCE(trailer_number, "")) <> ""
       ORDER BY vendor ASC, trailer_number ASC'
    );
    if ($vendorTrailerStmt) {
        $vendorTrailerStmt->execute();
        $vendorTrailerStmt->bind_result($optionTrailerNumber, $optionVendor);
        while ($vendorTrailerStmt->fetch()) {
            $optionTrailer = trim((string)$optionTrailerNumber);
            if ($optionTrailer === '' || isset($assignedOpenTrailers[$optionTrailer])) {
                continue;
            }
            $vendorTrailerOptions[] = [
                'trailer_number' => $optionTrailer,
                'vendor' => trim((string)$optionVendor),
            ];
        }
        $vendorTrailerStmt->close();
    }

    if ($id > 0) {
        $assignmentStmt = $mysqli->prepare(
            'SELECT tah.id,
                    tah.trailer_number,
                    tah.assigned_date,
                    tah.removed_date,
                    ta.vendor,
                    ta.trailer_type,
                    ta.trailer_fee_mode,
                    ta.trailer_fee_value
               FROM trailer_assignment_history tah
          LEFT JOIN trailer_assets ta
                 ON (
                    (tah.trailer_id IS NOT NULL AND ta.id = tah.trailer_id)
                    OR TRIM(COALESCE(ta.trailer_number, "")) = TRIM(COALESCE(tah.trailer_number, ""))
                 )
              WHERE tah.driver_contact_id = ?
                AND tah.removed_date IS NULL
           ORDER BY ta.vendor ASC, tah.trailer_number ASC'
        );
        if ($assignmentStmt) {
            $assignmentStmt->bind_param('i', $id);
            $assignmentStmt->execute();
            $assignmentStmt->bind_result(
                $assignmentId,
                $assignmentTrailerNumber,
                $assignmentAssignedDate,
                $assignmentRemovedDate,
                $assignmentVendor,
                $assignmentTrailerType,
                $assignmentFeeMode,
                $assignmentFeeValue
            );
            while ($assignmentStmt->fetch()) {
                $driverTrailerAssignments[] = [
                    'id' => $assignmentId,
                    'trailer_number' => $assignmentTrailerNumber,
                    'assigned_date' => $assignmentAssignedDate,
                    'removed_date' => $assignmentRemovedDate,
                    'vendor' => $assignmentVendor,
                    'trailer_type' => $assignmentTrailerType,
                    'trailer_fee_mode' => $assignmentFeeMode,
                    'trailer_fee_value' => $assignmentFeeValue,
                ];
            }
            $assignmentStmt->close();
        }
    }
} catch (Throwable $e) {
    // Keep Driver Contacts available even if trailer assignment options cannot load.
}

// Fetch contacts (server-side order)
$query = "SELECT id, first_name, last_name, owner_name, owner_dot_number, driver_type, email, phone, truck_no, alt_truck_no, trailer_no, trailer_received_date, trailer_removed_date, is_disabled
          FROM driver_contacts";
if (!$showDisabled) {
    $query .= " WHERE is_disabled = 0";
}
$query .= " ORDER BY $orderBy";
$res = $mysqli->query($query);

$drivers = [];
$driverQuery = "SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM driver_contacts";
if (!$showDisabled) {
    $driverQuery .= " WHERE is_disabled = 0";
}
$driverQuery .= " ORDER BY last_name, first_name";
$resDrivers = $mysqli->query($driverQuery);
while ($r = $resDrivers->fetch_assoc()) {
    $drivers[] = $r;
}

$duplicateDriverContacts = [];
$resDuplicateDrivers = $mysqli->query(
    "SELECT id, first_name, last_name, email, phone, truck_no, alt_truck_no, is_disabled
       FROM driver_contacts
      ORDER BY is_disabled ASC, last_name ASC, first_name ASC"
);
while ($resDuplicateDrivers && ($row = $resDuplicateDrivers->fetch_assoc())) {
    $duplicateDriverContacts[] = [
        'id' => (int)($row['id'] ?? 0),
        'first_name' => (string)($row['first_name'] ?? ''),
        'last_name' => (string)($row['last_name'] ?? ''),
        'email' => (string)($row['email'] ?? ''),
        'phone' => (string)($row['phone'] ?? ''),
        'truck_no' => (string)($row['truck_no'] ?? ''),
        'alt_truck_no' => (string)($row['alt_truck_no'] ?? ''),
        'is_disabled' => (int)($row['is_disabled'] ?? 0),
    ];
}
if ($resDuplicateDrivers) {
    $resDuplicateDrivers->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Driver Contacts</title>
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
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
    <h1>Driver Contacts</h1>

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
      <button type="button" class="btn btn-primary" id="addContactBtn" data-bs-toggle="modal" data-bs-target="#contactModal">
        Add Contact
      </button>
      <button type="button" class="btn btn-outline-primary" id="addInsuranceBtn" data-bs-toggle="modal" data-bs-target="#insuranceModal">
        Add Insurance Costs
      </button>
    </div>

    <h2>Existing Contacts</h2>
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
          <option value="owner_name"        <?= $sort==='owner_name'?'selected':'' ?>>Owner Operator</option>
          <option value="email"             <?= $sort==='email'?'selected':'' ?>>Email</option>
          <option value="phone"             <?= $sort==='phone'?'selected':'' ?>>Phone</option>
          <option value="truck_no"          <?= $sort==='truck_no'?'selected':'' ?>>Truck No.</option>
          <option value="alt_truck_no"      <?= $sort==='alt_truck_no'?'selected':'' ?>>Alt Truck No.</option>
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
        <div class="form-check mt-4">
          <input class="form-check-input" type="checkbox" name="show_disabled" id="showDisabled" value="1" <?= $showDisabled ? 'checked' : '' ?>>
          <label class="form-check-label" for="showDisabled">Show disabled drivers</label>
        </div>
      </div>
      <div class="col-auto">
        <button class="btn btn-outline-primary" type="submit">Apply</button>
        <a class="btn btn-outline-secondary" href="driver_contacts.php">Reset</a>
      </div>
    </form>
    

    <table class="table table-striped sortable" id="contactsTable">
      <thead>
        <tr>
          <th data-type="number">#</th>
          <th data-type="string">Driver Name <span class="sort-indicator"></span></th>
          <th data-type="string">Owner Operator <span class="sort-indicator"></span></th>
          <th data-type="string">DOT Number<span class="sort-indicator"></span></th>
          <th data-type="string">Type <span class="sort-indicator"></span></th>
          <th data-type="string">Email <span class="sort-indicator"></span></th>
          <th data-type="string">Phone <span class="sort-indicator"></span></th>
          <th data-type="string">Truck No. <span class="sort-indicator"></span></th>
          <th data-type="string">Alt Truck No. <span class="sort-indicator"></span></th>
          <th data-type="none">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php $rowNumber = 1; ?>
        <?php while ($row = $res->fetch_assoc()): ?>
          <tr>
            <td><?= $rowNumber++ ?></td>
            <td>
              <?= htmlspecialchars(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''), ENT_QUOTES) ?>
              <?php if (!empty($row['is_disabled'])): ?>
                <span class="badge bg-secondary ms-1">Disabled</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($row['owner_name'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($row['owner_dot_number'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($row['driver_type'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($row['email'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($row['phone'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($row['truck_no'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($row['alt_truck_no'] ?? '', ENT_QUOTES) ?></td>
            <td>
              <div class="d-flex gap-2">
                <a href="driver_contacts.php?id=<?= (int)$row['id']?>" class="btn btn-sm btn-outline-primary">Edit</a>
                <form method="post" class="m-0">
                  <input type="hidden" name="action" value="toggle_disabled">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <input type="hidden" name="disabled" value="<?= !empty($row['is_disabled']) ? '0' : '1' ?>">
                  <button type="submit" class="btn btn-sm btn-outline-warning" onclick="return confirm('Are you sure you want to <?= !empty($row['is_disabled']) ? 're-enable' : 'disable' ?> this driver?');">
                    <?= !empty($row['is_disabled']) ? 'Enable' : 'Disable' ?>
                  </button>
                </form>
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

  <?php
  // Fetch existing owner operators and DOT numbers for datalists
  $ownerOptions = [];
  $dotOptions = [];
  $ownerDotMap = [];
  $dotOwnerMap = [];
  $ownerEmailMap = [];
  $dotEmailMap = [];
  $emailOwnerMap = [];
  $emailDotMap = [];
  $res = $mysqli->query("
      SELECT owner_name, dot_number AS owner_dot_number, email
        FROM owner_operators
       ORDER BY owner_name, dot_number, email
  ");
  while ($res && ($row = $res->fetch_assoc())) {
      $ownerOptionValue = trim((string)($row['owner_name'] ?? ''));
      $dotOptionValue = trim((string)($row['owner_dot_number'] ?? ''));
      $emailOptionValue = trim((string)($row['email'] ?? ''));
      if ($ownerOptionValue !== '') {
          $ownerOptions[$ownerOptionValue] = $ownerOptionValue;
      }
      if ($dotOptionValue !== '') {
          $dotOptions[$dotOptionValue] = $dotOptionValue;
      }
      if ($ownerOptionValue !== '' && $dotOptionValue !== '') {
          $ownerDotMap[$ownerOptionValue] = $dotOptionValue;
          $dotOwnerMap[$dotOptionValue] = $ownerOptionValue;
      }
      if ($ownerOptionValue !== '' && $emailOptionValue !== '') {
          $ownerEmailMap[$ownerOptionValue] = $emailOptionValue;
          $emailOwnerMap[strtolower($emailOptionValue)] = $ownerOptionValue;
      }
      if ($dotOptionValue !== '' && $emailOptionValue !== '') {
          $dotEmailMap[$dotOptionValue] = $emailOptionValue;
          $emailDotMap[strtolower($emailOptionValue)] = $dotOptionValue;
      }
  }
  if ($res) {
      $res->close();
  }
  ?>

  <div class="modal fade" id="contactModal" tabindex="-1" aria-labelledby="contactModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="contactModalLabel"><?= $id ? 'Edit Contact' : 'Add Contact' ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form method="post" class="mb-0" id="contactForm">
            <input type="hidden" name="id" id="contactId" value="<?= (int)$id ?>">
            <input type="hidden" name="action" id="contactAction" value="">
            <input type="hidden" name="driver_id" id="trailerActionDriverId" value="<?= (int)$id ?>">
            <input type="hidden" name="assignment_id" id="trailerActionAssignmentId" value="">
            <input type="hidden" name="removed_date" id="trailerActionRemovedDate" value="">
            <input type="hidden" name="duplicate_confirmed" id="contactDuplicateConfirmed" value="0">
            <input type="hidden" name="alt_truck_no" id="contactAltTruckNo" value="<?= htmlspecialchars($alt_truck ?? '', ENT_QUOTES) ?>">

            <?php if (!empty($duplicateWarnings)): ?>
              <div class="alert alert-warning">
                <strong>Possible duplicate driver.</strong>
                <div class="mt-1">Please review these existing contacts before creating this driver:</div>
                <ul class="mb-0 mt-2">
                  <?php foreach ($duplicateWarnings as $warning): ?>
                    <li>
                      <?= htmlspecialchars($warning['name'], ENT_QUOTES) ?>
                      <?php if ($warning['status']): ?>(<?= htmlspecialchars($warning['status'], ENT_QUOTES) ?>)<?php endif; ?>
                      - <?= htmlspecialchars($warning['reason'], ENT_QUOTES) ?>
                      <?php if ($warning['email']): ?>, <?= htmlspecialchars($warning['email'], ENT_QUOTES) ?><?php endif; ?>
                      <?php if ($warning['phone']): ?>, <?= htmlspecialchars($warning['phone'], ENT_QUOTES) ?><?php endif; ?>
                      <?php if ($warning['truck_no']): ?>, Truck <?= htmlspecialchars($warning['truck_no'], ENT_QUOTES) ?><?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Driver First Name</label>
                <input type="text" name="first_name" id="contactFirstName" class="form-control" value="<?= htmlspecialchars($first ?? '', ENT_QUOTES) ?>" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Driver Last Name</label>
                <input type="text" name="last_name" id="contactLastName" class="form-control" value="<?= htmlspecialchars($last ?? '', ENT_QUOTES) ?>" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Owner Operator</label>
                <select name="owner_name_select" id="contactOwnerNameSelect" class="form-select">
                  <option value="">Select Owner Operator</option>
                  <option value="__new__" <?= $owner && !isset($ownerOptions[$owner]) ? 'selected' : '' ?>>Add New Owner Operator...</option>
                  <?php foreach ($ownerOptions as $opt): ?>
                    <option value="<?= htmlspecialchars($opt, ENT_QUOTES) ?>" <?= $owner === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt, ENT_QUOTES) ?></option>
                  <?php endforeach; ?>
                </select>
                <input type="text" name="owner_name" id="contactOwnerName" class="form-control mt-2" value="<?= htmlspecialchars($owner ?? '', ENT_QUOTES) ?>" style="display: <?= $owner && !isset($ownerOptions[$owner]) ? 'block' : 'none' ?>;" placeholder="Enter new owner operator">
              </div>
              <div class="col-md-4">
                <label class="form-label">Owner Operator DOT #</label>
                <select name="owner_dot_number_select" id="contactOwnerDotSelect" class="form-select">
                  <option value="">Select DOT Number</option>
                  <option value="__new__" <?= $owner_dot && !isset($dotOptions[$owner_dot]) ? 'selected' : '' ?>>Add New DOT Number...</option>
                  <?php foreach ($dotOptions as $opt): ?>
                    <option value="<?= htmlspecialchars($opt, ENT_QUOTES) ?>" <?= $owner_dot === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt, ENT_QUOTES) ?></option>
                  <?php endforeach; ?>
                </select>
                <input type="text" name="owner_dot_number" id="contactOwnerDot" class="form-control mt-2" value="<?= htmlspecialchars($owner_dot ?? '', ENT_QUOTES) ?>" style="display: <?= $owner_dot && !isset($dotOptions[$owner_dot]) ? 'block' : 'none' ?>;" placeholder="Enter new DOT number">
              </div>
              <div class="col-md-4">
                <label class="form-label">Type</label>
                <select name="driver_type" id="contactDriverType" class="form-select">
                  <option value="">Select type</option>
                  <option value="Leased" <?= $driver_type === 'Leased' ? 'selected' : '' ?>>Leased</option>
                  <option value="Brokered" <?= $driver_type === 'Brokered' ? 'selected' : '' ?>>Brokered</option>
                  <option value="Company Driver" <?= $driver_type === 'Company Driver' ? 'selected' : '' ?>>Company Driver</option>
                </select>
              </div>

              <div class="col-md-4">
                <label class="form-label">Email</label>
                <input type="email" name="email" id="contactEmail" class="form-control" value="<?= htmlspecialchars($email ?? '', ENT_QUOTES) ?>" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" id="contactPhone" class="form-control" value="<?= htmlspecialchars($phone ?? '', ENT_QUOTES) ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">Truck No.</label>
                <input type="text" name="truck_no" id="contactTruckNo" class="form-control" value="<?= htmlspecialchars($truck ?? '', ENT_QUOTES) ?>">
              </div>
              <?php if ($id > 0): ?>
                <div class="col-12">
                  <hr>
                  <h6 class="mb-2">Active Trailer Assignments by Client</h6>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle">
                      <thead>
                        <tr>
                          <th>Client</th>
                          <th>Trailer</th>
                          <th>Type</th>
                          <th>Fee</th>
                          <th>Received</th>
                          <th>Remove</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (!$driverTrailerAssignments): ?>
                          <tr><td colspan="6" class="text-muted">No active client trailer assignments.</td></tr>
                        <?php else: ?>
                          <?php foreach ($driverTrailerAssignments as $assignment): ?>
                            <?php
                              $assignmentMode = (string)($assignment['trailer_fee_mode'] ?? 'percentage');
                              $assignmentFeeValue = $assignment['trailer_fee_value'];
                              $assignmentFee = '';
                              if ($assignmentFeeValue !== null && $assignmentFeeValue !== '') {
                                  $assignmentFee = $assignmentMode === 'flat'
                                      ? '$' . number_format((float)$assignmentFeeValue, 2) . '/day'
                                      : rtrim(rtrim(number_format((float)$assignmentFeeValue, 2, '.', ''), '0'), '.') . '%';
                              }
                            ?>
                            <tr>
                              <td><?= htmlspecialchars((string)($assignment['vendor'] ?? ''), ENT_QUOTES) ?></td>
                              <td><?= htmlspecialchars((string)($assignment['trailer_number'] ?? ''), ENT_QUOTES) ?></td>
                              <td><?= htmlspecialchars((string)($assignment['trailer_type'] ?? ''), ENT_QUOTES) ?></td>
                              <td><?= htmlspecialchars($assignmentFee, ENT_QUOTES) ?></td>
                              <td><?= htmlspecialchars((string)($assignment['assigned_date'] ?? ''), ENT_QUOTES) ?></td>
                              <td>
                                <div class="d-flex gap-2 align-items-center">
                                  <input type="date" class="form-control form-control-sm trailer-assignment-remove-date">
                                  <button
                                    type="submit"
                                    class="btn btn-sm btn-outline-warning remove-vendor-trailer-btn"
                                    data-driver-id="<?= (int)$id ?>"
                                    data-assignment-id="<?= (int)$assignment['id'] ?>"
                                    data-assigned-date="<?= htmlspecialchars((string)($assignment['assigned_date'] ?? ''), ENT_QUOTES) ?>"
                                    onclick="return prepareRemoveVendorTrailer(this);"
                                  >Remove</button>
                                </div>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                  <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-6">
                      <label class="form-label">Add Client Trailer</label>
                      <select name="trailer_number" id="vendorTrailerAssignSelect" class="form-select">
                        <option value="">Select available trailer</option>
                        <?php foreach ($vendorTrailerOptions as $trailerOption): ?>
                          <option value="<?= htmlspecialchars($trailerOption['trailer_number'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars($trailerOption['vendor'] . ' - ' . $trailerOption['trailer_number'], ENT_QUOTES) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Received Date</label>
                      <input type="date" name="assigned_date" id="vendorTrailerAssignDate" class="form-control" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES) ?>">
                    </div>
                    <div class="col-md-3">
                      <button type="submit" id="assignVendorTrailerBtn" class="btn btn-outline-primary w-100" data-driver-id="<?= (int)$id ?>" onclick="return prepareAssignVendorTrailer();">Assign Trailer</button>
                    </div>
                    <div class="col-12">
                      <div class="form-text">A driver can have one active trailer per vendor. Trailers already assigned to another active driver are hidden.</div>
                    </div>
                  </div>
                </div>
              <?php endif; ?>
            </div>

            <div class="modal-footer px-0">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <?php if ($id): ?>
                <a href="driver_contacts.php" class="btn btn-secondary">New Contact</a>
              <?php endif; ?>
              <?php if (!empty($duplicateWarnings)): ?>
                <button type="submit" class="btn btn-warning" onclick="setTrailerAction('', '', '', ''); document.getElementById('contactDuplicateConfirmed').value='1';">Create Anyway</button>
              <?php endif; ?>
              <button type="submit" class="btn btn-primary" id="contactSubmitBtn" onclick="setTrailerAction('', '', '', '');"><?= $id ? 'Update Contact' : 'Add Contact' ?></button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="gasModal" tabindex="-1" aria-labelledby="gasModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="gasModalLabel">Add Gas Costs</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form method="post" class="mb-0">
            <input type="hidden" name="form_type" value="gas">
            <label class="form-label">Driver</label>
            <select name="driver_id" id="driver-gas" class="form-select" required>
              <option value="">--Select Driver--</option>
              <?php foreach ($drivers as $d): ?>
                <option value="<?= (int)$d['id'] ?>" <?= ((int)$gas_driver_id === (int)$d['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($d['name'], ENT_QUOTES) ?>
                </option>
              <?php endforeach; ?>
            </select>

            <div class="table-responsive mt-3">
              <table class="table table-bordered">
                <thead><tr><th>Date</th><th>Amount</th></tr></thead>
                <tbody>
                  <?php for ($i = 0; $i < 7; $i++): ?>
                    <tr>
                      <td><input type="date" name="dates[]" class="form-control date-input" data-idx="<?= $i ?>"></td>
                      <td><input type="number" step="0.01" name="amounts[]" id="gas-<?= $i ?>" class="form-control"></td>
                    </tr>
                  <?php endfor; ?>
                </tbody>
              </table>
            </div>

            <div class="modal-footer px-0">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary">Save Gas Costs</button>
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

  <script>
    <?php $openModal = ($openContactModal || ($contactSubmitted && !empty($errors)) || ($id && $_SERVER['REQUEST_METHOD'] !== 'POST')); ?>
    <?php if ($openModal): ?>
    window.addEventListener('load', () => {
      const modalEl = document.getElementById('contactModal');
      if (modalEl && window.bootstrap) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
      }
    });
    <?php endif; ?>
    <?php if ($openGasModal): ?>
    window.addEventListener('load', () => {
      const modalEl = document.getElementById('gasModal');
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

    const addContactBtn = document.getElementById('addContactBtn');
    const contactForm = document.getElementById('contactForm');
    const contactModalLabel = document.getElementById('contactModalLabel');
    const contactSubmitBtn = document.getElementById('contactSubmitBtn');
    const contactDuplicateConfirmed = document.getElementById('contactDuplicateConfirmed');
    const existingDriverContacts = <?= json_encode($duplicateDriverContacts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    if (addContactBtn && contactForm) {
      addContactBtn.addEventListener('click', () => {
        document.getElementById('contactId').value = '0';
        if (contactDuplicateConfirmed) contactDuplicateConfirmed.value = '0';
        document.getElementById('contactFirstName').value = '';
        document.getElementById('contactLastName').value = '';
        document.getElementById('contactOwnerNameSelect').value = '';
        document.getElementById('contactOwnerName').value = '';
        document.getElementById('contactOwnerName').style.display = 'none';
        document.getElementById('contactOwnerDotSelect').value = '';
        document.getElementById('contactOwnerDot').value = '';
        document.getElementById('contactOwnerDot').style.display = 'none';
        document.getElementById('contactEmail').value = '';
        document.getElementById('contactPhone').value = '';
        document.getElementById('contactTruckNo').value = '';
        document.getElementById('contactAltTruckNo').value = '';
        if (contactModalLabel) contactModalLabel.textContent = 'Add Contact';
        if (contactSubmitBtn) contactSubmitBtn.textContent = 'Add Contact';
      });
    }

    function normalizeDriverText(value) {
      return String(value || '').trim().replace(/\s+/g, ' ').toLowerCase();
    }

    function normalizeDriverDigits(value) {
      return String(value || '').replace(/\D+/g, '');
    }

    function normalizeDriverUnit(value) {
      return String(value || '').replace(/[^a-zA-Z0-9]+/g, '').toLowerCase();
    }

    function findPossibleDuplicateDrivers() {
      const currentId = parseInt(document.getElementById('contactId')?.value || '0', 10);
      if (currentId > 0) return [];

      const first = document.getElementById('contactFirstName')?.value || '';
      const last = document.getElementById('contactLastName')?.value || '';
      const email = document.getElementById('contactEmail')?.value || '';
      const phone = document.getElementById('contactPhone')?.value || '';
      const truck = document.getElementById('contactTruckNo')?.value || '';
      const altTruck = document.getElementById('contactAltTruckNo')?.value || '';
      const inputFullName = normalizeDriverText(`${first} ${last}`);
      const inputEmail = normalizeDriverText(email);
      const inputPhone = normalizeDriverDigits(phone);
      const inputTrucks = [normalizeDriverUnit(truck), normalizeDriverUnit(altTruck)].filter(Boolean);

      return existingDriverContacts.map((driver) => {
        if (parseInt(driver.id || '0', 10) === currentId) return null;
        const rowFullName = normalizeDriverText(`${driver.first_name || ''} ${driver.last_name || ''}`);
        const rowEmail = normalizeDriverText(driver.email || '');
        const rowPhone = normalizeDriverDigits(driver.phone || '');
        const rowTrucks = [normalizeDriverUnit(driver.truck_no || ''), normalizeDriverUnit(driver.alt_truck_no || '')].filter(Boolean);
        const sharedTruck = inputTrucks.some((truckNo) => rowTrucks.includes(truckNo));
        const sameName = inputFullName && inputFullName === rowFullName;
        const sameEmail = inputEmail && inputEmail === rowEmail;
        const samePhone = inputPhone.length >= 7 && inputPhone === rowPhone;
        const reasons = [];

        if (sameName && sameEmail) reasons.push('same driver name and email');
        if (sameName && samePhone) reasons.push('same driver name and phone');
        if (sharedTruck && samePhone) reasons.push('same truck number and phone');
        if (sharedTruck && sameEmail) reasons.push('same truck number and email');
        if (sameName && sharedTruck) reasons.push('same driver name and truck number');
        if (!reasons.length) return null;

        return {
          name: `${driver.first_name || ''} ${driver.last_name || ''}`.trim(),
          email: driver.email || '',
          phone: driver.phone || '',
          truck_no: driver.truck_no || '',
          status: parseInt(driver.is_disabled || '0', 10) === 1 ? 'Disabled' : 'Active',
          reason: [...new Set(reasons)].join(', ')
        };
      }).filter(Boolean).slice(0, 8);
    }

    contactForm?.addEventListener('submit', (event) => {
      if (contactDuplicateConfirmed?.value === '1') return;
      const matches = findPossibleDuplicateDrivers();
      if (!matches.length) return;

      const detail = matches.map((match) => {
        const pieces = [`${match.name} (${match.status})`, match.reason];
        if (match.email) pieces.push(match.email);
        if (match.phone) pieces.push(match.phone);
        if (match.truck_no) pieces.push(`Truck ${match.truck_no}`);
        return `- ${pieces.join(' | ')}`;
      }).join('\n');
      const proceed = window.confirm(`This driver looks like a possible duplicate:\n\n${detail}\n\nCreate this driver anyway?`);
      if (!proceed) {
        event.preventDefault();
        return;
      }
      if (contactDuplicateConfirmed) contactDuplicateConfirmed.value = '1';
    });

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

    // Link Owner Operator and DOT fields
    const ownerNameSelect = document.getElementById('contactOwnerNameSelect');
    const ownerNameInput = document.getElementById('contactOwnerName');
    const ownerDotSelect = document.getElementById('contactOwnerDotSelect');
    const ownerDotInput = document.getElementById('contactOwnerDot');
    const contactEmail = document.getElementById('contactEmail');
    const ownerDotMap = <?= json_encode($ownerDotMap) ?>;
    const dotOwnerMap = <?= json_encode($dotOwnerMap) ?>;
    const ownerEmailMap = <?= json_encode($ownerEmailMap) ?>;
    const dotEmailMap = <?= json_encode($dotEmailMap) ?>;
    const emailOwnerMap = <?= json_encode($emailOwnerMap) ?>;
    const emailDotMap = <?= json_encode($emailDotMap) ?>;

    function toggleOwnerInput() {
      if (ownerNameSelect.value === '__new__') {
        ownerNameInput.style.display = 'block';
        ownerNameInput.focus();
      } else {
        ownerNameInput.style.display = 'none';
        ownerNameInput.value = '';
      }
    }

    function toggleDotInput() {
      if (ownerDotSelect.value === '__new__') {
        ownerDotInput.style.display = 'block';
        ownerDotInput.focus();
      } else {
        ownerDotInput.style.display = 'none';
        ownerDotInput.value = '';
      }
    }

    function setSelectValueOrNew(select, textInput, value) {
      if (!select || !textInput || !value) return;
      const option = Array.from(select.options).find((opt) => opt.value === value);
      if (option) {
        select.value = value;
        textInput.value = '';
      } else {
        select.value = '__new__';
        textInput.value = value;
      }
    }

    if (ownerNameSelect && ownerNameInput) {
      ownerNameSelect.addEventListener('change', (e) => {
        toggleOwnerInput();
        const selectedOwner = e.target.value;
        if (selectedOwner && selectedOwner !== '__new__' && ownerDotMap[selectedOwner]) {
          setSelectValueOrNew(ownerDotSelect, ownerDotInput, ownerDotMap[selectedOwner]);
          toggleDotInput();
        }
        if (selectedOwner && selectedOwner !== '__new__' && ownerEmailMap[selectedOwner] && contactEmail) {
          contactEmail.value = ownerEmailMap[selectedOwner];
        }
      });
      // Set initial state
      toggleOwnerInput();
    }

    if (ownerDotSelect && ownerDotInput) {
      ownerDotSelect.addEventListener('change', (e) => {
        toggleDotInput();
        const selectedDot = e.target.value;
        if (selectedDot && selectedDot !== '__new__' && dotOwnerMap[selectedDot]) {
          setSelectValueOrNew(ownerNameSelect, ownerNameInput, dotOwnerMap[selectedDot]);
          toggleOwnerInput();
        }
        if (selectedDot && selectedDot !== '__new__' && dotEmailMap[selectedDot] && contactEmail) {
          contactEmail.value = dotEmailMap[selectedDot];
        }
      });
      // Set initial state
      toggleDotInput();
    }

    contactEmail?.addEventListener('change', () => {
      const selectedEmail = contactEmail.value.trim().toLowerCase();
      if (!selectedEmail) return;
      if (emailOwnerMap[selectedEmail]) {
        setSelectValueOrNew(ownerNameSelect, ownerNameInput, emailOwnerMap[selectedEmail]);
        toggleOwnerInput();
      }
      if (emailDotMap[selectedEmail]) {
        setSelectValueOrNew(ownerDotSelect, ownerDotInput, emailDotMap[selectedEmail]);
        toggleDotInput();
      }
    });

    function setTrailerAction(action, driverId, assignmentId, removedDate) {
      const actionInput = document.getElementById('contactAction');
      const driverInput = document.getElementById('trailerActionDriverId');
      const assignmentInput = document.getElementById('trailerActionAssignmentId');
      const removedInput = document.getElementById('trailerActionRemovedDate');
      if (actionInput) actionInput.value = action || '';
      if (driverInput) driverInput.value = driverId || '';
      if (assignmentInput) assignmentInput.value = assignmentId || '';
      if (removedInput) removedInput.value = removedDate || '';
    }

    function prepareAssignVendorTrailer() {
      const assignVendorTrailerBtn = document.getElementById('assignVendorTrailerBtn');
      const trailerSelect = document.getElementById('vendorTrailerAssignSelect');
      const dateInput = document.getElementById('vendorTrailerAssignDate');
      const trailerNumber = trailerSelect ? trailerSelect.value : '';
      if (!trailerNumber) {
        alert('Select an available trailer to assign.');
        return false;
      }
      setTrailerAction('assign_vendor_trailer', assignVendorTrailerBtn?.dataset.driverId || '', '', '');
      return true;
    }

    function prepareRemoveVendorTrailer(button) {
      const row = button.closest('tr');
      const dateInput = row ? row.querySelector('.trailer-assignment-remove-date') : null;
      const removedDate = dateInput ? dateInput.value : '';
      if (!removedDate) {
        alert('Enter the trailer removed date before removing this assignment.');
        return false;
      }
      const assignedDate = button.dataset.assignedDate || '';
      if (assignedDate && removedDate < assignedDate) {
        alert('Trailer removed date cannot be earlier than the received date.');
        return false;
      }
      if (!confirm('Remove this trailer assignment?')) return false;
      setTrailerAction('remove_vendor_trailer', button.dataset.driverId || '', button.dataset.assignmentId || '', removedDate);
      return true;
    }
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
