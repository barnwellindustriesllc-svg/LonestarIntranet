<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/efs_card_service.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');

if (isset($_GET['ajax']) && $_GET['ajax'] === 'gas') {
  $driverId = (int)($_GET['driver_id'] ?? 0);
  $date = trim((string)($_GET['date'] ?? ''));
  header('Content-Type: application/json');
  if ($driverId > 0 && $date !== '') {
    $payload = ['amount' => '', 'description' => '', 'card' => '', 'qty' => '', 'gross_amt' => '', 'fees_amt' => ''];
    $hasEntrySource = false;
    $stmt = $mysqli->prepare(
      "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'fuel_report_transactions'
          AND COLUMN_NAME = 'entry_source'"
    );
    $stmt->execute();
    $stmt->bind_result($colCount);
    if ($stmt->fetch()) {
      $hasEntrySource = ((int)$colCount > 0);
    }
    $stmt->close();

    if ($hasEntrySource) {
      $stmt = $mysqli->prepare(
        "SELECT description, card, qty, gross_amt, fees_amt
           FROM fuel_report_transactions
          WHERE driver_contact_id=? AND txn_date=? AND entry_source='manual'
          ORDER BY id DESC
          LIMIT 1"
      );
      $stmt->bind_param('is', $driverId, $date);
      $stmt->execute();
      $stmt->bind_result($description, $card, $qty, $grossAmt, $feesAmt);
      if ($stmt->fetch()) {
        $payload = [
          'amount' => round((float)$grossAmt + (float)$feesAmt, 2),
          'description' => (string)$description,
          'card' => (string)$card,
          'qty' => (string)$qty,
          'gross_amt' => (string)$grossAmt,
          'fees_amt' => (string)$feesAmt,
        ];
      }
      $stmt->close();
    }

    if ($payload['amount'] === '') {
      $stmt = $mysqli->prepare("SELECT amount FROM driver_gas_costs WHERE driver_id=? AND cost_date=?");
      $stmt->bind_param('is', $driverId, $date);
      $stmt->execute();
      $stmt->bind_result($amount);
      $payload['amount'] = $stmt->fetch() ? $amount : '';
      $stmt->close();
    }
    echo json_encode($payload);
  } else {
    echo json_encode(['amount' => '', 'description' => '', 'card' => '', 'qty' => '', 'gross_amt' => '', 'fees_amt' => '']);
  }
  exit;
}

function fuel_parse_money($value): float {
  $raw = preg_replace('/[^0-9\.\-]/', '', (string)$value);
  if ($raw === '' || $raw === '-' || $raw === '.' || $raw === '-.') return 0.0;
  return (float)$raw;
}

function fuel_parse_qty($value): float {
  $raw = preg_replace('/[^0-9\.\-]/', '', (string)$value);
  if ($raw === '' || $raw === '-' || $raw === '.' || $raw === '-.') return 0.0;
  return (float)$raw;
}

function fuel_parse_date($value): ?string {
  $v = trim((string)$value);
  if ($v === '') return null;
  $formats = ['m/d/Y', 'm/d/y', 'n/j/Y', 'n/j/y'];
  foreach ($formats as $fmt) {
    $dt = DateTimeImmutable::createFromFormat($fmt, $v);
    if ($dt instanceof DateTimeImmutable) {
      return $dt->format('Y-m-d');
    }
  }
  $ts = strtotime($v);
  return $ts ? date('Y-m-d', $ts) : null;
}

function fuel_week_start(string $date): string {
  try {
    $dt = new DateTimeImmutable($date);
  } catch (Throwable $e) {
    $dt = new DateTimeImmutable('now');
  }
  $dow = (int)$dt->format('w');
  return $dt->modify("-{$dow} days")->format('Y-m-d');
}

function fuel_week_end(string $weekStart): string {
  try {
    return (new DateTimeImmutable($weekStart))->modify('+6 days')->format('Y-m-d');
  } catch (Throwable $e) {
    return $weekStart;
  }
}

function fuel_decode_pdf_literal(string $value): string {
  $value = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $value);
  return trim(preg_replace('/\s+/', ' ', $value));
}

function fuel_extract_stream_text_items(string $stream, ?string $sourceId = null): array {
  $items = [];
  if (!preg_match_all('/BT(.*?)ET/s', $stream, $blocks)) return $items;

  foreach ($blocks[1] as $block) {
    if (!preg_match_all('/1\s+0\s+0\s+1\s+([\-0-9\.]+)\s+([\-0-9\.]+)\s+Tm/s', $block, $tmMatches, PREG_OFFSET_CAPTURE)) {
      continue;
    }
    $tmCount = count($tmMatches[0]);
    for ($i = 0; $i < $tmCount; $i++) {
      $x = (float)$tmMatches[1][$i][0];
      $y = (float)$tmMatches[2][$i][0];
      $segStart = $tmMatches[0][$i][1] + strlen($tmMatches[0][$i][0]);
      $segEnd = ($i + 1 < $tmCount) ? $tmMatches[0][$i + 1][1] : strlen($block);
      $segment = substr($block, $segStart, $segEnd - $segStart);

      $parts = [];
      if (preg_match_all('/\[(.*?)\]TJ/s', $segment, $arrMatches)) {
        foreach ($arrMatches[1] as $arrRaw) {
          if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $arrRaw, $strs)) {
            foreach ($strs[0] as $s) {
              $parts[] = substr($s, 1, -1);
            }
          }
        }
      }
      if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)\s*Tj/s', $segment, $tjMatches)) {
        foreach ($tjMatches[0] as $raw) {
          if (preg_match('/\((.*)\)\s*Tj/s', $raw, $m)) {
            $parts[] = $m[1];
          }
        }
      }
      if (!$parts) continue;

      $text = '';
      foreach ($parts as $p) $text .= fuel_decode_pdf_literal($p);
      $text = trim($text);
      if ($text === '') continue;

      $items[] = ['x' => $x, 'y' => $y, 'text' => $text, 'source_id' => $sourceId ?? 'default'];
    }
  }
  return $items;
}

function fuel_parse_pdf_items(string $pdfPath): array {
  $bytes = @file_get_contents($pdfPath);
  if ($bytes === false || $bytes === '') return [];

  $items = [];
  if (!preg_match_all('/(\d+)\s+0\s+obj\s*<<(.*?)>>\s*stream\r?\n(.*?)endstream/s', $bytes, $objMatches, PREG_SET_ORDER)) {
    return [];
  }
  foreach ($objMatches as $obj) {
    $objectId = (string)$obj[1];
    $dict = (string)$obj[2];
    $rawStream = (string)$obj[3];
    $stream = $rawStream;
    if (stripos($dict, '/FlateDecode') !== false) {
      $decoded = function_exists('zlib_decode') ? @zlib_decode($rawStream) : false;
      if ($decoded === false || $decoded === null) {
        $decoded = @gzuncompress($rawStream);
      }
      if ($decoded === false || $decoded === null) continue;
      $stream = $decoded;
    }
    if (strpos($stream, 'BT') === false) continue;
    $items = array_merge($items, fuel_extract_stream_text_items($stream, $objectId));
  }
  return $items;
}

function fuel_norm_name(string $name): string {
  return strtolower(trim(preg_replace('/[^a-z0-9 ]+/i', ' ', $name)));
}

function fuel_extract_prompt_name(string $promptData): string {
  $p = trim($promptData);
  if (preg_match('/^[A-Z]-\s*(.+)$/', $p, $m)) {
    return trim($m[1]);
  }
  return $p;
}

function fuel_extract_card_last4(string $cardValue): string {
  $digits = preg_replace('/\D+/', '', (string)$cardValue);
  if ($digits === '') return '';
  if (strlen($digits) <= 4) return $digits;
  return substr($digits, -4);
}

function fuel_card_type_label(string $entrySource, string $category = '', string $provider = ''): string {
  $entrySource = strtolower(trim($entrySource));
  $category = strtolower(trim($category));
  $provider = strtolower(trim($provider));
  if ($entrySource === 'manual') return 'Manual';
  if ($category === 'mudflap' || $provider === 'mudflap') return 'Mudflap';
  return 'RTS/WEX';
}

function fuel_resolve_driver_id(string $promptData, string $cardValue, array $driverIndex): ?int {
  $last4 = fuel_extract_card_last4($cardValue);
  if ($last4 !== '' && isset($driverIndex['card_last4'][$last4]) && count($driverIndex['card_last4'][$last4]) === 1) {
    return (int)$driverIndex['card_last4'][$last4][0];
  }

  $name = fuel_extract_prompt_name($promptData);
  $norm = fuel_norm_name($name);
  if ($norm === '') return null;

  if (isset($driverIndex['full'][$norm]) && count($driverIndex['full'][$norm]) === 1) {
    return (int)$driverIndex['full'][$norm][0];
  }
  $parts = preg_split('/\s+/', $norm);
  $first = $parts[0] ?? '';
  if ($first !== '' && isset($driverIndex['first'][$first]) && count($driverIndex['first'][$first]) === 1) {
    return (int)$driverIndex['first'][$first][0];
  }
  return null;
}

function fuel_build_driver_index(mysqli $mysqli): array {
  $idx = ['full' => [], 'first' => [], 'card_last4' => []];
  $q = $mysqli->query("SELECT id, first_name, last_name FROM driver_contacts WHERE COALESCE(is_disabled,0)=0");
  while ($r = $q->fetch_assoc()) {
    $id = (int)($r['id'] ?? 0);
    if ($id <= 0) continue;
    $full = fuel_norm_name(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')));
    $first = fuel_norm_name((string)($r['first_name'] ?? ''));
    if ($full !== '') {
      if (!isset($idx['full'][$full])) $idx['full'][$full] = [];
      $idx['full'][$full][] = $id;
    }
    if ($first !== '') {
      if (!isset($idx['first'][$first])) $idx['first'][$first] = [];
      $idx['first'][$first][] = $id;
    }
  }
  $q->close();

  // Also index by last 4 of assigned fuel cards to improve matching accuracy.
  $q2 = $mysqli->query("
    SELECT fc.card_last4, fc.assigned_driver_id
      FROM fuel_cards fc
      JOIN driver_contacts dc
        ON dc.id = fc.assigned_driver_id
     WHERE assigned_driver_id IS NOT NULL
       AND fc.card_last4 IS NOT NULL
       AND fc.card_last4 <> ''
       AND COALESCE(dc.is_disabled,0)=0
  ");
  while ($r = $q2->fetch_assoc()) {
    $last4 = fuel_extract_card_last4((string)($r['card_last4'] ?? ''));
    $driverId = (int)($r['assigned_driver_id'] ?? 0);
    if ($last4 === '' || $driverId <= 0) continue;
    if (!isset($idx['card_last4'][$last4])) $idx['card_last4'][$last4] = [];
    if (!in_array($driverId, $idx['card_last4'][$last4], true)) {
      $idx['card_last4'][$last4][] = $driverId;
    }
  }
  $q2->close();

  return $idx;
}

function fuel_parse_statement_date(array $items): ?string {
  foreach ($items as $it) {
    if (strtolower($it['text'] ?? '') === 'invoice date:') {
      $y = (float)$it['y'];
      foreach ($items as $v) {
        if (abs(((float)$v['y']) - $y) <= 1.0 && (float)$v['x'] > (float)$it['x'] && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $v['text'])) {
          return fuel_parse_date($v['text']);
        }
      }
    }
  }
  return null;
}

function fuel_parse_transactions_from_items(array $items): array {
  $groups = [];
  foreach ($items as $it) {
    $y = round((float)$it['y'], 1);
    $sourceId = (string)($it['source_id'] ?? 'default');
    $groupKey = $sourceId . '|' . $y;
    if (!isset($groups[$groupKey])) {
      $groups[$groupKey] = ['source_id' => $sourceId, 'y' => $y, 'items' => []];
    }
    $groups[$groupKey]['items'][] = $it;
  }
  uasort($groups, function ($a, $b) {
    $sourceCmp = strcmp((string)$a['source_id'], (string)$b['source_id']);
    if ($sourceCmp !== 0) return $sourceCmp;
    return ((float)$b['y'] <=> (float)$a['y']);
  });

  $rows = [];
  foreach ($groups as $group) {
    $cols = $group['items'];
    usort($cols, fn($a, $b) => ((float)$a['x'] <=> (float)$b['x']));

    $pick = function(float $minX, float $maxX) use ($cols): string {
      $parts = [];
      foreach ($cols as $c) {
        $x = (float)$c['x'];
        if ($x >= $minX && $x < $maxX) $parts[] = trim((string)$c['text']);
      }
      return trim(implode(' ', array_filter($parts, fn($p) => $p !== '')));
    };

    $dateRaw = $pick(10, 60);
    $date = fuel_parse_date($dateRaw);
    if (!$date) continue;

    $category = $pick(60, 120);
    $description = $pick(120, 240);
    $card = $pick(240, 278);
    $unit = $pick(278, 300);
    $promptData = $pick(300, 344);
    $invoice = $pick(344, 377);
    $loc = $pick(377, 404);
    $locationName = $pick(404, 505);
    $state = $pick(505, 525);
    $qtyRaw = $pick(525, 563);
    $grossAmtRaw = $pick(658, 696);
    $descAmtRaw = $pick(696, 727);
    $feesRaw = $pick(727, 756);
    $totalRaw = $pick(756, 790);

    if ($category === '' || stripos($category, 'balance') !== false || strtoupper($category) === 'PMT') continue;
    if ($grossAmtRaw === '' && $feesRaw === '' && $descAmtRaw === '') continue;
    if ($invoice === '' && $loc === '' && $locationName === '') continue;

    $rows[] = [
      'txn_date' => $date,
      'category' => $category,
      'description' => $description,
      'card' => $card,
      'unit' => $unit,
      'prompt_data' => $promptData,
      'invoice_number' => $invoice,
      'loc_number' => $loc,
      'location_name' => $locationName,
      'state' => $state,
      'qty' => fuel_parse_qty($qtyRaw),
      'gross_amt' => fuel_parse_money($grossAmtRaw),
      'fees_amt' => fuel_parse_money($feesRaw),
      'desc_amt' => fuel_parse_money($descAmtRaw),
      'total_amt' => fuel_parse_money($totalRaw),
    ];
  }
  return $rows;
}

function fuel_normalize_csv_header(string $header): string {
  return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $header), '_'));
}

function fuel_parse_csv_assoc_rows(string $path): array {
  $fh = fopen($path, 'r');
  if (!$fh) return [];

  $headers = fgetcsv($fh);
  if (!$headers) {
    fclose($fh);
    return [];
  }

  $keys = array_map(static fn($h) => fuel_normalize_csv_header((string)$h), $headers);
  $rows = [];
  while (($raw = fgetcsv($fh)) !== false) {
    $row = [];
    foreach ($keys as $idx => $key) {
      if ($key === '') continue;
      $row[$key] = $raw[$idx] ?? '';
    }
    if (array_filter($row, static fn($v) => trim((string)$v) !== '')) {
      $rows[] = $row;
    }
  }
  fclose($fh);

  return $rows;
}

function fuel_parse_mudflap_csv(string $path): array {
  $csvRows = fuel_parse_csv_assoc_rows($path);
  $rows = [];
  $statementDate = null;

  foreach ($csvRows as $raw) {
    $transactionId = trim((string)($raw['transaction_id'] ?? ''));
    $cardLast4 = fuel_extract_card_last4((string)($raw['card_last4'] ?? ''));
    $visitDate = fuel_parse_date(
      $raw['date_of_visit']
        ?? $raw['date_of_visit_local']
        ?? $raw['date_of_visit_local_time']
        ?? $raw['visit_date']
        ?? $raw['visited_at']
        ?? $raw['visit_datetime']
        ?? $raw['date_visited']
        ?? $raw['transaction_date']
        ?? $raw['transaction_datetime']
        ?? ''
    );
    $postedDate = fuel_parse_date(
      $raw['posted_date']
        ?? $raw['posted_date_local']
        ?? $raw['posted_date_utc']
        ?? $raw['post_date']
        ?? $raw['post_datetime']
        ?? $raw['date_posted']
        ?? ''
    );
    $txnDate = $visitDate ?: $postedDate;
    $status = strtolower(trim((string)($raw['status'] ?? '')));
    if (!$txnDate || $transactionId === '' || $cardLast4 === '') continue;
    if ($status !== '' && $status !== 'posted') continue;

    $truckStop = trim((string)($raw['truck_stop'] ?? ''));
    $city = trim((string)($raw['city'] ?? ''));
    $state = trim((string)($raw['state'] ?? ''));
    $zip = trim((string)($raw['zip'] ?? ''));
    $locationParts = array_filter([$truckStop, $city, $state, $zip], static fn($v) => $v !== '');
    $locationName = implode(', ', $locationParts);
    $fuelType = trim((string)($raw['fuel_type'] ?? ''));
    $description = $fuelType !== '' ? $fuelType : 'Mudflap Fuel';
    $fuelAmount = fuel_parse_money($raw['fuel_amount'] ?? 0);
    $nonFuelAmount = fuel_parse_money($raw['non_fuel_amount'] ?? 0);
    $paid = fuel_parse_money($raw['paid'] ?? ($fuelAmount + $nonFuelAmount));

    $rows[] = [
      'txn_date' => $txnDate,
      'visit_date' => $visitDate ?: $txnDate,
      'posted_date' => $postedDate,
      'category' => 'Mudflap',
      'description' => $description,
      'card' => $cardLast4,
      'unit' => trim((string)($raw['truck'] ?? '')),
      'prompt_data' => trim((string)($raw['name'] ?? '')),
      'invoice_number' => $transactionId,
      'loc_number' => trim((string)($raw['card_id'] ?? '')),
      'location_name' => $locationName,
      'state' => $state,
      'qty' => fuel_parse_qty($raw['gallons_dispensed'] ?? 0),
      'gross_amt' => $fuelAmount,
      'fees_amt' => $nonFuelAmount,
      'desc_amt' => fuel_parse_money($raw['savings'] ?? 0),
      'total_amt' => $paid,
    ];

    $dateForStatement = $postedDate ?: $txnDate;
    if ($statementDate === null || $dateForStatement > $statementDate) {
      $statementDate = $dateForStatement;
    }
  }

  return ['rows' => $rows, 'statement_date' => $statementDate];
}

function fuel_build_upload_row_hash(array $row, string $scope = ''): string {
  $gross = (float)($row['gross_amt'] ?? 0);
  $fees = (float)($row['fees_amt'] ?? 0);
  $parts = [
    (string)($row['txn_date'] ?? ''),
    (string)($row['visit_date'] ?? ''),
    (string)($row['posted_date'] ?? ''),
    strtolower((string)($row['category'] ?? '')),
    strtolower((string)($row['description'] ?? '')),
    (string)($row['card'] ?? ''),
    (string)($row['unit'] ?? ''),
    strtolower((string)($row['prompt_data'] ?? '')),
    (string)($row['invoice_number'] ?? ''),
    (string)($row['loc_number'] ?? ''),
    strtolower((string)($row['location_name'] ?? '')),
    strtolower((string)($row['state'] ?? '')),
    number_format((float)($row['qty'] ?? 0), 3, '.', ''),
    number_format($gross, 2, '.', ''),
    number_format($fees, 2, '.', ''),
    number_format((float)($row['desc_amt'] ?? 0), 2, '.', ''),
    number_format((float)($row['total_amt'] ?? 0), 2, '.', '')
  ];
  if ($scope !== '') {
    array_unshift($parts, $scope);
  }
  return hash('sha256', implode('|', $parts));
}

function fuel_build_upload_transaction_hash(array $row): string {
  $category = strtolower(trim((string)($row['category'] ?? '')));
  $invoice = strtolower(trim((string)($row['invoice_number'] ?? '')));
  $card = fuel_extract_card_last4((string)($row['card'] ?? ''));
  $businessDate = (string)($row['visit_date'] ?? $row['txn_date'] ?? '');
  $gross = number_format((float)($row['gross_amt'] ?? 0), 2, '.', '');
  $fees = number_format((float)($row['fees_amt'] ?? 0), 2, '.', '');
  $total = number_format((float)($row['total_amt'] ?? 0), 2, '.', '');
  $fallback = [
    strtolower(trim((string)($row['description'] ?? ''))),
    strtolower(trim((string)($row['location_name'] ?? ''))),
    number_format((float)($row['qty'] ?? 0), 3, '.', ''),
  ];

  $parts = ['upload_txn', $category, $invoice, $card, $businessDate, $gross, $fees, $total];
  if ($invoice === '') {
    $parts = array_merge($parts, $fallback);
  }
  return hash('sha256', implode('|', $parts));
}

function fuel_table_has_column(mysqli $mysqli, string $table, string $column): bool {
  $table = $mysqli->real_escape_string($table);
  $column = $mysqli->real_escape_string($column);
  $res = $mysqli->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
  return $res && $res->num_rows > 0;
}

function fuel_update_driver_gas_cost(mysqli $mysqli, int $driverId, string $costDate, float $amount): void {
  static $hasUpdatedAt = null;
  if ($hasUpdatedAt === null) {
    $hasUpdatedAt = fuel_table_has_column($mysqli, 'driver_gas_costs', 'updated_at');
  }

  if ($hasUpdatedAt) {
    $stmt = $mysqli->prepare("UPDATE driver_gas_costs SET amount=?, updated_at=NOW() WHERE driver_id=? AND cost_date=?");
  } else {
    $stmt = $mysqli->prepare("UPDATE driver_gas_costs SET amount=? WHERE driver_id=? AND cost_date=?");
  }
  $stmt->bind_param('dis', $amount, $driverId, $costDate);
  $stmt->execute();
  $stmt->close();
}

function fuel_driver_exists(mysqli $mysqli, int $driverId): bool {
  if ($driverId <= 0) {
    return false;
  }
  $stmt = $mysqli->prepare("SELECT COUNT(*) FROM driver_contacts WHERE id=?");
  $stmt->bind_param('i', $driverId);
  $stmt->execute();
  $stmt->bind_result($count);
  $stmt->fetch();
  $stmt->close();
  return (int)$count > 0;
}

function fuel_upsert_driver_gas_cost(mysqli $mysqli, int $driverId, string $costDate, float $amount): void {
  $stmt = $mysqli->prepare("SELECT id FROM driver_gas_costs WHERE driver_id=? AND cost_date=? LIMIT 1");
  $stmt->bind_param('is', $driverId, $costDate);
  $stmt->execute();
  $stmt->store_result();
  $exists = $stmt->num_rows > 0;
  $stmt->close();

  if ($exists) {
    fuel_update_driver_gas_cost($mysqli, $driverId, $costDate, $amount);
    return;
  }

  $ins = $mysqli->prepare("INSERT INTO driver_gas_costs (driver_id, cost_date, amount) VALUES (?, ?, ?)");
  $ins->bind_param('isd', $driverId, $costDate, $amount);
  $ins->execute();
  $ins->close();
}

function fuel_sync_uploaded_day_total(mysqli $mysqli, int $driverId, string $costDate): float {
  $stmt = $mysqli->prepare(
    "SELECT COALESCE(SUM(gross_amt + fees_amt), 0)
      FROM fuel_report_transactions
     WHERE driver_contact_id=?
        AND COALESCE(visit_date, txn_date)=?
        AND entry_source='upload'
        AND applied_to_gas_costs=1"
  );
  $stmt->bind_param('is', $driverId, $costDate);
  $stmt->execute();
  $stmt->bind_result($amount);
  $stmt->fetch();
  $stmt->close();

  $total = round((float)$amount, 2);
  fuel_upsert_driver_gas_cost($mysqli, $driverId, $costDate, $total);
  return $total;
}

function fuel_ensure_report_tables(mysqli $mysqli): void {
  $mysqli->query("
    CREATE TABLE IF NOT EXISTS fuel_report_uploads (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      original_filename VARCHAR(255) NOT NULL,
      file_hash CHAR(64) NOT NULL,
      statement_date DATE NULL,
      uploaded_by INT NULL,
      row_count INT UNSIGNED NOT NULL DEFAULT 0,
      inserted_row_count INT UNSIGNED NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_fuel_report_file_hash (file_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $mysqli->query("
    CREATE TABLE IF NOT EXISTS fuel_report_transactions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      upload_id BIGINT UNSIGNED NOT NULL,
      txn_date DATE NOT NULL,
      visit_date DATE NULL,
      posted_date DATE NULL,
      category VARCHAR(80) NOT NULL,
      description VARCHAR(255) NULL,
      card VARCHAR(20) NULL,
      unit VARCHAR(50) NULL,
      prompt_data VARCHAR(190) NULL,
      invoice_number VARCHAR(80) NULL,
      loc_number VARCHAR(80) NULL,
      location_name VARCHAR(190) NULL,
      state VARCHAR(20) NULL,
      qty DECIMAL(12,3) NOT NULL DEFAULT 0.000,
      gross_amt DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      fees_amt DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      desc_amt DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      total_amt DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      driver_contact_id INT NULL,
      entry_source VARCHAR(20) NOT NULL DEFAULT 'upload',
      row_hash CHAR(64) NOT NULL,
      transaction_hash CHAR(64) NULL,
      applied_to_gas_costs TINYINT(1) NOT NULL DEFAULT 0,
      applied_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_fuel_report_row_hash (row_hash),
      UNIQUE KEY uniq_fuel_report_transaction_hash (transaction_hash),
      KEY idx_fuel_report_txn_date (txn_date),
      KEY idx_fuel_report_visit_date (visit_date),
      KEY idx_fuel_report_posted_date (posted_date),
      KEY idx_fuel_report_driver_day (driver_contact_id, txn_date),
      KEY idx_fuel_report_upload (upload_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  if (!fuel_table_has_column($mysqli, 'fuel_report_transactions', 'visit_date')) {
    $mysqli->query("ALTER TABLE fuel_report_transactions ADD COLUMN visit_date DATE NULL AFTER txn_date");
    $mysqli->query("UPDATE fuel_report_transactions SET visit_date = txn_date WHERE visit_date IS NULL");
    $mysqli->query("ALTER TABLE fuel_report_transactions ADD KEY idx_fuel_report_visit_date (visit_date)");
  }

  if (!fuel_table_has_column($mysqli, 'fuel_report_transactions', 'posted_date')) {
    $mysqli->query("ALTER TABLE fuel_report_transactions ADD COLUMN posted_date DATE NULL AFTER visit_date");
    $mysqli->query("ALTER TABLE fuel_report_transactions ADD KEY idx_fuel_report_posted_date (posted_date)");
  }

  if (!fuel_table_has_column($mysqli, 'fuel_report_transactions', 'entry_source')) {
    $mysqli->query("ALTER TABLE fuel_report_transactions ADD COLUMN entry_source VARCHAR(20) NOT NULL DEFAULT 'upload' AFTER driver_contact_id");
  }

  if (!fuel_table_has_column($mysqli, 'fuel_report_transactions', 'transaction_hash')) {
    $mysqli->query("ALTER TABLE fuel_report_transactions ADD COLUMN transaction_hash CHAR(64) NULL AFTER row_hash");
  }

  $mysqli->query("
    UPDATE fuel_report_transactions
       SET transaction_hash = SHA2(CONCAT_WS('|',
             'upload_txn',
             LOWER(TRIM(COALESCE(category, ''))),
             LOWER(TRIM(COALESCE(invoice_number, ''))),
             RIGHT(REPLACE(REPLACE(REPLACE(COALESCE(card, ''), ' ', ''), '-', ''), '.', ''), 4),
             COALESCE(visit_date, txn_date),
             REPLACE(FORMAT(COALESCE(gross_amt, 0), 2), ',', ''),
             REPLACE(FORMAT(COALESCE(fees_amt, 0), 2), ',', ''),
             REPLACE(FORMAT(COALESCE(total_amt, 0), 2), ',', ''),
             CASE WHEN COALESCE(invoice_number, '') = '' THEN LOWER(TRIM(COALESCE(description, ''))) ELSE '' END,
             CASE WHEN COALESCE(invoice_number, '') = '' THEN LOWER(TRIM(COALESCE(location_name, ''))) ELSE '' END,
             CASE WHEN COALESCE(invoice_number, '') = '' THEN REPLACE(FORMAT(COALESCE(qty, 0), 3), ',', '') ELSE '' END
           ), 256)
     WHERE entry_source='upload'
       AND transaction_hash IS NULL
  ");
  $mysqli->query("
    DELETE dup
      FROM fuel_report_transactions dup
      JOIN fuel_report_transactions keep
        ON keep.transaction_hash = dup.transaction_hash
       AND keep.entry_source = 'upload'
       AND dup.entry_source = 'upload'
       AND keep.transaction_hash IS NOT NULL
       AND keep.id < dup.id
  ");
  $mysqli->query("
    UPDATE driver_gas_costs dgc
      JOIN (
        SELECT driver_contact_id,
               COALESCE(visit_date, txn_date) AS cost_date,
               ROUND(SUM(gross_amt + fees_amt), 2) AS amount
          FROM fuel_report_transactions
         WHERE entry_source='upload'
           AND applied_to_gas_costs=1
           AND driver_contact_id IS NOT NULL
         GROUP BY driver_contact_id, COALESCE(visit_date, txn_date)
      ) uploaded
        ON uploaded.driver_contact_id = dgc.driver_id
       AND uploaded.cost_date = dgc.cost_date
       SET dgc.amount = uploaded.amount
  ");
  $idx = $mysqli->query("SHOW INDEX FROM fuel_report_transactions WHERE Key_name = 'uniq_fuel_report_transaction_hash'");
  if (!$idx || $idx->num_rows === 0) {
    $mysqli->query("ALTER TABLE fuel_report_transactions ADD UNIQUE KEY uniq_fuel_report_transaction_hash (transaction_hash)");
  }
}

function fuel_create_manual_upload(mysqli $mysqli, ?int $userId, ?string $statementDate, int $rowCount): int {
  $originalFilename = 'manual-entry';
  $fileHash = hash('sha256', 'manual|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX));
  $stmt = $mysqli->prepare(
    "INSERT INTO fuel_report_uploads
     (original_filename, file_hash, statement_date, uploaded_by, row_count, inserted_row_count)
     VALUES (?,?,?,?,?,?)"
  );
  $stmt->bind_param('sssiii', $originalFilename, $fileHash, $statementDate, $userId, $rowCount, $rowCount);
  $stmt->execute();
  $uploadId = (int)$stmt->insert_id;
  $stmt->close();
  return $uploadId;
}

function fuel_log_event(mysqli $mysqli, string $eventType, ?int $cardId, ?int $driverId, ?int $loadId, ?string $message, array $meta = []): void {
  $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
  $json = $meta ? json_encode($meta) : null;
  if ($json === false) {
    $json = null;
  }
  $stmt = $mysqli->prepare(
    'INSERT INTO fuel_card_events (card_id, driver_id, load_id, event_type, message, meta_json, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
  );
  $stmt->bind_param('iiisssi', $cardId, $driverId, $loadId, $eventType, $message, $json, $userId);
  $stmt->execute();
  $stmt->close();
}

function fuel_is_efs_provider(string $provider): bool {
  $provider = strtolower(trim($provider));
  return strpos($provider, 'rts') !== false || strpos($provider, 'efs') !== false || strpos($provider, 'wex') !== false;
}

$action = $_POST['action'] ?? '';
$error = '';
$success = '';
$gasErrors = [];
$successGas = false;
$openGasModal = false;
$gasDriverId = '';
$uploadDebugRows = [];
$uploadDebugGrossTotal = 0.0;
$uploadDebugFeesTotal = 0.0;
fuel_ensure_report_tables($mysqli);

if ($action === 'add_card') {
  $provider = trim($_POST['provider'] ?? '');
  $label = trim($_POST['card_label'] ?? '');
  $last4 = preg_replace('/\D+/', '', (string)($_POST['card_last4'] ?? ''));
  $externalId = trim($_POST['external_id'] ?? '');
  $dailyLimit = $_POST['daily_limit'] !== '' ? (float)$_POST['daily_limit'] : null;
  $perTxnLimit = $_POST['per_txn_limit'] !== '' ? (float)$_POST['per_txn_limit'] : null;
  $fuelOnly = isset($_POST['fuel_only']) ? 1 : 0;
  $notes = trim($_POST['notes'] ?? '');

  if ($provider === '' || strlen($last4) !== 4) {
    $error = 'Provider and valid last 4 are required.';
  } else {
    $stmt = $mysqli->prepare(
      'INSERT INTO fuel_cards (provider, card_label, card_last4, external_id, daily_limit, per_txn_limit, fuel_only, notes)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ssssddis', $provider, $label, $last4, $externalId, $dailyLimit, $perTxnLimit, $fuelOnly, $notes);
    $stmt->execute();
    $cardId = $stmt->insert_id;
    $stmt->close();
    fuel_log_event($mysqli, 'card_created', (int)$cardId, null, null, 'Fuel card added.', [
      'provider' => $provider,
      'last4' => $last4,
    ]);
    $success = 'Fuel card added.';
  }
}

if ($action === 'save_gas_costs') {
  $driverId = (int)($_POST['driver_id'] ?? 0);
  $gasDriverId = $driverId;
  $dates = $_POST['dates'] ?? [];
  $descriptions = $_POST['descriptions'] ?? [];
  $cardsInput = $_POST['cards'] ?? [];
  $qtys = $_POST['qtys'] ?? [];
  $grossAmounts = $_POST['gross_amounts'] ?? [];
  $feeAmounts = $_POST['fee_amounts'] ?? [];

  if ($driverId <= 0) {
    $gasErrors[] = 'Select a driver for gas costs.';
  } else {
    $manualRows = [];
    $statementDate = null;
    for ($i = 0; $i < 7; $i++) {
      if (!empty($dates[$i])) {
        $d = (string)$dates[$i];
        $gross = fuel_parse_money($grossAmounts[$i] ?? 0);
        $fees = fuel_parse_money($feeAmounts[$i] ?? 0);
        $manualRows[] = [
          'txn_date' => $d,
          'description' => trim((string)($descriptions[$i] ?? '')),
          'card' => trim((string)($cardsInput[$i] ?? '')),
          'qty' => fuel_parse_qty($qtys[$i] ?? 0),
          'gross_amt' => $gross,
          'fees_amt' => $fees,
          'amount' => round($gross + $fees, 2),
        ];
        if ($statementDate === null || $d > $statementDate) {
          $statementDate = $d;
        }
      }
    }

    if (!$manualRows) {
      $gasErrors[] = 'Enter at least one fuel cost row with a date.';
    } else {
      $driverName = '';
      $stmt = $mysqli->prepare("SELECT CONCAT(first_name, ' ', last_name) AS driver_name FROM driver_contacts WHERE id=?");
      $stmt->bind_param('i', $driverId);
      $stmt->execute();
      $stmt->bind_result($driverNameRaw);
      if ($stmt->fetch()) {
        $driverName = trim((string)$driverNameRaw);
      }
      $stmt->close();

      $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
      $mysqli->begin_transaction();
      try {
        $uploadId = fuel_create_manual_upload($mysqli, $userId, $statementDate, count($manualRows));
        $deleteStmt = $mysqli->prepare(
          "DELETE FROM fuel_report_transactions
            WHERE driver_contact_id=? AND txn_date=? AND entry_source='manual'"
        );
        $insertStmt = $mysqli->prepare(
          "INSERT INTO fuel_report_transactions
           (upload_id, txn_date, visit_date, category, description, card, unit, prompt_data, invoice_number, loc_number, location_name, state, qty, gross_amt, fees_amt, desc_amt, total_amt, driver_contact_id, entry_source, row_hash, applied_to_gas_costs, applied_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );

        foreach ($manualRows as $row) {
          $txnDate = (string)$row['txn_date'];
          $visitDate = $txnDate;
          $description = $row['description'] !== '' ? (string)$row['description'] : 'Manual Fuel Entry';
          $cardValue = (string)$row['card'];
          $qty = (float)$row['qty'];
          $gross = (float)$row['gross_amt'];
          $fees = (float)$row['fees_amt'];
          $amount = (float)$row['amount'];
          $category = 'Manual Entry';
          $unit = 'GAL';
          $promptData = $driverName;
          $invoice = '';
          $loc = '';
          $location = 'Manual Entry';
          $state = '';
          $descAmt = 0.0;
          $totalAmt = $amount;
          $entrySource = 'manual';
          $appliedFlag = 1;
          $appliedAt = date('Y-m-d H:i:s');
          $rowHash = hash('sha256', implode('|', [
            'manual',
            $driverId,
            $txnDate,
            strtolower($description),
            $cardValue,
            number_format($qty, 3, '.', ''),
            number_format($gross, 2, '.', ''),
            number_format($fees, 2, '.', '')
          ]));

          $deleteStmt->bind_param('is', $driverId, $txnDate);
          $deleteStmt->execute();

          $insertStmt->bind_param(
            'isssssssssssdddddissis',
            $uploadId,
            $txnDate,
            $visitDate,
            $category,
            $description,
            $cardValue,
            $unit,
            $promptData,
            $invoice,
            $loc,
            $location,
            $state,
            $qty,
            $gross,
            $fees,
            $descAmt,
            $totalAmt,
            $driverId,
            $entrySource,
            $rowHash,
            $appliedFlag,
            $appliedAt
          );
          $insertStmt->execute();

          $stmt = $mysqli->prepare("SELECT id FROM driver_gas_costs WHERE driver_id=? AND cost_date=?");
          $stmt->bind_param('is', $driverId, $txnDate);
          $stmt->execute();
          $stmt->store_result();
          if ($stmt->num_rows) {
            fuel_update_driver_gas_cost($mysqli, $driverId, $txnDate, $amount);
          } else {
            $i2 = $mysqli->prepare("INSERT INTO driver_gas_costs (driver_id, cost_date, amount) VALUES (?, ?, ?)");
            $i2->bind_param('isd', $driverId, $txnDate, $amount);
            $i2->execute();
            $i2->close();
          }
          $stmt->close();
        }

        $deleteStmt->close();
        $insertStmt->close();
        $mysqli->commit();
        $successGas = true;
        $success = 'Fuel costs saved and logged as manual fuel transactions.';
      } catch (Throwable $e) {
        $mysqli->rollback();
        $gasErrors[] = 'Unable to save manual fuel costs: ' . $e->getMessage();
      }
    }
  }
  $openGasModal = true;
}

if ($action === 'assign_card') {
  $cardId = (int)($_POST['card_id'] ?? 0);
  $driverId = (int)($_POST['driver_id'] ?? 0);
  if ($cardId <= 0) {
    $error = 'Missing card selection.';
  } else {
    $stmt = $mysqli->prepare('UPDATE fuel_cards SET assigned_driver_id = ? WHERE id = ?');
    $driverId = $driverId > 0 ? $driverId : null;
    $stmt->bind_param('ii', $driverId, $cardId);
    $stmt->execute();
    $stmt->close();
    fuel_log_event($mysqli, 'card_assigned', $cardId, $driverId, null, 'Card assignment updated.');
    $success = 'Card assignment updated.';
  }
}

if ($action === 'toggle_card') {
  $cardId = (int)($_POST['card_id'] ?? 0);
  $active = ($_POST['is_active'] ?? '0') === '1' ? 1 : 0;
  if ($cardId <= 0) {
    $error = 'Missing card selection.';
  } else {
    $stmt = $mysqli->prepare('SELECT provider, external_id FROM fuel_cards WHERE id = ?');
    $stmt->bind_param('i', $cardId);
    $stmt->execute();
    $stmt->bind_result($cardProvider, $cardExternalId);
    $foundCard = $stmt->fetch();
    $stmt->close();

    if (!$foundCard) {
      $error = 'Fuel card was not found.';
    } else {
      try {
        $remoteStatus = null;
        if (fuel_is_efs_provider((string)$cardProvider)) {
          $efs = EfsCardService::fromEnvironment();
          try {
            $remoteStatus = $efs->setActive((string)$cardExternalId, $active === 1);
          } finally {
            $efs->close();
          }
        }

        $stmt = $mysqli->prepare('UPDATE fuel_cards SET is_active = ? WHERE id = ?');
        $stmt->bind_param('ii', $active, $cardId);
        $stmt->execute();
        $stmt->close();
        fuel_log_event($mysqli, $active ? 'card_enabled' : 'card_disabled', $cardId, null, null, 'Card status updated.', [
          'efs_status' => $remoteStatus,
          'source' => $remoteStatus === null ? 'local' : 'efs_verified',
        ]);
        $success = $remoteStatus === null ? 'Card status updated.' : "RTS/EFS card status updated and verified as {$remoteStatus}.";
      } catch (Throwable $e) {
        fuel_log_event($mysqli, 'card_status_failed', $cardId, null, null, 'RTS/EFS card status update failed.', [
          'requested_status' => $active ? 'Active' : 'Inactive',
          'error' => $e->getMessage(),
        ]);
        $error = 'RTS/EFS rejected the card status change; the local status was not changed. ' . $e->getMessage();
      }
    }
  }
}

if ($action === 'sync_efs_cards') {
  $synced = 0;
  $failed = [];
  try {
    $efs = EfsCardService::fromEnvironment();
    try {
      $res = $mysqli->query("SELECT id, card_last4, external_id FROM fuel_cards WHERE LOWER(provider) LIKE '%rts%' OR LOWER(provider) LIKE '%efs%' OR LOWER(provider) LIKE '%wex%'");
      $update = $mysqli->prepare('UPDATE fuel_cards SET is_active = ? WHERE id = ?');
      while ($card = $res->fetch_assoc()) {
        $id = (int)$card['id'];
        try {
          $status = $efs->getStatus((string)$card['external_id']);
          $isActive = strcasecmp($status, 'Active') === 0 ? 1 : 0;
          $update->bind_param('ii', $isActive, $id);
          $update->execute();
          $synced++;
          fuel_log_event($mysqli, 'card_synced', $id, null, null, 'Card status synchronized from RTS/EFS.', ['efs_status' => $status]);
        } catch (Throwable $cardError) {
          $failed[] = '•••• ' . (string)$card['card_last4'] . ': ' . $cardError->getMessage();
        }
      }
      $update->close();
      $res->close();
    } finally {
      $efs->close();
    }
    $success = "Synchronized {$synced} RTS/EFS card(s).";
    if ($failed) {
      $error = 'Some cards could not be synchronized: ' . implode(' | ', $failed);
    }
  } catch (Throwable $e) {
    $error = 'RTS/EFS synchronization failed: ' . $e->getMessage();
  }
}

if ($action === 'add_override') {
  $cardId = (int)($_POST['card_id'] ?? 0);
  $driverId = (int)($_POST['driver_id'] ?? 0);
  $overrideType = trim($_POST['override_type'] ?? '');
  $start = trim($_POST['start_time'] ?? '');
  $end = trim($_POST['end_time'] ?? '');
  $reason = trim($_POST['reason'] ?? '');
  if ($cardId <= 0 || $overrideType === '' || $start === '' || $end === '' || $reason === '') {
    $error = 'All override fields are required.';
  } else {
    $stmt = $mysqli->prepare(
      'INSERT INTO fuel_card_overrides (card_id, driver_id, override_type, start_time, end_time, reason, approved_by)
       VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $driverId = $driverId > 0 ? $driverId : null;
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $stmt->bind_param('iissssi', $cardId, $driverId, $overrideType, $start, $end, $reason, $userId);
    $stmt->execute();
    $stmt->close();
    fuel_log_event($mysqli, 'override_created', $cardId, $driverId, null, 'Override created.', [
      'type' => $overrideType,
      'start' => $start,
      'end' => $end,
    ]);
    $success = 'Override created.';
  }
}

if ($action === 'delete_card') {
  $cardId = (int)($_POST['card_id'] ?? 0);
  if ($cardId <= 0) {
    $error = 'Missing card selection.';
  } else {
    $stmt = $mysqli->prepare('DELETE FROM fuel_cards WHERE id = ?');
    $stmt->bind_param('i', $cardId);
    $stmt->execute();
    $stmt->close();
    fuel_log_event($mysqli, 'card_deleted', $cardId, null, null, 'Fuel card deleted.');
    $success = 'Fuel card deleted.';
  }
}

if ($action === 'update_override') {
  $overrideId = (int)($_POST['override_id'] ?? 0);
  $cardId = (int)($_POST['card_id'] ?? 0);
  $driverId = (int)($_POST['driver_id'] ?? 0);
  $overrideType = trim($_POST['override_type'] ?? '');
  $start = trim($_POST['start_time'] ?? '');
  $end = trim($_POST['end_time'] ?? '');
  $reason = trim($_POST['reason'] ?? '');
  if ($overrideId <= 0 || $cardId <= 0 || $overrideType === '' || $start === '' || $end === '' || $reason === '') {
    $error = 'All override fields are required.';
  } else {
    $stmt = $mysqli->prepare(
      'UPDATE fuel_card_overrides
          SET card_id = ?, driver_id = ?, override_type = ?, start_time = ?, end_time = ?, reason = ?
        WHERE id = ?'
    );
    $driverId = $driverId > 0 ? $driverId : null;
    $stmt->bind_param('iissssi', $cardId, $driverId, $overrideType, $start, $end, $reason, $overrideId);
    $stmt->execute();
    $stmt->close();
    fuel_log_event($mysqli, 'override_updated', $cardId, $driverId, null, 'Override updated.', [
      'override_id' => $overrideId,
      'type' => $overrideType,
      'start' => $start,
      'end' => $end,
    ]);
    $success = 'Override updated.';
  }
}

if ($action === 'delete_override') {
  $overrideId = (int)($_POST['override_id'] ?? 0);
  if ($overrideId <= 0) {
    $error = 'Missing override selection.';
  } else {
    $stmt = $mysqli->prepare('DELETE FROM fuel_card_overrides WHERE id = ?');
    $stmt->bind_param('i', $overrideId);
    $stmt->execute();
    $stmt->close();
    $success = 'Override deleted.';
  }
}

if ($action === 'apply_unmatched_fuel_row') {
  $rowId = (int)($_POST['row_id'] ?? 0);
  $driverId = (int)($_POST['driver_id'] ?? 0);
  if ($rowId <= 0 || $driverId <= 0) {
    $error = 'Select both an unmatched fuel row and a driver.';
  } else {
    $mysqli->begin_transaction();
    try {
      $stmt = $mysqli->prepare(
        "SELECT COALESCE(visit_date, txn_date) AS business_date, gross_amt, fees_amt, driver_contact_id, entry_source
           FROM fuel_report_transactions
          WHERE id=?
          LIMIT 1"
      );
      $stmt->bind_param('i', $rowId);
      $stmt->execute();
      $stmt->bind_result($txnDate, $grossAmt, $feesAmt, $existingDriverId, $entrySource);
      $found = $stmt->fetch();
      $stmt->close();

      if (!$found) {
        throw new RuntimeException('Unable to locate the selected fuel row.');
      }
      if ((string)$entrySource !== 'upload') {
        throw new RuntimeException('Only uploaded fuel rows can be applied from review.');
      }

      $txnDate = (string)$txnDate;
      $applyAmount = round((float)$grossAmt + (float)$feesAmt, 2);

      $cleanup = $mysqli->prepare(
        "DELETE FROM fuel_report_transactions
          WHERE driver_contact_id=? AND txn_date=? AND entry_source='manual'"
      );
      $cleanup->bind_param('is', $driverId, $txnDate);
      $cleanup->execute();
      $cleanup->close();

      $upd = $mysqli->prepare(
        "UPDATE fuel_report_transactions
            SET driver_contact_id=?,
                applied_to_gas_costs=?,
                applied_at=?
          WHERE id=?"
      );
      $appliedFlag = ($applyAmount != 0.0) ? 1 : 0;
      $appliedAt = $appliedFlag ? date('Y-m-d H:i:s') : null;
      $upd->bind_param('iisi', $driverId, $appliedFlag, $appliedAt, $rowId);
      $upd->execute();
      $upd->close();

      if ($appliedFlag === 1) {
        $newTotal = fuel_sync_uploaded_day_total($mysqli, $driverId, $txnDate);
        $success = 'Unmatched fuel row applied. Driver/day uploaded fuel total is now $' . number_format($newTotal, 2) . '.';
      } else {
        $success = 'Unmatched fuel row assigned to the driver, but not applied because Gross + Fees is 0.00.';
      }

      fuel_log_event($mysqli, 'unmatched_fuel_row_applied', null, $driverId, null, 'Unmatched uploaded fuel row assigned and reviewed.', [
        'fuel_report_transaction_id' => $rowId,
        'txn_date' => $txnDate,
        'previous_driver_id' => $existingDriverId !== null ? (int)$existingDriverId : null,
        'applied_amount' => $applyAmount,
      ]);

      $mysqli->commit();
    } catch (Throwable $e) {
      $mysqli->rollback();
      $error = 'Unable to apply unmatched fuel row: ' . $e->getMessage();
    }
  }
}

if ($action === 'update_weekly_fuel_row_driver') {
  $rowId = (int)($_POST['row_id'] ?? 0);
  $driverId = (int)($_POST['driver_id'] ?? 0);
  if ($rowId <= 0 || $driverId <= 0) {
    $error = 'Select a valid fuel row and driver.';
  } else {
    $mysqli->begin_transaction();
    try {
      if (!fuel_driver_exists($mysqli, $driverId)) {
        throw new RuntimeException('The selected driver no longer exists in Driver Contacts. Refresh the page and choose an active driver.');
      }

      $stmt = $mysqli->prepare(
        "SELECT COALESCE(visit_date, txn_date) AS business_date, gross_amt, fees_amt, driver_contact_id, entry_source
           FROM fuel_report_transactions
          WHERE id=?
          LIMIT 1"
      );
      $stmt->bind_param('i', $rowId);
      $stmt->execute();
      $stmt->bind_result($txnDate, $grossAmt, $feesAmt, $existingDriverId, $entrySource);
      $found = $stmt->fetch();
      $stmt->close();

      if (!$found) {
        throw new RuntimeException('Unable to locate the selected fuel row.');
      }
      if ((string)$entrySource !== 'upload') {
        throw new RuntimeException('Only uploaded fuel rows can be reassigned from the weekly breakdown.');
      }

      $txnDate = (string)$txnDate;
      $oldDriverId = $existingDriverId !== null ? (int)$existingDriverId : 0;
      $applyAmount = round((float)$grossAmt + (float)$feesAmt, 2);
      $appliedFlag = ($applyAmount != 0.0) ? 1 : 0;
      $appliedAt = $appliedFlag ? date('Y-m-d H:i:s') : null;

      $cleanup = $mysqli->prepare(
        "DELETE FROM fuel_report_transactions
          WHERE driver_contact_id=? AND txn_date=? AND entry_source='manual'"
      );
      $cleanup->bind_param('is', $driverId, $txnDate);
      $cleanup->execute();
      $cleanup->close();

      $upd = $mysqli->prepare(
        "UPDATE fuel_report_transactions
            SET driver_contact_id=?,
                applied_to_gas_costs=?,
                applied_at=?
          WHERE id=?"
      );
      $upd->bind_param('iisi', $driverId, $appliedFlag, $appliedAt, $rowId);
      $upd->execute();
      $upd->close();

      if ($oldDriverId > 0 && fuel_driver_exists($mysqli, $oldDriverId)) {
        fuel_sync_uploaded_day_total($mysqli, $oldDriverId, $txnDate);
      }
      $newTotal = fuel_sync_uploaded_day_total($mysqli, $driverId, $txnDate);

      fuel_log_event($mysqli, 'weekly_fuel_row_driver_updated', null, $driverId, null, 'Weekly fuel row driver updated.', [
        'fuel_report_transaction_id' => $rowId,
        'txn_date' => $txnDate,
        'previous_driver_id' => $oldDriverId > 0 ? $oldDriverId : null,
        'applied_amount' => $applyAmount,
      ]);

      $mysqli->commit();
      $success = 'Fuel row driver updated. Driver/day uploaded fuel total is now $' . number_format($newTotal, 2) . '.';
    } catch (Throwable $e) {
      $mysqli->rollback();
      $error = 'Unable to update weekly fuel row driver: ' . $e->getMessage();
    }
  }
}

if ($action === 'mark_unmatched_fuel_row_misc') {
  $rowId = (int)($_POST['row_id'] ?? 0);
  if ($rowId <= 0) {
    $error = 'Select a valid unmatched fuel row to mark as miscellaneous.';
  } else {
    $mysqli->begin_transaction();
    try {
      $stmt = $mysqli->prepare(
        "SELECT COALESCE(visit_date, txn_date) AS business_date, gross_amt, fees_amt, driver_contact_id, entry_source
           FROM fuel_report_transactions
          WHERE id=?
          LIMIT 1"
      );
      $stmt->bind_param('i', $rowId);
      $stmt->execute();
      $stmt->bind_result($txnDate, $grossAmt, $feesAmt, $existingDriverId, $entrySource);
      $found = $stmt->fetch();
      $stmt->close();

      if (!$found) {
        throw new RuntimeException('Unable to locate the selected fuel row.');
      }
      if ((string)$entrySource !== 'upload') {
        throw new RuntimeException('Only uploaded fuel rows can be marked as miscellaneous from review.');
      }

      $upd = $mysqli->prepare(
        "UPDATE fuel_report_transactions
            SET driver_contact_id=NULL,
                applied_to_gas_costs=1,
                applied_at=?
          WHERE id=?"
      );
      $appliedAt = date('Y-m-d H:i:s');
      $upd->bind_param('si', $appliedAt, $rowId);
      $upd->execute();
      $upd->close();

      fuel_log_event($mysqli, 'unmatched_fuel_row_marked_misc', null, null, null, 'Unmatched uploaded fuel row marked as miscellaneous and excluded from driver fuel costs.', [
        'fuel_report_transaction_id' => $rowId,
        'txn_date' => (string)$txnDate,
        'previous_driver_id' => $existingDriverId !== null ? (int)$existingDriverId : null,
        'gross_amt' => (float)$grossAmt,
        'fees_amt' => (float)$feesAmt,
      ]);

      $mysqli->commit();
      $success = 'Unmatched fuel row marked as miscellaneous. It will no longer appear in review or be assigned to a driver fuel cost.';
    } catch (Throwable $e) {
      $mysqli->rollback();
      $error = 'Unable to mark unmatched fuel row as miscellaneous: ' . $e->getMessage();
    }
  }
}

if ($action === 'upload_fuel_report') {
  $file = $_FILES['fuel_report_file'] ?? ($_FILES['fuel_report_pdf'] ?? null);
  if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $error = 'Please select a valid fuel report file to upload.';
  } else {
    $tmpPath = (string)($file['tmp_name'] ?? '');
    $origName = trim((string)($file['name'] ?? 'fuel_report'));
    $fileHash = hash_file('sha256', $tmpPath);
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    $check = $mysqli->prepare("SELECT id FROM fuel_report_uploads WHERE file_hash=? LIMIT 1");
    $check->bind_param('s', $fileHash);
    $check->execute();
    $check->store_result();
    $dup = $check->num_rows > 0;
    $check->close();
    if ($dup) {
      $error = 'This fuel report has already been uploaded.';
    } else {
      $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
      $items = [];
      $statementDate = null;
      if ($ext === 'csv') {
        $parsedCsv = fuel_parse_mudflap_csv($tmpPath);
        $rows = $parsedCsv['rows'];
        $statementDate = $parsedCsv['statement_date'];
      } else {
        $items = fuel_parse_pdf_items($tmpPath);
        $rows = fuel_parse_transactions_from_items($items);
        $statementDate = fuel_parse_statement_date($items);
      }
      if (!$rows) {
        $error = 'Unable to read fuel transactions from this report. Please confirm the report format.';
      } else {
        $driverIndex = fuel_build_driver_index($mysqli);
        $insertedRows = 0;
        $appliedRows = 0;
        $duplicateRows = 0;
        $inFileDuplicateRows = 0;
        $dailyTotals = [];
        $seenRowHashes = [];

        $mysqli->begin_transaction();
        try {
          $insUpload = $mysqli->prepare(
            "INSERT INTO fuel_report_uploads
             (original_filename, file_hash, statement_date, uploaded_by, row_count, inserted_row_count)
             VALUES (?,?,?,?,?,0)"
          );
          $rowCount = count($rows);
          $insUpload->bind_param('sssii', $origName, $fileHash, $statementDate, $userId, $rowCount);
          $insUpload->execute();
          $uploadId = (int)$insUpload->insert_id;
          $insUpload->close();

          $insRow = $mysqli->prepare(
            "INSERT IGNORE INTO fuel_report_transactions
             (upload_id, txn_date, visit_date, posted_date, category, description, card, unit, prompt_data, invoice_number, loc_number, location_name, state, qty, gross_amt, fees_amt, desc_amt, total_amt, driver_contact_id, entry_source, row_hash, transaction_hash, applied_to_gas_costs, applied_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
          );
          $appliedNow = null;
          $manualCleanup = [];
          foreach ($rows as $rowIndex => $r) {
            $driverId = fuel_resolve_driver_id((string)$r['prompt_data'], (string)$r['card'], $driverIndex);
            $gross = (float)$r['gross_amt'];
            $fees = (float)$r['fees_amt'];
            $dailyApply = round($gross + $fees, 2);
            $isApplied = ($driverId !== null && $dailyApply != 0.0) ? 1 : 0;
            $appliedNow = $isApplied ? date('Y-m-d H:i:s') : null;

            $contentHash = fuel_build_upload_row_hash($r);
            if (isset($seenRowHashes[$contentHash])) {
              $inFileDuplicateRows++;
            } else {
              $seenRowHashes[$contentHash] = true;
            }
            $rowHash = fuel_build_upload_row_hash($r, $uploadId . '|' . $rowIndex);
            $transactionHash = fuel_build_upload_transaction_hash($r);

            $txnDate = (string)$r['txn_date'];
            $visitDate = (string)($r['visit_date'] ?? $txnDate);
            $postedDate = isset($r['posted_date']) && $r['posted_date'] !== null ? (string)$r['posted_date'] : null;
            $category = (string)$r['category'];
            $description = (string)$r['description'];
            $card = (string)$r['card'];
            $unit = (string)$r['unit'];
            $prompt = (string)$r['prompt_data'];
            $invoice = (string)$r['invoice_number'];
            $loc = (string)$r['loc_number'];
            $location = (string)$r['location_name'];
            $state = (string)$r['state'];
            $qty = (float)$r['qty'];
            $descAmt = (float)$r['desc_amt'];
            $totalAmt = (float)$r['total_amt'];
            $driverRef = $driverId;
            $entrySource = 'upload';
            $rowStatus = 'saved';
            $rowReason = '';

            if ($driverId !== null) {
              $cleanupKey = $driverId . '|' . $visitDate;
              if (!isset($manualCleanup[$cleanupKey])) {
                $cleanup = $mysqli->prepare(
                  "DELETE FROM fuel_report_transactions
                    WHERE driver_contact_id=? AND txn_date=? AND entry_source='manual'"
                );
                $cleanup->bind_param('is', $driverId, $visitDate);
                $cleanup->execute();
                $cleanup->close();
                $manualCleanup[$cleanupKey] = true;
              }
            }

            $insRow->bind_param(
              'issssssssssssdddddisssis',
              $uploadId,
              $txnDate,
              $visitDate,
              $postedDate,
              $category,
              $description,
              $card,
              $unit,
              $prompt,
              $invoice,
              $loc,
              $location,
              $state,
              $qty,
              $gross,
              $fees,
              $descAmt,
              $totalAmt,
              $driverRef,
              $entrySource,
              $rowHash,
              $transactionHash,
              $isApplied,
              $appliedNow
            );
            $insRow->execute();
            if ((int)$insRow->affected_rows === 1) {
              $insertedRows++;
              if ($isApplied && $driverId !== null) {
                $k = $driverId . '|' . $visitDate;
                if (!isset($dailyTotals[$k])) {
                  $dailyTotals[$k] = ['driver_id' => $driverId, 'cost_date' => $visitDate, 'amount' => 0.0];
                }
                $dailyTotals[$k]['amount'] += $dailyApply;
                $appliedRows++;
              } elseif ($driverId === null) {
                $rowStatus = 'saved_not_applied';
                $rowReason = 'No driver match';
              } else {
                $rowStatus = 'saved_not_applied';
                $rowReason = 'Gross + fees = 0.00';
              }
            } else {
              $duplicateRows++;
              $rowStatus = 'skipped';
              $rowReason = 'Insert ignored by database';
            }

            $uploadDebugRows[] = [
              'row_no' => $rowIndex + 1,
              'txn_date' => $txnDate,
              'description' => $description,
              'card' => $card,
              'prompt_data' => $prompt,
              'gross_amt' => $gross,
              'fees_amt' => $fees,
              'daily_apply' => $dailyApply,
              'driver_id' => $driverId,
              'status' => $rowStatus,
              'reason' => $rowReason,
            ];
            $uploadDebugGrossTotal += $gross;
            $uploadDebugFeesTotal += $fees;
          }
          $insRow->close();

          foreach ($dailyTotals as $sum) {
            $driverId = (int)$sum['driver_id'];
            $costDate = (string)$sum['cost_date'];
            if ($driverId > 0 && $costDate !== '') {
              fuel_sync_uploaded_day_total($mysqli, $driverId, $costDate);
            }
          }

          $updUpload = $mysqli->prepare("UPDATE fuel_report_uploads SET inserted_row_count=? WHERE id=?");
          $updUpload->bind_param('ii', $insertedRows, $uploadId);
          $updUpload->execute();
          $updUpload->close();

          $mysqli->commit();
          $success = "Fuel report uploaded. Parsed rows: {$rowCount}. New transaction rows saved: {$insertedRows}. Gas-cost rows applied: {$appliedRows}.";
          if ($duplicateRows > 0) {
            $success .= " Skipped duplicate rows: {$duplicateRows}.";
          }
          if ($inFileDuplicateRows > 0) {
            $success .= " Duplicate row hashes detected inside parsed PDF rows: {$inFileDuplicateRows}.";
          }
        } catch (Throwable $e) {
          $mysqli->rollback();
          $error = 'Fuel report upload failed: ' . $e->getMessage();
        }
      }
    }
  }
}

$drivers = [];
$resDrivers = $mysqli->query(
  "SELECT id, CONCAT(first_name, ' ', last_name) AS name
     FROM driver_contacts
 ORDER BY last_name, first_name"
);
while ($row = $resDrivers->fetch_assoc()) {
  $drivers[] = $row;
}

$cards = [];
$resCards = $mysqli->query(
  "SELECT fc.*, CONCAT(dc.first_name, ' ', dc.last_name) AS driver_name
     FROM fuel_cards fc
LEFT JOIN driver_contacts dc ON dc.id = fc.assigned_driver_id
 ORDER BY fc.provider, fc.card_last4"
);
while ($row = $resCards->fetch_assoc()) {
  $cards[] = $row;
}

$recentOverrides = [];
$resOverrides = $mysqli->query(
  "SELECT fco.*, fc.card_last4, fc.provider, CONCAT(dc.first_name, ' ', dc.last_name) AS driver_name
     FROM fuel_card_overrides fco
LEFT JOIN fuel_cards fc ON fc.id = fco.card_id
LEFT JOIN driver_contacts dc ON dc.id = fco.driver_id
 ORDER BY fco.created_at DESC
 LIMIT 10"
);
while ($row = $resOverrides->fetch_assoc()) {
  $recentOverrides[] = $row;
}

$availableFuelWeeks = [];
$resFuelWeeks = $mysqli->query("
  SELECT DISTINCT DATE_SUB(COALESCE(visit_date, txn_date), INTERVAL (DAYOFWEEK(COALESCE(visit_date, txn_date)) - 1) DAY) AS week_start
    FROM fuel_report_transactions
   ORDER BY week_start DESC
");
while ($row = $resFuelWeeks->fetch_assoc()) {
  $weekStart = (string)($row['week_start'] ?? '');
  if ($weekStart !== '') $availableFuelWeeks[] = $weekStart;
}
$currentFuelWeek = fuel_week_start(date('Y-m-d'));
if (!in_array($currentFuelWeek, $availableFuelWeeks, true)) {
  array_unshift($availableFuelWeeks, $currentFuelWeek);
}
if (!$availableFuelWeeks) {
  $availableFuelWeeks[] = $currentFuelWeek;
}
$selectedFuelWeek = trim((string)($_GET['fuel_week_start'] ?? $availableFuelWeeks[0]));
if (!in_array($selectedFuelWeek, $availableFuelWeeks, true)) {
  $selectedFuelWeek = $availableFuelWeeks[0];
}
$selectedFuelWeekEnd = fuel_week_end($selectedFuelWeek);

$weeklyFuelRows = [];
$weeklyFuelChargeRows = [];
$activeCardIndexByLast4 = [];
$activeCardIndexByTypeLast4 = [];
$activeCardIdsByDriver = [];
$activeCardIdsByDriverType = [];
$resWeeklyCards = $mysqli->query("
  SELECT fc.id,
         fc.provider,
         fc.card_label,
         fc.card_last4,
         fc.assigned_driver_id,
         fc.is_active,
         COALESCE(dc.is_disabled, 0) AS driver_disabled,
         CONCAT(COALESCE(dc.first_name, ''), ' ', COALESCE(dc.last_name, '')) AS driver_name
    FROM fuel_cards fc
    JOIN driver_contacts dc ON dc.id = fc.assigned_driver_id
   WHERE fc.assigned_driver_id IS NOT NULL
   ORDER BY dc.last_name, dc.first_name, fc.provider, fc.card_last4
");
while ($row = $resWeeklyCards->fetch_assoc()) {
  $cardId = (int)($row['id'] ?? 0);
  if ($cardId <= 0) continue;

  $driverId = (int)($row['assigned_driver_id'] ?? 0);
  if ($driverId <= 0) continue;
  $driverName = trim((string)($row['driver_name'] ?? ''));
  if ($driverName === '') continue;
  $driverStatus = ((int)($row['driver_disabled'] ?? 0) === 1) ? 'Inactive Driver' : 'Active Driver';
  $cardStatus = ((int)($row['is_active'] ?? 0) === 1) ? 'Active Card' : 'Inactive Card';
  $cardLast4 = fuel_extract_card_last4((string)($row['card_last4'] ?? ''));
  $cardDisplay = trim((string)($row['card_label'] ?? ''));
  if ($cardDisplay === '') {
    $cardDisplay = $cardLast4 !== '' ? ('•••• ' . $cardLast4) : 'Unknown Card';
  }

  $weeklyFuelRows[$cardId] = [
    'card_id' => $cardId,
    'provider' => trim((string)($row['provider'] ?? '')),
    'card_display' => $cardDisplay,
    'card_last4' => $cardLast4,
    'driver_id' => $driverId,
    'driver_name' => $driverName,
    'card_status_label' => $cardStatus,
    'driver_status_label' => $driverStatus,
    'status_label' => $cardStatus . ' / ' . $driverStatus,
    'txn_count' => 0,
    'qty_total' => 0.0,
    'gross_total' => 0.0,
    'fees_total' => 0.0,
    'total_total' => 0.0,
  ];

  $cardType = fuel_card_type_label('upload', '', (string)($row['provider'] ?? ''));
  if ($cardLast4 !== '') {
    if (!isset($activeCardIndexByLast4[$cardLast4])) $activeCardIndexByLast4[$cardLast4] = [];
    $activeCardIndexByLast4[$cardLast4][] = $cardId;
    if (!isset($activeCardIndexByTypeLast4[$cardType])) $activeCardIndexByTypeLast4[$cardType] = [];
    if (!isset($activeCardIndexByTypeLast4[$cardType][$cardLast4])) $activeCardIndexByTypeLast4[$cardType][$cardLast4] = [];
    $activeCardIndexByTypeLast4[$cardType][$cardLast4][] = $cardId;
  }
  if ($driverId > 0) {
    if (!isset($activeCardIdsByDriver[$driverId])) $activeCardIdsByDriver[$driverId] = [];
    $activeCardIdsByDriver[$driverId][] = $cardId;
    if (!isset($activeCardIdsByDriverType[$driverId])) $activeCardIdsByDriverType[$driverId] = [];
    if (!isset($activeCardIdsByDriverType[$driverId][$cardType])) $activeCardIdsByDriverType[$driverId][$cardType] = [];
    $activeCardIdsByDriverType[$driverId][$cardType][] = $cardId;
  }
}

$weeklyFuelTotals = [
  'txn_count' => 0,
  'qty_total' => 0.0,
  'gross_total' => 0.0,
  'fees_total' => 0.0,
  'total_total' => 0.0,
];
$stmtWeeklyFuel = $mysqli->prepare("
  SELECT frt.id,
         COALESCE(frt.visit_date, frt.txn_date) AS business_date,
         frt.txn_date,
         frt.visit_date,
         frt.posted_date,
         frt.card,
         frt.description,
         frt.location_name,
         frt.qty,
         frt.gross_amt,
         frt.fees_amt,
         frt.total_amt,
         frt.driver_contact_id,
         frt.category,
         frt.entry_source,
         COALESCE(mdc.is_disabled, 0) AS matched_driver_disabled,
         CONCAT(COALESCE(mdc.first_name, ''), ' ', COALESCE(mdc.last_name, '')) AS matched_driver_name
    FROM fuel_report_transactions frt
    LEFT JOIN driver_contacts mdc ON mdc.id = frt.driver_contact_id
   WHERE COALESCE(frt.visit_date, frt.txn_date) BETWEEN ? AND ?
   ORDER BY business_date ASC, frt.id ASC
");
$stmtWeeklyFuel->bind_param('ss', $selectedFuelWeek, $selectedFuelWeekEnd);
$stmtWeeklyFuel->execute();
$resWeeklyFuel = $stmtWeeklyFuel->get_result();
while ($row = $resWeeklyFuel->fetch_assoc()) {
  $targetCardId = null;
  $last4 = fuel_extract_card_last4((string)($row['card'] ?? ''));
  $matchedDriverId = (int)($row['driver_contact_id'] ?? 0);
  $matchedDriverName = trim((string)($row['matched_driver_name'] ?? ''));
  $matchedDriverStatus = ((int)($row['matched_driver_disabled'] ?? 0) === 1) ? 'Inactive Driver' : 'Active Driver';
  $entrySource = strtolower(trim((string)($row['entry_source'] ?? '')));
  $category = strtolower(trim((string)($row['category'] ?? '')));
  $cardType = fuel_card_type_label($entrySource, $category);
  if ($last4 !== '' && !empty($activeCardIndexByTypeLast4[$cardType][$last4]) && count($activeCardIndexByTypeLast4[$cardType][$last4]) === 1) {
    $targetCardId = (int)$activeCardIndexByTypeLast4[$cardType][$last4][0];
  } elseif ($last4 !== '' && !empty($activeCardIndexByLast4[$last4]) && count($activeCardIndexByLast4[$last4]) === 1) {
    $targetCardId = (int)$activeCardIndexByLast4[$last4][0];
  } else {
    if ($matchedDriverId > 0 && !empty($activeCardIdsByDriverType[$matchedDriverId][$cardType]) && count($activeCardIdsByDriverType[$matchedDriverId][$cardType]) === 1) {
      $targetCardId = (int)$activeCardIdsByDriverType[$matchedDriverId][$cardType][0];
    } elseif ($matchedDriverId > 0 && !empty($activeCardIdsByDriver[$matchedDriverId]) && count($activeCardIdsByDriver[$matchedDriverId]) === 1) {
      $targetCardId = (int)$activeCardIdsByDriver[$matchedDriverId][0];
    }
  }

  if (($targetCardId === null || !isset($weeklyFuelRows[$targetCardId])) && $matchedDriverId <= 0) {
    continue;
  }

  $description = trim((string)($row['description'] ?? ''));
  $locationName = trim((string)($row['location_name'] ?? ''));
  if ($description === '') $description = 'Fuel Charge';

  $qty = (float)($row['qty'] ?? 0);
  $gross = (float)($row['gross_amt'] ?? 0);
  $fees = (float)($row['fees_amt'] ?? 0);
  $total = (float)($row['total_amt'] ?? 0);
  if ($total === 0.0) {
    $total = round($gross + $fees, 2);
  }

  if ($targetCardId !== null && isset($weeklyFuelRows[$targetCardId])) {
    $weeklyFuelRows[$targetCardId]['txn_count']++;
    $weeklyFuelRows[$targetCardId]['qty_total'] += $qty;
    $weeklyFuelRows[$targetCardId]['gross_total'] += $gross;
    $weeklyFuelRows[$targetCardId]['fees_total'] += $fees;
    $weeklyFuelRows[$targetCardId]['total_total'] += $total;
    $driverName = $matchedDriverName !== '' ? $matchedDriverName : $weeklyFuelRows[$targetCardId]['driver_name'];
    $statusLabel = $matchedDriverId > 0
      ? $weeklyFuelRows[$targetCardId]['card_status_label'] . ' / ' . $matchedDriverStatus
      : $weeklyFuelRows[$targetCardId]['status_label'];
    $cardDisplay = $weeklyFuelRows[$targetCardId]['card_display'];
  } else {
    $driverName = $matchedDriverName !== '' ? $matchedDriverName : 'Unmatched Driver';
    $statusLabel = 'No card match / ' . $matchedDriverStatus;
    $cardDisplay = $last4 !== '' ? ('Card ' . $last4) : ($entrySource === 'manual' ? 'Manual Entry' : 'Unknown Card');
  }
  $needsAttention = (stripos($statusLabel, 'No card match') !== false) || (($entrySource === 'upload') && $matchedDriverId <= 0);
  $weeklyFuelChargeRows[] = [
    'id' => (int)($row['id'] ?? 0),
    'txn_date' => (string)($row['business_date'] ?? $row['txn_date'] ?? ''),
    'posted_date' => (string)($row['posted_date'] ?? ''),
    'driver_id' => $matchedDriverId,
    'driver_name' => $driverName,
    'status_label' => $statusLabel,
    'card_type' => $cardType,
    'card_display' => $cardDisplay,
    'description' => $description,
    'location_name' => $locationName,
    'qty' => $qty,
    'gross' => $gross,
    'fees' => $fees,
    'total' => $total,
    'can_edit_driver' => ($entrySource === 'upload'),
    'needs_attention' => $needsAttention,
  ];

  $weeklyFuelTotals['txn_count']++;
  $weeklyFuelTotals['qty_total'] += $qty;
  $weeklyFuelTotals['gross_total'] += $gross;
  $weeklyFuelTotals['fees_total'] += $fees;
  $weeklyFuelTotals['total_total'] += $total;
}
$stmtWeeklyFuel->close();

$unmatchedFuelRows = [];
$resUnmatched = $mysqli->query(
  "SELECT frt.id, COALESCE(frt.visit_date, frt.txn_date) AS business_date, frt.txn_date, frt.posted_date,
          frt.description, frt.card, frt.prompt_data, frt.gross_amt, frt.fees_amt,
          frt.location_name, frt.invoice_number, fru.original_filename, fru.created_at AS uploaded_at
     FROM fuel_report_transactions frt
LEFT JOIN fuel_report_uploads fru ON fru.id = frt.upload_id
    WHERE frt.entry_source='upload'
      AND COALESCE(frt.applied_to_gas_costs, 0)=0
      AND frt.driver_contact_id IS NULL
 ORDER BY business_date DESC, frt.id DESC
    LIMIT 100"
);
while ($row = $resUnmatched->fetch_assoc()) {
  $unmatchedFuelRows[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Fuel Card Manager</title>
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

    .card-shell {
      border: 3px solid #222;
      border-radius: 12px;
      padding: 16px;
      background: #f9fafb;
      min-height: 70vh;
      box-shadow: 0 8px 18px rgba(0,0,0,0.08);
    }

    .manager-layout {
      display: grid;
      grid-template-columns: 320px 1fr;
      gap: 16px;
      min-height: 60vh;
    }

    @media (max-width: 992px) {
      .manager-layout { grid-template-columns: 1fr; }
    }

    .driver-panel, .cards-panel {
      background: #fff;
      border: 1px solid #ddd;
      border-radius: 10px;
      padding: 12px;
    }

    .driver-panel { max-height: 70vh; overflow: auto; }
    .driver-item { border-bottom: 1px solid #eee; padding: 8px 0; }
    .driver-item:last-child { border-bottom: none; }
    .driver-item button { width: 100%; text-align: left; background: #f4f6fb; border: 1px solid #ccd; border-radius: 6px; padding: 6px 8px; }

    .card-table th { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.03em; }
    .badge-active { background: #0f766e; }
    .badge-inactive { background: #b91c1c; }
    .weekly-fuel-toolbar { display:flex; justify-content:space-between; align-items:end; gap:12px; flex-wrap:wrap; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main">
      <h1>Fuel Card Manager</h1>
      <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES) ?></div>
      <?php endif; ?>
      <?php if ($uploadDebugRows): ?>
        <div class="card mb-3">
          <div class="card-header">Last Fuel Upload Row Diagnostics</div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm mb-0 align-middle">
                <thead>
                  <tr>
                    <th>Row</th>
                    <th>Visit Date</th>
                    <th>Description</th>
                    <th>Card</th>
                    <th>Prompt</th>
                    <th>Gross</th>
                    <th>Fees</th>
                    <th>Driver</th>
                    <th>Status</th>
                    <th>Reason</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($uploadDebugRows as $debugRow): ?>
                    <tr>
                      <td><?= (int)$debugRow['row_no'] ?></td>
                      <td><?= htmlspecialchars((string)$debugRow['txn_date'], ENT_QUOTES) ?></td>
                      <td><?= htmlspecialchars((string)$debugRow['description'], ENT_QUOTES) ?></td>
                      <td><?= htmlspecialchars((string)$debugRow['card'], ENT_QUOTES) ?></td>
                      <td><?= htmlspecialchars((string)$debugRow['prompt_data'], ENT_QUOTES) ?></td>
                      <td><?= number_format((float)$debugRow['gross_amt'], 2) ?></td>
                      <td><?= number_format((float)$debugRow['fees_amt'], 2) ?></td>
                      <td><?= $debugRow['driver_id'] !== null ? (int)$debugRow['driver_id'] : '—' ?></td>
                      <td><?= htmlspecialchars((string)$debugRow['status'], ENT_QUOTES) ?></td>
                      <td><?= htmlspecialchars((string)$debugRow['reason'], ENT_QUOTES) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot>
                  <tr>
                    <th colspan="5" class="text-end">Totals</th>
                    <th><?= number_format($uploadDebugGrossTotal, 2) ?></th>
                    <th><?= number_format($uploadDebugFeesTotal, 2) ?></th>
                    <th colspan="3"></th>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        </div>
      <?php endif; ?>
      <?php if (!empty($gasErrors)): ?>
        <div class="alert alert-danger">
          <strong>Gas Cost Errors:</strong>
          <ul class="mb-0"><?php foreach ($gasErrors as $gasError): ?><li><?= htmlspecialchars($gasError, ENT_QUOTES) ?></li><?php endforeach; ?></ul>
        </div>
      <?php elseif ($successGas): ?>
        <div class="alert alert-success">Gas costs saved.</div>
      <?php endif; ?>

      <div class="d-flex flex-wrap gap-2 mb-3">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCardModal">Add Fuel Card</button>
        <form method="post" class="d-inline">
          <input type="hidden" name="action" value="sync_efs_cards">
          <button class="btn btn-outline-success" type="submit" <?= EfsCardService::isConfigured() ? '' : 'disabled' ?>>Sync RTS/EFS Cards</button>
        </form>
        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#overrideModal">Create Override</button>
        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#gasCostsModal">Add Fuel Costs</button>
        <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#fuelReportModal">Fuel Report Uploader</button>
      </div>
      <?php if (!EfsCardService::isConfigured()): ?>
        <div class="alert alert-warning py-2">
          RTS/EFS live controls are disabled until the server variables <code>EFS_WS_USERNAME_<?= LONESTAR_IS_UAT ? 'UAT' : 'PROD' ?></code>
          and <code>EFS_WS_PASSWORD_<?= LONESTAR_IS_UAT ? 'UAT' : 'PROD' ?></code> are configured.
        </div>
      <?php endif; ?>

      <div class="card-shell">
        <div class="manager-layout">
          <div class="driver-panel">
            <h5>Drivers</h5>
            <?php if (!$drivers): ?>
              <div class="text-muted">No drivers found.</div>
            <?php else: ?>
              <?php foreach ($drivers as $driver): ?>
                <div class="driver-item">
                  <button type="button" data-driver-id="<?= (int)$driver['id'] ?>">
                    <?= htmlspecialchars($driver['name'] ?? '', ENT_QUOTES) ?>
                  </button>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <div class="cards-panel">
            <h5>Fuel Cards</h5>
            <div class="table-responsive">
              <table class="table table-sm card-table align-middle">
                <thead>
                  <tr>
                    <th>Provider</th>
                    <th>Card</th>
                    <th>Driver</th>
                    <th>Status</th>
                    <th>Limits</th>
                    <th>Fuel Only</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$cards): ?>
                    <tr><td colspan="7" class="text-muted">No cards created yet.</td></tr>
                  <?php else: ?>
                    <?php foreach ($cards as $card): ?>
                      <tr>
                        <td><?= htmlspecialchars($card['provider'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($card['card_label'] ?: ('•••• ' . $card['card_last4']), ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($card['driver_name'] ?? 'Unassigned', ENT_QUOTES) ?></td>
                        <td>
                          <?php if ((int)$card['is_active'] === 1): ?>
                            <span class="badge text-white badge-active">Active</span>
                          <?php else: ?>
                            <span class="badge text-white badge-inactive">Inactive</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?= $card['daily_limit'] !== null ? '$' . number_format((float)$card['daily_limit'], 2) . '/day' : '—' ?>
                          <?= $card['per_txn_limit'] !== null ? ' • $' . number_format((float)$card['per_txn_limit'], 2) . '/txn' : '' ?>
                        </td>
                        <td><?= (int)$card['fuel_only'] === 1 ? 'Yes' : 'No' ?></td>
                        <td>
                          <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="toggle_card">
                            <input type="hidden" name="card_id" value="<?= (int)$card['id'] ?>">
                            <input type="hidden" name="is_active" value="<?= (int)$card['is_active'] ? '0' : '1' ?>">
                            <button class="btn btn-sm btn-outline-secondary" type="submit">
                              <?= (int)$card['is_active'] ? 'Disable' : 'Enable' ?>
                            </button>
                          </form>
                          <button
                            class="btn btn-sm btn-outline-primary assign-btn"
                            type="button"
                            data-card-id="<?= (int)$card['id'] ?>"
                            data-driver-id="<?= (int)($card['assigned_driver_id'] ?? 0) ?>"
                          >
                            Assign
                          </button>
                          <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="delete_card">
                            <input type="hidden" name="card_id" value="<?= (int)$card['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Delete this fuel card?');">
                              Delete
                            </button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <h6 class="mt-4">Unmatched Uploaded Fuel Rows</h6>
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th>Visit Date</th>
                    <th>Description</th>
                    <th>Card</th>
                    <th>Prompt</th>
                    <th>Gross + Fees</th>
                    <th>Upload</th>
                    <th>Assign Driver</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$unmatchedFuelRows): ?>
                    <tr><td colspan="7" class="text-muted">No unmatched uploaded fuel rows.</td></tr>
                  <?php else: ?>
                    <?php foreach ($unmatchedFuelRows as $row): ?>
                      <tr class="table-warning">
                        <td>
                          <?= htmlspecialchars((string)($row['business_date'] ?? $row['txn_date'] ?? ''), ENT_QUOTES) ?>
                          <?php if (!empty($row['posted_date']) && (string)$row['posted_date'] !== (string)($row['business_date'] ?? $row['txn_date'] ?? '')): ?>
                            <div class="small text-muted">Posted <?= htmlspecialchars((string)$row['posted_date'], ENT_QUOTES) ?></div>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?= htmlspecialchars((string)($row['description'] ?? ''), ENT_QUOTES) ?>
                          <?php if (!empty($row['location_name'])): ?>
                            <div class="small text-muted"><?= htmlspecialchars((string)$row['location_name'], ENT_QUOTES) ?></div>
                          <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string)($row['card'] ?? ''), ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars((string)($row['prompt_data'] ?? ''), ENT_QUOTES) ?></td>
                        <td>$<?= number_format(((float)($row['gross_amt'] ?? 0) + (float)($row['fees_amt'] ?? 0)), 2) ?></td>
                        <td>
                          <?= htmlspecialchars((string)($row['original_filename'] ?? ''), ENT_QUOTES) ?>
                          <?php if (!empty($row['uploaded_at'])): ?>
                            <div class="small text-muted"><?= htmlspecialchars((string)$row['uploaded_at'], ENT_QUOTES) ?></div>
                          <?php endif; ?>
                        </td>
                        <td>
                          <div class="d-flex gap-2 align-items-center flex-wrap">
                          <form method="post" class="d-flex gap-2 align-items-center">
                            <input type="hidden" name="action" value="apply_unmatched_fuel_row">
                            <input type="hidden" name="row_id" value="<?= (int)$row['id'] ?>">
                            <select name="driver_id" class="form-select form-select-sm" required>
                              <option value="">Select driver</option>
                              <?php foreach ($drivers as $driver): ?>
                                <option value="<?= (int)$driver['id'] ?>"><?= htmlspecialchars((string)$driver['name'], ENT_QUOTES) ?></option>
                              <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-success">Apply</button>
                          </form>
                          <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="mark_unmatched_fuel_row_misc">
                            <input type="hidden" name="row_id" value="<?= (int)$row['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Mark Misc</button>
                          </form>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <h6 class="mt-4">Recent Overrides</h6>
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th>Card</th>
                    <th>Driver</th>
                    <th>Type</th>
                    <th>Window</th>
                    <th>Reason</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$recentOverrides): ?>
                    <tr><td colspan="6" class="text-muted">No overrides yet.</td></tr>
                  <?php else: ?>
                    <?php foreach ($recentOverrides as $override): ?>
                      <tr>
                        <td><?= htmlspecialchars(($override['provider'] ?? '') . ' •••• ' . ($override['card_last4'] ?? ''), ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($override['driver_name'] ?? 'Unassigned', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($override['override_type'] ?? '', ENT_QUOTES) ?></td>
                        <td>
                          <?= htmlspecialchars($override['start_time'] ?? '', ENT_QUOTES) ?>
                          → <?= htmlspecialchars($override['end_time'] ?? '', ENT_QUOTES) ?>
                        </td>
                        <td><?= htmlspecialchars($override['reason'] ?? '', ENT_QUOTES) ?></td>
                        <td>
                          <button
                            class="btn btn-sm btn-outline-primary edit-override-btn"
                            type="button"
                            data-override-id="<?= (int)$override['id'] ?>"
                            data-card-id="<?= (int)$override['card_id'] ?>"
                            data-driver-id="<?= (int)($override['driver_id'] ?? 0) ?>"
                            data-override-type="<?= htmlspecialchars($override['override_type'] ?? '', ENT_QUOTES) ?>"
                            data-start="<?= htmlspecialchars($override['start_time'] ?? '', ENT_QUOTES) ?>"
                            data-end="<?= htmlspecialchars($override['end_time'] ?? '', ENT_QUOTES) ?>"
                            data-reason="<?= htmlspecialchars($override['reason'] ?? '', ENT_QUOTES) ?>"
                          >
                            Edit
                          </button>
                          <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="delete_override">
                            <input type="hidden" name="override_id" value="<?= (int)$override['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this override?');">
                              Delete
                            </button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <h6 class="mt-4">Weekly Fuel Breakdown</h6>
            <form method="get" class="weekly-fuel-toolbar mb-3">
              <div>
                <label for="fuelWeekStart" class="form-label mb-1">Visible Week</label>
                <select
                  id="fuelWeekStart"
                  name="fuel_week_start"
                  class="form-select"
                  onchange="this.form.submit()"
                >
                  <?php foreach ($availableFuelWeeks as $weekStart): ?>
                    <option value="<?= htmlspecialchars($weekStart, ENT_QUOTES) ?>" <?= $weekStart === $selectedFuelWeek ? 'selected' : '' ?>>
                      <?= htmlspecialchars($weekStart, ENT_QUOTES) ?> to <?= htmlspecialchars(fuel_week_end($weekStart), ENT_QUOTES) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="small text-muted">
                Shows each fuel charge by Date of Visit during the selected week, including inactive drivers and inactive cards.
              </div>
            </form>
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Driver</th>
                    <th>Status</th>
                    <th>Card Type</th>
                    <th>Card</th>
                    <th>Description</th>
                    <th>Gallons</th>
                    <th>Gross</th>
                    <th>Fees</th>
                    <th>Total Fuel</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$weeklyFuelChargeRows): ?>
                    <tr><td colspan="10" class="text-muted">No weekly fuel charges found for assigned cards.</td></tr>
                  <?php else: ?>
                    <?php foreach ($weeklyFuelChargeRows as $weeklyRow): ?>
                      <tr class="<?= !empty($weeklyRow['needs_attention']) ? 'table-warning' : '' ?>">
                        <td>
                          <?= htmlspecialchars((string)$weeklyRow['txn_date'], ENT_QUOTES) ?>
                          <?php if (!empty($weeklyRow['posted_date']) && (string)$weeklyRow['posted_date'] !== (string)$weeklyRow['txn_date']): ?>
                            <div class="small text-muted">Posted <?= htmlspecialchars((string)$weeklyRow['posted_date'], ENT_QUOTES) ?></div>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if (!empty($weeklyRow['can_edit_driver'])): ?>
                            <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
                              <input type="hidden" name="action" value="update_weekly_fuel_row_driver">
                              <input type="hidden" name="row_id" value="<?= (int)$weeklyRow['id'] ?>">
                              <select name="driver_id" class="form-select form-select-sm" required>
                                <option value="">Select driver</option>
                                <?php foreach ($drivers as $driver): ?>
                                  <option value="<?= (int)$driver['id'] ?>" <?= ((int)$weeklyRow['driver_id'] === (int)$driver['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)$driver['name'], ENT_QUOTES) ?>
                                  </option>
                                <?php endforeach; ?>
                              </select>
                              <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                            </form>
                          <?php else: ?>
                            <?= htmlspecialchars((string)$weeklyRow['driver_name'], ENT_QUOTES) ?>
                          <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string)$weeklyRow['status_label'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars((string)$weeklyRow['card_type'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars((string)$weeklyRow['card_display'], ENT_QUOTES) ?></td>
                        <td>
                          <?= htmlspecialchars((string)$weeklyRow['description'], ENT_QUOTES) ?>
                          <?php if ($weeklyRow['location_name'] !== ''): ?>
                            <div class="small text-muted"><?= htmlspecialchars((string)$weeklyRow['location_name'], ENT_QUOTES) ?></div>
                          <?php endif; ?>
                        </td>
                        <td><?= number_format((float)$weeklyRow['qty'], 3) ?></td>
                        <td>$<?= number_format((float)$weeklyRow['gross'], 2) ?></td>
                        <td>$<?= number_format((float)$weeklyRow['fees'], 2) ?></td>
                        <td>$<?= number_format((float)$weeklyRow['total'], 2) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
                <?php if ($weeklyFuelChargeRows): ?>
                  <tfoot>
                    <tr>
                      <th colspan="5" class="text-end">Week Total</th>
                      <th><?= (int)$weeklyFuelTotals['txn_count'] ?> charge(s)</th>
                      <th><?= number_format((float)$weeklyFuelTotals['qty_total'], 3) ?></th>
                      <th>$<?= number_format((float)$weeklyFuelTotals['gross_total'], 2) ?></th>
                      <th>$<?= number_format((float)$weeklyFuelTotals['fees_total'], 2) ?></th>
                      <th>$<?= number_format((float)$weeklyFuelTotals['total_total'], 2) ?></th>
                    </tr>
                  </tfoot>
                <?php endif; ?>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="addCardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <form method="post">
          <input type="hidden" name="action" value="add_card">
          <div class="modal-header">
            <h5 class="modal-title">Add Fuel Card</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Provider</label>
                <input type="text" name="provider" class="form-control" list="fuelProviderOptions" required>
                <datalist id="fuelProviderOptions">
                  <option value="RTS/WEX">
                  <option value="Mudflap">
                </datalist>
              </div>
              <div class="col-md-4">
                <label class="form-label">Card Label</label>
                <input type="text" name="card_label" class="form-control" placeholder="Truck # or nickname">
              </div>
              <div class="col-md-4">
                <label class="form-label">Card Last 4</label>
                <input type="text" name="card_last4" class="form-control" maxlength="4" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">External ID / Full EFS Card Number</label>
                <input type="text" name="external_id" class="form-control" autocomplete="off">
                <div class="form-text">Required for RTS/EFS API management; stored server-side and never displayed.</div>
              </div>
              <div class="col-md-3">
                <label class="form-label">Daily Limit</label>
                <input type="number" step="0.01" name="daily_limit" class="form-control">
              </div>
              <div class="col-md-3">
                <label class="form-label">Per Txn Limit</label>
                <input type="number" step="0.01" name="per_txn_limit" class="form-control">
              </div>
              <div class="col-md-3">
                <label class="form-label">Fuel Only</label>
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" name="fuel_only" checked>
                  <label class="form-check-label">Yes</label>
                </div>
              </div>
              <div class="col-md-9">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control">
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Card</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="assignModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="post" id="assignForm">
          <input type="hidden" name="action" value="assign_card">
          <input type="hidden" name="card_id" id="assignCardId">
          <div class="modal-header">
            <h5 class="modal-title">Assign Card</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <label class="form-label">Driver</label>
            <select name="driver_id" id="assignDriverId" class="form-select">
              <option value="">Unassigned</option>
              <?php foreach ($drivers as $driver): ?>
                <option value="<?= (int)$driver['id'] ?>"><?= htmlspecialchars($driver['name'] ?? '', ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Assignment</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="overrideModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="post">
          <input type="hidden" name="action" value="add_override">
          <div class="modal-header">
            <h5 class="modal-title">Create Override</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-2">
              <label class="form-label">Card</label>
              <select name="card_id" class="form-select" required>
                <option value="">Select card</option>
                <?php foreach ($cards as $card): ?>
                  <option value="<?= (int)$card['id'] ?>">
                    <?= htmlspecialchars(($card['provider'] ?? '') . ' •••• ' . ($card['card_last4'] ?? ''), ENT_QUOTES) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label">Driver</label>
              <select name="driver_id" class="form-select">
                <option value="">Unassigned</option>
                <?php foreach ($drivers as $driver): ?>
                  <option value="<?= (int)$driver['id'] ?>"><?= htmlspecialchars($driver['name'] ?? '', ENT_QUOTES) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label">Override Type</label>
              <select name="override_type" class="form-select" required>
                <option value="enable">Enable</option>
                <option value="disable">Disable</option>
                <option value="extend">Extend Window</option>
                <option value="increase_limit">Increase Limit</option>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label">Start Time</label>
              <input type="datetime-local" name="start_time" class="form-control" required>
            </div>
            <div class="mb-2">
              <label class="form-label">End Time</label>
              <input type="datetime-local" name="end_time" class="form-control" required>
            </div>
            <div class="mb-2">
              <label class="form-label">Reason</label>
              <textarea name="reason" class="form-control" rows="2" required></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Override</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="fuelReportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="upload_fuel_report">
          <div class="modal-header">
            <h5 class="modal-title">Fuel Report Uploader</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-2">
              <label class="form-label">Fuel Report</label>
              <input type="file" name="fuel_report_file" class="form-control" accept="application/pdf,.pdf,text/csv,.csv" required>
            </div>
            <div class="small text-muted">
              Upload RTS/WEX PDF reports or Mudflap CSV transaction reports. The uploader stores each transaction row and applies daily gas totals using <strong>Gross Amt + Fees</strong>.
              <strong>Desc Amt</strong> is stored but not applied to driver gas totals.
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-success">Upload Report</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="gasCostsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <form method="post" class="mb-0">
          <input type="hidden" name="action" value="save_gas_costs">
          <div class="modal-header">
            <h5 class="modal-title">Add Fuel Costs</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <label class="form-label">Driver</label>
            <select name="driver_id" id="driver-gas" class="form-select" required>
              <option value="">--Select Driver--</option>
              <?php foreach ($drivers as $driver): ?>
                <option value="<?= (int)$driver['id'] ?>" <?= ((int)$gasDriverId === (int)$driver['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($driver['name'] ?? '', ENT_QUOTES) ?>
                </option>
              <?php endforeach; ?>
            </select>

            <div class="table-responsive mt-3">
              <table class="table table-bordered">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Description</th>
                    <th>Card</th>
                    <th>Qty</th>
                    <th>Gross Amt</th>
                    <th>Fees Amt</th>
                  </tr>
                </thead>
                <tbody>
                  <?php for ($i = 0; $i < 7; $i++): ?>
                    <tr>
                      <td><input type="date" name="dates[]" class="form-control date-input" data-idx="<?= $i ?>"></td>
                      <td><input type="text" name="descriptions[]" id="gas-description-<?= $i ?>" class="form-control" placeholder="Manual Fuel Entry"></td>
                      <td><input type="text" name="cards[]" id="gas-card-<?= $i ?>" class="form-control" placeholder="Last 4 or card"></td>
                      <td><input type="number" step="0.001" name="qtys[]" id="gas-qty-<?= $i ?>" class="form-control"></td>
                      <td><input type="number" step="0.01" name="gross_amounts[]" id="gas-gross-<?= $i ?>" class="form-control"></td>
                      <td><input type="number" step="0.01" name="fee_amounts[]" id="gas-fees-<?= $i ?>" class="form-control"></td>
                    </tr>
                  <?php endfor; ?>
                </tbody>
              </table>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Fuel Costs</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="editOverrideModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="post" id="editOverrideForm">
          <input type="hidden" name="action" value="update_override">
          <input type="hidden" name="override_id" id="editOverrideId">
          <div class="modal-header">
            <h5 class="modal-title">Edit Override</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-2">
              <label class="form-label">Card</label>
              <select name="card_id" id="editOverrideCard" class="form-select" required>
                <?php foreach ($cards as $card): ?>
                  <option value="<?= (int)$card['id'] ?>">
                    <?= htmlspecialchars(($card['provider'] ?? '') . ' •••• ' . ($card['card_last4'] ?? ''), ENT_QUOTES) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label">Driver</label>
              <select name="driver_id" id="editOverrideDriver" class="form-select">
                <option value="">Unassigned</option>
                <?php foreach ($drivers as $driver): ?>
                  <option value="<?= (int)$driver['id'] ?>"><?= htmlspecialchars($driver['name'] ?? '', ENT_QUOTES) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label">Override Type</label>
              <select name="override_type" id="editOverrideType" class="form-select" required>
                <option value="enable">Enable</option>
                <option value="disable">Disable</option>
                <option value="extend">Extend Window</option>
                <option value="increase_limit">Increase Limit</option>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label">Start Time</label>
              <input type="datetime-local" name="start_time" id="editOverrideStart" class="form-control" required>
            </div>
            <div class="mb-2">
              <label class="form-label">End Time</label>
              <input type="datetime-local" name="end_time" id="editOverrideEnd" class="form-control" required>
            </div>
            <div class="mb-2">
              <label class="form-label">Reason</label>
              <textarea name="reason" id="editOverrideReason" class="form-control" rows="2" required></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Update Override</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const assignButtons = document.querySelectorAll('.assign-btn');
    const assignModal = new bootstrap.Modal(document.getElementById('assignModal'));
    const assignCardId = document.getElementById('assignCardId');
    const assignDriverId = document.getElementById('assignDriverId');
    const editOverrideModal = new bootstrap.Modal(document.getElementById('editOverrideModal'));

    const toLocalInputValue = (value) => {
      if (!value) return '';
      const dt = new Date(value.replace(' ', 'T'));
      if (Number.isNaN(dt.getTime())) return '';
      const pad = (n) => String(n).padStart(2, '0');
      return `${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())}T${pad(dt.getHours())}:${pad(dt.getMinutes())}`;
    };

    assignButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        assignCardId.value = btn.dataset.cardId || '';
        assignDriverId.value = btn.dataset.driverId || '';
        assignModal.show();
      });
    });

    document.querySelectorAll('.edit-override-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        document.getElementById('editOverrideId').value = btn.dataset.overrideId || '';
        document.getElementById('editOverrideCard').value = btn.dataset.cardId || '';
        document.getElementById('editOverrideDriver').value = btn.dataset.driverId || '';
        document.getElementById('editOverrideType').value = btn.dataset.overrideType || '';
        document.getElementById('editOverrideStart').value = toLocalInputValue(btn.dataset.start || '');
        document.getElementById('editOverrideEnd').value = toLocalInputValue(btn.dataset.end || '');
        document.getElementById('editOverrideReason').value = btn.dataset.reason || '';
        editOverrideModal.show();
      });
    });

    document.querySelectorAll('.date-input').forEach((input) => {
      input.addEventListener('change', (e) => {
        const idx = e.target.dataset.idx;
        const drv = document.getElementById('driver-gas').value;
        const d = e.target.value;
        if (drv && d) {
          fetch(`?ajax=gas&driver_id=${drv}&date=${d}`)
            .then((r) => r.json())
            .then((j) => {
              const descriptionField = document.getElementById(`gas-description-${idx}`);
              const cardField = document.getElementById(`gas-card-${idx}`);
              const qtyField = document.getElementById(`gas-qty-${idx}`);
              const grossField = document.getElementById(`gas-gross-${idx}`);
              const feesField = document.getElementById(`gas-fees-${idx}`);
              if (descriptionField) descriptionField.value = j.description ?? '';
              if (cardField) cardField.value = j.card ?? '';
              if (qtyField) qtyField.value = j.qty ?? '';
              if (grossField) grossField.value = j.gross_amt !== '' ? j.gross_amt : (j.amount ?? '');
              if (feesField) feesField.value = j.fees_amt ?? '';
            });
        }
      });
    });

    <?php if ($openGasModal): ?>
    window.addEventListener('load', () => {
      const modalEl = document.getElementById('gasCostsModal');
      if (modalEl && window.bootstrap) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
      }
    });
    <?php endif; ?>
  </script>
</body>
</html>
