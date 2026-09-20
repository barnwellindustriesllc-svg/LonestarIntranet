<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');

$docTypes = [
  'license' => 'License (CDL)',
  'coi' => 'COI (Insurance)',
  'pec' => 'PEC',
  'h2s' => 'H2S',
  'medical_card' => 'Medical Card',
  'mvr' => 'MVR',
  'w9' => 'W-9',
  'voided_check' => 'Voided Check',
  'contract' => 'Contract',
  'broker_carrier_agreement' => 'Broker/Carrier Agreement',
  'trailer_agreement' => 'Trailer Agreement',
  'misc' => 'Misc',
];

$action = $_GET['action'] ?? '';

const DRIVER_DOC_MAX_POST_HINT = 'Upload failed because the file exceeds the server size limit. Please upload a smaller file.';
const DRIVER_DOC_FILES_DIR = __DIR__ . '/files';
const DRIVER_DOC_EXPIRING_TYPES = ['license', 'coi', 'pec', 'h2s', 'medical_card'];

function driver_doc_type_requires_expiration(string $docType): bool {
  return in_array($docType, DRIVER_DOC_EXPIRING_TYPES, true);
}

function driver_doc_valid_date(string $date): bool {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    return false;
  }
  [$year, $month, $day] = array_map('intval', explode('-', $date));
  return checkdate($month, $day, $year);
}

function driver_doc_ensure_expiration_column(mysqli $mysqli): void {
  $res = $mysqli->query(
    "SELECT COUNT(*) AS col_count
       FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'driver_documents'
        AND COLUMN_NAME = 'expiration_date'"
  );
  $row = $res ? $res->fetch_assoc() : null;
  if ($res) {
    $res->close();
  }
  if ((int)($row['col_count'] ?? 0) === 0) {
    $mysqli->query("ALTER TABLE driver_documents ADD COLUMN expiration_date DATE NULL AFTER file_size");
  }
}

try {
  driver_doc_ensure_expiration_column($mysqli);
} catch (Throwable $e) {
  error_log('Driver document expiration column setup failed: ' . $e->getMessage());
}

if (in_array($action, ['upload', 'replace'], true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  driver_doc_log_error('Upload endpoint must be POST.', [
    'action' => $action,
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
  ]);
  http_response_code(405);
  echo 'Upload endpoint expects POST. If you are being redirected, check web server rules or security filters.';
  exit;
}

function driver_doc_log_error(string $message, array $context = []): void {
  global $mysqli;
  if (!$mysqli) {
    return;
  }

  $action = isset($context['action']) ? (string)$context['action'] : null;
  $driverId = array_key_exists('driver_id', $context) ? (int)$context['driver_id'] : null;
  $docType = isset($context['doc_type']) ? (string)$context['doc_type'] : null;
  $docId = array_key_exists('doc_id', $context) ? (int)$context['doc_id'] : null;
  $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
  $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
  $json = json_encode($context);
  if ($json === false) {
    $json = null;
  } elseif (strlen($json) > 8000) {
    $json = substr($json, 0, 8000);
  }

  try {
    $stmt = $mysqli->prepare(
      'INSERT INTO driver_document_upload_logs
        (action, driver_contact_id, doc_type, doc_id, message, context_json, user_id, ip_address)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sissssis', $action, $driverId, $docType, $docId, $message, $json, $userId, $ipAddress);
    $stmt->execute();
    $stmt->close();
  } catch (mysqli_sql_exception $e) {
    error_log('Driver document log insert failed: ' . $e->getMessage());
  }
}

function driver_doc_redirect(string $message, array $params = [], array $context = []): void {
  $context['action'] = $context['action'] ?? ($_GET['action'] ?? null);
  if (!array_key_exists('driver_id', $context) && isset($params['driver_id'])) {
    $context['driver_id'] = $params['driver_id'];
  }
  if (!array_key_exists('doc_type', $context) && isset($params['doc_type'])) {
    $context['doc_type'] = $params['doc_type'];
  }
  if (!array_key_exists('doc_id', $context) && isset($_POST['doc_id'])) {
    $context['doc_id'] = $_POST['doc_id'];
  }

  driver_doc_log_error($message, $context);
  if (session_status() !== PHP_SESSION_NONE) {
    $_SESSION['driver_doc_last_error'] = [
      'message' => $message,
      'context' => $context,
      'time' => time(),
    ];
  }
  $qs = http_build_query($params + ['error' => $message]);
  $target = 'driver_document_lookup.php' . ($qs ? ('?' . $qs) : '');
  if (!headers_sent()) {
    header('Location: ' . $target);
    exit;
  }

  http_response_code(400);
  echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Upload Error</title></head><body>';
  echo '<p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>';
  echo '<p><a href="' . htmlspecialchars($target, ENT_QUOTES) . '">Return to document lookup</a></p>';
  echo '</body></html>';
  exit;
}

function driver_doc_upload_error_message(int $uploadError): string {
  return match ($uploadError) {
    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large.',
    UPLOAD_ERR_PARTIAL => 'File upload was incomplete.',
    UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
    default => 'File upload failed.',
  };
}

function driver_doc_normalize_uploads(array $fileField): array {
  if (is_array($fileField['name'] ?? null)) {
    $uploads = [];
    foreach ($fileField['name'] as $idx => $name) {
      $uploads[] = [
        'name' => $name,
        'type' => $fileField['type'][$idx] ?? '',
        'tmp_name' => $fileField['tmp_name'][$idx] ?? '',
        'error' => $fileField['error'][$idx] ?? UPLOAD_ERR_NO_FILE,
        'size' => $fileField['size'][$idx] ?? 0,
      ];
    }
    return $uploads;
  }

  return [$fileField];
}

function driver_doc_store_upload(array $upload, array $allowedExts, array $allowedMimes, callable $redirectWithError, string $failurePrefix = 'Upload failed'): array {
  if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    $redirectWithError(driver_doc_upload_error_message($uploadError), [
      'upload_error' => $uploadError,
      'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
    ]);
  }

  if (!is_uploaded_file($upload['tmp_name'] ?? '')) {
    $redirectWithError('Unable to process uploaded file.', [
      'tmp_name' => $upload['tmp_name'] ?? null,
      'size' => $upload['size'] ?? null,
    ]);
  }

  $filename = basename($upload['name'] ?? '');
  if ($filename === '') {
    $filename = 'document_' . time();
  }
  $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
  if (!in_array($ext, $allowedExts, true)) {
    $redirectWithError('Invalid file type. Allowed: PDF, JPG, PNG, DOCX.', [
      'filename' => $filename,
      'ext' => $ext,
    ]);
  }

  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $detectedMime = $finfo ? finfo_file($finfo, $upload['tmp_name']) : '';
  if ($finfo) {
    finfo_close($finfo);
  }
  $mimeType = $detectedMime ?: ($upload['type'] ?? 'application/octet-stream');
  if (!in_array($mimeType, $allowedMimes, true)) {
    $redirectWithError('Invalid file type. Allowed: PDF, JPG, PNG, DOCX.', [
      'filename' => $filename,
      'mime_type' => $mimeType,
    ]);
  }

  if (!is_dir(DRIVER_DOC_FILES_DIR) && !mkdir(DRIVER_DOC_FILES_DIR, 0755, true)) {
    $redirectWithError($failurePrefix . '. Files directory is missing or not writable.', [
      'files_dir' => DRIVER_DOC_FILES_DIR,
    ]);
  }

  $safeBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($filename, PATHINFO_FILENAME));
  $random = bin2hex(random_bytes(8));
  $storedName = $safeBase . '_' . $random . '.' . $ext;
  $storedPath = DRIVER_DOC_FILES_DIR . '/' . $storedName;
  if (!move_uploaded_file($upload['tmp_name'], $storedPath)) {
    $redirectWithError($failurePrefix . '. Unable to save file to disk.', [
      'stored_path' => $storedPath,
    ]);
  }

  return [
    'filename' => $filename,
    'mime_type' => $mimeType,
    'stored_name' => $storedName,
    'stored_path' => $storedPath,
    'file_size' => (int)filesize($storedPath),
  ];
}

if ($action === 'file') {
  $docId = (int)($_GET['id'] ?? 0);
  if ($docId <= 0) {
    http_response_code(400);
    echo 'Invalid document request.';
    exit;
  }

  $stmt = $mysqli->prepare(
    'SELECT filename, mime_type, file_blob, file_path, file_size
       FROM driver_documents
      WHERE id = ?
      LIMIT 1'
  );
  $stmt->bind_param('i', $docId);
  $stmt->execute();
  $res = $stmt->get_result();
  $doc = $res->fetch_assoc();
  $stmt->close();

  if (!$doc) {
    http_response_code(404);
    echo 'Document not found.';
    exit;
  }

  $filename = $doc['filename'] ?: ('document_' . $docId);
  $mimeType = $doc['mime_type'] ?: 'application/octet-stream';

  header('Content-Type: ' . $mimeType);
  header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
  if (!empty($doc['file_path'])) {
    $path = DRIVER_DOC_FILES_DIR . '/' . ltrim($doc['file_path'], '/');
    if (is_file($path)) {
      if (!empty($doc['file_size'])) {
        header('Content-Length: ' . (int)$doc['file_size']);
      } else {
        header('Content-Length: ' . (int)filesize($path));
      }
      readfile($path);
      exit;
    }
  }
  echo $doc['file_blob'];
  exit;
}

if ($action === 'download') {
  $docId = (int)($_GET['id'] ?? 0);
  if ($docId <= 0) {
    http_response_code(400);
    echo 'Invalid document request.';
    exit;
  }

  $stmt = $mysqli->prepare(
    'SELECT filename, mime_type, file_blob, file_path, file_size
       FROM driver_documents
      WHERE id = ?
      LIMIT 1'
  );
  $stmt->bind_param('i', $docId);
  $stmt->execute();
  $res = $stmt->get_result();
  $doc = $res->fetch_assoc();
  $stmt->close();

  if (!$doc) {
    http_response_code(404);
    echo 'Document not found.';
    exit;
  }

  $filename = $doc['filename'] ?: ('document_' . $docId);
  $mimeType = $doc['mime_type'] ?: 'application/octet-stream';

  header('Content-Type: ' . $mimeType);
  header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
  if (!empty($doc['file_path'])) {
    $path = DRIVER_DOC_FILES_DIR . '/' . ltrim($doc['file_path'], '/');
    if (is_file($path)) {
      if (!empty($doc['file_size'])) {
        header('Content-Length: ' . (int)$doc['file_size']);
      } else {
        header('Content-Length: ' . (int)filesize($path));
      }
      readfile($path);
      exit;
    }
  }
  echo $doc['file_blob'];
  exit;
}

if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $driverId = (int)($_POST['driver_id'] ?? 0);
  $docType = trim($_POST['doc_type'] ?? '');
  $expirationDate = trim($_POST['expiration_date'] ?? '');
  $docTypeOk = $docType !== '' && preg_match('/^[a-z0-9_]{2,64}$/', $docType);
  if (!empty($_POST['debug'])) {
    driver_doc_log_error('Upload request received.', [
      'action' => 'upload',
      'driver_id' => $driverId,
      'doc_type' => $docType,
      'files_keys' => array_keys($_FILES),
      'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
    ]);
  }
  $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'docx'];
  $allowedMimes = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  ];

  $redirectWithError = function (string $message, array $context = []) use ($driverId, $docType) {
    driver_doc_redirect($message, [
      'driver_id' => $driverId ?: '',
      'doc_type' => $docType ?: '',
    ], $context);
  };

  if ($driverId <= 0 || !$docTypeOk) {
    $redirectWithError('Invalid upload request.', [
      'driver_id' => $driverId,
      'doc_type' => $docType,
    ]);
  }
  if (driver_doc_type_requires_expiration($docType) && $expirationDate === '') {
    $redirectWithError('Expiration date is required for this document type.', [
      'driver_id' => $driverId,
      'doc_type' => $docType,
    ]);
  }
  if ($expirationDate !== '' && !driver_doc_valid_date($expirationDate)) {
    $redirectWithError('Expiration date must be a valid date.', [
      'driver_id' => $driverId,
      'doc_type' => $docType,
      'expiration_date' => $expirationDate,
    ]);
  }

  if (empty($_FILES['document_file'])) {
    if (!empty($_SERVER['CONTENT_LENGTH'])) {
      $redirectWithError(DRIVER_DOC_MAX_POST_HINT, [
        'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
        'post_max_size' => ini_get('post_max_size'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
      ]);
    }
    $redirectWithError('No file received.', [
      'files_keys' => array_keys($_FILES),
      'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
    ]);
  }

  $uploads = array_values(array_filter(
    driver_doc_normalize_uploads($_FILES['document_file']),
    fn(array $upload): bool => (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
  ));
  if (!$uploads) {
    $redirectWithError('No file was uploaded.', [
      'files_keys' => array_keys($_FILES),
      'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
    ]);
  }

  $storedFiles = [];
  try {
    $stmt = $mysqli->prepare(
      'INSERT INTO driver_documents (driver_contact_id, doc_type, filename, mime_type, file_path, file_size, expiration_date)
       VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, ""))'
    );
    foreach ($uploads as $upload) {
      $stored = driver_doc_store_upload($upload, $allowedExts, $allowedMimes, $redirectWithError, 'Upload failed');
      $storedFiles[] = $stored['stored_path'];
      $storedFilename = $stored['filename'];
      $storedMimeType = $stored['mime_type'];
      $storedName = $stored['stored_name'];
      $storedFileSize = $stored['file_size'];
      $stmt->bind_param('issssis', $driverId, $docType, $storedFilename, $storedMimeType, $storedName, $storedFileSize, $expirationDate);
      $stmt->execute();
    }
    $stmt->close();
  } catch (mysqli_sql_exception $e) {
    foreach ($storedFiles as $storedPath) {
      @unlink($storedPath);
    }
    error_log('Driver document upload failed: ' . $e->getMessage());
    $redirectWithError('Upload failed. Make sure the driver_documents table exists.', [
      'db_error' => $e->getMessage(),
    ]);
  }

  $qs = http_build_query([
    'uploaded' => 1,
    'uploaded_count' => count($uploads),
    'driver_id' => $driverId,
    'doc_type' => $docType,
  ]);
  header('Location: driver_document_lookup.php?' . $qs);
  exit;
}

if ($action === 'replace' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $docId = (int)($_POST['doc_id'] ?? 0);
  $expirationDate = trim($_POST['expiration_date'] ?? '');
  $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'docx'];
  $allowedMimes = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  ];
  $redirectWithError = function (string $message, array $context = []) {
    driver_doc_redirect($message, [], $context);
  };

  if (!empty($_POST['debug'])) {
    driver_doc_log_error('Replace request received.', [
      'action' => 'replace',
      'doc_id' => $docId,
      'files_keys' => array_keys($_FILES),
      'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
    ]);
  }

  if ($docId <= 0) {
    $redirectWithError('Invalid replace request.', [
      'doc_id' => $docId,
    ]);
  }

  $info = null;
  try {
    $stmtInfo = $mysqli->prepare(
      'SELECT driver_contact_id, doc_type, file_path FROM driver_documents WHERE id = ? LIMIT 1'
    );
    $stmtInfo->bind_param('i', $docId);
    $stmtInfo->execute();
    $stmtInfo->bind_result($infoDriverId, $infoDocType, $infoFilePath);
    if ($stmtInfo->fetch()) {
      $info = [
        'driver_contact_id' => $infoDriverId,
        'doc_type' => $infoDocType,
        'file_path' => $infoFilePath,
      ];
    }
    $stmtInfo->close();
  } catch (mysqli_sql_exception $e) {
    error_log('Driver document replace lookup failed: ' . $e->getMessage());
    $redirectWithError('Replace failed. Unable to locate the existing document.', [
      'db_error' => $e->getMessage(),
      'doc_id' => $docId,
    ]);
  }
  if (!$info) {
    $redirectWithError('Replace failed. Unable to locate the existing document.', [
      'doc_id' => $docId,
    ]);
  }
  if (driver_doc_type_requires_expiration((string)$info['doc_type']) && $expirationDate === '') {
    $redirectWithError('Expiration date is required for this document type.', [
      'doc_id' => $docId,
      'doc_type' => $info['doc_type'],
    ]);
  }
  if ($expirationDate !== '' && !driver_doc_valid_date($expirationDate)) {
    $redirectWithError('Expiration date must be a valid date.', [
      'doc_id' => $docId,
      'doc_type' => $info['doc_type'],
      'expiration_date' => $expirationDate,
    ]);
  }

  if (empty($_FILES['document_file'])) {
    if (!empty($_SERVER['CONTENT_LENGTH'])) {
      $redirectWithError(DRIVER_DOC_MAX_POST_HINT, [
        'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
        'post_max_size' => ini_get('post_max_size'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
      ]);
    }
    $redirectWithError('No file received.', [
      'files_keys' => array_keys($_FILES),
      'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
    ]);
  }

  $uploads = array_values(array_filter(
    driver_doc_normalize_uploads($_FILES['document_file']),
    fn(array $upload): bool => (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
  ));
  if (!$uploads) {
    $redirectWithError('No file was uploaded.', [
      'files_keys' => array_keys($_FILES),
      'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
    ]);
  }
  $stored = driver_doc_store_upload($uploads[0], $allowedExts, $allowedMimes, $redirectWithError, 'Replace failed');
  $filename = $stored['filename'];
  $mimeType = $stored['mime_type'];
  $storedName = $stored['stored_name'];
  $storedPath = $stored['stored_path'];
  $fileSize = $stored['file_size'];

  try {
    $stmt = $mysqli->prepare(
      'UPDATE driver_documents
          SET filename = ?, mime_type = ?, file_path = ?, file_size = ?, expiration_date = NULLIF(?, ""), file_blob = NULL, uploaded_at = NOW()
        WHERE id = ?'
    );
    $stmt->bind_param('sssisi', $filename, $mimeType, $storedName, $fileSize, $expirationDate, $docId);
    $stmt->execute();
    $stmt->close();

    if (!empty($info['file_path'])) {
      $oldPath = DRIVER_DOC_FILES_DIR . '/' . ltrim($info['file_path'], '/');
      if (is_file($oldPath)) {
        @unlink($oldPath);
      }
    }
  } catch (mysqli_sql_exception $e) {
    @unlink($storedPath);
    error_log('Driver document replace failed: ' . $e->getMessage());
    $redirectWithError('Replace failed. Make sure the driver_documents table exists.', [
      'db_error' => $e->getMessage(),
    ]);
  }

  $qs = http_build_query([
    'replaced' => 1,
    'driver_id' => $info['driver_contact_id'] ?? '',
    'doc_type' => $info['doc_type'] ?? '',
  ]);
  header('Location: driver_document_lookup.php?' . $qs);
  exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=utf-8');
  $docId = (int)($_POST['doc_id'] ?? 0);
  if ($docId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid delete request.']);
    exit;
  }

  $stmtPath = $mysqli->prepare('SELECT file_path FROM driver_documents WHERE id = ? LIMIT 1');
  $stmtPath->bind_param('i', $docId);
  $stmtPath->execute();
  $stmtPath->bind_result($filePathToDelete);
  $pathRow = $stmtPath->fetch() ? ['file_path' => $filePathToDelete] : [];
  $stmtPath->close();

  $stmt = $mysqli->prepare('DELETE FROM driver_documents WHERE id = ?');
  $stmt->bind_param('i', $docId);
  $stmt->execute();
  $stmt->close();

  if (!empty($pathRow['file_path'])) {
    $deletePath = DRIVER_DOC_FILES_DIR . '/' . ltrim($pathRow['file_path'], '/');
    if (is_file($deletePath)) {
      @unlink($deletePath);
    }
  }

  echo json_encode(['ok' => true]);
  exit;
}

if ($action === 'list') {
  header('Content-Type: application/json; charset=utf-8');
  $driverId = (int)($_GET['driver_id'] ?? 0);
  $docType = $_GET['doc_type'] ?? '';

  $docType = trim($docType);
  $docTypeOk = $docType !== '' && preg_match('/^[a-z0-9_]{2,64}$/', $docType);
  if ($driverId <= 0 || !$docTypeOk) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request.']);
    exit;
  }

  $stmt = $mysqli->prepare(
    'SELECT id, filename, mime_type, uploaded_at, expiration_date
       FROM driver_documents
      WHERE driver_contact_id = ?
        AND doc_type = ?
   ORDER BY uploaded_at DESC, id DESC'
  );
  $stmt->bind_param('is', $driverId, $docType);
  $stmt->execute();
  $res = $stmt->get_result();
  $docs = [];
  while ($row = $res->fetch_assoc()) {
    $docs[] = [
      'id' => (int)$row['id'],
      'filename' => $row['filename'],
      'mime_type' => $row['mime_type'],
      'uploaded_at' => $row['uploaded_at'],
      'expiration_date' => $row['expiration_date'],
    ];
  }
  $stmt->close();

  echo json_encode([
    'driver_id' => $driverId,
    'doc_type' => $docType,
    'documents' => $docs,
  ]);
  exit;
}

$uploadMax = ini_get('upload_max_filesize');
$postMax = ini_get('post_max_size');
$fileinfoAvailable = function_exists('finfo_open');
$debugEnabled = (($_GET['debug'] ?? '') === '1');
$showDisabledDrivers = (($_GET['show_disabled'] ?? '') === '1');
$lastUploadError = $_SESSION['driver_doc_last_error'] ?? null;
$fileUploads = ini_get('file_uploads');
$uploadTmpDir = ini_get('upload_tmp_dir');
$uploadTmpDirResolved = $uploadTmpDir ?: sys_get_temp_dir();
$uploadTmpWritable = $uploadTmpDirResolved ? (is_dir($uploadTmpDirResolved) && is_writable($uploadTmpDirResolved)) : false;
$filesDirWritable = is_dir(DRIVER_DOC_FILES_DIR) ? is_writable(DRIVER_DOC_FILES_DIR) : is_writable(__DIR__);
$basePath = function_exists('lonestar_base_path') ? lonestar_base_path() : '';
$selfPath = ($basePath !== '') ? ($basePath . '/driver_document_lookup') : 'driver_document_lookup';
$recentUploadLogs = [];

$drivers = [];
$driverSql = "
  SELECT id, CONCAT(first_name, ' ', last_name) AS name
    FROM driver_contacts
";
if (!$showDisabledDrivers) {
  $driverSql .= " WHERE COALESCE(is_disabled, 0) = 0";
}
$driverSql .= " ORDER BY first_name, last_name";
$resDrivers = $mysqli->query($driverSql);
while ($row = $resDrivers->fetch_assoc()) {
  $drivers[] = $row;
}

if ($debugEnabled) {
  try {
    $stmtLogs = $mysqli->prepare(
      'SELECT id, action, driver_contact_id, doc_type, doc_id, message, created_at, user_id, ip_address, context_json
         FROM driver_document_upload_logs
     ORDER BY id DESC
        LIMIT 5'
    );
    $stmtLogs->execute();
    $resLogs = $stmtLogs->get_result();
    while ($row = $resLogs->fetch_assoc()) {
      $recentUploadLogs[] = $row;
    }
    $stmtLogs->close();
  } catch (mysqli_sql_exception $e) {
    $recentUploadLogs = [];
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Driver Document Lookup</title>
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

    .document-shell {
      border: 3px solid #222;
      border-radius: 12px;
      padding: 16px;
      background: #f9fafb;
      min-height: 70vh;
      box-shadow: 0 8px 18px rgba(0,0,0,0.08);
    }

    .document-layout {
      display: grid;
      grid-template-columns: 300px 1fr;
      gap: 16px;
      min-height: 60vh;
    }

    @media (max-width: 992px) {
      .document-layout { grid-template-columns: 1fr; }
    }

    .driver-list {
      background: #fff;
      border: 1px solid #ddd;
      border-radius: 10px;
      padding: 12px;
      max-height: 70vh;
      overflow: auto;
    }

    .driver-item {
      border-bottom: 1px solid #eee;
      padding: 10px 0;
    }

    .driver-item:last-child {
      border-bottom: none;
    }

    .driver-item summary {
      cursor: pointer;
      font-weight: 600;
      list-style: none;
    }

    .driver-item summary::-webkit-details-marker { display: none; }

    .driver-item summary::after {
      content: "▾";
      float: right;
      color: #777;
      transition: transform 0.2s ease;
    }

    .driver-item[open] summary::after {
      transform: rotate(180deg);
    }

    .doc-type-list {
      display: flex;
      flex-direction: column;
      gap: 6px;
      margin-top: 8px;
    }

    .doc-type-btn {
      border: 1px solid #ccd;
      background: #f4f6fb;
      color: #1a1a1a;
      padding: 6px 8px;
      border-radius: 6px;
      text-align: left;
      cursor: pointer;
      font-size: 0.9rem;
    }

    .doc-type-btn:hover {
      background: #e8ecf6;
    }

    .viewer {
      background: #fff;
      border: 1px solid #ddd;
      border-radius: 10px;
      padding: 12px;
      min-height: 60vh;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .viewer-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-weight: 600;
      color: #222;
    }

    .viewer-actions {
      display: flex;
      gap: 8px;
      align-items: center;
    }

    .upload-btn {
      border: 1px solid #002A5C;
      background: #002A5C;
      color: #fff;
      font-weight: 600;
      padding: 6px 10px;
      border-radius: 6px;
      cursor: pointer;
    }

    .doc-list {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .doc-card {
      border: 1px solid #cdd5df;
      background: #f8fafc;
      padding: 8px;
      border-radius: 8px;
      font-size: 0.85rem;
      display: flex;
      flex-direction: column;
      gap: 6px;
      min-width: 160px;
    }

    .doc-name {
      font-weight: 600;
      color: #222;
    }

    .doc-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }

    .doc-link {
      border: 1px solid #cdd5df;
      background: #f8fafc;
      padding: 6px 10px;
      border-radius: 6px;
      font-size: 0.85rem;
      cursor: pointer;
    }

    .doc-link:hover {
      background: #eef2f8;
    }

    .doc-viewer {
      flex: 1;
      min-height: 600px;
      border: 1px solid #d7d7d7;
      border-radius: 8px;
      overflow: hidden;
      background: #fff;
    }

    .doc-frame {
      width: 100%;
      height: 100%;
      border: none;
    }

    .empty-state {
      color: #666;
      font-style: italic;
    }

    .toast {
      position: fixed;
      right: 16px;
      top: calc(var(--banner-h) + 16px);
      background: #1f2937;
      color: #fff;
      padding: 10px 14px;
      border-radius: 8px;
      box-shadow: 0 8px 18px rgba(0,0,0,0.2);
      opacity: 0;
      transform: translateY(-8px);
      transition: opacity .2s ease, transform .2s ease;
      z-index: 300;
      pointer-events: none;
    }

    .toast.show {
      opacity: 1;
      transform: translateY(0);
    }

    .app-modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.45);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 200;
      opacity: 1;
    }

    .app-modal-backdrop.show { display: flex; }

    .modal-card {
      width: min(520px, 92vw);
      background: #fff;
      border-radius: 12px;
      padding: 16px;
      border: 2px solid #222;
      box-shadow: 0 14px 28px rgba(0,0,0,0.18);
    }

    .modal-title {
      font-weight: 700;
      margin-bottom: 12px;
    }

    .drop-zone {
      border: 2px dashed #7c8796;
      background: #f8fafc;
      border-radius: 8px;
      padding: 18px;
      text-align: center;
      cursor: pointer;
      transition: border-color .2s ease, background .2s ease;
    }

    .drop-zone:hover,
    .drop-zone.drag-over {
      border-color: #1a1a1a;
      background: #eef2f8;
    }

    .drop-zone-title {
      font-weight: 700;
      color: #222;
    }

    .drop-zone-subtitle {
      color: #5f6b7a;
      font-size: .9rem;
      margin-top: 4px;
    }

    .selected-files {
      margin: 8px 0 0;
      padding-left: 18px;
      color: #263241;
      font-size: .9rem;
    }

    .modal-actions {
      display: flex;
      gap: 8px;
      justify-content: flex-end;
      margin-top: 12px;
    }

    .btn-plain {
      border: 1px solid #999;
      background: #f3f3f3;
      padding: 6px 10px;
      border-radius: 6px;
      cursor: pointer;
    }

    .btn-primary {
      border: 1px solid #222;
      background: #1a1a1a;
      color: #fff;
      padding: 6px 10px;
      border-radius: 6px;
      cursor: pointer;
    }

    .btn-danger {
      border: 1px solid #b91c1c;
      background: #b91c1c;
      color: #fff;
      padding: 6px 10px;
      border-radius: 6px;
      cursor: pointer;
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main">
      <h1>Driver Document Lookup</h1>
      <form method="get" class="mb-3">
        <?php if ($debugEnabled): ?>
          <input type="hidden" name="debug" value="1">
        <?php endif; ?>
        <div class="form-check">
          <input
            class="form-check-input"
            type="checkbox"
            id="showDisabledDrivers"
            name="show_disabled"
            value="1"
            <?= $showDisabledDrivers ? 'checked' : '' ?>
            onchange="this.form.submit()"
          >
          <label class="form-check-label" for="showDisabledDrivers">View disabled drivers</label>
        </div>
      </form>
      <div class="alert alert-secondary mb-3" style="max-width: 680px;">
        <strong>System Info:</strong>
        Upload max: <?= htmlspecialchars($uploadMax ?: 'Unknown', ENT_QUOTES) ?>,
        Post max: <?= htmlspecialchars($postMax ?: 'Unknown', ENT_QUOTES) ?>,
        Fileinfo: <?= $fileinfoAvailable ? 'Available' : 'Missing' ?>
      </div>
      <?php if ($debugEnabled): ?>
        <div class="alert alert-warning mb-3" style="max-width: 680px;">
          <strong>Upload Diagnostics (debug=1):</strong><br>
          <div>Request method: <?= htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? '', ENT_QUOTES) ?></div>
          <div>Content-Length: <?= htmlspecialchars($_SERVER['CONTENT_LENGTH'] ?? 'n/a', ENT_QUOTES) ?></div>
          <div>file_uploads: <?= htmlspecialchars((string)$fileUploads, ENT_QUOTES) ?></div>
          <div>upload_tmp_dir: <?= htmlspecialchars($uploadTmpDirResolved ?: 'n/a', ENT_QUOTES) ?> (writable: <?= $uploadTmpWritable ? 'yes' : 'no' ?>)</div>
          <div>files/ writable: <?= $filesDirWritable ? 'yes' : 'no' ?></div>
          <?php if ($lastUploadError): ?>
            Last error (<?= htmlspecialchars(date('Y-m-d H:i:s', $lastUploadError['time'] ?? time()), ENT_QUOTES) ?>):
            <?= htmlspecialchars($lastUploadError['message'] ?? 'Unknown error', ENT_QUOTES) ?><br>
            Context:
            <pre style="white-space: pre-wrap; margin: 6px 0 0;"><?= htmlspecialchars(json_encode($lastUploadError['context'] ?? [], JSON_PRETTY_PRINT), ENT_QUOTES) ?></pre>
          <?php else: ?>
            No recent upload errors recorded in this session.
          <?php endif; ?>
          <?php if (!empty($recentUploadLogs)): ?>
            <hr>
            <strong>Recent DB Logs:</strong>
            <?php foreach ($recentUploadLogs as $log): ?>
              <div style="margin-top: 8px;">
                #<?= (int)$log['id'] ?> • <?= htmlspecialchars($log['created_at'] ?? '', ENT_QUOTES) ?>
                • <?= htmlspecialchars($log['action'] ?? '', ENT_QUOTES) ?>
                • <?= htmlspecialchars($log['message'] ?? '', ENT_QUOTES) ?><br>
                Driver: <?= htmlspecialchars((string)($log['driver_contact_id'] ?? ''), ENT_QUOTES) ?>
                • Doc: <?= htmlspecialchars((string)($log['doc_type'] ?? ''), ENT_QUOTES) ?>
                • Doc ID: <?= htmlspecialchars((string)($log['doc_id'] ?? ''), ENT_QUOTES) ?>
                • User: <?= htmlspecialchars((string)($log['user_id'] ?? ''), ENT_QUOTES) ?>
                • IP: <?= htmlspecialchars((string)($log['ip_address'] ?? ''), ENT_QUOTES) ?>
                <?php if (!empty($log['context_json'])): ?>
                  <pre style="white-space: pre-wrap; margin: 6px 0 0;"><?= htmlspecialchars($log['context_json'], ENT_QUOTES) ?></pre>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <hr>
            <div>No recent DB logs found.</div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="toast" id="toast"></div>
      <div class="document-shell">
        <div class="document-layout">
          <div class="driver-list">
            <?php if (empty($drivers)): ?>
              <div class="empty-state">No drivers found.</div>
            <?php else: ?>
              <?php foreach ($drivers as $driver): ?>
                <details class="driver-item" data-driver-id="<?= (int)$driver['id'] ?>">
                  <summary><?= htmlspecialchars($driver['name'] ?? '', ENT_QUOTES) ?></summary>
                  <div class="doc-type-list">
                    <?php foreach ($docTypes as $key => $label): ?>
                      <button
                        class="doc-type-btn"
                        type="button"
                        data-doc-type="<?= htmlspecialchars($key, ENT_QUOTES) ?>"
                      >
                        <?= htmlspecialchars($label, ENT_QUOTES) ?>
                      </button>
                    <?php endforeach; ?>
                  </div>
                </details>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <div class="viewer">
            <div class="viewer-header">
              <span id="viewerHeader">Select a driver and document type.</span>
              <div class="viewer-actions">
                <button class="doc-link" type="button" id="exportBtn" disabled>Export Current</button>
                <button class="upload-btn" type="button" id="openUploadBtn" disabled>Upload Document</button>
              </div>
            </div>
            <div class="doc-list" id="docList"></div>
            <div class="doc-viewer">
              <iframe class="doc-frame" id="docFrame" title="Document Viewer"></iframe>
            </div>
            <div class="empty-state" id="viewerHint">
              Documents will appear here once selected.
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="app-modal-backdrop" id="uploadModal">
    <div class="modal-card">
      <div class="modal-title">Upload Driver Document</div>
      <form
        id="uploadForm"
        method="post"
        enctype="multipart/form-data"
        action="<?= htmlspecialchars($selfPath, ENT_QUOTES) ?>?action=upload"
        data-upload-url="<?= htmlspecialchars($selfPath, ENT_QUOTES) ?>"
      >
        <input type="hidden" name="driver_id" id="uploadDriverId">
        <input type="hidden" name="doc_id" id="replaceDocId">
        <?php if ($debugEnabled): ?>
          <input type="hidden" name="debug" value="1">
        <?php endif; ?>
        <div class="mb-2">
          <label for="uploadDocType" class="form-label">Document Type</label>
          <select name="doc_type" id="uploadDocType" class="form-select" required>
            <?php foreach ($docTypes as $key => $label): ?>
              <option value="<?= htmlspecialchars($key, ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2" id="expirationDateGroup" style="display: none;">
          <label for="uploadExpirationDate" class="form-label">Expiration Date</label>
          <input
            type="date"
            name="expiration_date"
            id="uploadExpirationDate"
            class="form-control"
          >
        </div>
        <div class="mb-2">
          <label for="uploadFile" class="form-label">Select File(s)</label>
          <div class="drop-zone" id="uploadDropZone">
            <div class="drop-zone-title">Drop documents here</div>
            <div class="drop-zone-subtitle">or click to browse</div>
          </div>
          <input
            type="file"
            name="document_file[]"
            id="uploadFile"
            class="form-control mt-2"
            accept=".pdf,.jpg,.jpeg,.png,.docx,application/pdf,image/jpeg,image/png,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
            multiple
            required
          >
          <ul class="selected-files" id="selectedFilesList"></ul>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn-plain" id="cancelUploadBtn">Cancel</button>
          <button type="submit" class="btn-primary">Upload</button>
        </div>
      </form>
    </div>
  </div>
  <div class="app-modal-backdrop" id="deleteModal">
    <div class="modal-card">
      <div class="modal-title">Delete Document</div>
      <div>Are you sure you want to delete this document? This cannot be undone.</div>
      <div class="modal-actions">
        <button type="button" class="btn-plain" id="cancelDeleteBtn">Cancel</button>
        <button type="button" class="btn-danger" id="confirmDeleteBtn">Delete</button>
      </div>
    </div>
  </div>
  <script>
    const docList = document.getElementById('docList');
    const docFrame = document.getElementById('docFrame');
    const viewerHeader = document.getElementById('viewerHeader');
    const viewerHint = document.getElementById('viewerHint');
    const openUploadBtn = document.getElementById('openUploadBtn');
    const exportBtn = document.getElementById('exportBtn');
    const uploadModal = document.getElementById('uploadModal');
    const uploadDriverId = document.getElementById('uploadDriverId');
    const replaceDocId = document.getElementById('replaceDocId');
    const uploadDocType = document.getElementById('uploadDocType');
    const uploadExpirationDate = document.getElementById('uploadExpirationDate');
    const expirationDateGroup = document.getElementById('expirationDateGroup');
    const uploadFile = document.getElementById('uploadFile');
    const uploadDropZone = document.getElementById('uploadDropZone');
    const selectedFilesList = document.getElementById('selectedFilesList');
    const cancelUploadBtn = document.getElementById('cancelUploadBtn');
    const uploadForm = document.getElementById('uploadForm');
    const uploadUrl = uploadForm.dataset.uploadUrl || 'driver_document_lookup.php';
    const toast = document.getElementById('toast');
    const deleteModal = document.getElementById('deleteModal');
    const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');

    let currentDriverId = null;
    let currentDriverName = '';
    let currentDocType = '';
    let currentDocId = null;
    let pendingDeleteId = null;
    const expiringDocTypes = <?= json_encode(DRIVER_DOC_EXPIRING_TYPES) ?>;

    const humanize = (value) => value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

    const showToast = (message) => {
      toast.textContent = message;
      toast.classList.add('show');
      setTimeout(() => toast.classList.remove('show'), 2200);
    };

    const updateSelectedFilesList = () => {
      if (!selectedFilesList || !uploadFile) return;
      selectedFilesList.innerHTML = '';
      Array.from(uploadFile.files || []).forEach((file) => {
        const item = document.createElement('li');
        item.textContent = file.name;
        selectedFilesList.appendChild(item);
      });
    };

    const resetUploadFileInput = () => {
      if (!uploadFile) return;
      uploadFile.value = '';
      updateSelectedFilesList();
    };

    const updateExpirationRequirement = () => {
      if (!uploadDocType || !uploadExpirationDate || !expirationDateGroup) return;
      const requiresExpiration = expiringDocTypes.includes(uploadDocType.value);
      expirationDateGroup.style.display = requiresExpiration ? '' : 'none';
      uploadExpirationDate.required = requiresExpiration;
      if (!requiresExpiration) {
        uploadExpirationDate.value = '';
      }
    };

    const setUploadMode = (mode) => {
      if (!uploadFile) return;
      const isReplace = mode === 'replace';
      uploadFile.multiple = !isReplace;
      uploadFile.name = isReplace ? 'document_file' : 'document_file[]';
      uploadFile.required = true;
      const uploadLabel = document.querySelector('label[for="uploadFile"]');
      if (uploadLabel) uploadLabel.textContent = isReplace ? 'Select File' : 'Select File(s)';
      if (uploadDropZone) {
        uploadDropZone.querySelector('.drop-zone-title').textContent = isReplace ? 'Drop replacement document here' : 'Drop documents here';
        uploadDropZone.querySelector('.drop-zone-subtitle').textContent = isReplace ? 'or click to browse one file' : 'or click to browse';
      }
      if (!isReplace && uploadExpirationDate) {
        uploadExpirationDate.value = '';
      }
      updateExpirationRequirement();
      resetUploadFileInput();
    };

    uploadFile?.addEventListener('change', updateSelectedFilesList);
    uploadDocType?.addEventListener('change', updateExpirationRequirement);

    uploadDropZone?.addEventListener('click', () => {
      uploadFile?.click();
    });

    ['dragenter', 'dragover'].forEach((eventName) => {
      uploadDropZone?.addEventListener(eventName, (event) => {
        event.preventDefault();
        event.stopPropagation();
        uploadDropZone.classList.add('drag-over');
      });
    });

    ['dragleave', 'drop'].forEach((eventName) => {
      uploadDropZone?.addEventListener(eventName, (event) => {
        event.preventDefault();
        event.stopPropagation();
        uploadDropZone.classList.remove('drag-over');
      });
    });

    uploadDropZone?.addEventListener('drop', (event) => {
      if (!uploadFile || !event.dataTransfer?.files?.length) return;
      const transfer = new DataTransfer();
      const droppedFiles = Array.from(event.dataTransfer.files);
      const filesToAdd = uploadFile.multiple ? droppedFiles : droppedFiles.slice(0, 1);
      filesToAdd.forEach((file) => transfer.items.add(file));
      uploadFile.files = transfer.files;
      updateSelectedFilesList();
    });

    const loadDocuments = async (driverId, docType, driverName) => {
      currentDriverId = driverId;
      currentDriverName = driverName;
      currentDocType = docType;
      currentDocId = null;
      openUploadBtn.disabled = false;
      exportBtn.disabled = true;
      uploadDriverId.value = driverId;
      uploadDocType.value = docType;
      replaceDocId.value = '';
      uploadForm.action = `${uploadUrl}?action=upload`;
      setUploadMode('upload');

      viewerHeader.textContent = `${driverName} • ${humanize(docType)}`;
      docList.innerHTML = '';
      docFrame.src = '';
      viewerHint.textContent = 'Loading documents...';
      viewerHint.style.display = 'block';

      try {
        const response = await fetch(`driver_document_lookup.php?action=list&driver_id=${driverId}&doc_type=${encodeURIComponent(docType)}`);
        const data = await response.json();
        if (!response.ok) {
          throw new Error(data.error || 'Unable to load documents.');
        }

        if (!data.documents.length) {
          viewerHint.textContent = 'No documents uploaded for this category.';
          return;
        }

        viewerHint.style.display = 'none';
        data.documents.forEach((doc, index) => {
          const card = document.createElement('div');
          card.className = 'doc-card';

          const name = document.createElement('div');
          name.className = 'doc-name';
          name.textContent = doc.filename || 'Document';

          const date = document.createElement('div');
          date.className = 'doc-meta';
          date.textContent = doc.uploaded_at ? `Uploaded: ${doc.uploaded_at}` : '';

          const expiration = document.createElement('div');
          expiration.className = 'doc-meta';
          expiration.textContent = doc.expiration_date ? `Expires: ${doc.expiration_date}` : '';

          const actions = document.createElement('div');
          actions.className = 'doc-actions';

          const viewBtn = document.createElement('button');
          viewBtn.type = 'button';
          viewBtn.className = 'doc-link';
          viewBtn.textContent = 'View';
          viewBtn.addEventListener('click', () => {
            currentDocId = doc.id;
            exportBtn.disabled = false;
            docFrame.src = `driver_document_lookup.php?action=file&id=${doc.id}`;
          });

          const exportBtnInline = document.createElement('button');
          exportBtnInline.type = 'button';
          exportBtnInline.className = 'doc-link';
          exportBtnInline.textContent = 'Export';
          exportBtnInline.addEventListener('click', () => {
            window.location.href = `driver_document_lookup.php?action=download&id=${doc.id}`;
          });

          const replaceBtn = document.createElement('button');
          replaceBtn.type = 'button';
          replaceBtn.className = 'doc-link';
          replaceBtn.textContent = 'Replace';
          replaceBtn.addEventListener('click', () => {
            replaceDocId.value = doc.id;
            uploadDocType.value = currentDocType;
            uploadDocType.disabled = true;
            if (uploadExpirationDate) uploadExpirationDate.value = doc.expiration_date || '';
            uploadForm.action = `${uploadUrl}?action=replace`;
            setUploadMode('replace');
            uploadModal.classList.add('show');
          });

          const deleteBtn = document.createElement('button');
          deleteBtn.type = 'button';
          deleteBtn.className = 'doc-link';
          deleteBtn.textContent = 'Delete';
          deleteBtn.addEventListener('click', async () => {
            pendingDeleteId = doc.id;
            deleteModal.classList.add('show');
          });

          actions.appendChild(viewBtn);
          actions.appendChild(exportBtnInline);
          actions.appendChild(replaceBtn);
          actions.appendChild(deleteBtn);

          card.appendChild(name);
          if (date.textContent) card.appendChild(date);
          if (expiration.textContent) card.appendChild(expiration);
          card.appendChild(actions);
          docList.appendChild(card);
          if (index === 0) {
            currentDocId = doc.id;
            exportBtn.disabled = false;
            docFrame.src = `driver_document_lookup.php?action=file&id=${doc.id}`;
          }
        });
      } catch (err) {
        viewerHint.textContent = err.message;
      }
    };

    document.querySelectorAll('.driver-item summary').forEach((summary) => {
      summary.addEventListener('click', (event) => {
        const driverEl = event.target.closest('[data-driver-id]');
        if (!driverEl) return;
        currentDriverId = driverEl.getAttribute('data-driver-id');
        currentDriverName = driverEl.querySelector('summary')?.textContent?.trim() || 'Driver';
        openUploadBtn.disabled = false;
        uploadDriverId.value = currentDriverId;
      });
    });

    document.querySelectorAll('.doc-type-btn').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        const driverEl = event.target.closest('[data-driver-id]');
        if (!driverEl) return;
        const driverId = driverEl.getAttribute('data-driver-id');
        const driverName = driverEl.querySelector('summary')?.textContent?.trim() || 'Driver';
        const docType = event.target.getAttribute('data-doc-type');
        loadDocuments(driverId, docType, driverName);
      });
    });

    openUploadBtn.addEventListener('click', () => {
      if (!currentDriverId) return;
      uploadDriverId.value = currentDriverId;
      uploadDocType.value = currentDocType || uploadDocType.value;
      uploadDocType.disabled = false;
      replaceDocId.value = '';
      uploadForm.action = `${uploadUrl}?action=upload`;
      setUploadMode('upload');
      uploadModal.classList.add('show');
    });

    cancelUploadBtn.addEventListener('click', () => {
      uploadModal.classList.remove('show');
      uploadDocType.disabled = false;
      resetUploadFileInput();
    });

    uploadModal.addEventListener('click', (event) => {
      if (event.target === uploadModal) {
        uploadModal.classList.remove('show');
        uploadDocType.disabled = false;
        resetUploadFileInput();
      }
    });

    cancelDeleteBtn.addEventListener('click', () => {
      deleteModal.classList.remove('show');
      pendingDeleteId = null;
    });

    deleteModal.addEventListener('click', (event) => {
      if (event.target === deleteModal) {
        deleteModal.classList.remove('show');
        pendingDeleteId = null;
      }
    });

    confirmDeleteBtn.addEventListener('click', async () => {
      if (!pendingDeleteId) return;
      confirmDeleteBtn.disabled = true;
      try {
        const formData = new FormData();
        formData.append('doc_id', pendingDeleteId);
        const response = await fetch(`${uploadUrl}?action=delete`, {
          method: 'POST',
          body: formData,
        });
        const result = await response.json();
        if (!response.ok || !result.ok) {
          showToast(result.error || 'Unable to delete document.');
        } else {
          showToast('Document deleted.');
          loadDocuments(currentDriverId, currentDocType, currentDriverName);
        }
      } catch (err) {
        showToast(err.message || 'Unable to delete document.');
      } finally {
        confirmDeleteBtn.disabled = false;
        deleteModal.classList.remove('show');
        pendingDeleteId = null;
      }
    });

    exportBtn.addEventListener('click', () => {
      if (!currentDocId) return;
      window.location.href = `driver_document_lookup.php?action=download&id=${currentDocId}`;
    });

    const urlParams = new URLSearchParams(window.location.search);
    const driverParam = urlParams.get('driver_id');
    const docTypeParam = urlParams.get('doc_type');
    if (driverParam && docTypeParam) {
      const driverSummary = document.querySelector(`[data-driver-id="${driverParam}"] summary`);
      const nameFromDom = driverSummary ? driverSummary.textContent.trim() : 'Driver';
      loadDocuments(driverParam, docTypeParam, nameFromDom);
    }
    if (urlParams.get('uploaded') === '1') {
      const uploadedCount = parseInt(urlParams.get('uploaded_count') || '1', 10);
      showToast(uploadedCount > 1 ? `${uploadedCount} documents uploaded.` : 'Document uploaded.');
    }
    if (urlParams.get('replaced') === '1') {
      showToast('Document replaced.');
    }
    const errorParam = urlParams.get('error');
    if (errorParam) {
      showToast(errorParam);
    }
  </script>
</body>
</html>
