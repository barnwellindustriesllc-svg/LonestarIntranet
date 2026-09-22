<?php
// upload.php

// Error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require __DIR__ . '/SimpleXLSX.php';
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payout_net_helpers.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/rtex_load_helpers.php';

use Shuchkin\SimpleXLSX;

// ---------------------------
// Helpers
// ---------------------------
const TSS_SOURCE_TIMEZONE = 'America/Chicago';

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES); }
function digits_only($s) { return preg_replace('/\D+/', '', (string)$s); }
function norm_name($first, $last) {
  return strtolower(trim(preg_replace('/\s+/', ' ', trim(($first ?? '').' '.($last ?? '')))));
}
function norm_full($name) {
  return strtolower(trim(preg_replace('/\s+/', ' ', (string)$name)));
}
function lev($a,$b){ return levenshtein($a,$b); }
function parse_money($value): float {
  $raw = preg_replace('/[^0-9\.\-]/', '', (string)$value);
  if ($raw === '' || $raw === '-' || $raw === '.' || $raw === '-.') return 0.0;
  return (float)$raw;
}
function parse_percent_number($value): ?float {
  $raw = preg_replace('/[^0-9\.\-]/', '', (string)$value);
  if ($raw === '' || $raw === '-' || $raw === '.' || $raw === '-.') return null;
  return (float)$raw;
}
function rtex_apply_base_rate_reduction(float $rate, float $hours, float $totalAmount, float $reductionPct): array {
  $reductionPct = max(0.0, min(100.0, $reductionPct));
  if ($reductionPct <= 0.0) {
    return [round($rate, 2), round($totalAmount, 2)];
  }

  $factor = 1.0 - ($reductionPct / 100.0);
  if ($hours > 0.0 && $rate > 0.0) {
    $baseRate = max(0.0, $rate - 10.0);
    $adjustedRate = round(($baseRate * $factor) + 10.0, 2);
    return [$adjustedRate, round($adjustedRate * $hours, 2)];
  }

  return [round($rate, 2), round($totalAmount * $factor, 2)];
}
function find_first_header_index(array $headers, array $candidates): ?int {
  foreach ($candidates as $candidate) {
    $idx = array_search($candidate, $headers, true);
    if ($idx !== false) return (int)$idx;
  }
  $normalized = [];
  foreach ($headers as $idx => $header) {
    $normalized[strtolower(trim((string)$header))] = (int)$idx;
  }
  foreach ($candidates as $candidate) {
    $key = strtolower(trim((string)$candidate));
    if (isset($normalized[$key])) return $normalized[$key];
  }
  return null;
}
function ensure_ls_detail_trailer_override_column(mysqli $mysqli): void {
  $res = $mysqli->query("SHOW COLUMNS FROM ls_detail_raw LIKE 'tss_trailer_pct_override'");
  if ($res && $res->num_rows > 0) return;
  $mysqli->query("ALTER TABLE ls_detail_raw ADD COLUMN tss_trailer_pct_override DECIMAL(6,2) NULL AFTER upload_date");
}
function ensure_ls_detail_total_bonuses_column(mysqli $mysqli): void {
  $res = $mysqli->query("SHOW COLUMNS FROM ls_detail_raw LIKE 'total_bonuses'");
  if ($res && $res->num_rows > 0) return;
  $afterColumn = 'upload_date';
  $trailerRes = $mysqli->query("SHOW COLUMNS FROM ls_detail_raw LIKE 'tss_trailer_pct_override'");
  if ($trailerRes && $trailerRes->num_rows > 0) {
    $afterColumn = 'tss_trailer_pct_override';
  }
  $mysqli->query("ALTER TABLE ls_detail_raw ADD COLUMN total_bonuses DECIMAL(12,2) NULL AFTER `{$afterColumn}`");
}
function ensure_ls_detail_fuel_surcharge_rate_column(mysqli $mysqli): void {
  $res = $mysqli->query("SHOW COLUMNS FROM ls_detail_raw LIKE 'fuel_surcharge_rate'");
  if ($res && $res->num_rows > 0) return;
  $afterColumn = 'upload_date';
  $bonusRes = $mysqli->query("SHOW COLUMNS FROM ls_detail_raw LIKE 'total_bonuses'");
  if ($bonusRes && $bonusRes->num_rows > 0) {
    $afterColumn = 'total_bonuses';
  } else {
    $trailerRes = $mysqli->query("SHOW COLUMNS FROM ls_detail_raw LIKE 'tss_trailer_pct_override'");
    if ($trailerRes && $trailerRes->num_rows > 0) {
      $afterColumn = 'tss_trailer_pct_override';
    }
  }
  $mysqli->query("ALTER TABLE ls_detail_raw ADD COLUMN fuel_surcharge_rate DECIMAL(12,4) NULL AFTER `{$afterColumn}`");
}
function ensure_ls_detail_fuel_surcharge_type_column(mysqli $mysqli): void {
  $res = $mysqli->query("SHOW COLUMNS FROM ls_detail_raw LIKE 'fuel_surcharge_type'");
  if ($res && $res->num_rows > 0) return;
  $mysqli->query("ALTER TABLE ls_detail_raw ADD COLUMN fuel_surcharge_type VARCHAR(20) NOT NULL DEFAULT 'mileage' AFTER `fuel_surcharge_rate`");
}
function normalize_fuel_surcharge_type($value): string {
  $type = strtolower(trim((string)$value));
  if (in_array($type, ['ton', 'tons', 'tonnage', 'per ton', 'per-ton'], true)) {
    return 'tonnage';
  }
  return 'mileage';
}
function ensure_ls_detail_broker_fee_override_column(mysqli $mysqli): void {
  $res = $mysqli->query("SHOW COLUMNS FROM ls_detail_raw LIKE 'broker_fee_override_pct'");
  if ($res && $res->num_rows > 0) return;
  $afterColumn = 'fuel_surcharge_rate';
  $fuelRes = $mysqli->query("SHOW COLUMNS FROM ls_detail_raw LIKE 'fuel_surcharge_rate'");
  if (!$fuelRes || $fuelRes->num_rows === 0) {
    $afterColumn = 'upload_date';
  }
  $mysqli->query("ALTER TABLE ls_detail_raw ADD COLUMN broker_fee_override_pct DECIMAL(6,2) NULL AFTER `{$afterColumn}`");
}
function tss_tz(): DateTimeZone {
  return new DateTimeZone(TSS_SOURCE_TIMEZONE);
}
function tss_today(): string {
  return (new DateTimeImmutable('now', tss_tz()))->format('Y-m-d');
}
function excel_serial_to_date($value, string $timezone = TSS_SOURCE_TIMEZONE): ?string {
  if ($value === null) return null;
  $v = trim((string)$value);
  if ($v === '') return null;
  if (is_numeric($v)) {
    $serial = (float)$v;
    if ($serial > 0.0) {
      $tz = new DateTimeZone($timezone);
      $base = new DateTimeImmutable('1899-12-30 00:00:00', $tz);
      $seconds = (int)round($serial * 86400);
      $dt = $base->modify('+' . $seconds . ' seconds');
      if ($dt instanceof DateTimeImmutable) {
        return $dt->format('Y-m-d');
      }
    }
  }
  try {
    return (new DateTimeImmutable($v, new DateTimeZone($timezone)))->format('Y-m-d');
  } catch (Throwable $e) {
    return null;
  }
}
function parse_any_date($value, string $timezone = TSS_SOURCE_TIMEZONE): ?string {
  $v = trim((string)$value);
  if ($v === '') return null;
  if (preg_match('/^\d+(\.\d+)?$/', $v)) return excel_serial_to_date($v, $timezone);
  if (preg_match('/^\s*(\d{1,2})\/(\d{1,2})\/(\d{4})\b/', $v, $m)) {
    return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[1], (int)$m[2]);
  }
  if (preg_match('/^\s*(\d{4})-(\d{1,2})-(\d{1,2})\b/', $v, $m)) {
    return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
  }
  try {
    return (new DateTimeImmutable($v, new DateTimeZone($timezone)))->format('Y-m-d');
  } catch (Throwable $e) {
    return null;
  }
}
function normalize_header_label($value): string {
  return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '', (string)$value)));
}
function find_header_index_loose(array $headers, array $candidates): ?int {
  $normalized = [];
  foreach ($headers as $idx => $header) {
    $key = normalize_header_label($header);
    if ($key !== '') $normalized[$key] = (int)$idx;
  }
  foreach ($candidates as $candidate) {
    $key = normalize_header_label($candidate);
    if (isset($normalized[$key])) return $normalized[$key];
  }
  return null;
}
function split_full_driver_name(string $name): array {
  $clean = trim(preg_replace('/\s+/', ' ', $name));
  if ($clean === '') return ['', ''];
  $parts = explode(' ', $clean);
  if (count($parts) === 1) return [$parts[0], ''];
  $last = array_pop($parts);
  return [implode(' ', $parts), $last];
}
function ensure_rtex_payout_rows_table(mysqli $mysqli): void {
  $mysqli->query("
    CREATE TABLE IF NOT EXISTS rtex_payout_rows (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      upload_date DATE NOT NULL,
      invoice_date DATE NULL,
      sheet_name VARCHAR(120) NOT NULL DEFAULT '',
      source_line_no INT NOT NULL,
      work_date DATE NOT NULL,
      driver_name VARCHAR(255) NOT NULL DEFAULT '',
      truck_raw VARCHAR(50) NOT NULL DEFAULT '',
      truck_digits VARCHAR(20) NOT NULL DEFAULT '',
      job_number VARCHAR(50) NOT NULL DEFAULT '',
      ticket_number VARCHAR(80) NOT NULL DEFAULT '',
      start_time VARCHAR(40) NOT NULL DEFAULT '',
      end_time VARCHAR(40) NOT NULL DEFAULT '',
      hours DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      surcharge_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      matched_contact_id INT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_rtex_upload_sheet_line (upload_date, sheet_name, source_line_no),
      KEY idx_rtex_driver_week (work_date, matched_contact_id),
      KEY idx_rtex_ticket (ticket_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}
function ensure_nextier_payout_rows_table(mysqli $mysqli): void {
  $mysqli->query("
    CREATE TABLE IF NOT EXISTS nextier_payout_rows (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      upload_date DATE NOT NULL,
      sheet_name VARCHAR(120) NOT NULL DEFAULT '',
      source_line_no INT NOT NULL,
      work_date DATE NOT NULL,
      well_name VARCHAR(255) NOT NULL DEFAULT '',
      load_id VARCHAR(80) NOT NULL DEFAULT '',
      dispatched_loader VARCHAR(255) NOT NULL DEFAULT '',
      bol_number VARCHAR(80) NOT NULL DEFAULT '',
      weight DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      tons DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      trucking_co VARCHAR(255) NOT NULL DEFAULT '',
      driver_name VARCHAR(255) NOT NULL DEFAULT '',
      miles DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      line_haul DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      fsc_rate DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
      fsc_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      bonus DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      matched_contact_id INT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_nextier_upload_sheet_line (upload_date, sheet_name, source_line_no),
      KEY idx_nextier_driver_week (work_date, matched_contact_id),
      KEY idx_nextier_load (load_id),
      KEY idx_nextier_bol (bol_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}
function ensure_nextier_trailer_reconciliation_table(mysqli $mysqli): void {
  $mysqli->query("
    CREATE TABLE IF NOT EXISTS nextier_trailer_reconciliations (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      payout_week_start DATE NOT NULL,
      applied_payout_week_start DATE NULL,
      source_upload_date DATE NOT NULL,
      raw_trailer_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      billed_trailer_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      source_row_count INT UNSIGNED NOT NULL DEFAULT 0,
      source_notes VARCHAR(255) NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_nextier_trailer_week (payout_week_start),
      KEY idx_nextier_trailer_upload (source_upload_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
  $col = $mysqli->query("SHOW COLUMNS FROM nextier_trailer_reconciliations LIKE 'applied_payout_week_start'");
  if (!$col || $col->num_rows === 0) {
    $mysqli->query("ALTER TABLE nextier_trailer_reconciliations ADD COLUMN applied_payout_week_start DATE NULL AFTER payout_week_start");
  }
  $idx = $mysqli->query("SHOW INDEX FROM nextier_trailer_reconciliations WHERE Key_name = 'idx_nextier_trailer_applied_week'");
  if (!$idx || $idx->num_rows === 0) {
    $mysqli->query("ALTER TABLE nextier_trailer_reconciliations ADD KEY idx_nextier_trailer_applied_week (applied_payout_week_start)");
  }
}
function is_nextier_trailer_rental_summary_row(array $row): bool {
  $text = strtolower(trim(implode(' ', array_map(static fn($value) => (string)$value, $row))));
  if ($text === '') return false;
  if (
    strpos($text, 'wet sand hopper rental') !== false
    || strpos($text, 'sand hopper rental') !== false
    || strpos($text, 'hopper rental') !== false
  ) {
    return true;
  }
  if (strpos($text, 'trailer') === false) return false;
  return (
    strpos($text, 'rental') !== false
    || strpos($text, 'rent') !== false
    || strpos($text, 'usage') !== false
    || strpos($text, 'fee') !== false
    || strpos($text, 'deduction') !== false
  );
}
function nextier_trailer_summary_week_start(array $row, ?int $completedIndex = null): string {
  if ($completedIndex !== null) {
    $date = parse_any_date($row[$completedIndex] ?? '', TSS_SOURCE_TIMEZONE);
    if ($date !== null) {
      return business_sunday_week_start($date);
    }
  }

  $text = trim(implode(' ', array_map(static fn($value) => (string)$value, $row)));
  if (preg_match('/week\s+(\d{1,2})\/(\d{1,2})\s*-\s*(\d{1,2})\/(\d{1,2})\/(\d{4})/i', $text, $m)) {
    $year = (int)$m[5];
    $startMonth = (int)$m[1];
    $startDay = (int)$m[2];
    return sprintf('%04d-%02d-%02d', $year, $startMonth, $startDay);
  }

  return '';
}
function save_nextier_trailer_reconciliation_upload(
  mysqli $mysqli,
  string $serviceWeekStart,
  string $appliedPayoutWeekStart,
  string $uploadDate,
  float $rawTrailerTotal,
  float $billedTrailerTotal,
  int $rowCount
): void {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $serviceWeekStart) || $rowCount <= 0 || abs($rawTrailerTotal) < 0.01) {
    return;
  }
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $appliedPayoutWeekStart)) {
    $appliedPayoutWeekStart = $serviceWeekStart;
  }
  ensure_nextier_trailer_reconciliation_table($mysqli);
  $billedTotal = round(abs($billedTrailerTotal), 2);
  $rawTrailerTotal = round($rawTrailerTotal, 2);
  $notes = 'Imported from NexTier trailer rental summary rows.';
  $stmt = $mysqli->prepare("
    INSERT INTO nextier_trailer_reconciliations
      (payout_week_start, applied_payout_week_start, source_upload_date, raw_trailer_total, billed_trailer_total, source_row_count, source_notes)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      applied_payout_week_start = VALUES(applied_payout_week_start),
      source_upload_date = VALUES(source_upload_date),
      raw_trailer_total = VALUES(raw_trailer_total),
      billed_trailer_total = VALUES(billed_trailer_total),
      source_row_count = VALUES(source_row_count),
      source_notes = VALUES(source_notes),
      updated_at = NOW()
  ");
  if (!$stmt) return;
  $stmt->bind_param('sssddis', $serviceWeekStart, $appliedPayoutWeekStart, $uploadDate, $rawTrailerTotal, $billedTotal, $rowCount, $notes);
  $stmt->execute();
  $stmt->close();
}
function save_nextier_trailer_catchup_adjustments(
  mysqli $mysqli,
  string $serviceWeekStart,
  string $appliedPayoutWeekStart,
  string $uploadDate
): int {
  if (
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $serviceWeekStart)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $appliedPayoutWeekStart)
    || $serviceWeekStart === $appliedPayoutWeekStart
  ) {
    return 0;
  }

  ensure_tss_misc_adjustments_table($mysqli);
  lonestar_trailer_history_ensure_table($mysqli);
  $serviceWeekEnd = business_week_end($serviceWeekStart);
  $insertedOrUpdated = 0;

  $stmt = $mysqli->prepare(
    "SELECT dc.id,
            COALESCE(NULLIF(CONCAT(dc.first_name, ' ', dc.last_name), ''), 'Driver') AS driver_name,
            tah.trailer_number,
            tah.assigned_date,
            tah.removed_date,
            COALESCE(NULLIF(tah.trailer_fee_mode, ''), ta.trailer_fee_mode) AS trailer_fee_mode,
            COALESCE(tah.trailer_fee_value, ta.trailer_fee_value) AS trailer_fee_value
       FROM trailer_assignment_history tah
       JOIN driver_contacts dc
         ON dc.id = tah.driver_contact_id
  LEFT JOIN trailer_assets ta
         ON (
            (tah.trailer_id IS NOT NULL AND ta.id = tah.trailer_id)
            OR TRIM(COALESCE(ta.trailer_number, '')) = TRIM(COALESCE(tah.trailer_number, ''))
         )
      WHERE COALESCE(dc.is_disabled, 0) = 0
        AND UPPER(TRIM(COALESCE(NULLIF(tah.vendor, ''), ta.vendor, ''))) = 'NEXTIER'
        AND tah.assigned_date <= ?
        AND COALESCE(tah.removed_date, ?) >= ?
      ORDER BY driver_name, tah.assigned_date"
  );
  if (!$stmt) return 0;
  $stmt->bind_param('sss', $serviceWeekEnd, $serviceWeekEnd, $serviceWeekStart);
  $stmt->execute();
  $stmt->bind_result($rawDriverId, $rawDriverName, $rawTrailerNo, $rawAssignedDate, $rawRemovedDate, $rawFeeMode, $rawFeeValue);
  $stmt->store_result();

  while ($stmt->fetch()) {
    $driverId = (int)$rawDriverId;
    $trailerNo = trim((string)$rawTrailerNo);
    $assignedDate = trim((string)$rawAssignedDate);
    $removedDate = trim((string)$rawRemovedDate);
    $periodStart = max($serviceWeekStart, $assignedDate);
    $periodEnd = $removedDate !== '' ? min($serviceWeekEnd, $removedDate) : $serviceWeekEnd;
    $days = lonestar_nextier_trailer_fee_days($periodStart, $periodEnd, $assignedDate);
    if ($driverId <= 0 || $trailerNo === '' || $days <= 0) {
      continue;
    }

    $dailyRate = ((string)$rawFeeMode === 'flat' && $rawFeeValue !== null)
      ? max(0.0, (float)$rawFeeValue)
      : 70.0;
    $amount = round($days * $dailyRate, 2);
    if ($amount <= 0.0) {
      continue;
    }
    $comments = "Auto NexTier trailer rental catch-up deduction - {$trailerNo} - service week {$serviceWeekStart} to {$serviceWeekEnd}";

    $existingId = 0;
    $existing = $mysqli->prepare(
      "SELECT id
         FROM tss_misc_adjustments
        WHERE payout_vendor = 'NEXTIER'
          AND payout_week_start = ?
          AND driver_contact_id = ?
          AND adjustment_type = 'misc_deduction'
          AND comments = ?
        LIMIT 1"
    );
    if ($existing) {
      $existing->bind_param('sis', $appliedPayoutWeekStart, $driverId, $comments);
      $existing->execute();
      $existing->bind_result($existingId);
      $existing->fetch();
      $existing->close();
    }

    if ($existingId > 0) {
      $update = $mysqli->prepare(
        "UPDATE tss_misc_adjustments
            SET source_upload_date = ?,
                amount = ?,
                updated_at = NOW()
          WHERE id = ?
          LIMIT 1"
      );
      if ($update) {
        $update->bind_param('sdi', $uploadDate, $amount, $existingId);
        $update->execute();
        $update->close();
        $insertedOrUpdated++;
      }
      continue;
    }

    $insert = $mysqli->prepare(
      "INSERT INTO tss_misc_adjustments
          (payout_vendor, source_upload_date, payout_week_start, driver_contact_id, adjustment_type, amount, comments, created_by)
       VALUES
          ('NEXTIER', ?, ?, ?, 'misc_deduction', ?, ?, NULL)"
    );
    if (!$insert) continue;
    $insert->bind_param('ssids', $uploadDate, $appliedPayoutWeekStart, $driverId, $amount, $comments);
    $insert->execute();
    $insert->close();
    $insertedOrUpdated++;
  }
  $stmt->close();

  return $insertedOrUpdated;
}
function get_nextier_trailer_reconciliation(mysqli $mysqli, string $weekStart): array {
  $weekEnd = business_week_end($weekStart);
  ensure_nextier_trailer_reconciliation_table($mysqli);

  $stored = [
    'source_upload_date' => '',
    'applied_payout_week_start' => '',
    'raw_trailer_total' => 0.0,
    'billed_trailer_total' => 0.0,
    'source_row_count' => 0,
    'updated_at' => '',
  ];
  $stmt = $mysqli->prepare("
    SELECT source_upload_date, applied_payout_week_start, raw_trailer_total, billed_trailer_total, source_row_count, updated_at
      FROM nextier_trailer_reconciliations
     WHERE payout_week_start = ?
     LIMIT 1
  ");
  if ($stmt) {
    $stmt->bind_param('s', $weekStart);
    $stmt->execute();
    $stmt->bind_result($sourceUploadDate, $appliedPayoutWeekStart, $rawTrailerTotal, $billedTrailerTotal, $sourceRowCount, $updatedAt);
    if ($stmt->fetch()) {
      $stored = [
        'source_upload_date' => (string)$sourceUploadDate,
        'applied_payout_week_start' => (string)$appliedPayoutWeekStart,
        'raw_trailer_total' => round((float)$rawTrailerTotal, 2),
        'billed_trailer_total' => round((float)$billedTrailerTotal, 2),
        'source_row_count' => (int)$sourceRowCount,
        'updated_at' => (string)$updatedAt,
      ];
    }
    $stmt->close();
  }

  $drivers = [];
  lonestar_trailer_history_ensure_table($mysqli);
  $stmt = $mysqli->prepare("
    SELECT dc.id,
           COALESCE(NULLIF(CONCAT(dc.first_name, ' ', dc.last_name), ''), 'Driver') AS driver_name,
           tah.trailer_number,
           tah.assigned_date,
           tah.removed_date,
           COALESCE(NULLIF(tah.trailer_fee_mode, ''), ta.trailer_fee_mode) AS trailer_fee_mode,
           COALESCE(tah.trailer_fee_value, ta.trailer_fee_value) AS trailer_fee_value
      FROM trailer_assignment_history tah
      JOIN driver_contacts dc
        ON dc.id = tah.driver_contact_id
 LEFT JOIN trailer_assets ta
        ON (
          (tah.trailer_id IS NOT NULL AND ta.id = tah.trailer_id)
          OR TRIM(COALESCE(ta.trailer_number, '')) = TRIM(COALESCE(tah.trailer_number, ''))
        )
     WHERE COALESCE(dc.is_disabled, 0) = 0
       AND UPPER(TRIM(COALESCE(NULLIF(tah.vendor, ''), ta.vendor, ''))) = 'NEXTIER'
       AND tah.assigned_date <= ?
       AND COALESCE(tah.removed_date, ?) >= ?
     ORDER BY driver_name, tah.assigned_date
  ");
  if ($stmt) {
    $stmt->bind_param('sss', $weekEnd, $weekEnd, $weekStart);
    $stmt->execute();
    $stmt->bind_result($rawDriverId, $rawDriverName, $rawTrailerNo, $rawAssignedDate, $rawRemovedDate, $rawFeeMode, $rawFeeValue);
    $stmt->store_result();
    while ($stmt->fetch()) {
      $driverId = (int)$rawDriverId;
      $trailerNo = trim((string)$rawTrailerNo);
      $assignedDate = trim((string)$rawAssignedDate);
      $removedDate = trim((string)$rawRemovedDate);
      $periodStart = max($weekStart, $assignedDate);
      $periodEnd = $removedDate !== '' ? min($weekEnd, $removedDate) : $weekEnd;
      $days = lonestar_nextier_trailer_fee_days($periodStart, $periodEnd, $assignedDate);
      if ($driverId <= 0 || $trailerNo === '' || $days <= 0) {
        continue;
      }
      $dailyRate = ((string)$rawFeeMode === 'flat' && $rawFeeValue !== null)
        ? max(0.0, (float)$rawFeeValue)
        : 70.0;
      $calculated = round($days * $dailyRate, 2);
      if ($calculated <= 0.0) continue;
      $drivers[] = [
        'driver_contact_id' => $driverId,
        'driver_name' => trim((string)$rawDriverName),
        'trailer_number' => $trailerNo,
        'days' => $days,
        'daily_rate' => $dailyRate,
        'gross_total' => lonestar_driver_vendor_week_gross($mysqli, $driverId, $weekStart, $weekEnd, 'nextier'),
        'calculated_trailer_total' => round($calculated, 2),
      ];
    }
    $stmt->close();
  }

  $calculatedTotal = round(array_sum(array_map(static fn($row) => (float)$row['calculated_trailer_total'], $drivers)), 2);
  $difference = round($stored['billed_trailer_total'] - $calculatedTotal, 2);

  return [
    'week_start' => $weekStart,
    'week_end' => $weekEnd,
    'source_upload_date' => $stored['source_upload_date'],
    'applied_payout_week_start' => $stored['applied_payout_week_start'],
    'raw_trailer_total' => $stored['raw_trailer_total'],
    'billed_trailer_total' => $stored['billed_trailer_total'],
    'source_row_count' => $stored['source_row_count'],
    'updated_at' => $stored['updated_at'],
    'calculated_trailer_total' => $calculatedTotal,
    'difference' => $difference,
    'is_balanced' => abs($difference) < 0.01,
    'driver_rows' => $drivers,
  ];
}
function get_nextier_trailer_catchups_applied_to_week(mysqli $mysqli, string $appliedPayoutWeekStart): array {
  ensure_nextier_trailer_reconciliation_table($mysqli);
  $rows = [];
  $stmt = $mysqli->prepare("
    SELECT payout_week_start, source_upload_date, raw_trailer_total, billed_trailer_total, source_row_count, updated_at
      FROM nextier_trailer_reconciliations
     WHERE applied_payout_week_start = ?
       AND payout_week_start <> ?
     ORDER BY payout_week_start
  ");
  if (!$stmt) return $rows;
  $stmt->bind_param('ss', $appliedPayoutWeekStart, $appliedPayoutWeekStart);
  $stmt->execute();
  $stmt->bind_result($serviceWeekStart, $sourceUploadDate, $rawTrailerTotal, $billedTrailerTotal, $sourceRowCount, $updatedAt);
  while ($stmt->fetch()) {
    $rows[] = [
      'service_week_start' => (string)$serviceWeekStart,
      'service_week_end' => business_week_end((string)$serviceWeekStart),
      'source_upload_date' => (string)$sourceUploadDate,
      'raw_trailer_total' => round((float)$rawTrailerTotal, 2),
      'billed_trailer_total' => round((float)$billedTrailerTotal, 2),
      'source_row_count' => (int)$sourceRowCount,
      'updated_at' => (string)$updatedAt,
    ];
  }
  $stmt->close();
  return $rows;
}
function ensure_nickelrock_payout_rows_table(mysqli $mysqli): void {
  $mysqli->query("
    CREATE TABLE IF NOT EXISTS nickelrock_payout_rows (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      upload_date DATE NOT NULL,
      work_date DATE NOT NULL,
      provider_name VARCHAR(255) NOT NULL DEFAULT '',
      job_name VARCHAR(255) NOT NULL DEFAULT '',
      ticket_number VARCHAR(80) NOT NULL DEFAULT '',
      tons DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      driver_name VARCHAR(255) NOT NULL DEFAULT '',
      truck_raw VARCHAR(50) NOT NULL DEFAULT '',
      truck_digits VARCHAR(20) NOT NULL DEFAULT '',
      rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      work_order VARCHAR(80) NOT NULL DEFAULT '',
      vendor_number VARCHAR(80) NOT NULL DEFAULT '',
      matched_contact_id INT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_nickelrock_ticket_date (work_date, ticket_number),
      KEY idx_nickelrock_driver_week (work_date, matched_contact_id),
      KEY idx_nickelrock_ticket (ticket_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
  $jobNameColumn = $mysqli->query("SHOW COLUMNS FROM nickelrock_payout_rows LIKE 'job_name'");
  if (!$jobNameColumn || $jobNameColumn->num_rows === 0) {
    $mysqli->query("ALTER TABLE nickelrock_payout_rows ADD COLUMN job_name VARCHAR(255) NOT NULL DEFAULT '' AFTER provider_name");
  }
  if ($jobNameColumn) $jobNameColumn->close();
}
function ensure_nickelrock_job_rates_table(mysqli $mysqli): void {
  $tableCheck = $mysqli->query("SHOW TABLES LIKE 'nickelrock_job_rates'");
  $tableAlreadyExisted = $tableCheck && $tableCheck->num_rows > 0;
  if ($tableCheck) $tableCheck->close();
  $mysqli->query("
    CREATE TABLE IF NOT EXISTS nickelrock_job_rates (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      job_name VARCHAR(255) NOT NULL,
      rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      work_order VARCHAR(80) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_nickelrock_job_name (job_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
  $workOrderColumn = $mysqli->query("SHOW COLUMNS FROM nickelrock_job_rates LIKE 'work_order'");
  if (!$workOrderColumn || $workOrderColumn->num_rows === 0) {
    $mysqli->query("ALTER TABLE nickelrock_job_rates ADD COLUMN work_order VARCHAR(80) NOT NULL DEFAULT '' AFTER rate");
  }
  if ($workOrderColumn) $workOrderColumn->close();
  if (!$tableAlreadyExisted) {
    $mysqli->query("
      INSERT IGNORE INTO nickelrock_job_rates (job_name, rate, work_order)
      SELECT TRIM(job_name), MAX(rate), MAX(work_order)
        FROM nickelrock_payout_rows
       WHERE TRIM(COALESCE(job_name, '')) <> ''
       GROUP BY TRIM(job_name)
    ");
  }
  $mysqli->query("
    UPDATE nickelrock_job_rates AS job_rates
    JOIN (
      SELECT TRIM(job_name) AS job_name, MAX(work_order) AS work_order
        FROM nickelrock_payout_rows
       WHERE TRIM(COALESCE(job_name, '')) <> ''
         AND TRIM(COALESCE(work_order, '')) <> ''
       GROUP BY TRIM(job_name)
    ) AS payout_rows ON payout_rows.job_name = job_rates.job_name
       SET job_rates.work_order = payout_rows.work_order
     WHERE TRIM(COALESCE(job_rates.work_order, '')) = ''
  ");
}
function get_nickelrock_job_rates(mysqli $mysqli): array {
  $rows = [];
  $res = $mysqli->query("SELECT id, job_name, rate, work_order FROM nickelrock_job_rates ORDER BY job_name");
  if ($res) {
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $res->close();
  }
  return $rows;
}
function get_nickelrock_job_details(mysqli $mysqli, string $jobName): ?array {
  $details = null;
  $stmt = $mysqli->prepare("SELECT rate, work_order FROM nickelrock_job_rates WHERE job_name = ? LIMIT 1");
  if (!$stmt) return null;
  $stmt->bind_param('s', $jobName);
  $stmt->execute();
  $stmt->bind_result($rateRaw, $workOrderRaw);
  if ($stmt->fetch()) {
    $details = ['rate' => round((float)$rateRaw, 2), 'work_order' => trim((string)$workOrderRaw)];
  }
  $stmt->close();
  return $details;
}
function get_nickelrock_job_rate(mysqli $mysqli, string $jobName): ?float {
  $details = get_nickelrock_job_details($mysqli, $jobName);
  return $details === null ? null : (float)$details['rate'];
}
function clean_nextier_driver_name(string $driverName): string {
  $driverName = preg_replace('/\([^)]*Lone Star Roadside LLC[^)]*\)/i', '', $driverName);
  return trim(preg_replace('/\s+/', ' ', (string)$driverName));
}
function get_nickelrock_payout_week_options(mysqli $mysqli): array {
  $weeks = [];
  $q = $mysqli->query("
    SELECT DISTINCT DATE_SUB(work_date, INTERVAL (DAYOFWEEK(work_date) - 1) DAY) AS week_start
      FROM nickelrock_payout_rows
     ORDER BY week_start DESC
  ");
  if ($q) {
    while ($row = $q->fetch_assoc()) {
      $weekStart = (string)($row['week_start'] ?? '');
      if ($weekStart !== '') $weeks[] = $weekStart;
    }
    $q->close();
  }
  if (!$weeks) $weeks[] = business_sunday_week_start(tss_today());
  return $weeks;
}
function get_nickelrock_review_rows(mysqli $mysqli, string $weekStart): array {
  $weekEnd = business_week_end($weekStart);
  $rows = [];
  $sql = "
    SELECT n.*,
           CONCAT(dc.first_name, ' ', dc.last_name) AS matched_driver_name
      FROM nickelrock_payout_rows n
      LEFT JOIN driver_contacts dc ON dc.id = n.matched_contact_id
     WHERE n.work_date BETWEEN ? AND ?
     ORDER BY n.job_name, n.work_date, n.provider_name, n.truck_digits, n.ticket_number, n.id
  ";
  if ($stmt = $mysqli->prepare($sql)) {
    $stmt->bind_param('ss', $weekStart, $weekEnd);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
  }
  return $rows;
}
function nickelrock_total_pay(array $row): float {
  $total = (float)($row['total_amount'] ?? 0);
  if (abs($total) >= 0.01) return round($total, 2);
  return round((float)($row['tons'] ?? 0) * (float)($row['rate'] ?? 0), 2);
}
function sync_nickelrock_driver_payout(mysqli $mysqli, array $row): void {
  $vendor = 'Nickel Rock';
  $payAsString = number_format(nickelrock_total_pay($row), 2, '.', '');
  $workDate = (string)($row['work_date'] ?? '');
  $ticketNumber = (string)($row['ticket_number'] ?? '');
  $driverName = (string)($row['driver_name'] ?? '');
  $contactId = !empty($row['matched_contact_id']) ? (int)$row['matched_contact_id'] : null;
  $today = tss_today();
  $stmt = $mysqli->prepare("
    INSERT INTO driver_payouts
    (payout_date, ticket_number, driver_name, vendor_name, tss_pay, upload_date, driver_contact_id)
    VALUES (?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      driver_name=VALUES(driver_name),
      tss_pay=VALUES(tss_pay),
      upload_date=VALUES(upload_date),
      driver_contact_id=VALUES(driver_contact_id)
  ");
  if (!$stmt) return;
  $stmt->bind_param('ssssssi', $workDate, $ticketNumber, $driverName, $vendor, $payAsString, $today, $contactId);
  $stmt->execute();
  $stmt->close();
}
function nickelrock_xlsx_col(int $index): string {
  $col = '';
  $index += 1;
  while ($index > 0) {
    $mod = ($index - 1) % 26;
    $col = chr(65 + $mod) . $col;
    $index = (int)(($index - 1) / 26);
  }
  return $col;
}
function nickelrock_build_xlsx(array $rows): string {
  $shared = [];
  $sharedIndex = [];
  $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
  $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
  foreach ($rows as $rIdx => $row) {
    $rowNum = $rIdx + 1;
    $xml .= '<row r="' . $rowNum . '">';
    foreach ($row as $cIdx => $value) {
      if ($value === null || $value === '') continue;
      $col = nickelrock_xlsx_col($cIdx) . $rowNum;
      if (is_array($value) && isset($value['formula'])) {
        $xml .= '<c r="' . $col . '"><f>' . h($value['formula']) . '</f><v>' . h((string)($value['value'] ?? '')) . '</v></c>';
      } elseif (is_numeric($value)) {
        $xml .= '<c r="' . $col . '"><v>' . h((string)$value) . '</v></c>';
      } else {
        $key = (string)$value;
        if (!array_key_exists($key, $sharedIndex)) {
          $sharedIndex[$key] = count($shared);
          $shared[] = $key;
        }
        $xml .= '<c r="' . $col . '" t="s"><v>' . $sharedIndex[$key] . '</v></c>';
      }
    }
    $xml .= '</row>';
  }
  $xml .= '</sheetData></worksheet>';
  $sharedXml = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared) . '" uniqueCount="' . count($shared) . '">';
  foreach ($shared as $text) $sharedXml .= '<si><t>' . h($text) . '</t></si>';
  $sharedXml .= '</sst>';
  $workbookXml = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="1" sheetId="1" r:id="rId1"/></sheets></workbook>';
  $workbookRels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>';
  $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
  $contentTypes = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>';
  $zipPath = tempnam(sys_get_temp_dir(), 'xlsx_');
  $zip = new ZipArchive();
  $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
  $zip->addFromString('[Content_Types].xml', $contentTypes);
  $zip->addFromString('_rels/.rels', $rels);
  $zip->addFromString('xl/workbook.xml', $workbookXml);
  $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
  $zip->addFromString('xl/sharedStrings.xml', $sharedXml);
  $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
  $zip->close();
  $data = file_get_contents($zipPath);
  @unlink($zipPath);
  return $data !== false ? $data : '';
}
function build_nickelrock_invoice_xlsx(array $rows): string {
  $out = [['Delivery Date','Provider Name','Job Name','Ticket','Tons','Driver','Truck','Rate','Pay']];
  foreach ($rows as $row) {
    $r = count($out) + 1;
    $out[] = [
      (string)($row['work_date'] ?? ''),
      (string)($row['provider_name'] ?? ''),
      (string)($row['job_name'] ?? ''),
      (string)($row['ticket_number'] ?? ''),
      (float)($row['tons'] ?? 0),
      (string)($row['driver_name'] ?? ''),
      (string)($row['truck_raw'] ?? ''),
      (float)($row['rate'] ?? 0),
      ['formula' => "E{$r}*H{$r}", 'value' => nickelrock_total_pay($row)],
    ];
  }
  $totalRow = count($out) + 1;
  $lastDataRow = max(2, $totalRow - 1);
  $out[] = ['', '', '', 'Tons', ['formula' => "SUM(E2:E{$lastDataRow})", 'value' => array_sum(array_map(static fn($r) => (float)($r['tons'] ?? 0), $rows))], '', '', 'Total', ['formula' => "SUM(I2:I{$lastDataRow})", 'value' => array_sum(array_map(static fn($r) => nickelrock_total_pay($r), $rows))]];
  $workOrder = trim((string)($rows[0]['work_order'] ?? ''));
  $vendorNumber = trim((string)($rows[0]['vendor_number'] ?? ''));
  if ($workOrder !== '') $out[] = ['', 'Work Order -' . $workOrder];
  if ($vendorNumber !== '') $out[] = ['', 'Client -' . $vendorNumber];
  return nickelrock_build_xlsx($out);
}
function build_rtex_invoice_xlsx(array $rows): string {
  $out = [['Date', 'Name', 'Truck', 'Job #', 'Ticket #', 'Start', 'End', '# of Hours', 'Rate', 'Total']];
  foreach ($rows as $row) {
    $out[] = [
      (string)($row['work_date'] ?? ''),
      (string)($row['driver_name'] ?? ''),
      (string)($row['truck_raw'] ?? ''),
      (string)($row['job_number'] ?? ''),
      (string)($row['ticket_number'] ?? ''),
      rtex_excel_time($row['start_time'] ?? ''),
      rtex_excel_time($row['end_time'] ?? ''),
      (float)($row['hours'] ?? 0),
      (float)($row['rate'] ?? 0),
      (float)($row['total_amount'] ?? 0),
    ];
  }
  $totalRow = count($out) + 1;
  $lastDataRow = max(2, $totalRow - 1);
  $out[] = ['', '', '', '', '', '', 'Totals', ['formula' => "SUM(H2:H{$lastDataRow})", 'value' => array_sum(array_map(static fn($r) => (float)($r['hours'] ?? 0), $rows))], '', ['formula' => "SUM(J2:J{$lastDataRow})", 'value' => array_sum(array_map(static fn($r) => (float)($r['total_amount'] ?? 0), $rows))]];
  return nickelrock_build_xlsx($out);
}
function nickelrock_tool_command(string $command): string {
  $envMap = [
    'tesseract' => 'TESSERACT_CMD',
    'pdftoppm' => 'PDFTOPPM_CMD',
    'pdftotext' => 'PDFTOTEXT_CMD',
    'magick' => 'MAGICK_CMD',
  ];
  $envName = $envMap[$command] ?? '';
  $configured = $envName !== '' ? trim((string)getenv($envName)) : '';
  if ($configured !== '' && (is_file($configured) || strpos($configured, DIRECTORY_SEPARATOR) !== false)) {
    return $configured;
  }
  return $command;
}
function nickelrock_command_exists(string $command): bool {
  $resolved = nickelrock_tool_command($command);
  if (is_file($resolved)) return true;
  $safe = preg_replace('/[^A-Za-z0-9_.-]/', '', $resolved);
  if ($safe === '') return false;
  $check = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? "where {$safe}" : "command -v {$safe}";
  $out = [];
  $code = 1;
  @exec($check, $out, $code);
  return $code === 0 && !empty($out);
}
function nickelrock_run_command(array $parts): string {
  if (!$parts) return '';
  $cmd = '';
  foreach ($parts as $idx => $part) {
    $cmd .= ($idx === 0) ? escapeshellarg((string)$part) : (' ' . (string)$part);
  }
  return (string)@shell_exec($cmd);
}
function nickelrock_ocr_file_text(string $path, string $originalName, array &$warnings): string {
  $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
  $text = '';
  $stderrNull = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? ' 2>NUL' : ' 2>/dev/null';
  if ($ext === 'pdf' && nickelrock_command_exists('pdftotext')) {
    $text = nickelrock_run_command([nickelrock_tool_command('pdftotext'), '-layout', escapeshellarg($path), '-']);
  }
  if (trim($text) !== '') return $text;

  $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp', 'webp'], true);
  if ($isImage && nickelrock_command_exists('tesseract')) {
    $text = nickelrock_run_command([nickelrock_tool_command('tesseract'), escapeshellarg($path), 'stdout', '--psm 6' . $stderrNull]);
    if (trim($text) !== '') return $text;
  }

  if ($ext === 'pdf' && nickelrock_command_exists('pdftoppm') && nickelrock_command_exists('tesseract')) {
    $tmpBase = tempnam(sys_get_temp_dir(), 'nr_ocr_');
    if ($tmpBase !== false) {
      @unlink($tmpBase);
      $prefix = $tmpBase . '_page';
      nickelrock_run_command([nickelrock_tool_command('pdftoppm'), '-r 220 -png', escapeshellarg($path), escapeshellarg($prefix)]);
      $pages = glob($prefix . '-*.png') ?: [];
      foreach ($pages as $pagePath) {
        $text .= "\n" . nickelrock_run_command([nickelrock_tool_command('tesseract'), escapeshellarg($pagePath), 'stdout', '--psm 6' . $stderrNull]);
        @unlink($pagePath);
      }
      if (trim($text) !== '') return $text;
    }
  }

  if ($ext === 'pdf' && nickelrock_command_exists('magick') && nickelrock_command_exists('tesseract')) {
    $tmpBase = tempnam(sys_get_temp_dir(), 'nr_ocr_');
    if ($tmpBase !== false) {
      @unlink($tmpBase);
      $pattern = $tmpBase . '_%03d.png';
      nickelrock_run_command([nickelrock_tool_command('magick'), '-density 220', escapeshellarg($path), '-quality 90', escapeshellarg($pattern)]);
      $pages = glob($tmpBase . '_*.png') ?: [];
      foreach ($pages as $pagePath) {
        $pageText = nickelrock_run_command([nickelrock_tool_command('tesseract'), escapeshellarg($pagePath), 'stdout', '--psm 6' . $stderrNull]);
        $text .= "\n" . $pageText;
        @unlink($pagePath);
      }
      if (trim($text) !== '') return $text;
    }
  }

  $warnings[] = "{$originalName}: OCR tools were not available or no text could be read.";
  return '';
}
function nickelrock_parse_bol_text(string $text, array $defaults): array {
  $plain = preg_replace('/[ \t]+/', ' ', str_replace(["\r", "\f"], "\n", $text));
  $oneLine = preg_replace('/\s+/', ' ', $plain);

  $ticket = '';
  if (preg_match('/Ticket\s*#?\s*[:#]?\s*([0-9]{3,})/i', $oneLine, $m)) {
    $ticket = $m[1];
  }

  $date = '';
  if (preg_match('/Date\s*:?\s*([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{4})/i', $oneLine, $m)) {
    $date = parse_any_date($m[1], TSS_SOURCE_TIMEZONE) ?: '';
  } elseif (preg_match('/\b([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{4})\b/', $oneLine, $m)) {
    $date = parse_any_date($m[1], TSS_SOURCE_TIMEZONE) ?: '';
  }

  $truckRaw = '';
  if (preg_match('/Truck\s*:?\s*([A-Za-z0-9 -]+)/i', $plain, $m)) {
    $truckRaw = trim(preg_split('/\n/', $m[1])[0] ?? '');
  }

  $tons = 0.0;
  if (preg_match('/Net\s*:?\s*([0-9]+(?:\.[0-9]+)?)/i', $oneLine, $m)) {
    $tons = round((float)$m[1], 2);
  } elseif (preg_match('/Gross\s*:?\s*([0-9]+(?:\.[0-9]+)?).*?Tare\s*:?\s*([0-9]+(?:\.[0-9]+)?).*?Net\s*:?\s*([0-9]+(?:\.[0-9]+)?)/i', $oneLine, $m)) {
    $tons = round((float)$m[3], 2);
  }

  $provider = trim((string)($defaults['provider_name'] ?? ''));
  if ($provider === '' && preg_match('/Order\s*:?\s*(?:[0-9]+\s*-\s*)?(.+?)(?:\s+Customer\s*:|\s+Product\s*:|\n|$)/i', $plain, $m)) {
    $provider = trim($m[1]);
  }

  return [
    'work_date' => $date,
    'provider_name' => $provider,
    'ticket_number' => $ticket,
    'tons' => $tons,
    'truck_raw' => $truckRaw,
    'rate' => round(parse_money($defaults['rate'] ?? 4.75), 2),
    'work_order' => trim((string)($defaults['work_order'] ?? '')),
    'vendor_number' => trim((string)($defaults['vendor_number'] ?? '')),
  ];
}
function rtex_excel_time($value): string {
  $v = trim((string)$value);
  if ($v === '') return '';
  if (is_numeric($v)) {
    $seconds = (int)round(((float)$v) * 86400);
    $seconds = (($seconds % 86400) + 86400) % 86400;
    return sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
  }
  if (preg_match('/\b(\d{1,2}):(\d{2})(?::\d{2})?\s*(AM|PM)?\b/i', $v, $m)) {
    $hour = (int)$m[1];
    $minute = (int)$m[2];
    $ampm = strtoupper($m[3] ?? '');
    if ($ampm === 'PM' && $hour < 12) $hour += 12;
    if ($ampm === 'AM' && $hour === 12) $hour = 0;
    if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
      return sprintf('%02d:%02d', $hour, $minute);
    }
  }
  try {
    return (new DateTimeImmutable($v, tss_tz()))->format('H:i');
  } catch (Throwable $e) {
    return '';
  }
}
function build_driver_match_context(mysqli $mysqli): array {
  $contacts = [];
  $byTruck = [];
  $namesForId = [];

  $qc = $mysqli->query("SELECT id, first_name, last_name, truck_no, alt_truck_no FROM driver_contacts WHERE COALESCE(is_disabled, 0) = 0");
  if ($qc) {
    while ($c = $qc->fetch_assoc()) {
      $c['first_name'] = $c['first_name'] ?? '';
      $c['last_name'] = $c['last_name'] ?? '';
      $c['name_norm'] = norm_name($c['first_name'], $c['last_name']);
      $c['truck_norm'] = digits_only($c['truck_no'] ?? '');
      $c['alt_truck_norm'] = digits_only($c['alt_truck_no'] ?? '');
      $contacts[] = $c;
      foreach (array_filter([$c['truck_norm'], $c['alt_truck_norm']]) as $digits) {
        $byTruck[$digits][] = $c;
      }
      $namesForId[(int)$c['id']] = [$c['name_norm']];
    }
    $qc->close();
  }

  $qa = $mysqli->query("SELECT driver_contact_id, alias_full_norm FROM driver_name_aliases");
  if ($qa) {
    while ($a = $qa->fetch_assoc()) {
      $cid = (int)($a['driver_contact_id'] ?? 0);
      $alias = strtolower(trim($a['alias_full_norm'] ?? ''));
      if ($cid > 0 && $alias !== '') $namesForId[$cid][] = $alias;
    }
    $qa->close();
  }

  return ['contacts' => $contacts, 'by_truck' => $byTruck, 'names_for_id' => $namesForId];
}
function resolve_driver_contact_from_context(array $ctx, string $driverName, string $truckDigits): ?int {
  $nameNorm = norm_full($driverName);
  $namesForId = $ctx['names_for_id'] ?? [];
  $byTruck = $ctx['by_truck'] ?? [];
  $contacts = $ctx['contacts'] ?? [];

  $nameMatches = static function (int $contactId) use ($namesForId, $nameNorm): bool {
    foreach ($namesForId[$contactId] ?? [] as $candidate) {
      if ($candidate !== '' && $candidate === $nameNorm) return true;
    }
    return false;
  };

  $truckCandidates = $byTruck[$truckDigits] ?? [];
  $exactTruckName = [];
  foreach ($truckCandidates as $c) {
    if ($nameMatches((int)$c['id'])) $exactTruckName[] = (int)$c['id'];
  }
  $exactTruckName = array_values(array_unique($exactTruckName));
  if (count($exactTruckName) === 1) return $exactTruckName[0];

  $exactName = [];
  foreach ($contacts as $c) {
    if ($nameMatches((int)$c['id'])) $exactName[] = (int)$c['id'];
  }
  $exactName = array_values(array_unique($exactName));
  if (count($exactName) === 1) return $exactName[0];

  $truckIds = array_values(array_unique(array_map(static fn($c) => (int)$c['id'], $truckCandidates)));
  if (count($truckIds) === 1) return $truckIds[0];

  return null;
}
function is_trailer_usage_row(string $category, string $description): bool {
  $c = strtolower(trim($category));
  $d = strtolower(trim($description));
  if (strpos($c, 'trailer usage') !== false) return true;
  if (strpos($d, 'trailer usage') !== false) return true;
  return false;
}
function get_open_unresolved_count(mysqli $mysqli): int {
  $count = 0;
  $sql = "SELECT COUNT(*) AS cnt FROM ls_unresolved_matches WHERE status='unresolved'";
  if ($stmt = $mysqli->prepare($sql)) {
    $stmt->execute();
    $stmt->bind_result($cnt);
    if ($stmt->fetch()) $count = (int)$cnt;
    $stmt->close();
  }
  return $count;
}
function has_ls_detail_upload_for_date(mysqli $mysqli, string $uploadDate): bool {
  $has = false;
  $sql = "SELECT COUNT(*) AS cnt FROM ls_detail_raw WHERE upload_date = ?";
  if ($stmt = $mysqli->prepare($sql)) {
    $stmt->bind_param('s', $uploadDate);
    $stmt->execute();
    $stmt->bind_result($cnt);
    if ($stmt->fetch()) $has = ((int)$cnt > 0);
    $stmt->close();
  }
  return $has;
}
function has_tss_payout_upload_for_date(mysqli $mysqli, string $uploadDate): bool {
  $has = false;
  $sql = "SELECT COUNT(*) AS cnt FROM tss_payout_rows WHERE upload_date = ?";
  if ($stmt = $mysqli->prepare($sql)) {
    $stmt->bind_param('s', $uploadDate);
    $stmt->execute();
    $stmt->bind_result($cnt);
    if ($stmt->fetch()) $has = ((int)$cnt > 0);
    $stmt->close();
  }
  return $has;
}
function business_sunday_week_start(string $date): string {
  try {
    $dt = new DateTimeImmutable($date, tss_tz());
  } catch (Throwable $e) {
    $dt = new DateTimeImmutable('now', tss_tz());
  }
  $dow = (int)$dt->format('w');
  return $dt->modify("-{$dow} days")->format('Y-m-d');
}
function business_week_end(string $weekStart): string {
  return (new DateTimeImmutable($weekStart, tss_tz()))->modify('+6 days')->format('Y-m-d');
}
function ensure_tss_misc_adjustments_table(mysqli $mysqli): void {
  $mysqli->query("
    CREATE TABLE IF NOT EXISTS tss_misc_adjustments (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      payout_vendor VARCHAR(20) NOT NULL DEFAULT 'TSS',
      source_upload_date DATE NOT NULL,
      payout_week_start DATE NOT NULL,
      driver_contact_id INT NOT NULL,
      adjustment_type ENUM('misc_payment','misc_deduction') NOT NULL,
      amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      comments VARCHAR(255) NOT NULL,
      created_by INT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_tss_misc_adjustments_week_driver (payout_week_start, driver_contact_id),
      KEY idx_tss_misc_adjustments_source_date (source_upload_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
  $col = $mysqli->query("SHOW COLUMNS FROM tss_misc_adjustments LIKE 'payout_vendor'");
  if (!$col || $col->num_rows === 0) {
    $mysqli->query("ALTER TABLE tss_misc_adjustments ADD COLUMN payout_vendor VARCHAR(20) NOT NULL DEFAULT 'TSS' AFTER id");
    $mysqli->query("UPDATE tss_misc_adjustments SET payout_vendor = 'TSS' WHERE payout_vendor = '' OR payout_vendor IS NULL");
  }
  $updatedAtCol = $mysqli->query("SHOW COLUMNS FROM tss_misc_adjustments LIKE 'updated_at'");
  if (!$updatedAtCol || $updatedAtCol->num_rows === 0) {
    $mysqli->query("ALTER TABLE tss_misc_adjustments ADD COLUMN updated_at DATETIME NULL AFTER created_at");
  }
  $idx = $mysqli->query("SHOW INDEX FROM tss_misc_adjustments WHERE Key_name = 'idx_tss_misc_adjustments_vendor_week_driver'");
  if (!$idx || $idx->num_rows === 0) {
    $mysqli->query("ALTER TABLE tss_misc_adjustments ADD KEY idx_tss_misc_adjustments_vendor_week_driver (payout_vendor, payout_week_start, driver_contact_id)");
  }
}
function get_driver_dropdown_options(mysqli $mysqli): array {
  $drivers = [];
  $q = $mysqli->query("
    SELECT id, CONCAT(first_name, ' ', last_name) AS name, truck_no
      FROM driver_contacts
     WHERE COALESCE(is_disabled,0) = 0
     ORDER BY last_name, first_name
  ");
  if ($q) {
    while ($row = $q->fetch_assoc()) {
      $drivers[] = $row;
    }
    $q->close();
  }
  return $drivers;
}
function get_payout_week_options(mysqli $mysqli): array {
  $weeks = [];
  $q = $mysqli->query("
    SELECT DISTINCT DATE_SUB(payout_date, INTERVAL (DAYOFWEEK(payout_date) - 1) DAY) AS week_start
      FROM driver_payouts
     ORDER BY week_start DESC
  ");
  if ($q) {
    while ($row = $q->fetch_assoc()) {
      $weekStart = (string)($row['week_start'] ?? '');
      if ($weekStart !== '') $weeks[] = $weekStart;
    }
    $q->close();
  }
  if (!$weeks) {
    $weeks[] = business_sunday_week_start(tss_today());
  }
  return $weeks;
}
function tss_ls_detail_date_expr(string $alias = 'ldr'): string {
  $prefix = $alias !== '' ? $alias . '.' : '';
  return "COALESCE(
    NULLIF(DATE({$prefix}`Delivery Date`), '0000-00-00'),
    STR_TO_DATE({$prefix}`Delivery Date`, '%Y-%m-%d'),
    STR_TO_DATE(SUBSTRING_INDEX({$prefix}`Delivery Date`, ',', 1), '%m/%d/%Y'),
    STR_TO_DATE(SUBSTRING_INDEX({$prefix}`Delivery Date`, ',', 1), '%c/%e/%Y'),
    STR_TO_DATE({$prefix}`Delivery Date`, '%m/%d/%Y'),
    STR_TO_DATE({$prefix}`Delivery Date`, '%c/%e/%Y')
  )";
}
function get_tss_review_week_options(mysqli $mysqli): array {
  $weeks = [];
  $dateExpr = tss_ls_detail_date_expr('ldr');
  $q = $mysqli->query("
    SELECT DISTINCT DATE_SUB({$dateExpr}, INTERVAL (DAYOFWEEK({$dateExpr}) - 1) DAY) AS week_start
      FROM ls_detail_raw ldr
     WHERE {$dateExpr} IS NOT NULL
     ORDER BY week_start DESC
  ");
  if ($q) {
    while ($row = $q->fetch_assoc()) {
      $weekStart = (string)($row['week_start'] ?? '');
      if ($weekStart !== '') $weeks[] = $weekStart;
    }
    $q->close();
  }
  if (!$weeks) $weeks[] = business_sunday_week_start(tss_today());
  return $weeks;
}
function get_tss_review_rows(mysqli $mysqli, string $weekStart): array {
  $weekEnd = business_week_end($weekStart);
  $rows = [];
  $dateExpr = tss_ls_detail_date_expr('ldr');
  $sql = "
    SELECT ldr.*,
           COALESCE(ldr.broker_fee_override_pct, '') AS broker_fee_override_pct,
           CONCAT(dc.first_name, ' ', dc.last_name) AS matched_driver_name,
           COALESCE(dc.driver_type, '') AS matched_driver_type
      FROM ls_detail_raw ldr
      LEFT JOIN driver_contacts dc ON dc.id = ldr.matched_contact_id
     WHERE {$dateExpr} BETWEEN ? AND ?
     ORDER BY {$dateExpr}, ldr.`Truck #`, ldr.`Truckload ID`
  ";
  if ($stmt = $mysqli->prepare($sql)) {
    $stmt->bind_param('ss', $weekStart, $weekEnd);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
  }
  return $rows;
}
function get_rtex_payout_week_options(mysqli $mysqli): array {
  $weeks = [];
  $q = $mysqli->query("
    SELECT DISTINCT DATE_SUB(work_date, INTERVAL (DAYOFWEEK(work_date) - 1) DAY) AS week_start
      FROM rtex_payout_rows
     ORDER BY week_start DESC
  ");
  if ($q) {
    while ($row = $q->fetch_assoc()) {
      $weekStart = (string)($row['week_start'] ?? '');
      if ($weekStart !== '') $weeks[] = $weekStart;
    }
    $q->close();
  }
  if (!$weeks) {
    $weeks[] = business_sunday_week_start(tss_today());
  }
  return $weeks;
}
function get_rtex_review_rows(mysqli $mysqli, string $weekStart): array {
  $weekEnd = business_week_end($weekStart);
  $rows = [];
  $sql = "
    SELECT r.*,
           CONCAT(dc.first_name, ' ', dc.last_name) AS matched_driver_name
      FROM rtex_payout_rows r
      LEFT JOIN driver_contacts dc ON dc.id = r.matched_contact_id
     WHERE r.work_date BETWEEN ? AND ?
     ORDER BY r.work_date, r.driver_name, r.ticket_number, r.id
  ";
  if ($stmt = $mysqli->prepare($sql)) {
    $stmt->bind_param('ss', $weekStart, $weekEnd);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $rows[] = $row;
    }
    $stmt->close();
  }
  return $rows;
}
function get_nextier_payout_week_options(mysqli $mysqli): array {
  $weeks = [];
  $q = $mysqli->query("
    SELECT DISTINCT DATE_SUB(work_date, INTERVAL (DAYOFWEEK(work_date) - 1) DAY) AS week_start
      FROM nextier_payout_rows
     ORDER BY week_start DESC
  ");
  if ($q) {
    while ($row = $q->fetch_assoc()) {
      $weekStart = (string)($row['week_start'] ?? '');
      if ($weekStart !== '') $weeks[] = $weekStart;
    }
    $q->close();
  }
  if (!$weeks) {
    $weeks[] = business_sunday_week_start(tss_today());
  }
  return $weeks;
}
function get_nextier_review_rows(mysqli $mysqli, string $weekStart): array {
  $weekEnd = business_week_end($weekStart);
  $rows = [];
  $sql = "
    SELECT n.*,
           CONCAT(dc.first_name, ' ', dc.last_name) AS matched_driver_name
      FROM nextier_payout_rows n
      LEFT JOIN driver_contacts dc ON dc.id = n.matched_contact_id
     WHERE n.work_date BETWEEN ? AND ?
     ORDER BY n.work_date, n.driver_name, n.load_id, n.id
  ";
  if ($stmt = $mysqli->prepare($sql)) {
    $stmt->bind_param('ss', $weekStart, $weekEnd);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $rows[] = $row;
    }
    $stmt->close();
  }
  return $rows;
}
function nextier_ticket_number_from_row(array $row): string {
  $ticket = trim((string)($row['load_id'] ?? ''));
  $bol = trim((string)($row['bol_number'] ?? ''));
  if ($bol !== '') $ticket .= '-' . $bol;
  return $ticket;
}
function nextier_total_pay(array $row): float {
  $rate = (float)($row['rate'] ?? 0);
  $tons = (float)($row['tons'] ?? 0);
  $bonus = (float)($row['bonus'] ?? 0);
  if ($rate > 0.0 && $tons > 0.0) {
    return round(($rate * $tons) + $bonus, 2);
  }
  return round((float)($row['line_haul'] ?? 0) - (float)($row['fsc_total'] ?? 0), 2);
}
function driver_contact_name(mysqli $mysqli, int $driverContactId): string {
  if ($driverContactId <= 0) return '';
  $stmt = $mysqli->prepare("SELECT CONCAT(first_name, ' ', last_name) AS driver_name FROM driver_contacts WHERE id = ? LIMIT 1");
  if (!$stmt) return '';
  $stmt->bind_param('i', $driverContactId);
  $stmt->execute();
  $stmt->bind_result($driverName);
  $name = $stmt->fetch() ? trim((string)$driverName) : '';
  $stmt->close();
  return $name;
}
function get_driver_bonus_reconciliation_rows(mysqli $mysqli, string $weekStart): array {
  $weekEnd = business_week_end($weekStart);

  $lsDateExpr = "COALESCE(
    NULLIF(DATE(`Delivery Date`), '0000-00-00'),
    STR_TO_DATE(`Delivery Date`, '%Y-%m-%d'),
    STR_TO_DATE(SUBSTRING_INDEX(`Delivery Date`, ',', 1), '%m/%d/%Y'),
    STR_TO_DATE(SUBSTRING_INDEX(`Delivery Date`, ',', 1), '%c/%e/%Y'),
    STR_TO_DATE(`Delivery Date`, '%m/%d/%Y'),
    STR_TO_DATE(`Delivery Date`, '%c/%e/%Y')
  )";

  $lsTotals = [];
  $sqlLs = "
    SELECT matched_contact_id AS driver_contact_id,
           SUM(COALESCE(total_bonuses, 0)) AS ls_total_bonuses
      FROM ls_detail_raw
     WHERE matched_contact_id IS NOT NULL
       AND total_bonuses IS NOT NULL
       AND {$lsDateExpr} BETWEEN ? AND ?
     GROUP BY matched_contact_id
  ";
  if ($stmt = $mysqli->prepare($sqlLs)) {
    $stmt->bind_param('ss', $weekStart, $weekEnd);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $lsTotals[(int)$row['driver_contact_id']] = (float)($row['ls_total_bonuses'] ?? 0);
    }
    $stmt->close();
  }

  $tssTotals = [];
  $sqlTss = "
    SELECT matched_contact_id AS driver_contact_id,
           SUM(COALESCE(pay_amount, 0)) AS tss_bonus_total
      FROM tss_payout_rows
     WHERE matched_contact_id IS NOT NULL
       AND work_date BETWEEN ? AND ?
       AND LOWER(TRIM(COALESCE(misc_category, ''))) = 'miscellaneous revenue'
     GROUP BY matched_contact_id
  ";
  if ($stmt = $mysqli->prepare($sqlTss)) {
    $stmt->bind_param('ss', $weekStart, $weekEnd);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $tssTotals[(int)$row['driver_contact_id']] = (float)($row['tss_bonus_total'] ?? 0);
    }
    $stmt->close();
  }

  $driverIds = array_values(array_unique(array_merge(array_keys($lsTotals), array_keys($tssTotals))));
  if (empty($driverIds)) {
    return [];
  }

  $driverNames = [];
  $placeholders = implode(',', array_fill(0, count($driverIds), '?'));
  $types = str_repeat('i', count($driverIds));
  $sqlDrivers = "
    SELECT id, CONCAT(first_name, ' ', last_name) AS name, truck_no
      FROM driver_contacts
     WHERE id IN ($placeholders)
  ";
  if ($stmt = $mysqli->prepare($sqlDrivers)) {
    $stmt->bind_param($types, ...$driverIds);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $driverNames[(int)$row['id']] = (string)($row['name'] ?? '');
    }
    $stmt->close();
  }

  $rows = [];
  foreach ($driverIds as $driverId) {
    $lsAmount = (float)($lsTotals[$driverId] ?? 0);
    $tssAmount = (float)($tssTotals[$driverId] ?? 0);
    $difference = round($lsAmount - $tssAmount, 2);
    $matches = abs($difference) < 0.01;
    $rows[] = [
      'driver_contact_id' => $driverId,
      'driver_name' => $driverNames[$driverId] ?? ('Driver #' . $driverId),
      'ls_total_bonuses' => $lsAmount,
      'tss_bonus_total' => $tssAmount,
      'difference' => $difference,
      'matches' => $matches,
    ];
  }

  usort($rows, static function ($a, $b) {
    if (($a['matches'] ? 1 : 0) !== ($b['matches'] ? 1 : 0)) {
      return ($a['matches'] ? 1 : 0) <=> ($b['matches'] ? 1 : 0);
    }
    return strcasecmp((string)$a['driver_name'], (string)$b['driver_name']);
  });

  return $rows;
}
function get_driver_fuel_surcharge_rows(mysqli $mysqli, string $weekStart): array {
  $weekEnd = business_week_end($weekStart);
  $lsDateExpr = "COALESCE(
    NULLIF(DATE(ldr.`Delivery Date`), '0000-00-00'),
    STR_TO_DATE(ldr.`Delivery Date`, '%Y-%m-%d'),
    STR_TO_DATE(SUBSTRING_INDEX(ldr.`Delivery Date`, ',', 1), '%m/%d/%Y'),
    STR_TO_DATE(SUBSTRING_INDEX(ldr.`Delivery Date`, ',', 1), '%c/%e/%Y'),
    STR_TO_DATE(ldr.`Delivery Date`, '%m/%d/%Y'),
    STR_TO_DATE(ldr.`Delivery Date`, '%c/%e/%Y')
  )";
  $rowDateExpr = "COALESCE({$lsDateExpr}, dp.payout_date)";
  $driverIdExpr = "COALESCE(ldr.matched_contact_id, dp.driver_contact_id)";
  $milesExpr = "CAST(REPLACE(REPLACE(COALESCE(ldr.`Mileage`, '0'), ',', ''), '$', '') AS DECIMAL(12,2))";
  $tonsExpr = "CAST(REPLACE(REPLACE(COALESCE(ldr.`Net Weight (Tons)`, '0'), ',', ''), '$', '') AS DECIMAL(12,2))";
  $surchargeBaseExpr = "CASE WHEN LOWER(TRIM(COALESCE(ldr.fuel_surcharge_type, 'mileage'))) IN ('ton', 'tons', 'tonnage', 'per ton', 'per-ton') THEN {$tonsExpr} ELSE {$milesExpr} END";

  $rows = [];
  $sql = "
    SELECT {$driverIdExpr} AS driver_contact_id,
           CONCAT(dc.first_name, ' ', dc.last_name) AS driver_name,
           COALESCE(NULLIF(dc.truck_no, ''), GROUP_CONCAT(DISTINCT ldr.`Truck #` ORDER BY ldr.`Truck #` SEPARATOR ', ')) AS truck_number,
           SUM({$milesExpr}) AS total_miles,
           SUM({$tonsExpr}) AS total_tons,
           SUM({$surchargeBaseExpr} * COALESCE(ldr.fuel_surcharge_rate, 0)) AS total_fuel_surcharge
      FROM ls_detail_raw ldr
      LEFT JOIN (
        SELECT upload_date, ticket_number, driver_contact_id, MIN(payout_date) AS payout_date
          FROM driver_payouts
         WHERE driver_contact_id IS NOT NULL
         GROUP BY upload_date, ticket_number, driver_contact_id
      ) dp
        ON ldr.matched_contact_id IS NULL
       AND dp.upload_date = ldr.upload_date
       AND dp.ticket_number = ldr.`Truckload ID`
      JOIN driver_contacts dc ON dc.id = {$driverIdExpr}
     WHERE {$driverIdExpr} IS NOT NULL
       AND COALESCE(ldr.fuel_surcharge_rate, 0) > 0
       AND {$rowDateExpr} BETWEEN ? AND ?
     GROUP BY {$driverIdExpr}, dc.first_name, dc.last_name, dc.truck_no
    HAVING total_fuel_surcharge > 0
     ORDER BY driver_name ASC
  ";
  if ($stmt = $mysqli->prepare($sql)) {
    $stmt->bind_param('ss', $weekStart, $weekEnd);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $rows[] = [
        'driver_contact_id' => (int)($row['driver_contact_id'] ?? 0),
        'driver_name' => (string)($row['driver_name'] ?? ''),
        'truck_number' => (string)($row['truck_number'] ?? ''),
        'total_miles' => (float)($row['total_miles'] ?? 0),
        'total_tons' => (float)($row['total_tons'] ?? 0),
        'total_fuel_surcharge' => (float)($row['total_fuel_surcharge'] ?? 0),
      ];
    }
    $stmt->close();
  }
  return $rows;
}
function ensure_ls_detail_upload_registry(mysqli $mysqli): bool {
  $sql = "
    CREATE TABLE IF NOT EXISTS ls_detail_upload_registry (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      file_hash CHAR(64) NOT NULL,
      original_filename VARCHAR(255) NOT NULL DEFAULT '',
      upload_date DATE NOT NULL,
      status ENUM('processing','completed') NOT NULL DEFAULT 'processing',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      completed_at DATETIME NULL,
      UNIQUE KEY uniq_ls_detail_file_hash (file_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ";
  try {
    return $mysqli->query($sql) !== false;
  } catch (mysqli_sql_exception $ex) {
    return false;
  }
}
function reserve_ls_detail_upload_hash(mysqli $mysqli, string $fileHash, string $originalName, string $uploadDate): string {
  $stmt = $mysqli->prepare("
    INSERT INTO ls_detail_upload_registry
    (file_hash, original_filename, upload_date, status)
    VALUES (?,?,?,'processing')
  ");
  if (!$stmt) return 'error';
  try {
    $stmt->bind_param('sss', $fileHash, $originalName, $uploadDate);
    $ok = $stmt->execute();
    $errno = (int)$stmt->errno;
    $stmt->close();
    if ($ok) return 'ok';
    if ($errno === 1062) return 'duplicate';
    return 'error';
  } catch (mysqli_sql_exception $ex) {
    $stmt->close();
    if ((int)$ex->getCode() === 1062) return 'duplicate';
    return 'error';
  }
}
function complete_ls_detail_upload_hash(mysqli $mysqli, string $fileHash): void {
  $stmt = $mysqli->prepare("
    UPDATE ls_detail_upload_registry
       SET status='completed', completed_at=NOW()
     WHERE file_hash=?
     LIMIT 1
  ");
  if ($stmt) {
    $stmt->bind_param('s', $fileHash);
    $stmt->execute();
    $stmt->close();
  }
}
function release_ls_detail_upload_hash(mysqli $mysqli, string $fileHash): void {
  $stmt = $mysqli->prepare("
    DELETE FROM ls_detail_upload_registry
     WHERE file_hash=? AND status='processing'
     LIMIT 1
  ");
  if ($stmt) {
    $stmt->bind_param('s', $fileHash);
    $stmt->execute();
    $stmt->close();
  }
}
function ensure_driver_payouts_split_index(mysqli $mysqli): void {
  // Allow one row per ticket/date/driver/vendor while still preventing exact duplicates.
  $hasLegacy = false;
  if ($rs = $mysqli->query("SHOW INDEX FROM driver_payouts WHERE Key_name = 'uniq_ticket_date'")) {
    $hasLegacy = ($rs->num_rows > 0);
    $rs->close();
  }
  if ($hasLegacy) {
    $mysqli->query("ALTER TABLE driver_payouts DROP INDEX uniq_ticket_date");
  }

  $hasNew = false;
  if ($rs = $mysqli->query("SHOW INDEX FROM driver_payouts WHERE Key_name = 'uniq_ticket_date_driver_vendor'")) {
    $hasNew = ($rs->num_rows > 0);
    $rs->close();
  }
  if (!$hasNew) {
    $mysqli->query("ALTER TABLE driver_payouts ADD UNIQUE KEY uniq_ticket_date_driver_vendor (ticket_number, payout_date, vendor_name, driver_name)");
  }
}
function ensure_ls_unresolved_use_role_index(mysqli $mysqli): void {
  $uniqueKeys = [];
  if ($rs = $mysqli->query("SHOW INDEX FROM ls_unresolved_matches WHERE Non_unique = 0")) {
    while ($r = $rs->fetch_assoc()) {
      $k = $r['Key_name'] ?? '';
      $seq = (int)($r['Seq_in_index'] ?? 0);
      $col = $r['Column_name'] ?? '';
      if ($k === '' || $col === '') continue;
      if (!isset($uniqueKeys[$k])) $uniqueKeys[$k] = [];
      $uniqueKeys[$k][$seq] = $col;
    }
    $rs->close();
  }

  // Drop legacy uniqueness that blocks separate pickup/delivery unresolved rows for same ticket/date.
  foreach ($uniqueKeys as $keyName => $colsBySeq) {
    ksort($colsBySeq);
    $cols = array_values($colsBySeq);
    if ($keyName !== 'PRIMARY' && $cols === ['upload_date', 'delivery_date', 'truckload_id']) {
      $mysqli->query("ALTER TABLE ls_unresolved_matches DROP INDEX `{$keyName}`");
    }
  }

  $hasRoleUnique = false;
  if ($rs = $mysqli->query("SHOW INDEX FROM ls_unresolved_matches WHERE Key_name = 'uniq_unresolved_ticket_role'")) {
    $hasRoleUnique = ($rs->num_rows > 0);
    $rs->close();
  }
  if (!$hasRoleUnique) {
    $mysqli->query("ALTER TABLE ls_unresolved_matches ADD UNIQUE KEY uniq_unresolved_ticket_role (upload_date, delivery_date, truckload_id, use_role)");
  }
}
function resolve_contact_id_by_name(mysqli $mysqli, string $first, string $last): ?int {
  $nameNorm = norm_name($first, $last);
  if ($nameNorm === '') return null;

  $matches = [];

  $stmt = $mysqli->prepare("
    SELECT id
      FROM driver_contacts
     WHERE LOWER(TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')))) = ?
  ");
  if ($stmt) {
    $stmt->bind_param('s', $nameNorm);
    $stmt->execute();
    if (method_exists($stmt, 'get_result')) {
      $res = $stmt->get_result();
      while ($r = $res->fetch_assoc()) {
        $id = (int)($r['id'] ?? 0);
        if ($id > 0) $matches[$id] = true;
      }
    } else {
      $stmt->bind_result($id);
      while ($stmt->fetch()) {
        $id = (int)$id;
        if ($id > 0) $matches[$id] = true;
      }
    }
    $stmt->close();
  }

  $stmt = $mysqli->prepare("
    SELECT DISTINCT driver_contact_id
      FROM driver_name_aliases
     WHERE alias_full_norm = ?
  ");
  if ($stmt) {
    $stmt->bind_param('s', $nameNorm);
    $stmt->execute();
    if (method_exists($stmt, 'get_result')) {
      $res = $stmt->get_result();
      while ($r = $res->fetch_assoc()) {
        $id = (int)($r['driver_contact_id'] ?? 0);
        if ($id > 0) $matches[$id] = true;
      }
    } else {
      $stmt->bind_result($id);
      while ($stmt->fetch()) {
        $id = (int)$id;
        if ($id > 0) $matches[$id] = true;
      }
    }
    $stmt->close();
  }

  $ids = array_keys($matches);
  if (count($ids) === 1) return (int)$ids[0];
  return null;
}
function upsert_unresolved_match(
  mysqli $mysqli,
  string $uploadDate,
  string $ticketNumber,
  string $deliveryDate,
  string $truckRaw,
  string $truckDigits,
  string $useRole,
  string $pickupFirst,
  string $pickupLast,
  string $deliveryFirst,
  string $deliveryLast,
  string $calcRate
): bool {
  $stmt = $mysqli->prepare("
    INSERT INTO ls_unresolved_matches
    (upload_date, truckload_id, delivery_date, truck_raw, truck_digits, use_role,
     pickup_first_name, pickup_last_name, delivery_first_name, delivery_last_name, calc_rate, status, matched_contact_id, resolved_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,'unresolved',NULL,NULL)
    ON DUPLICATE KEY UPDATE
      status='unresolved',
      matched_contact_id=NULL,
      resolved_at=NULL,
      truck_raw=VALUES(truck_raw),
      truck_digits=VALUES(truck_digits),
      use_role=VALUES(use_role),
      pickup_first_name=VALUES(pickup_first_name),
      pickup_last_name=VALUES(pickup_last_name),
      delivery_first_name=VALUES(delivery_first_name),
      delivery_last_name=VALUES(delivery_last_name),
      calc_rate=VALUES(calc_rate)
  ");
  if (!$stmt) return false;
  $stmt->bind_param(
    'sssssssssss',
    $uploadDate,
    $ticketNumber,
    $deliveryDate,
    $truckRaw,
    $truckDigits,
    $useRole,
    $pickupFirst,
    $pickupLast,
    $deliveryFirst,
    $deliveryLast,
    $calcRate
  );
  $ok = $stmt->execute();
  $stmt->close();
  return $ok;
}

// Columns we expect from TSS
$expectedHeaders = [
  'Truckload ID','Customer Name','Well','Well State','Delivery Date','Pull Point',
  'Pull Point State','Sand Type','BOL','OG Loads Allowed?','Base Freight Rate (Carrier)',
  'Calculated Freight Rate (Carrier)','OG Deduction','Net Weight (lb)','Net Weight (Tons)',
  'Gross Weight (lb)','Gross Weight (Tons)','Mileage','Truck #','Driver Role',
  'Pickup Driver First Name','Pickup Driver Last Name','Pickup Contract','Pickup Carrier',
  'Delivery Driver First Name','Delivery Driver Last Name','Delivery Contract',
  'Delivery Carrier','Trailer Type'
];
$optionalTrailerPctHeaders = ['TSS Trailer Percentage', 'TSS Trailer %', 'Trailer Percentage', 'Trailer %'];

$errors = [];
$success = false;
$dataRows = [];
$headersRow = [];
$lastUploadType = '';
$tssPayoutSummary = null;
$rtexPayoutSummary = null;
$nextierPayoutSummary = null;
$nickelrockPayoutSummary = null;
$splitResolveSummary = null;
$todayDate = tss_today();
$openUnresolvedCount = get_open_unresolved_count($mysqli);
$openSplitCount = isset($_SESSION['split_rows']) ? count($_SESSION['split_rows']) : 0;
$hasLsDetailForToday = has_ls_detail_upload_for_date($mysqli, $todayDate);
$hasTssPayoutForToday = has_tss_payout_upload_for_date($mysqli, $todayDate);
ensure_driver_payouts_split_index($mysqli);
ensure_ls_unresolved_use_role_index($mysqli);
ensure_tss_misc_adjustments_table($mysqli);
ensure_rtex_payout_rows_table($mysqli);
ensure_rtex_load_schema($mysqli);
ensure_nextier_payout_rows_table($mysqli);
ensure_nextier_trailer_reconciliation_table($mysqli);
ensure_nickelrock_payout_rows_table($mysqli);
ensure_nickelrock_job_rates_table($mysqli);
ensure_ls_detail_total_bonuses_column($mysqli);
ensure_ls_detail_fuel_surcharge_rate_column($mysqli);
ensure_ls_detail_fuel_surcharge_type_column($mysqli);
ensure_ls_detail_broker_fee_override_column($mysqli);
lonestar_vendor_broker_fees_ensure_table($mysqli);
$driverOptions = get_driver_dropdown_options($mysqli);
$payoutWeekOptions = get_payout_week_options($mysqli);
$miscAdjustmentForm = [
  'driver_contact_id' => '',
  'payout_week_start' => business_sunday_week_start($todayDate),
  'adjustment_type' => 'misc_payment',
  'amount' => '',
  'comments' => '',
  'allow_without_daily_uploads' => false,
];
$rtexMiscAdjustmentForm = [
  'driver_contact_id' => '',
  'payout_week_start' => business_sunday_week_start($todayDate),
  'adjustment_type' => 'misc_payment',
  'amount' => '',
  'comments' => '',
];
$nextierMiscAdjustmentForm = [
  'driver_contact_id' => '',
  'payout_week_start' => business_sunday_week_start($todayDate),
  'adjustment_type' => 'misc_payment',
  'amount' => '',
  'comments' => '',
];
$nickelrockMiscAdjustmentForm = [
  'driver_contact_id' => '',
  'payout_week_start' => business_sunday_week_start($todayDate),
  'adjustment_type' => 'misc_payment',
  'amount' => '',
  'comments' => '',
];
$bonusReconciliationForm = [
  'payout_week_start' => trim((string)($_GET['bonus_payout_week_start'] ?? business_sunday_week_start($todayDate))),
  'allow_without_daily_uploads' => isset($_GET['allow_bonus_without_daily_uploads']),
];
$bonusReconciliationRows = [];
$bonusRequiresDailyUploads = (!$hasLsDetailForToday || !$hasTssPayoutForToday);
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bonusReconciliationForm['payout_week_start'])) {
  if (!$bonusRequiresDailyUploads || !empty($bonusReconciliationForm['allow_without_daily_uploads'])) {
    $bonusReconciliationRows = get_driver_bonus_reconciliation_rows($mysqli, $bonusReconciliationForm['payout_week_start']);
  }
}
$fuelSurchargeForm = [
  'payout_week_start' => trim((string)($_GET['fuel_surcharge_week_start'] ?? business_sunday_week_start($todayDate))),
];
$fuelSurchargeRows = [];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fuelSurchargeForm['payout_week_start'])) {
  $fuelSurchargeRows = get_driver_fuel_surcharge_rows($mysqli, $fuelSurchargeForm['payout_week_start']);
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'save_vendor_broker_fee')) {
  $lastUploadType = 'vendor_broker_fee';
  $scope = lonestar_payout_normalize_vendor_scope((string)($_POST['vendor_scope'] ?? 'tss'));
  $mode = (string)($_POST['fee_mode'] ?? 'percentage');
  $rawValue = trim((string)($_POST['fee_value'] ?? ''));
  if (!in_array($mode, ['percentage', 'flat'], true)) {
    $mode = 'percentage';
  }
  if ($rawValue === '' || !is_numeric(str_replace('%', '', $rawValue))) {
    $errors[] = 'Broker fee must be a number.';
  } else {
    $feeValue = (float)str_replace('%', '', $rawValue);
    if ($feeValue < 0 || ($mode === 'percentage' && $feeValue > 100)) {
      $errors[] = $mode === 'percentage'
        ? 'Broker fee percentage must be between 0 and 100.'
        : 'Broker fee dollar amount cannot be negative.';
    } else {
      $defaults = lonestar_vendor_broker_fee_defaults();
      $label = $defaults[$scope]['label'] ?? strtoupper($scope);
      $beforeBrokerFee = lonestar_vendor_broker_fee_settings($mysqli, $scope);
      $stmt = $mysqli->prepare(
        "INSERT INTO vendor_broker_fees (vendor_scope, vendor_label, fee_mode, fee_value)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE fee_mode=VALUES(fee_mode), fee_value=VALUES(fee_value), updated_at=NOW()"
      );
      if (!$stmt) {
        $errors[] = 'Unable to save broker fee: ' . $mysqli->error;
      } else {
        $stmt->bind_param('sssd', $scope, $label, $mode, $feeValue);
        if ($stmt->execute()) {
          $success = true;
          $_GET['vendor'] = $scope;
          audit_log_change($mysqli, 'update', 'upload_vendor_broker_fee', $scope, 'Updated broker fee for ' . $label, [
            'fee_mode' => $beforeBrokerFee['fee_mode'] ?? '',
            'fee_value' => $beforeBrokerFee['fee_value'] ?? '',
          ], [
            'fee_mode' => $mode,
            'fee_value' => $feeValue,
          ]);
        } else {
          $errors[] = 'Unable to save broker fee: ' . $stmt->error;
        }
        $stmt->close();
      }
    }
  }
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'save_tss_review_row')) {
  $lastUploadType = 'tss_review';
  $_GET['vendor'] = 'tss';
  $oldTicketNumber = trim((string)($_POST['old_truckload_id'] ?? ''));
  $oldDeliveryDate = trim((string)($_POST['old_delivery_date'] ?? ''));
  $oldUploadDate = trim((string)($_POST['old_upload_date'] ?? ''));
  $oldTruckRaw = trim((string)($_POST['old_truck_raw'] ?? ''));
  $matchedContactId = (int)($_POST['matched_contact_id'] ?? 0);
  $rawValue = trim((string)($_POST['broker_fee_override_pct'] ?? ''));
  $trailerPctRaw = trim((string)($_POST['tss_trailer_pct_override'] ?? ''));
  $totalBonusesRaw = trim((string)($_POST['total_bonuses'] ?? ''));
  $fuelSurchargeRaw = trim((string)($_POST['fuel_surcharge_rate'] ?? ''));
  $fuelSurchargeType = normalize_fuel_surcharge_type($_POST['fuel_surcharge_type'] ?? 'mileage');
  $postedDetail = is_array($_POST['ls_detail'] ?? null) ? $_POST['ls_detail'] : [];

  if ($oldTicketNumber === '' || $oldDeliveryDate === '' || $oldUploadDate === '') {
    $errors[] = 'Unable to locate the TSS review row to update.';
  } elseif ($rawValue !== '' && (!is_numeric(str_replace('%', '', $rawValue)) || (float)str_replace('%', '', $rawValue) < 0 || (float)str_replace('%', '', $rawValue) > 100)) {
    $errors[] = 'Broker fee override must be a percentage between 0 and 100.';
  } elseif ($trailerPctRaw !== '' && (!is_numeric(str_replace('%', '', $trailerPctRaw)) || (float)str_replace('%', '', $trailerPctRaw) < 0 || (float)str_replace('%', '', $trailerPctRaw) > 100)) {
    $errors[] = 'Trailer percentage must be between 0 and 100.';
  } elseif ($totalBonusesRaw !== '' && !is_numeric(str_replace(['$', ','], '', $totalBonusesRaw))) {
    $errors[] = 'Total Bonuses must be a number.';
  } elseif ($fuelSurchargeRaw !== '' && !is_numeric(str_replace(['$', ','], '', $fuelSurchargeRaw))) {
    $errors[] = 'Fuel Surcharge Rate must be a number.';
  } else {
    ensure_ls_detail_broker_fee_override_column($mysqli);
    $oldRow = null;
    $stmtOld = $mysqli->prepare("SELECT * FROM ls_detail_raw WHERE `Truckload ID` = ? AND `Delivery Date` = ? AND upload_date = ? AND `Truck #` = ? LIMIT 1");
    if ($stmtOld) {
      $stmtOld->bind_param('ssss', $oldTicketNumber, $oldDeliveryDate, $oldUploadDate, $oldTruckRaw);
      $stmtOld->execute();
      $resOld = $stmtOld->get_result();
      $oldRow = $resOld ? $resOld->fetch_assoc() : null;
      $stmtOld->close();
    }

    if (!$oldRow) {
      $errors[] = 'Unable to locate the TSS review row to update.';
    } else {
      $assignments = [];
      foreach ($expectedHeaders as $header) {
        $value = (string)($postedDetail[$header] ?? '');
        if ($header === 'Delivery Date') {
          $value = parse_any_date($value, TSS_SOURCE_TIMEZONE) ?: $value;
        }
        $assignments[] = '`' . $mysqli->real_escape_string($header) . '` = ' . "'" . $mysqli->real_escape_string($value) . "'";
      }
      $assignments[] = 'matched_contact_id = ' . ($matchedContactId > 0 ? (string)$matchedContactId : 'NULL');
      $assignments[] = 'tss_trailer_pct_override = ' . ($trailerPctRaw === '' ? 'NULL' : "'" . $mysqli->real_escape_string(number_format((float)str_replace('%', '', $trailerPctRaw), 2, '.', '')) . "'");
      $assignments[] = 'total_bonuses = ' . ($totalBonusesRaw === '' ? 'NULL' : "'" . $mysqli->real_escape_string(number_format((float)str_replace(['$', ','], '', $totalBonusesRaw), 2, '.', '')) . "'");
      $assignments[] = 'fuel_surcharge_rate = ' . ($fuelSurchargeRaw === '' ? 'NULL' : "'" . $mysqli->real_escape_string(number_format((float)str_replace(['$', ','], '', $fuelSurchargeRaw), 4, '.', '')) . "'");
      $assignments[] = "fuel_surcharge_type = '" . $mysqli->real_escape_string($fuelSurchargeType) . "'";
      $assignments[] = 'broker_fee_override_pct = ' . ($rawValue === '' ? 'NULL' : "'" . $mysqli->real_escape_string(number_format((float)str_replace('%', '', $rawValue), 2, '.', '')) . "'");

      $where = "`Truckload ID` = '" . $mysqli->real_escape_string($oldTicketNumber) . "'"
        . " AND `Delivery Date` = '" . $mysqli->real_escape_string($oldDeliveryDate) . "'"
        . " AND upload_date = '" . $mysqli->real_escape_string($oldUploadDate) . "'"
        . " AND `Truck #` = '" . $mysqli->real_escape_string($oldTruckRaw) . "'";
      $sqlUpdate = 'UPDATE ls_detail_raw SET ' . implode(', ', $assignments) . ' WHERE ' . $where . ' LIMIT 1';
      if (!$mysqli->query($sqlUpdate)) {
        $errors[] = 'Unable to save TSS review row: ' . $mysqli->error;
      }

      if (empty($errors)) {
        $oldPayoutDate = parse_any_date($oldRow['Delivery Date'] ?? '', TSS_SOURCE_TIMEZONE) ?: (string)($oldRow['Delivery Date'] ?? '');
        $oldTicket = (string)($oldRow['Truckload ID'] ?? '');
        $oldDriverName = '';
        if (!empty($oldRow['matched_contact_id'])) {
          $oldDriverName = driver_contact_name($mysqli, (int)$oldRow['matched_contact_id']);
        }
        if ($oldDriverName === '') {
          $oldDriverName = trim((string)($oldRow['Pickup Driver First Name'] ?? '') . ' ' . (string)($oldRow['Pickup Driver Last Name'] ?? ''));
        }
        $del = $mysqli->prepare("
          DELETE FROM driver_payouts
           WHERE payout_date = ?
             AND ticket_number = ?
             AND vendor_name = 'TSS'
             AND upload_date = ?
             AND (driver_name = ? OR driver_contact_id = ?)
           LIMIT 1
        ");
        if ($del) {
          $oldCid = !empty($oldRow['matched_contact_id']) ? (int)$oldRow['matched_contact_id'] : 0;
          $del->bind_param('ssssi', $oldPayoutDate, $oldTicket, $oldUploadDate, $oldDriverName, $oldCid);
          $del->execute();
          $del->close();
        }

        $newDeliveryDate = (string)($postedDetail['Delivery Date'] ?? '');
        $newPayoutDate = parse_any_date($newDeliveryDate, TSS_SOURCE_TIMEZONE) ?: $newDeliveryDate;
        $newTicket = (string)($postedDetail['Truckload ID'] ?? '');
        $newPay = (string)($postedDetail['Calculated Freight Rate (Carrier)'] ?? '');
        $newDriverName = $matchedContactId > 0 ? driver_contact_name($mysqli, $matchedContactId) : '';
        if ($newDriverName === '') {
          $newDriverName = trim((string)($postedDetail['Pickup Driver First Name'] ?? '') . ' ' . (string)($postedDetail['Pickup Driver Last Name'] ?? ''));
        }
        if ($newDriverName === '') {
          $newDriverName = trim((string)($postedDetail['Delivery Driver First Name'] ?? '') . ' ' . (string)($postedDetail['Delivery Driver Last Name'] ?? ''));
        }

        if ($newPayoutDate !== '' && $newTicket !== '' && $newDriverName !== '') {
          $vendor = 'TSS';
          $contactIdBind = $matchedContactId > 0 ? $matchedContactId : null;
          $ins = $mysqli->prepare("
            INSERT INTO driver_payouts
            (payout_date, ticket_number, driver_name, vendor_name, tss_pay, upload_date, driver_contact_id)
            VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              driver_name=VALUES(driver_name),
              tss_pay=VALUES(tss_pay),
              upload_date=VALUES(upload_date),
              driver_contact_id=VALUES(driver_contact_id)
          ");
          if (!$ins) {
            $errors[] = 'Unable to update TSS payout row: ' . $mysqli->error;
          } else {
            $ins->bind_param('ssssssi', $newPayoutDate, $newTicket, $newDriverName, $vendor, $newPay, $oldUploadDate, $contactIdBind);
            if (!$ins->execute()) {
              $errors[] = 'Unable to update TSS payout row: ' . $ins->error;
            }
            $ins->close();
          }
        }
      }

      if (empty($errors)) {
        $success = true;
        $tssPayoutSummary = ['review_message' => 'TSS review row saved.'];
      }
    }
  }
}

// ---------------------------
// Resolution POST (modal)
// ---------------------------
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'resolve_split')) {
  $idx = isset($_POST['split_row_index']) ? (int)$_POST['split_row_index'] : -1;
  $markSameDriver = isset($_POST['drivers_same']);
  $pickupPct = (float)($_POST['pickup_pct'] ?? 0);
  $deliveryPct = (float)($_POST['delivery_pct'] ?? 0);

  $splitRows = $_SESSION['split_rows'] ?? [];
  $splitRow = null;
  $splitKey = null;
  if (isset($splitRows[$idx])) {
    $splitRow = $splitRows[$idx];
    $splitKey = $idx;
  } else {
    foreach ($splitRows as $k => $row) {
      if ((int)($row['row_index'] ?? -1) === $idx) {
        $splitRow = $row;
        $splitKey = $k;
        break;
      }
    }
  }

  if ($splitRow === null) {
    $errors[] = 'Unable to locate split row. Please re-upload.';
  } else {
    $hdr = $splitRow['headers'] ?? [];
    $cells = $splitRow['cells'] ?? [];

    $iPF = array_search('Pickup Driver First Name', $hdr, true);
    $iPL = array_search('Pickup Driver Last Name', $hdr, true);
    $iDF = array_search('Delivery Driver First Name', $hdr, true);
    $iDL = array_search('Delivery Driver Last Name', $hdr, true);

    $pickupFirst = trim((string)($cells[$iPF] ?? ''));
    $pickupLast = trim((string)($cells[$iPL] ?? ''));
    $deliveryFirst = trim((string)($cells[$iDF] ?? ''));
    $deliveryLast = trim((string)($cells[$iDL] ?? ''));

    $pickupDriverName = trim($pickupFirst . ' ' . $pickupLast);
    $deliveryDriverName = trim($deliveryFirst . ' ' . $deliveryLast);
    $pickupContactId = resolve_contact_id_by_name($mysqli, $pickupFirst, $pickupLast);
    $deliveryContactId = resolve_contact_id_by_name($mysqli, $deliveryFirst, $deliveryLast);
    $ticketNumber = trim((string)($splitRow['ticket_number'] ?? ''));
    $payoutDate = trim((string)($splitRow['payout_date'] ?? ''));
    $uploadDate = trim((string)($splitRow['upload_date'] ?? ''));
    $totalPay = parse_money($splitRow['tss_pay'] ?? 0);
    $iTruck = array_search('Truck #', $hdr, true);
    $truckRaw = trim((string)($cells[$iTruck] ?? ''));
    $truckDigits = digits_only($truckRaw);

    if ($payoutDate === '') {
      $iDeliveryDate = array_search('Delivery Date', $hdr, true);
      $payoutDate = parse_any_date($cells[$iDeliveryDate] ?? '', TSS_SOURCE_TIMEZONE) ?: tss_today();
    }
    if ($uploadDate === '') {
      $uploadDate = $_SESSION['upload_date'] ?? tss_today();
    }

    if (!$markSameDriver) {
      if ($pickupPct <= 0 || $deliveryPct <= 0) {
        $errors[] = 'Split percentages must both be greater than 0.';
      }
      if (abs(($pickupPct + $deliveryPct) - 100.0) > 0.01) {
        $errors[] = 'Split percentages must total 100%.';
      }
    }

    if (empty($errors)) {
      $ins = $mysqli->prepare("
        INSERT INTO driver_payouts
        (payout_date, ticket_number, driver_name, vendor_name, tss_pay, upload_date, driver_contact_id)
        VALUES (?,?,?,?,?,?,?)
      ");
      if (!$ins) {
        $errors[] = 'Split payout prepare error: ' . $mysqli->error;
      } else {
        $mysqli->begin_transaction();
        $insertCount = 0;
        try {
          if ($markSameDriver) {
            $driverName = $deliveryDriverName !== '' ? $deliveryDriverName : $pickupDriverName;
            if ($driverName === '') {
              $driverName = 'Unknown Driver';
            }
            $cid = null;
            $payAsString = number_format($totalPay, 2, '.', '');
            $vendorName = 'TSS';
            $ins->bind_param('ssssssi', $payoutDate, $ticketNumber, $driverName, $vendorName, $payAsString, $uploadDate, $cid);
            if (!$ins->execute()) {
              throw new RuntimeException('Split payout insert error: ' . $ins->error);
            }
            $insertCount += max(0, (int)$ins->affected_rows);
          } else {
            // Remove prior split rows for this same ticket/date so re-resolving does not silently keep stale values.
            $del = $mysqli->prepare(
              "DELETE FROM driver_payouts
               WHERE payout_date = ?
                 AND ticket_number = ?
                 AND vendor_name IN ('TSS Split (Pickup)', 'TSS Split (Delivery)')"
            );
            if ($del) {
              $del->bind_param('ss', $payoutDate, $ticketNumber);
              $del->execute();
              $del->close();
            }

            $pickupAmount = round($totalPay * ($pickupPct / 100.0), 2);
            $deliveryAmount = round($totalPay - $pickupAmount, 2);

            $pickupPayAsString = number_format($pickupAmount, 2, '.', '');
            $pickupVendor = 'TSS Split (Pickup)';
            $pickupName = $pickupDriverName !== '' ? $pickupDriverName : 'Unknown Pickup Driver';
            $ins->bind_param('ssssssi', $payoutDate, $ticketNumber, $pickupName, $pickupVendor, $pickupPayAsString, $uploadDate, $pickupContactId);
            if (!$ins->execute()) {
              throw new RuntimeException('Pickup split insert error: ' . $ins->error);
            }
            if ((int)$ins->affected_rows !== 1) {
              throw new RuntimeException('Pickup split row was not inserted. Please verify unique keys on driver_payouts.');
            }
            $insertCount++;

            $deliveryPayAsString = number_format($deliveryAmount, 2, '.', '');
            $deliveryVendor = 'TSS Split (Delivery)';
            $deliveryName = $deliveryDriverName !== '' ? $deliveryDriverName : 'Unknown Delivery Driver';
            $ins->bind_param('ssssssi', $payoutDate, $ticketNumber, $deliveryName, $deliveryVendor, $deliveryPayAsString, $uploadDate, $deliveryContactId);
            if (!$ins->execute()) {
              throw new RuntimeException('Delivery split insert error: ' . $ins->error);
            }
            if ((int)$ins->affected_rows !== 1) {
              throw new RuntimeException('Delivery split row was not inserted. Please verify unique keys on driver_payouts.');
            }
            $insertCount++;

            // If either split driver is not uniquely matched, enqueue unresolved role rows
            // so contacts can be linked later without blocking payout split posting.
            if ($pickupContactId === null) {
              $ok = upsert_unresolved_match(
                $mysqli,
                $uploadDate,
                $ticketNumber,
                $payoutDate,
                $truckRaw,
                $truckDigits,
                'pickup',
                $pickupFirst,
                $pickupLast,
                $deliveryFirst,
                $deliveryLast,
                $pickupPayAsString
              );
              if (!$ok) {
                throw new RuntimeException('Failed to enqueue pickup unresolved match for split row.');
              }
            }
            if ($deliveryContactId === null) {
              $ok = upsert_unresolved_match(
                $mysqli,
                $uploadDate,
                $ticketNumber,
                $payoutDate,
                $truckRaw,
                $truckDigits,
                'delivery',
                $pickupFirst,
                $pickupLast,
                $deliveryFirst,
                $deliveryLast,
                $deliveryPayAsString
              );
              if (!$ok) {
                throw new RuntimeException('Failed to enqueue delivery unresolved match for split row.');
              }
            }
          }
        } catch (Throwable $e) {
          $errors[] = $e->getMessage();
        }

        $ins->close();

        if (empty($errors)) {
          $mysqli->commit();
          unset($splitRows[$splitKey]);
          $splitRows = array_values($splitRows);
          foreach ($splitRows as $k => &$rowRef) {
            $rowRef['row_index'] = $k;
          }
          unset($rowRef);
          $_SESSION['split_rows'] = $splitRows;
          $success = true;
          $splitResolveSummary = "Split payout saved. {$insertCount} payout row(s) inserted.";
        } else {
          $mysqli->rollback();
        }
      }
    }
  }
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'resolve')) {
  $idx          = isset($_POST['row_index']) ? (int)$_POST['row_index'] : -1; // session-based unresolved
  $chosenId     = (int)($_POST['contact_id'] ?? 0);
  $markAsNew    = isset($_POST['mark_new']);
  $useRole      = $_SESSION['use_role'] ?? 'pickup'; // pickup|delivery
  $today        = $_SESSION['upload_date'] ?? tss_today();

  // These hidden fields are for DB-backed unresolved (from unresolved.php modal)
  $u_upload     = $_POST['upload_date']  ?? null;
  $u_delivery   = $_POST['delivery_date']?? null;
  $u_ticket     = $_POST['truckload_id'] ?? null;
  $u_useRole    = in_array($_POST['use_role'] ?? 'pickup', ['pickup','delivery'], true) ? $_POST['use_role'] : 'pickup';

  // Prefer DB-backed resolution if identifiers provided
  if ($u_upload && $u_delivery && $u_ticket) {
    // Pull unresolved row from DB
    $stmt = $mysqli->prepare("
      SELECT *
        FROM ls_unresolved_matches
       WHERE upload_date=? AND delivery_date=? AND truckload_id=? AND use_role=? AND status='unresolved'
       LIMIT 1
    ");
    $stmt->bind_param('ssss', $u_upload, $u_delivery, $u_ticket, $u_useRole);
    $stmt->execute();
    $rowU = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$rowU) {
      $errors[] = 'Unresolved record not found or already resolved.';
    } else {
      $driverFirst = ($rowU['use_role']==='delivery') ? ($rowU['delivery_first_name'] ?? '') : ($rowU['pickup_first_name'] ?? '');
      $driverLast  = ($rowU['use_role']==='delivery') ? ($rowU['delivery_last_name'] ?? '') : ($rowU['pickup_last_name'] ?? '');
      $driverName  = trim($driverFirst.' '.$driverLast);
      $vendor      = 'TSS';
      $splitVendor = (($rowU['use_role'] ?? 'pickup') === 'delivery') ? 'TSS Split (Delivery)' : 'TSS Split (Pickup)';

      $cid = $markAsNew ? null : ($chosenId ?: null);
      $splitId = null;
      $qSplit = $mysqli->prepare(
        "SELECT id
           FROM driver_payouts
          WHERE payout_date=? AND ticket_number=? AND upload_date=? AND vendor_name=?
          ORDER BY id DESC
          LIMIT 1"
      );
      if ($qSplit) {
        $qSplit->bind_param('ssss', $rowU['delivery_date'], $u_ticket, $u_upload, $splitVendor);
        $qSplit->execute();
        $qSplit->bind_result($sid);
        if ($qSplit->fetch()) $splitId = (int)$sid;
        $qSplit->close();
      }
      if ($splitId) {
        $updP = $mysqli->prepare("UPDATE driver_payouts SET driver_contact_id=? WHERE id=? LIMIT 1");
        if ($updP) {
          $updP->bind_param('ii', $cid, $splitId);
          if (!$updP->execute()) $errors[]='Split payout update error: '.$updP->error;
          $updP->close();
        }
      } else {
        $ins = $mysqli->prepare("
          INSERT IGNORE INTO driver_payouts
          (payout_date, ticket_number, driver_name, vendor_name, tss_pay, upload_date, driver_contact_id)
          VALUES (?,?,?,?,?,?,?)
        ");
        $ins->bind_param('ssssssi', $rowU['delivery_date'], $u_ticket, $driverName, $vendor, $rowU['calc_rate'], $u_upload, $cid);
        if (!$ins->execute()) {
          $errors[]='Insert payout error: '.$ins->error;
        }
        $ins->close();
      }

      if (empty($errors)) {
        // Update ls_detail_raw for auditing
        if (!$markAsNew && $chosenId) {
          $updR = $mysqli->prepare("
            UPDATE ls_detail_raw
               SET matched_contact_id = ?
             WHERE `Truckload ID` = ? AND `Delivery Date` = ? AND `upload_date` = ?
             LIMIT 1
          ");
          if ($updR) {
            $updR->bind_param('isss', $chosenId, $u_ticket, $rowU['delivery_date'], $u_upload);
            $updR->execute(); $updR->close();
          }
        }
        // Mark unresolved resolved
        $updU = $mysqli->prepare("
          UPDATE ls_unresolved_matches
             SET matched_contact_id=?, status='resolved', resolved_at=NOW()
           WHERE upload_date=? AND delivery_date=? AND truckload_id=? AND use_role=?
           LIMIT 1
        ");
        if ($updU) {
          $updU->bind_param('issss', $cid, $u_upload, $rowU['delivery_date'], $u_ticket, $u_useRole);
          $updU->execute(); $updU->close();
        }
        $success = true;
      }
    }
  } else {
    // Session-backed resolution (from upload page)
    if (!isset($_SESSION['unmatched_rows'][$idx])) {
      $errors[] = 'Unable to locate unmatched row. Please re-upload.';
    } else {
      $rowPkg = $_SESSION['unmatched_rows'][$idx];
      $hdr    = $rowPkg['headers'];
      $cells  = $rowPkg['cells'];
      $useRole = $rowPkg['use_role'] ?? $useRole;

      $iDeliveryDate = array_search('Delivery Date', $hdr, true);
      $iTicket       = array_search('Truckload ID', $hdr, true);
      $iCalcRate     = array_search('Calculated Freight Rate (Carrier)', $hdr, true);
      $iPF = array_search('Pickup Driver First Name', $hdr, true);
      $iPL = array_search('Pickup Driver Last Name',  $hdr, true);
      $iDF = array_search('Delivery Driver First Name', $hdr, true);
      $iDL = array_search('Delivery Driver Last Name',  $hdr, true);

      $driverFirst = $useRole === 'delivery' ? ($cells[$iDF] ?? '') : ($cells[$iPF] ?? '');
      $driverLast  = $useRole === 'delivery' ? ($cells[$iDL] ?? '') : ($cells[$iPL] ?? '');

      $payoutDate   = parse_any_date($cells[$iDeliveryDate] ?? '', TSS_SOURCE_TIMEZONE) ?: $today;
      $ticketNumber = (string)($cells[$iTicket] ?? '');
      $tssPay       = (string)($cells[$iCalcRate] ?? '');
      $driverName   = trim($driverFirst . ' ' . $driverLast);
      $vendor       = 'TSS';

      $ins = $mysqli->prepare("
        INSERT IGNORE INTO driver_payouts
        (payout_date, ticket_number, driver_name, vendor_name, tss_pay, upload_date, driver_contact_id)
        VALUES (?,?,?,?,?,?,?)
      ");
      if (!$ins) {
        $errors[] = 'Resolve prepare error: ' . $mysqli->error;
      } else {
        $cid = $markAsNew ? null : ($chosenId ?: null);
        $ins->bind_param('ssssssi', $payoutDate, $ticketNumber, $driverName, $vendor, $tssPay, $today, $cid);
        if (!$ins->execute()) {
          $errors[] = 'Resolve insert error: ' . $ins->error;
        } else {
          // Update raw row audit
          if (!$markAsNew && $chosenId) {
            $upd = $mysqli->prepare("
              UPDATE ls_detail_raw
                 SET matched_contact_id = ?
               WHERE `Truckload ID` = ? AND `Delivery Date` = ? AND `upload_date` = ?
               LIMIT 1
            ");
            if ($upd) {
              $upd->bind_param('isss', $chosenId, $ticketNumber, $payoutDate, $today);
              $upd->execute(); $upd->close();
            }
          }
          // Save alias for matched contact
          if (!$markAsNew && $chosenId && $driverFirst !== '' && $driverLast !== '') {
            $insAlias = $mysqli->prepare(
              "INSERT IGNORE INTO driver_name_aliases (driver_contact_id, alias_first_name, alias_last_name)
               VALUES (?,?,?)"
            );
            if ($insAlias) {
              $insAlias->bind_param('iss', $chosenId, $driverFirst, $driverLast);
              $insAlias->execute();
              $insAlias->close();
            }
          }
          // Also mark DB unresolved if exists (best effort)
          $updU = $mysqli->prepare("
            UPDATE ls_unresolved_matches
               SET matched_contact_id = ?, status='resolved', resolved_at=NOW()
             WHERE upload_date = ? AND truckload_id = ? AND delivery_date = ?
             LIMIT 1
          ");
          if ($updU) {
            $cid2 = $markAsNew ? null : ($chosenId ?: null);
            $updU->bind_param('isss', $cid2, $today, $ticketNumber, $payoutDate);
            $updU->execute(); $updU->close();
          }

          unset($_SESSION['unmatched_rows'][$idx]);
          $success = true;
        }
        $ins->close();
      }
    }
  }
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'save_misc_adjustment')) {
  $lastUploadType = 'misc_adjustment';
  $adjustmentVendor = strtoupper(trim((string)($_POST['adjustment_vendor'] ?? 'TSS')));
  if (!in_array($adjustmentVendor, ['TSS', 'RTEX', 'NEXTIER', 'NICKELROCK'], true)) {
    $adjustmentVendor = 'TSS';
  }
  if ($adjustmentVendor === 'RTEX') {
    $lastUploadType = 'rtex_misc_adjustment';
  } elseif ($adjustmentVendor === 'NEXTIER') {
    $lastUploadType = 'nextier_misc_adjustment';
  } elseif ($adjustmentVendor === 'NICKELROCK') {
    $lastUploadType = 'nickelrock_misc_adjustment';
  }
  $submittedAdjustmentForm = [
    'driver_contact_id' => trim((string)($_POST['driver_contact_id'] ?? '')),
    'payout_week_start' => trim((string)($_POST['payout_week_start'] ?? '')),
    'adjustment_type' => trim((string)($_POST['adjustment_type'] ?? 'misc_payment')),
    'amount' => trim((string)($_POST['amount'] ?? '')),
    'comments' => trim((string)($_POST['comments'] ?? '')),
    'allow_without_daily_uploads' => isset($_POST['allow_without_daily_uploads']),
  ];
  if ($adjustmentVendor === 'RTEX') {
    $rtexMiscAdjustmentForm = $submittedAdjustmentForm;
  } elseif ($adjustmentVendor === 'NEXTIER') {
    $nextierMiscAdjustmentForm = $submittedAdjustmentForm;
  } elseif ($adjustmentVendor === 'NICKELROCK') {
    $nickelrockMiscAdjustmentForm = $submittedAdjustmentForm;
  } else {
    $miscAdjustmentForm = $submittedAdjustmentForm;
  }

  $allowWithoutDailyUploads = !empty($submittedAdjustmentForm['allow_without_daily_uploads']);
  if ($adjustmentVendor === 'TSS' && (!$hasLsDetailForToday || !$hasTssPayoutForToday) && !$allowWithoutDailyUploads) {
    $errors[] = 'Miscellaneous Payment Adjustments are only available after both LS Detail and TSS Payout files have been uploaded for today.';
  }

  $driverContactId = (int)$submittedAdjustmentForm['driver_contact_id'];
  $payoutWeekStart = $submittedAdjustmentForm['payout_week_start'];
  $adjustmentType = $submittedAdjustmentForm['adjustment_type'];
  $amount = parse_money($submittedAdjustmentForm['amount']);
  $comments = $submittedAdjustmentForm['comments'];

  if ($driverContactId <= 0) {
    $errors[] = 'Select a driver for the miscellaneous adjustment.';
  }
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payoutWeekStart)) {
    $errors[] = 'Select a valid payout week.';
  }
  if (!in_array($adjustmentType, ['misc_payment', 'misc_deduction'], true)) {
    $errors[] = 'Select a valid adjustment type.';
  }
  if ($amount <= 0) {
    $errors[] = 'Enter an adjustment amount greater than 0.';
  }
  if ($comments === '') {
    $errors[] = 'Enter comments explaining the payment or deduction.';
  }

  if (empty($errors)) {
    $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $stmt = $mysqli->prepare("
      INSERT INTO tss_misc_adjustments
      (payout_vendor, source_upload_date, payout_week_start, driver_contact_id, adjustment_type, amount, comments, created_by)
      VALUES (?,?,?,?,?,?,?,?)
    ");
    if (!$stmt) {
      $errors[] = 'Unable to save miscellaneous adjustment: ' . $mysqli->error;
    } else {
      $stmt->bind_param(
        'sssisdsi',
        $adjustmentVendor,
        $todayDate,
        $payoutWeekStart,
        $driverContactId,
        $adjustmentType,
        $amount,
        $comments,
        $createdBy
      );
      if ($stmt->execute()) {
        $success = true;
        $resetAdjustmentForm = [
          'driver_contact_id' => '',
          'payout_week_start' => business_sunday_week_start($todayDate),
          'adjustment_type' => 'misc_payment',
          'amount' => '',
          'comments' => '',
          'allow_without_daily_uploads' => false,
        ];
        if ($adjustmentVendor === 'RTEX') {
          $rtexMiscAdjustmentForm = $resetAdjustmentForm;
        } elseif ($adjustmentVendor === 'NEXTIER') {
          $nextierMiscAdjustmentForm = $resetAdjustmentForm;
        } elseif ($adjustmentVendor === 'NICKELROCK') {
          $nickelrockMiscAdjustmentForm = $resetAdjustmentForm;
        } else {
          $miscAdjustmentForm = $resetAdjustmentForm;
        }
      } else {
        $errors[] = 'Unable to save miscellaneous adjustment: ' . $stmt->error;
      }
      $stmt->close();
    }
  }
}

require __DIR__ . '/includes/rtex_load_actions.php';

if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'delete_nickelrock_job_rate')) {
  $lastUploadType = 'nickelrock_review';
  $jobRateId = (int)($_POST['job_rate_id'] ?? 0);
  if ($jobRateId <= 0) {
    $errors[] = 'Unable to locate that Nickel Rock job rate.';
  } else {
    try {
      $stmt = $mysqli->prepare("DELETE FROM nickelrock_job_rates WHERE id = ? LIMIT 1");
      $stmt->bind_param('i', $jobRateId);
      $stmt->execute();
      $deleted = $stmt->affected_rows;
      $stmt->close();
      if ($deleted > 0) {
        $success = true;
        $nickelrockPayoutSummary = ['review_message' => 'Nickel Rock job rate deleted. Existing payout rows were not changed.'];
      } else {
        $errors[] = 'Unable to locate that Nickel Rock job rate.';
      }
    } catch (Throwable $e) {
      $errors[] = 'Unable to delete the Nickel Rock job rate: ' . $e->getMessage();
    }
  }
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'save_nickelrock_job_rate')) {
  $lastUploadType = 'nickelrock_review';
  $jobRateId = (int)($_POST['job_rate_id'] ?? 0);
  $jobRateName = trim((string)($_POST['job_name'] ?? ''));
  $jobRateAmount = round(parse_money($_POST['rate'] ?? 0), 2);
  $jobRateWorkOrder = trim((string)($_POST['work_order'] ?? ''));
  if ($jobRateName === '') $errors[] = 'Enter a Nickel Rock job name.';
  if ($jobRateAmount <= 0) $errors[] = 'Enter a Nickel Rock job rate greater than zero.';
  if ($jobRateWorkOrder === '') $errors[] = 'Enter a Nickel Rock work order number.';
  if (empty($errors)) {
    try {
      if ($jobRateId > 0) {
        $oldJobName = '';
        $findJob = $mysqli->prepare("SELECT job_name FROM nickelrock_job_rates WHERE id = ? LIMIT 1");
        $findJob->bind_param('i', $jobRateId);
        $findJob->execute();
        $findJob->bind_result($oldJobNameRaw);
        if ($findJob->fetch()) $oldJobName = (string)$oldJobNameRaw;
        $findJob->close();
        $stmt = $mysqli->prepare("UPDATE nickelrock_job_rates SET job_name = ?, rate = ?, work_order = ? WHERE id = ? LIMIT 1");
        $stmt->bind_param('sdsi', $jobRateName, $jobRateAmount, $jobRateWorkOrder, $jobRateId);
        $stmt->execute();
        $stmt->close();
        if ($oldJobName !== '' && strcasecmp($oldJobName, $jobRateName) !== 0) {
          $renameRows = $mysqli->prepare("UPDATE nickelrock_payout_rows SET job_name = ? WHERE job_name = ?");
          $renameRows->bind_param('ss', $jobRateName, $oldJobName);
          $renameRows->execute();
          $renameRows->close();
        }
      } else {
        $stmt = $mysqli->prepare("
          INSERT INTO nickelrock_job_rates (job_name, rate, work_order)
          VALUES (?, ?, ?)
          ON DUPLICATE KEY UPDATE rate = VALUES(rate), work_order = VALUES(work_order)
        ");
        $stmt->bind_param('sds', $jobRateName, $jobRateAmount, $jobRateWorkOrder);
        $stmt->execute();
        $stmt->close();
      }
      $success = true;
      $nickelrockPayoutSummary = ['review_message' => 'Nickel Rock job rate saved.'];
    } catch (Throwable $e) {
      $errors[] = 'Unable to save the Nickel Rock job rate: ' . $e->getMessage();
    }
  }
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'import_nickelrock_bols')) {
  $lastUploadType = 'nickelrock_review';
  $driverCtx = build_driver_match_context($mysqli);
  $defaults = [
    'provider_name' => trim((string)($_POST['provider_name_default'] ?? 'Nickel Rock - Stonehenge Pit')),
    'job_name' => trim((string)($_POST['job_name_default'] ?? '')),
    'rate' => trim((string)($_POST['rate_default'] ?? '9.00')),
    'work_order' => trim((string)($_POST['work_order_default'] ?? '53405')),
    'vendor_number' => trim((string)($_POST['vendor_number_default'] ?? '4138')),
  ];
  $defaultJobDetails = get_nickelrock_job_details($mysqli, (string)$defaults['job_name']);
  if ($defaultJobDetails !== null) {
    $defaults['rate'] = number_format((float)$defaultJobDetails['rate'], 2, '.', '');
    $defaults['work_order'] = (string)$defaultJobDetails['work_order'];
  }
  $files = $_FILES['nickelrock_bol_files'] ?? null;
  $warnings = [];
  $imported = 0;
  $synced = 0;
  if (!$files || empty($files['name']) || !is_array($files['name'])) {
    $errors[] = 'Select one or more Nickel Rock BOL image or PDF files.';
  } else {
    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
      $name = (string)($files['name'][$i] ?? '');
      $tmp = (string)($files['tmp_name'][$i] ?? '');
      $err = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
      if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        if ($err !== UPLOAD_ERR_NO_FILE) $warnings[] = "{$name}: upload failed.";
        continue;
      }
      $text = nickelrock_ocr_file_text($tmp, $name, $warnings);
      if (trim($text) === '') continue;
      $parsed = nickelrock_parse_bol_text($text, $defaults);
      $missing = [];
      if ($parsed['work_date'] === '') $missing[] = 'date';
      if ($parsed['ticket_number'] === '') $missing[] = 'ticket';
      if ((float)$parsed['tons'] <= 0) $missing[] = 'net tons';
      if ($missing) {
        $warnings[] = "{$name}: missing " . implode(', ', $missing) . ".";
        continue;
      }

      $truckDigits = digits_only($parsed['truck_raw'] ?? '');
      $contactId = resolve_driver_contact_from_context($driverCtx, '', $truckDigits);
      $driverName = $contactId ? driver_contact_name($mysqli, (int)$contactId) : '';
      $workDate = (string)$parsed['work_date'];
      $providerName = (string)$parsed['provider_name'];
      $jobName = (string)($defaults['job_name'] ?? '');
      $ticketNumber = (string)$parsed['ticket_number'];
      $tons = (float)$parsed['tons'];
      $truckRaw = (string)$parsed['truck_raw'];
      $rate = (float)$parsed['rate'];
      $workOrder = (string)$parsed['work_order'];
      $vendorNumber = (string)$parsed['vendor_number'];
      $totalAmount = round($tons * $rate, 2);
      $today = tss_today();
      $stmt = $mysqli->prepare("
        INSERT INTO nickelrock_payout_rows
        (upload_date, work_date, provider_name, job_name, ticket_number, tons, driver_name, truck_raw, truck_digits, rate, total_amount, work_order, vendor_number, matched_contact_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          provider_name=VALUES(provider_name),
          job_name=VALUES(job_name),
          tons=VALUES(tons),
          driver_name=VALUES(driver_name),
          truck_raw=VALUES(truck_raw),
          truck_digits=VALUES(truck_digits),
          rate=VALUES(rate),
          total_amount=VALUES(total_amount),
          work_order=VALUES(work_order),
          vendor_number=VALUES(vendor_number),
          matched_contact_id=VALUES(matched_contact_id)
      ");
      if (!$stmt) {
        $warnings[] = "{$name}: unable to prepare insert.";
        continue;
      }
      $contactIdBind = $contactId ?: null;
      $stmt->bind_param(
        'sssssdsssddssi',
        $today,
        $workDate,
        $providerName,
        $jobName,
        $ticketNumber,
        $tons,
        $driverName,
        $truckRaw,
        $truckDigits,
        $rate,
        $totalAmount,
        $workOrder,
        $vendorNumber,
        $contactIdBind
      );
      if ($stmt->execute()) {
        $imported++;
        if ($contactId && $driverName !== '') {
          sync_nickelrock_driver_payout($mysqli, [
            'work_date' => $workDate,
            'ticket_number' => $ticketNumber,
            'driver_name' => $driverName,
            'tons' => $tons,
            'rate' => $rate,
            'total_amount' => $totalAmount,
            'matched_contact_id' => $contactId,
          ]);
          $synced++;
        } else {
          $warnings[] = "{$name}: imported but driver was not matched by truck.";
        }
      } else {
        $warnings[] = "{$name}: insert failed - {$stmt->error}";
      }
      $stmt->close();
    }
  }

  if ($imported > 0) {
    $success = true;
    $nickelrockPayoutSummary = [
      'review_message' => "{$imported} Nickel Rock BOL" . ($imported === 1 ? '' : 's') . " imported. {$synced} synced to driver payouts."
        . ($warnings ? ' ' . implode(' ', array_slice($warnings, 0, 4)) : ''),
    ];
  } elseif (empty($errors)) {
    $errors[] = 'No Nickel Rock BOL rows could be imported. ' . implode(' ', array_slice($warnings, 0, 4));
  }
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'add_rtex_row')) {
  $lastUploadType = 'rtex_review';
  $rtexWeekStart = trim((string)($_POST['rtex_week_start'] ?? ''));
  $workDate = trim((string)($_POST['work_date'] ?? ''));
  $ticketNumber = trim((string)($_POST['ticket_number'] ?? ''));
  $driverName = trim((string)($_POST['driver_name'] ?? ''));
  $truckRaw = trim((string)($_POST['truck_raw'] ?? ''));
  $jobNumber = trim((string)($_POST['job_number'] ?? ''));
  $startTime = rtex_excel_time($_POST['start_time'] ?? '');
  $endTime = rtex_excel_time($_POST['end_time'] ?? '');
  $hours = round(parse_money($_POST['hours'] ?? 0), 2);
  $rate = round(parse_money($_POST['rate'] ?? 0), 2);
  $totalAmount = round(parse_money($_POST['total_amount'] ?? 0), 2);
  $baseRateReductionPct = parse_percent_number($_POST['rtex_base_rate_reduction_pct'] ?? 0);
  if ($baseRateReductionPct === null) $baseRateReductionPct = 0.0;
  $baseRateReductionPct = max(0.0, min(100.0, (float)$baseRateReductionPct));
  $driverContactId = (int)($_POST['matched_contact_id'] ?? 0);

  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) $errors[] = 'Enter a valid RTEX work date.';
  if ($driverContactId > 0) {
    $contactName = driver_contact_name($mysqli, $driverContactId);
    if ($contactName !== '') $driverName = $contactName;
  }
  if ($driverName === '') $errors[] = 'Enter or select a driver.';
  if ($truckRaw === '') $errors[] = 'Enter a truck.';
  if ($hours <= 0) $errors[] = 'Enter hours greater than 0.';
  if ($totalAmount <= 0 && $hours > 0 && $rate > 0) {
    $totalAmount = round($hours * $rate, 2);
  }
  if ($totalAmount <= 0) $errors[] = 'Enter an RTEX total amount or a rate that can calculate one.';
  if (empty($errors)) {
    [$rate, $totalAmount] = rtex_apply_base_rate_reduction($rate, $hours, $totalAmount, $baseRateReductionPct);
  }

  if (empty($errors)) {
    $today = tss_today();
    $truckDigits = digits_only($truckRaw);
    if ($ticketNumber === '') {
      $ticketNumber = 'RTEX-' . ($jobNumber !== '' ? $jobNumber : 'JOB') . '-' . ($truckDigits !== '' ? $truckDigits : 'TRUCK') . '-' . str_replace('-', '', $workDate) . '-manual-' . time();
    }
    $sourceLineNo = 1;
    $lineStmt = $mysqli->prepare("SELECT COALESCE(MAX(source_line_no), 0) + 1 FROM rtex_payout_rows WHERE upload_date = ? AND sheet_name = 'Manual Entry'");
    if ($lineStmt) {
      $lineStmt->bind_param('s', $today);
      $lineStmt->execute();
      $lineStmt->bind_result($nextLineNo);
      if ($lineStmt->fetch()) $sourceLineNo = (int)$nextLineNo;
      $lineStmt->close();
    }
    $sheetName = 'Manual Entry';
    $invoiceDate = $workDate;
    $surchargeAmount = 0.0;
    $contactIdBind = $driverContactId > 0 ? $driverContactId : null;
    $stmt = $mysqli->prepare("
      INSERT INTO rtex_payout_rows
      (upload_date, invoice_date, sheet_name, source_line_no, work_date, driver_name, truck_raw, truck_digits,
       job_number, ticket_number, start_time, end_time, hours, rate, total_amount, surcharge_amount, matched_contact_id)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    if (!$stmt) {
      $errors[] = 'Unable to add RTEX row: ' . $mysqli->error;
    } else {
      $stmt->bind_param(
        'sssissssssssddddi',
        $today,
        $invoiceDate,
        $sheetName,
        $sourceLineNo,
        $workDate,
        $driverName,
        $truckRaw,
        $truckDigits,
        $jobNumber,
        $ticketNumber,
        $startTime,
        $endTime,
        $hours,
        $rate,
        $totalAmount,
        $surchargeAmount,
        $contactIdBind
      );
      if (!$stmt->execute()) {
        $errors[] = 'Unable to add RTEX row: ' . $stmt->error;
      }
      $stmt->close();
    }

    if (empty($errors)) {
      $vendor = 'RTEX';
      $payAsString = number_format($totalAmount, 2, '.', '');
      $ins = $mysqli->prepare("
        INSERT INTO driver_payouts
        (payout_date, ticket_number, driver_name, vendor_name, tss_pay, upload_date, driver_contact_id)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          driver_name=VALUES(driver_name),
          tss_pay=VALUES(tss_pay),
          upload_date=VALUES(upload_date),
          driver_contact_id=VALUES(driver_contact_id)
      ");
      if ($ins) {
        $ins->bind_param('ssssssi', $workDate, $ticketNumber, $driverName, $vendor, $payAsString, $today, $contactIdBind);
        if (!$ins->execute()) $errors[] = 'Unable to add RTEX payout row: ' . $ins->error;
        $ins->close();
      }
    }

    if (empty($errors)) {
      $success = true;
      $rtexPayoutSummary = ['review_message' => 'RTEX row added.'];
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rtexWeekStart)) {
        $rtexWeekStart = business_sunday_week_start($workDate);
      }
    }
  }
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'export_rtex_invoice')) {
  $weekStartExport = trim((string)($_POST['rtex_week_start'] ?? ''));
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStartExport)) {
    $weekStartExport = business_sunday_week_start(tss_today());
  }
  $exportRows = array_values(array_filter(get_rtex_review_rows($mysqli, $weekStartExport), static fn($row) => ($row['billing_mode'] ?? 'hourly') === 'hourly'));
  if (!$exportRows) {
    $errors[] = 'No RTEX rows were found for that week.';
  } else {
    $content = build_rtex_invoice_xlsx($exportRows);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename=rtex_invoice_' . str_replace('-', '', $weekStartExport) . '.xlsx');
    echo $content;
    exit;
  }
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && in_array(($_POST['action'] ?? ''), ['add_nickelrock_row', 'save_nickelrock_row'], true)) {
  $isAdd = ($_POST['action'] ?? '') === 'add_nickelrock_row';
  $lastUploadType = 'nickelrock_review';
  $rowId = (int)($_POST['nickelrock_row_id'] ?? 0);
  $nickelrockWeekStart = trim((string)($_POST['nickelrock_week_start'] ?? ''));
  $workDate = trim((string)($_POST['work_date'] ?? ''));
  $providerName = trim((string)($_POST['provider_name'] ?? ''));
  $jobName = trim((string)($_POST['job_name'] ?? ''));
  $ticketNumber = trim((string)($_POST['ticket_number'] ?? ''));
  $tons = round(parse_money($_POST['tons'] ?? 0), 2);
  $driverName = trim((string)($_POST['driver_name'] ?? ''));
  $truckRaw = trim((string)($_POST['truck_raw'] ?? ''));
  $rate = round(parse_money($_POST['rate'] ?? 0), 2);
  $totalAmount = round(parse_money($_POST['total_amount'] ?? 0), 2);
  $workOrder = trim((string)($_POST['work_order'] ?? ''));
  $vendorNumber = trim((string)($_POST['vendor_number'] ?? ''));
  $driverContactId = (int)($_POST['matched_contact_id'] ?? 0);

  if (!$isAdd && $rowId <= 0) $errors[] = 'Unable to locate the Nickel Rock row to update.';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) $errors[] = 'Enter a valid Nickel Rock delivery date.';
  if ($ticketNumber === '') $errors[] = 'Enter a ticket number.';
  if ($providerName === '') $errors[] = 'Enter a provider name.';
  if ($jobName === '') $errors[] = 'Enter a job name.';
  $keyJobDetails = $jobName !== '' ? get_nickelrock_job_details($mysqli, $jobName) : null;
  if ($jobName !== '' && $keyJobDetails === null) {
    $errors[] = 'Select a job from the Nickel Rock Job Rate Key.';
  } elseif ($keyJobDetails !== null) {
    $rate = (float)$keyJobDetails['rate'];
    $workOrder = (string)$keyJobDetails['work_order'];
  }
  if ($driverContactId > 0) {
    $contactName = driver_contact_name($mysqli, $driverContactId);
    if ($contactName !== '') $driverName = $contactName;
  }
  if ($driverName === '') $errors[] = 'Enter or select a driver.';
  // Nickel Rock pay is derived from tonnage and rate. Always recalculate it
  // when a row is saved so edits cannot retain a stale total_amount value.
  $totalAmount = round($tons * $rate, 2);

  if (empty($errors)) {
    $truckDigits = digits_only($truckRaw);
    $contactIdBind = $driverContactId > 0 ? $driverContactId : null;
    $today = tss_today();
    $old = null;
    if (!$isAdd) {
      $stmtOld = $mysqli->prepare("SELECT * FROM nickelrock_payout_rows WHERE id = ? LIMIT 1");
      if ($stmtOld) {
        $stmtOld->bind_param('i', $rowId);
        $stmtOld->execute();
        $resOld = $stmtOld->get_result();
        $old = $resOld ? $resOld->fetch_assoc() : null;
        $stmtOld->close();
      }
      if (!$old) $errors[] = 'Unable to locate the Nickel Rock row to update.';
    }

    if (empty($errors)) {
      if ($isAdd) {
        $stmt = $mysqli->prepare("
          INSERT INTO nickelrock_payout_rows
          (upload_date, work_date, provider_name, job_name, ticket_number, tons, driver_name, truck_raw, truck_digits, rate, total_amount, work_order, vendor_number, matched_contact_id)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE
            provider_name=VALUES(provider_name),
            job_name=VALUES(job_name),
            tons=VALUES(tons),
            driver_name=VALUES(driver_name),
            truck_raw=VALUES(truck_raw),
            truck_digits=VALUES(truck_digits),
            rate=VALUES(rate),
            total_amount=VALUES(total_amount),
            work_order=VALUES(work_order),
            vendor_number=VALUES(vendor_number),
            matched_contact_id=VALUES(matched_contact_id)
        ");
        if ($stmt) $stmt->bind_param('sssssdsssddssi', $today, $workDate, $providerName, $jobName, $ticketNumber, $tons, $driverName, $truckRaw, $truckDigits, $rate, $totalAmount, $workOrder, $vendorNumber, $contactIdBind);
      } else {
        $stmt = $mysqli->prepare("
          UPDATE nickelrock_payout_rows
             SET work_date = ?,
                 provider_name = ?,
                 job_name = ?,
                 ticket_number = ?,
                 tons = ?,
                 driver_name = ?,
                 truck_raw = ?,
                 truck_digits = ?,
                 rate = ?,
                 total_amount = ?,
                 work_order = ?,
                 vendor_number = ?,
                 matched_contact_id = ?
           WHERE id = ?
           LIMIT 1
        ");
        if ($stmt) $stmt->bind_param('ssssdsssddssii', $workDate, $providerName, $jobName, $ticketNumber, $tons, $driverName, $truckRaw, $truckDigits, $rate, $totalAmount, $workOrder, $vendorNumber, $contactIdBind, $rowId);
      }
      if (!$stmt || !$stmt->execute()) {
        $errors[] = 'Unable to save Nickel Rock row: ' . ($stmt ? $stmt->error : $mysqli->error);
      }
      if ($stmt) $stmt->close();

      if (empty($errors)) {
        if ($old) {
          $del = $mysqli->prepare("
            DELETE FROM driver_payouts
             WHERE payout_date = ?
               AND ticket_number = ?
               AND vendor_name = 'Nickel Rock'
               AND upload_date = ?
               AND driver_name = ?
             LIMIT 1
          ");
          if ($del) {
            $del->bind_param('ssss', $old['work_date'], $old['ticket_number'], $old['upload_date'], $old['driver_name']);
            $del->execute();
            $del->close();
          }
        }
        sync_nickelrock_driver_payout($mysqli, [
          'work_date' => $workDate,
          'ticket_number' => $ticketNumber,
          'driver_name' => $driverName,
          'tons' => $tons,
          'rate' => $rate,
          'total_amount' => $totalAmount,
          'matched_contact_id' => $contactIdBind,
        ]);
        $success = true;
        $nickelrockPayoutSummary = ['review_message' => $isAdd ? 'Nickel Rock row added.' : 'Nickel Rock row updated.'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $nickelrockWeekStart)) {
          $nickelrockWeekStart = business_sunday_week_start($workDate);
        }
      }
    }
  }
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && in_array(($_POST['action'] ?? ''), ['delete_nickelrock_row', 'delete_nickelrock_rows'], true)) {
  $lastUploadType = 'nickelrock_review';
  $nickelrockWeekStart = trim((string)($_POST['nickelrock_week_start'] ?? ''));
  $rowIds = (($_POST['action'] ?? '') === 'delete_nickelrock_row')
    ? [(int)($_POST['nickelrock_row_id'] ?? 0)]
    : array_values(array_unique(array_filter(array_map('intval', $_POST['nickelrock_row_ids'] ?? []), static fn($id) => $id > 0)));
  $rowIds = array_values(array_filter($rowIds, static fn($id) => $id > 0));
  if (!$rowIds) {
    $errors[] = (($_POST['action'] ?? '') === 'delete_nickelrock_row') ? 'Unable to locate the Nickel Rock row to delete.' : 'Select at least one Nickel Rock row to delete.';
  } else {
    $deleted = 0;
    foreach ($rowIds as $rowId) {
      $old = null;
      $stmtOld = $mysqli->prepare("SELECT * FROM nickelrock_payout_rows WHERE id = ? LIMIT 1");
      if ($stmtOld) {
        $stmtOld->bind_param('i', $rowId);
        $stmtOld->execute();
        $resOld = $stmtOld->get_result();
        $old = $resOld ? $resOld->fetch_assoc() : null;
        $stmtOld->close();
      }
      if (!$old) continue;
      $delPayout = $mysqli->prepare("
        DELETE FROM driver_payouts
         WHERE payout_date = ?
           AND ticket_number = ?
           AND vendor_name = 'Nickel Rock'
           AND upload_date = ?
           AND driver_name = ?
         LIMIT 1
      ");
      if ($delPayout) {
        $delPayout->bind_param('ssss', $old['work_date'], $old['ticket_number'], $old['upload_date'], $old['driver_name']);
        $delPayout->execute();
        $delPayout->close();
      }
      $delRaw = $mysqli->prepare("DELETE FROM nickelrock_payout_rows WHERE id = ? LIMIT 1");
      if ($delRaw) {
        $delRaw->bind_param('i', $rowId);
        if ($delRaw->execute()) $deleted += max(0, (int)$delRaw->affected_rows);
        $delRaw->close();
      }
    }
    if ($deleted > 0) {
      $success = true;
      $nickelrockPayoutSummary = ['review_message' => $deleted . ' Nickel Rock row' . ($deleted === 1 ? '' : 's') . ' deleted.'];
    } else {
      $errors[] = 'No Nickel Rock rows were deleted.';
    }
  }
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'export_nickelrock_invoice')) {
  $weekStartExport = trim((string)($_POST['nickelrock_week_start'] ?? ''));
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStartExport)) {
    $weekStartExport = business_sunday_week_start(tss_today());
  }
  $exportRows = get_nickelrock_review_rows($mysqli, $weekStartExport);
  if (!$exportRows) {
    $errors[] = 'No Nickel Rock rows were found for that week.';
  } else {
    $rowsByJob = [];
    foreach ($exportRows as $exportRow) {
      $jobName = trim((string)($exportRow['job_name'] ?? ''));
      if ($jobName === '') $jobName = 'Unassigned Job';
      $jobKey = strtolower($jobName);
      if (!isset($rowsByJob[$jobKey])) {
        $rowsByJob[$jobKey] = ['name' => $jobName, 'rows' => []];
      }
      $rowsByJob[$jobKey]['rows'][] = $exportRow;
    }

    $zipPath = tempnam(sys_get_temp_dir(), 'nickelrock_jobs_');
    $zip = new ZipArchive();
    if ($zipPath === false || $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      $errors[] = 'Unable to create the Nickel Rock job invoice archive.';
      if ($zipPath !== false) @unlink($zipPath);
    } else {
      $usedFilenames = [];
      foreach ($rowsByJob as $jobGroup) {
        $safeJobName = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$jobGroup['name']), '_');
        if ($safeJobName === '') $safeJobName = 'Unassigned_Job';
        $baseFilename = 'nickel_rock_' . $safeJobName . '_' . str_replace('-', '', $weekStartExport);
        $filename = $baseFilename . '.xlsx';
        $copy = 2;
        while (isset($usedFilenames[strtolower($filename)])) {
          $filename = $baseFilename . '_' . $copy . '.xlsx';
          $copy++;
        }
        $usedFilenames[strtolower($filename)] = true;
        $zip->addFromString($filename, build_nickelrock_invoice_xlsx($jobGroup['rows']));
      }
      $zip->close();
      $content = file_get_contents($zipPath);
      @unlink($zipPath);
      if ($content === false) {
        $errors[] = 'Unable to read the Nickel Rock job invoice archive.';
      } else {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename=nickel_rock_invoices_' . str_replace('-', '', $weekStartExport) . '.zip');
        echo $content;
        exit;
      }
    }
  }
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'save_rtex_row')) {
  $lastUploadType = 'rtex_review';
  $rowId = (int)($_POST['rtex_row_id'] ?? 0);
  $rtexWeekStart = trim((string)($_POST['rtex_week_start'] ?? ''));
  $workDate = trim((string)($_POST['work_date'] ?? ''));
  $ticketNumber = trim((string)($_POST['ticket_number'] ?? ''));
  $driverName = trim((string)($_POST['driver_name'] ?? ''));
  $truckRaw = trim((string)($_POST['truck_raw'] ?? ''));
  $jobNumber = trim((string)($_POST['job_number'] ?? ''));
  $startTime = rtex_excel_time($_POST['start_time'] ?? '');
  $endTime = rtex_excel_time($_POST['end_time'] ?? '');
  $hours = round(parse_money($_POST['hours'] ?? 0), 2);
  $rate = round(parse_money($_POST['rate'] ?? 0), 2);
  $totalAmount = round(parse_money($_POST['total_amount'] ?? 0), 2);
  $baseRateReductionPct = parse_percent_number($_POST['rtex_base_rate_reduction_pct'] ?? 0);
  if ($baseRateReductionPct === null) $baseRateReductionPct = 0.0;
  $baseRateReductionPct = max(0.0, min(100.0, (float)$baseRateReductionPct));
  $driverContactId = (int)($_POST['matched_contact_id'] ?? 0);

  if ($rowId <= 0) $errors[] = 'Unable to locate the RTEX row to update.';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) $errors[] = 'Enter a valid RTEX work date.';
  if ($ticketNumber === '') $errors[] = 'Enter a ticket number.';
  if ($driverContactId > 0) {
    $contactName = driver_contact_name($mysqli, $driverContactId);
    if ($contactName !== '') $driverName = $contactName;
  }
  if ($driverName === '') $errors[] = 'Enter or select a driver.';
  if ($totalAmount <= 0 && $hours > 0 && $rate > 0) {
    $totalAmount = round($hours * $rate, 2);
  }
  if ($totalAmount <= 0) $errors[] = 'Enter an RTEX total amount or a rate that can calculate one.';
  if (empty($errors)) {
    [$rate, $totalAmount] = rtex_apply_base_rate_reduction($rate, $hours, $totalAmount, $baseRateReductionPct);
  }

  if (empty($errors)) {
    $old = null;
    $stmtOld = $mysqli->prepare("SELECT * FROM rtex_payout_rows WHERE id = ? AND billing_mode='hourly' LIMIT 1");
    if ($stmtOld) {
      $stmtOld->bind_param('i', $rowId);
      $stmtOld->execute();
      $resOld = $stmtOld->get_result();
      $old = $resOld ? $resOld->fetch_assoc() : null;
      $stmtOld->close();
    }
    if (!$old) {
      $errors[] = 'Unable to locate the RTEX row to update.';
    } else {
      $truckDigits = digits_only($truckRaw);
      $contactIdBind = $driverContactId > 0 ? $driverContactId : null;
      $stmt = $mysqli->prepare("
        UPDATE rtex_payout_rows
           SET work_date = ?,
               driver_name = ?,
               truck_raw = ?,
               truck_digits = ?,
               job_number = ?,
               ticket_number = ?,
               start_time = ?,
               end_time = ?,
               hours = ?,
               rate = ?,
               total_amount = ?,
               matched_contact_id = ?
         WHERE id = ?
         LIMIT 1
      ");
      if (!$stmt) {
        $errors[] = 'Unable to update RTEX row: ' . $mysqli->error;
      } else {
        $stmt->bind_param(
          'ssssssssdddii',
          $workDate,
          $driverName,
          $truckRaw,
          $truckDigits,
          $jobNumber,
          $ticketNumber,
          $startTime,
          $endTime,
          $hours,
          $rate,
          $totalAmount,
          $contactIdBind,
          $rowId
        );
        if (!$stmt->execute()) {
          $errors[] = 'Unable to update RTEX row: ' . $stmt->error;
        }
        $stmt->close();
      }

      if (empty($errors)) {
        $oldPayoutDate = (string)($old['work_date'] ?? '');
        $oldTicket = (string)($old['ticket_number'] ?? '');
        $oldUploadDate = (string)($old['upload_date'] ?? '');
        $oldDriverName = (string)($old['driver_name'] ?? '');
        $del = $mysqli->prepare("
          DELETE FROM driver_payouts
           WHERE payout_date = ?
             AND ticket_number = ?
             AND vendor_name = 'RTEX'
             AND upload_date = ?
             AND driver_name = ?
           LIMIT 1
        ");
        if ($del) {
          $del->bind_param('ssss', $oldPayoutDate, $oldTicket, $oldUploadDate, $oldDriverName);
          $del->execute();
          $del->close();
        }

        $ins = $mysqli->prepare("
          INSERT IGNORE INTO driver_payouts
          (payout_date, ticket_number, driver_name, vendor_name, tss_pay, upload_date, driver_contact_id)
          VALUES (?,?,?,?,?,?,?)
        ");
        if (!$ins) {
          $errors[] = 'Unable to update RTEX payout row: ' . $mysqli->error;
        } else {
          $vendor = 'RTEX';
          $payAsString = number_format($totalAmount, 2, '.', '');
          $ins->bind_param('ssssssi', $workDate, $ticketNumber, $driverName, $vendor, $payAsString, $oldUploadDate, $contactIdBind);
          if (!$ins->execute()) {
            $errors[] = 'Unable to update RTEX payout row: ' . $ins->error;
          }
          $ins->close();
        }
      }

      if (empty($errors)) {
        $success = true;
        $rtexPayoutSummary = ['review_message' => 'RTEX row updated.'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rtexWeekStart)) {
          $rtexWeekStart = business_sunday_week_start($workDate);
        }
      }
    }
  }
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'delete_rtex_row')) {
  $lastUploadType = 'rtex_review';
  $rowId = (int)($_POST['rtex_row_id'] ?? 0);
  $rtexWeekStart = trim((string)($_POST['rtex_week_start'] ?? ''));

  if ($rowId <= 0) {
    $errors[] = 'Unable to locate the RTEX row to delete.';
  } else {
    $old = null;
    $stmtOld = $mysqli->prepare("SELECT * FROM rtex_payout_rows WHERE id = ? AND billing_mode='hourly' LIMIT 1");
    if ($stmtOld) {
      $stmtOld->bind_param('i', $rowId);
      $stmtOld->execute();
      $resOld = $stmtOld->get_result();
      $old = $resOld ? $resOld->fetch_assoc() : null;
      $stmtOld->close();
    }

    if (!$old) {
      $errors[] = 'Unable to locate the RTEX row to delete.';
    } else {
      $oldPayoutDate = (string)($old['work_date'] ?? '');
      $oldTicket = (string)($old['ticket_number'] ?? '');
      $oldUploadDate = (string)($old['upload_date'] ?? '');
      $oldDriverName = (string)($old['driver_name'] ?? '');

      $delPayout = $mysqli->prepare("
        DELETE FROM driver_payouts
         WHERE payout_date = ?
           AND ticket_number = ?
           AND vendor_name = 'RTEX'
           AND upload_date = ?
           AND driver_name = ?
         LIMIT 1
      ");
      if ($delPayout) {
        $delPayout->bind_param('ssss', $oldPayoutDate, $oldTicket, $oldUploadDate, $oldDriverName);
        $delPayout->execute();
        $delPayout->close();
      }

      $delRaw = $mysqli->prepare("DELETE FROM rtex_payout_rows WHERE id = ? LIMIT 1");
      if (!$delRaw) {
        $errors[] = 'Unable to delete RTEX row: ' . $mysqli->error;
      } else {
        $delRaw->bind_param('i', $rowId);
        if ($delRaw->execute()) {
          $success = true;
          $rtexPayoutSummary = ['review_message' => 'RTEX row deleted.'];
          if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rtexWeekStart)) {
            $rtexWeekStart = business_sunday_week_start($oldPayoutDate);
          }
        } else {
          $errors[] = 'Unable to delete RTEX row: ' . $delRaw->error;
        }
        $delRaw->close();
      }
    }
  }
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'delete_rtex_rows')) {
  $lastUploadType = 'rtex_review';
  $rtexWeekStart = trim((string)($_POST['rtex_week_start'] ?? ''));
  $rowIds = array_values(array_unique(array_filter(array_map('intval', $_POST['rtex_row_ids'] ?? []), static fn($id) => $id > 0)));

  if (empty($rowIds)) {
    $errors[] = 'Select at least one RTEX row to delete.';
  } else {
    $deleted = 0;
    foreach ($rowIds as $rowId) {
      $old = null;
      $stmtOld = $mysqli->prepare("SELECT * FROM rtex_payout_rows WHERE id = ? AND billing_mode='hourly' LIMIT 1");
      if ($stmtOld) {
        $stmtOld->bind_param('i', $rowId);
        $stmtOld->execute();
        $resOld = $stmtOld->get_result();
        $old = $resOld ? $resOld->fetch_assoc() : null;
        $stmtOld->close();
      }
      if (!$old) continue;

      $oldPayoutDate = (string)($old['work_date'] ?? '');
      $oldTicket = (string)($old['ticket_number'] ?? '');
      $oldUploadDate = (string)($old['upload_date'] ?? '');
      $oldDriverName = (string)($old['driver_name'] ?? '');

      $delPayout = $mysqli->prepare("
        DELETE FROM driver_payouts
         WHERE payout_date = ?
           AND ticket_number = ?
           AND vendor_name = 'RTEX'
           AND upload_date = ?
           AND driver_name = ?
         LIMIT 1
      ");
      if ($delPayout) {
        $delPayout->bind_param('ssss', $oldPayoutDate, $oldTicket, $oldUploadDate, $oldDriverName);
        $delPayout->execute();
        $delPayout->close();
      }

      $delRaw = $mysqli->prepare("DELETE FROM rtex_payout_rows WHERE id = ? LIMIT 1");
      if ($delRaw) {
        $delRaw->bind_param('i', $rowId);
        if ($delRaw->execute()) $deleted += max(0, (int)$delRaw->affected_rows);
        $delRaw->close();
      }
    }

    if ($deleted > 0) {
      $success = true;
      $rtexPayoutSummary = ['review_message' => $deleted . ' RTEX row' . ($deleted === 1 ? '' : 's') . ' deleted.'];
    } else {
      $errors[] = 'No RTEX rows were deleted.';
    }
  }
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'save_nextier_row')) {
  $lastUploadType = 'nextier_review';
  $rowId = (int)($_POST['nextier_row_id'] ?? 0);
  $nextierWeekStart = trim((string)($_POST['nextier_week_start'] ?? ''));
  $workDate = trim((string)($_POST['work_date'] ?? ''));
  $wellName = trim((string)($_POST['well_name'] ?? ''));
  $loadId = trim((string)($_POST['load_id'] ?? ''));
  $loader = trim((string)($_POST['dispatched_loader'] ?? ''));
  $bolNumber = trim((string)($_POST['bol_number'] ?? ''));
  $weight = round(parse_money($_POST['weight'] ?? 0), 2);
  $tons = round(parse_money($_POST['tons'] ?? 0), 2);
  $truckingCo = trim((string)($_POST['trucking_co'] ?? ''));
  $driverName = clean_nextier_driver_name(trim((string)($_POST['driver_name'] ?? '')));
  $miles = round(parse_money($_POST['miles'] ?? 0), 2);
  $rate = round(parse_money($_POST['rate'] ?? 0), 2);
  $lineHaul = round(parse_money($_POST['line_haul'] ?? 0), 2);
  $fscRate = round(parse_money($_POST['fsc_rate'] ?? 0), 4);
  $fscTotal = round(parse_money($_POST['fsc_total'] ?? 0), 2);
  $bonus = round(parse_money($_POST['bonus'] ?? 0), 2);
  $driverContactId = (int)($_POST['matched_contact_id'] ?? 0);

  if ($rowId <= 0) $errors[] = 'Unable to locate the NexTier row to update.';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) $errors[] = 'Enter a valid NexTier work date.';
  if ($loadId === '') $errors[] = 'Enter a load ID.';
  if ($driverContactId > 0) {
    $contactName = driver_contact_name($mysqli, $driverContactId);
    if ($contactName !== '') $driverName = $contactName;
  }
  if ($driverName === '') $errors[] = 'Enter or select a driver.';

  if (empty($errors)) {
    $old = null;
    $stmtOld = $mysqli->prepare("SELECT * FROM nextier_payout_rows WHERE id = ? LIMIT 1");
    if ($stmtOld) {
      $stmtOld->bind_param('i', $rowId);
      $stmtOld->execute();
      $resOld = $stmtOld->get_result();
      $old = $resOld ? $resOld->fetch_assoc() : null;
      $stmtOld->close();
    }
    if (!$old) {
      $errors[] = 'Unable to locate the NexTier row to update.';
    } else {
      $contactIdBind = $driverContactId > 0 ? $driverContactId : null;
      $stmt = $mysqli->prepare("
        UPDATE nextier_payout_rows
           SET work_date = ?,
               well_name = ?,
               load_id = ?,
               dispatched_loader = ?,
               bol_number = ?,
               weight = ?,
               tons = ?,
               trucking_co = ?,
               driver_name = ?,
               miles = ?,
               rate = ?,
               line_haul = ?,
               fsc_rate = ?,
               fsc_total = ?,
               bonus = ?,
               matched_contact_id = ?
         WHERE id = ?
         LIMIT 1
      ");
      if (!$stmt) {
        $errors[] = 'Unable to update NexTier row: ' . $mysqli->error;
      } else {
        $stmt->bind_param(
          'sssssddssddddddii',
          $workDate,
          $wellName,
          $loadId,
          $loader,
          $bolNumber,
          $weight,
          $tons,
          $truckingCo,
          $driverName,
          $miles,
          $rate,
          $lineHaul,
          $fscRate,
          $fscTotal,
          $bonus,
          $contactIdBind,
          $rowId
        );
        if (!$stmt->execute()) {
          $errors[] = 'Unable to update NexTier row: ' . $stmt->error;
        }
        $stmt->close();
      }

      if (empty($errors)) {
        $oldPayoutDate = (string)($old['work_date'] ?? '');
        $oldTicket = nextier_ticket_number_from_row($old);
        $oldUploadDate = (string)($old['upload_date'] ?? '');
        $oldDriverName = (string)($old['driver_name'] ?? '');
        $del = $mysqli->prepare("
          DELETE FROM driver_payouts
           WHERE payout_date = ?
             AND ticket_number = ?
             AND vendor_name = 'NexTier'
             AND upload_date = ?
             AND driver_name = ?
           LIMIT 1
        ");
        if ($del) {
          $del->bind_param('ssss', $oldPayoutDate, $oldTicket, $oldUploadDate, $oldDriverName);
          $del->execute();
          $del->close();
        }

        $newRow = [
          'load_id' => $loadId,
          'bol_number' => $bolNumber,
          'rate' => $rate,
          'tons' => $tons,
          'line_haul' => $lineHaul,
          'fsc_total' => $fscTotal,
          'bonus' => $bonus,
        ];
        $ticketNumber = nextier_ticket_number_from_row($newRow);
        $payAsString = number_format(nextier_total_pay($newRow), 2, '.', '');
        $vendor = 'NexTier';
        $ins = $mysqli->prepare("
          INSERT IGNORE INTO driver_payouts
          (payout_date, ticket_number, driver_name, vendor_name, tss_pay, upload_date, driver_contact_id)
          VALUES (?,?,?,?,?,?,?)
        ");
        if (!$ins) {
          $errors[] = 'Unable to update NexTier payout row: ' . $mysqli->error;
        } else {
          $ins->bind_param('ssssssi', $workDate, $ticketNumber, $driverName, $vendor, $payAsString, $oldUploadDate, $contactIdBind);
          if (!$ins->execute()) {
            $errors[] = 'Unable to update NexTier payout row: ' . $ins->error;
          }
          $ins->close();
        }
      }

      if (empty($errors)) {
        $success = true;
        $nextierPayoutSummary = ['review_message' => 'NexTier row updated.'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextierWeekStart)) {
          $nextierWeekStart = business_sunday_week_start($workDate);
        }
      }
    }
  }
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && in_array(($_POST['action'] ?? ''), ['delete_nextier_row', 'delete_nextier_rows'], true)) {
  $lastUploadType = 'nextier_review';
  $nextierWeekStart = trim((string)($_POST['nextier_week_start'] ?? ''));
  $rowIds = (($_POST['action'] ?? '') === 'delete_nextier_row')
    ? [(int)($_POST['nextier_row_id'] ?? 0)]
    : array_values(array_unique(array_filter(array_map('intval', $_POST['nextier_row_ids'] ?? []), static fn($id) => $id > 0)));

  if (empty($rowIds) || max($rowIds) <= 0) {
    $errors[] = (($_POST['action'] ?? '') === 'delete_nextier_row') ? 'Unable to locate the NexTier row to delete.' : 'Select at least one NexTier row to delete.';
  } else {
    $deleted = 0;
    foreach ($rowIds as $rowId) {
      $old = null;
      $stmtOld = $mysqli->prepare("SELECT * FROM nextier_payout_rows WHERE id = ? LIMIT 1");
      if ($stmtOld) {
        $stmtOld->bind_param('i', $rowId);
        $stmtOld->execute();
        $resOld = $stmtOld->get_result();
        $old = $resOld ? $resOld->fetch_assoc() : null;
        $stmtOld->close();
      }
      if (!$old) continue;

      $oldPayoutDate = (string)($old['work_date'] ?? '');
      $oldTicket = nextier_ticket_number_from_row($old);
      $oldUploadDate = (string)($old['upload_date'] ?? '');
      $oldDriverName = (string)($old['driver_name'] ?? '');

      $delPayout = $mysqli->prepare("
        DELETE FROM driver_payouts
         WHERE payout_date = ?
           AND ticket_number = ?
           AND vendor_name = 'NexTier'
           AND upload_date = ?
           AND driver_name = ?
         LIMIT 1
      ");
      if ($delPayout) {
        $delPayout->bind_param('ssss', $oldPayoutDate, $oldTicket, $oldUploadDate, $oldDriverName);
        $delPayout->execute();
        $delPayout->close();
      }

      $delRaw = $mysqli->prepare("DELETE FROM nextier_payout_rows WHERE id = ? LIMIT 1");
      if ($delRaw) {
        $delRaw->bind_param('i', $rowId);
        if ($delRaw->execute()) $deleted += max(0, (int)$delRaw->affected_rows);
        $delRaw->close();
      }
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextierWeekStart)) {
        $nextierWeekStart = business_sunday_week_start($oldPayoutDate);
      }
    }

    if ($deleted > 0) {
      $success = true;
      $nextierPayoutSummary = ['review_message' => $deleted . ' NexTier row' . ($deleted === 1 ? '' : 's') . ' deleted.'];
    } else {
      $errors[] = 'No NexTier rows were deleted.';
    }
  }
}

// ---------------------------
// Upload POST
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['action'])) {
  $uploadMode = ($_POST['upload_mode'] ?? 'ls_detail');
  $today = $todayDate;
  $lastUploadType = $uploadMode;

  if ($uploadMode === 'tss_payout') {
    if (!$hasLsDetailForToday) {
      $errors[] = "We cannot upload this file until an LS Detail file has been successfully uploaded for {$today}.";
    } elseif ($openUnresolvedCount > 0) {
      $errors[] = "We cannot upload this file until all Open Unresolved Matches have been resolved. Please click the Open Unresolved Review button above to resolve these issues before moving forward.";
    } elseif (empty($_FILES['payout_file']) || $_FILES['payout_file']['error'] !== UPLOAD_ERR_OK) {
      $errors[] = "Please upload a valid TSS payout .xlsx file.";
    } elseif ($xlsx = SimpleXLSX::parse($_FILES['payout_file']['tmp_name'])) {
      $all = $xlsx->rows();
      if (empty($all)) {
        $errors[] = "The payout file contains no rows.";
      } else {
        $qTruck = $mysqli->query("
          SELECT id, truck_no, alt_truck_no
            FROM driver_contacts
           WHERE COALESCE(is_disabled, 0) = 0
        ");
        $truckToIds = [];
        if ($qTruck) {
          while ($c = $qTruck->fetch_assoc()) {
            foreach ([digits_only($c['truck_no'] ?? ''), digits_only($c['alt_truck_no'] ?? '')] as $td) {
              if ($td !== '') $truckToIds[$td][(int)$c['id']] = true;
            }
          }
          $qTruck->close();
        }

        $insRow = $mysqli->prepare("
          INSERT INTO tss_payout_rows
          (upload_date, as_of_date, section_no, source_line_no, row_type, truck_raw, truck_digits, work_date,
           ticket_number, bol_number, unloaded_at, job_description, quantity, pay_amount, misc_category,
           misc_description, extracted_ticket_number, matched_contact_id)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE
            as_of_date=VALUES(as_of_date),
            truck_raw=VALUES(truck_raw),
            truck_digits=VALUES(truck_digits),
            work_date=VALUES(work_date),
            ticket_number=VALUES(ticket_number),
            bol_number=VALUES(bol_number),
            unloaded_at=VALUES(unloaded_at),
            job_description=VALUES(job_description),
            quantity=VALUES(quantity),
            pay_amount=VALUES(pay_amount),
            misc_category=VALUES(misc_category),
            misc_description=VALUES(misc_description),
            extracted_ticket_number=VALUES(extracted_ticket_number),
            matched_contact_id=VALUES(matched_contact_id)
        ");
        $insMiscPayout = $mysqli->prepare("
          INSERT IGNORE INTO driver_payouts
          (payout_date, ticket_number, driver_name, vendor_name, tss_pay, upload_date, driver_contact_id)
          VALUES (?,?,?,?,?,?,?)
        ");
        $qFindContactByTicket = $mysqli->prepare("
          SELECT ldr.matched_contact_id
            FROM ls_detail_raw ldr
            JOIN driver_contacts dc
              ON dc.id = ldr.matched_contact_id
           WHERE `Truckload ID` = ?
             AND REPLACE(REPLACE(`Truck #`,'LS',''),' ','') LIKE CONCAT('%', ?, '%')
             AND ldr.matched_contact_id IS NOT NULL
             AND COALESCE(dc.is_disabled, 0) = 0
           ORDER BY upload_date DESC
           LIMIT 1
        ");
        $qContactName = $mysqli->prepare("
          SELECT first_name, last_name
            FROM driver_contacts
           WHERE id = ?
             AND COALESCE(is_disabled, 0) = 0
           LIMIT 1
        ");

        if (!$insRow || !$insMiscPayout || !$qFindContactByTicket || !$qContactName) {
          $errors[] = 'Payout parser setup error: ' . $mysqli->error;
        } else {
          $sectionNo = 0;
          $truckRaw = '';
          $truckDigits = '';
          $asOfDate = null;
          $mode = '';
          $miscCategory = '';

          $summary = [
            'ticket_rows' => 0,
            'misc_rows' => 0,
            'trailer_usage_skipped' => 0,
            'misc_inserted_to_payouts' => 0,
            'misc_unmatched' => 0
          ];

          foreach ($all as $rIdx => $row) {
            $lineNo = $rIdx + 1;
            $a = trim((string)($row[0] ?? ''));
            $b = trim((string)($row[1] ?? ''));
            $c = trim((string)($row[2] ?? ''));
            $d = trim((string)($row[3] ?? ''));
            $e = trim((string)($row[4] ?? ''));
            $f = trim((string)($row[5] ?? ''));
            $g = trim((string)($row[6] ?? ''));

            if ($a !== '' && preg_match('/^(\d+)\s*LS$/i', $a, $mTruck)) {
              $sectionNo++;
              $truckRaw = $a;
              $truckDigits = digits_only($mTruck[1]);
              $asOfDate = null;
              $mode = '';
              $miscCategory = '';
              continue;
            }

            if ($a !== '' && preg_match('/^As of:\s*(.+)$/i', $a, $mAsOf)) {
              $asOfDate = parse_any_date($mAsOf[1], TSS_SOURCE_TIMEZONE);
              continue;
            }

            if (strcasecmp($a, 'Ticket Pay') === 0) { $mode = 'ticket'; continue; }
            if (strcasecmp($a, 'Misc Pay') === 0) { $mode = 'misc'; continue; }
            if (strcasecmp($a, 'Deductions') === 0) { $mode = ''; $miscCategory = ''; continue; }
            if ($truckDigits === '' || $mode === '') continue;

            if ($mode === 'ticket') {
              if (strcasecmp($b, 'Ticket#') === 0 || strcasecmp($a, 'Date') === 0) continue;
              if ($b === '' || !preg_match('/^\d+$/', $b)) continue;

              $workDate = excel_serial_to_date($a, TSS_SOURCE_TIMEZONE);
              $ticketNumber = $b;
              $payAmount = round(parse_money($g), 2);

              $null = null;
              $zeroMatched = null;
              $insRow->bind_param(
                'ssiisssssssssdsssi',
                $today,
                $asOfDate,
                $sectionNo,
                $lineNo,
                $mode,
                $truckRaw,
                $truckDigits,
                $workDate,
                $ticketNumber,
                $c,
                $d,
                $e,
                $f,
                $payAmount,
                $null,
                $null,
                $null,
                $zeroMatched
              );
              if (!$insRow->execute()) {
                $errors[] = 'TSS payout insert error (ticket row ' . $lineNo . '): ' . $insRow->error;
                break;
              }
              $summary['ticket_rows']++;
              continue;
            }

            if ($mode === 'misc') {
              if (strcasecmp($a, 'Date') === 0 || strcasecmp($b, 'Description') === 0) continue;
              if (strcasecmp($a, 'Total Pay') === 0 || strcasecmp($a, 'Net Payable') === 0) continue;
              if ($a !== '' && $b === '' && $c === '') {
                $miscCategory = $a;
                continue;
              }
              if ($b === '' || $c === '') continue;
              if (is_trailer_usage_row($miscCategory, $b)) {
                $summary['trailer_usage_skipped']++;
                continue;
              }

              $workDate = excel_serial_to_date($a, TSS_SOURCE_TIMEZONE);
              $payAmount = round(parse_money($c), 2);
              $extractedTicket = null;
              if (preg_match('/\b(\d{6,10})\b/', $b, $mTicket)) $extractedTicket = $mTicket[1];

              $matchedContactId = null;
              if ($extractedTicket !== null) {
                $qFindContactByTicket->bind_param('ss', $extractedTicket, $truckDigits);
                if ($qFindContactByTicket->execute()) {
                  $resMatch = $qFindContactByTicket->get_result()->fetch_assoc();
                  if ($resMatch && !empty($resMatch['matched_contact_id'])) {
                    $matchedContactId = (int)$resMatch['matched_contact_id'];
                  }
                }
              }
              if ($matchedContactId === null && isset($truckToIds[$truckDigits]) && count($truckToIds[$truckDigits]) === 1) {
                $matchedContactId = (int)array_key_first($truckToIds[$truckDigits]);
              }

              $rowType = 'misc';
              $nullTicket = null;
              $nullBol = null;
              $nullUnloaded = null;
              $nullJob = null;
              $nullQty = null;
              $insRow->bind_param(
                'ssiisssssssssdsssi',
                $today,
                $asOfDate,
                $sectionNo,
                $lineNo,
                $rowType,
                $truckRaw,
                $truckDigits,
                $workDate,
                $nullTicket,
                $nullBol,
                $nullUnloaded,
                $nullJob,
                $nullQty,
                $payAmount,
                $miscCategory,
                $b,
                $extractedTicket,
                $matchedContactId
              );
              if (!$insRow->execute()) {
                $errors[] = 'TSS payout insert error (misc row ' . $lineNo . '): ' . $insRow->error;
                break;
              }

              $summary['misc_rows']++;
              if ($payAmount != 0.0) {
                $driverName = trim($truckRaw . ' Misc');
                if ($matchedContactId) {
                  $qContactName->bind_param('i', $matchedContactId);
                  if ($qContactName->execute()) {
                    $rName = $qContactName->get_result()->fetch_assoc();
                    if ($rName) {
                      $driverName = trim(($rName['first_name'] ?? '') . ' ' . ($rName['last_name'] ?? ''));
                    }
                  }
                }

                $payoutDate = $workDate ?: ($asOfDate ?: $today);
                $miscTicket = $extractedTicket ?: ('MISC-' . $truckDigits . '-' . $lineNo);
                $vendor = 'TSS Misc Revenue';
                $contactIdForBind = $matchedContactId ?: null;
                $payAsString = number_format($payAmount, 2, '.', '');
                $insMiscPayout->bind_param(
                  'ssssssi',
                  $payoutDate,
                  $miscTicket,
                  $driverName,
                  $vendor,
                  $payAsString,
                  $today,
                  $contactIdForBind
                );
                if (!$insMiscPayout->execute()) {
                  $errors[] = 'Misc payout write error (line ' . $lineNo . '): ' . $insMiscPayout->error;
                  break;
                }
                if ($insMiscPayout->affected_rows > 0) $summary['misc_inserted_to_payouts']++;
                if (!$matchedContactId) $summary['misc_unmatched']++;
              }
            }
          }

          $insRow->close();
          $insMiscPayout->close();
          $qFindContactByTicket->close();
          $qContactName->close();

          if (empty($errors)) {
            $success = true;
            $tssPayoutSummary = $summary;
            $_SESSION['unmatched_rows'] = [];
          }
        }
      }
    } else {
      $errors[] = 'Parse error: ' . SimpleXLSX::parseError();
    }
  } elseif ($uploadMode === 'rtex_payout') {
    if (empty($_FILES['rtex_file']) || $_FILES['rtex_file']['error'] !== UPLOAD_ERR_OK) {
      $errors[] = "Please upload a valid RTEX invoice .xlsx file.";
    } elseif ($xlsx = SimpleXLSX::parse($_FILES['rtex_file']['tmp_name'])) {
      $rtexBaseRateReductionPct = parse_percent_number($_POST['rtex_base_rate_reduction_pct'] ?? 0);
      if ($rtexBaseRateReductionPct === null) $rtexBaseRateReductionPct = 0.0;
      $rtexBaseRateReductionPct = max(0.0, min(100.0, (float)$rtexBaseRateReductionPct));
      $driverCtx = build_driver_match_context($mysqli);
      $sheetNames = method_exists($xlsx, 'sheetNames') ? $xlsx->sheetNames() : ['RTEX Invoice'];

      $insRaw = $mysqli->prepare("
        INSERT INTO rtex_payout_rows
        (upload_date, invoice_date, sheet_name, source_line_no, work_date, driver_name, truck_raw, truck_digits,
         job_number, ticket_number, start_time, end_time, hours, rate, total_amount, surcharge_amount, matched_contact_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          invoice_date=VALUES(invoice_date),
          work_date=VALUES(work_date),
          driver_name=VALUES(driver_name),
          truck_raw=VALUES(truck_raw),
          truck_digits=VALUES(truck_digits),
          job_number=VALUES(job_number),
          ticket_number=VALUES(ticket_number),
          start_time=VALUES(start_time),
          end_time=VALUES(end_time),
          hours=VALUES(hours),
          rate=VALUES(rate),
          total_amount=VALUES(total_amount),
          surcharge_amount=VALUES(surcharge_amount),
          matched_contact_id=VALUES(matched_contact_id)
      ");
      $insPayout = $mysqli->prepare("
        INSERT INTO driver_payouts
        (payout_date, ticket_number, driver_name, vendor_name, tss_pay, upload_date, driver_contact_id)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          tss_pay=VALUES(tss_pay),
          upload_date=VALUES(upload_date),
          driver_contact_id=VALUES(driver_contact_id)
      ");

      if (!$insRaw || !$insPayout) {
        $errors[] = 'RTEX parser setup error: ' . $mysqli->error;
      } else {
        $summary = [
          'rows_seen' => 0,
          'raw_rows_saved' => 0,
          'payout_rows_inserted' => 0,
          'payout_rows_existing' => 0,
          'matched' => 0,
          'unmatched' => 0,
          'surcharge_skipped' => 0.0,
          'last_week_start' => '',
        ];

        $cellText = static function ($value): string {
          if ($value === null) return '';
          if (is_float($value) && abs($value - round($value)) < 0.000001) return (string)(int)round($value);
          return trim((string)$value);
        };
        $trailerReconByWeek = [];

        foreach ($sheetNames as $sheetIndex => $sheetName) {
          $all = $xlsx->rows($sheetIndex);
          if (empty($all)) continue;

          $invoiceDate = null;
          foreach ($all as $row) {
            foreach ($row as $idx => $cell) {
              if (stripos((string)$cell, 'Invoice date') !== false) {
                $invoiceDate = parse_any_date($row[$idx + 1] ?? '', TSS_SOURCE_TIMEZONE);
                break 2;
              }
            }
          }

          $headerIndex = null;
          $headers = [];
          foreach ($all as $idx => $row) {
            $candidateHeaders = array_map($cellText, $row);
            $iDate = find_header_index_loose($candidateHeaders, ['Date']);
            $iName = find_header_index_loose($candidateHeaders, ['Name']);
            $iTruck = find_header_index_loose($candidateHeaders, ['Truck']);
            $iTotal = find_header_index_loose($candidateHeaders, ['Total']);
            if ($iDate !== null && $iName !== null && $iTruck !== null && $iTotal !== null) {
              $headerIndex = $idx;
              $headers = $candidateHeaders;
              break;
            }
          }
          if ($headerIndex === null) continue;

          $iDate = find_header_index_loose($headers, ['Date']);
          $iName = find_header_index_loose($headers, ['Name']);
          $iTruck = find_header_index_loose($headers, ['Truck']);
          $iJob = find_header_index_loose($headers, ['Job #', 'Job']);
          $iTicket = find_header_index_loose($headers, ['Ticket #', 'Ticket']);
          $iStart = find_header_index_loose($headers, ['Start']);
          $iEnd = find_header_index_loose($headers, ['End']);
          $iHours = find_header_index_loose($headers, ['# of Hours', 'Hours']);
          $iRate = find_header_index_loose($headers, ['Rate']);
          $iTotal = find_header_index_loose($headers, ['Total']);

          for ($rowIndex = $headerIndex + 1; $rowIndex < count($all); $rowIndex++) {
            $row = $all[$rowIndex];
            $firstCell = $cellText($row[0] ?? '');
            if (stripos($firstCell, '12%') !== false || stripos($firstCell, 'surcharge') !== false) {
              $summary['surcharge_skipped'] += parse_money($row[$iTotal] ?? ($row[2] ?? 0));
              continue;
            }

            $workDate = parse_any_date($row[$iDate] ?? '', TSS_SOURCE_TIMEZONE);
            $driverName = $cellText($row[$iName] ?? '');
            $truckRaw = $cellText($row[$iTruck] ?? '');
            $totalAmount = round(parse_money($row[$iTotal] ?? 0), 2);
            if ($workDate === null || $driverName === '' || $truckRaw === '' || abs($totalAmount) < 0.01) {
              continue;
            }

            $summary['rows_seen']++;
            $summary['last_week_start'] = business_sunday_week_start($workDate);
            $truckDigits = digits_only($truckRaw);
            $jobNumber = $iJob !== null ? $cellText($row[$iJob] ?? '') : '';
            $ticketNumber = $iTicket !== null ? $cellText($row[$iTicket] ?? '') : '';
            if ($ticketNumber === '') {
              $ticketNumber = 'RTEX-' . ($jobNumber !== '' ? $jobNumber : 'JOB') . '-' . ($truckDigits !== '' ? $truckDigits : 'TRUCK') . '-' . str_replace('-', '', $workDate) . '-' . ($sheetIndex + 1) . '-' . ($rowIndex + 1);
            }
            $loadCheck = rtex_stmt($mysqli, "SELECT id FROM rtex_payout_rows WHERE billing_mode='load' AND work_date=? AND ticket_number=? LIMIT 1", 'ss', [$workDate, $ticketNumber]);
            $loadConflict = $loadCheck->get_result()->fetch_assoc();
            $loadCheck->close();
            if ($loadConflict) {
              $errors[] = 'RTEX ticket ' . $ticketNumber . ' on ' . $workDate . ' is already saved as a load; the hourly import skipped it.';
              continue;
            }
            $startTime = $iStart !== null ? rtex_excel_time($row[$iStart] ?? '') : '';
            $endTime = $iEnd !== null ? rtex_excel_time($row[$iEnd] ?? '') : '';
            $hours = $iHours !== null ? round(parse_money($row[$iHours] ?? 0), 2) : 0.0;
            $rate = $iRate !== null ? round(parse_money($row[$iRate] ?? 0), 2) : 0.0;
            [$rate, $totalAmount] = rtex_apply_base_rate_reduction($rate, $hours, $totalAmount, $rtexBaseRateReductionPct);
            $contactId = resolve_driver_contact_from_context($driverCtx, $driverName, $truckDigits);
            if ($contactId) {
              $summary['matched']++;
              [$aliasFirst, $aliasLast] = split_full_driver_name($driverName);
              if ($aliasFirst !== '' && $aliasLast !== '') {
                $insAlias = $mysqli->prepare(
                  "INSERT IGNORE INTO driver_name_aliases (driver_contact_id, alias_first_name, alias_last_name)
                   VALUES (?,?,?)"
                );
                if ($insAlias) {
                  $insAlias->bind_param('iss', $contactId, $aliasFirst, $aliasLast);
                  $insAlias->execute();
                  $insAlias->close();
                }
              }
            } else {
              $summary['unmatched']++;
            }

            $lineNo = $rowIndex + 1;
            $invoiceDateBind = $invoiceDate;
            $sheetNameBind = (string)$sheetName;
            $surchargeAmount = 0.0;
            $contactIdBind = $contactId ?: null;
            $insRaw->bind_param(
              'sssissssssssddddi',
              $today,
              $invoiceDateBind,
              $sheetNameBind,
              $lineNo,
              $workDate,
              $driverName,
              $truckRaw,
              $truckDigits,
              $jobNumber,
              $ticketNumber,
              $startTime,
              $endTime,
              $hours,
              $rate,
              $totalAmount,
              $surchargeAmount,
              $contactIdBind
            );
            if (!$insRaw->execute()) {
              $errors[] = 'RTEX raw insert error (sheet ' . h($sheetNameBind) . ', row ' . $lineNo . '): ' . $insRaw->error;
              break 2;
            }
            if ($insRaw->affected_rows > 0) $summary['raw_rows_saved']++;

            $vendor = 'RTEX';
            $payAsString = number_format($totalAmount, 2, '.', '');
            $insPayout->bind_param('ssssssi', $workDate, $ticketNumber, $driverName, $vendor, $payAsString, $today, $contactIdBind);
            if (!$insPayout->execute()) {
              $errors[] = 'RTEX payout insert error (sheet ' . h($sheetNameBind) . ', row ' . $lineNo . '): ' . $insPayout->error;
              break 2;
            }
            if ($insPayout->affected_rows === 1) {
              $summary['payout_rows_inserted']++;
            } else {
              $summary['payout_rows_existing']++;
            }
          }
        }

        $insRaw->close();
        $insPayout->close();

        if (empty($errors)) {
          $success = true;
          $rtexPayoutSummary = $summary;
          $_SESSION['unmatched_rows'] = [];
        }
      }
    } else {
      $errors[] = 'RTEX parse error: ' . SimpleXLSX::parseError();
    }
  } elseif ($uploadMode === 'nextier_payout') {
    if (empty($_FILES['nextier_file']) || $_FILES['nextier_file']['error'] !== UPLOAD_ERR_OK) {
      $errors[] = "Please upload a valid NexTier statement .xlsx file.";
    } elseif ($xlsx = SimpleXLSX::parse($_FILES['nextier_file']['tmp_name'])) {
      $driverCtx = build_driver_match_context($mysqli);
      $sheetNames = method_exists($xlsx, 'sheetNames') ? $xlsx->sheetNames() : ['NexTier Statement'];

      $insRaw = $mysqli->prepare("
        INSERT INTO nextier_payout_rows
        (upload_date, sheet_name, source_line_no, work_date, well_name, load_id, dispatched_loader, bol_number,
         weight, tons, trucking_co, driver_name, miles, rate, line_haul, fsc_rate, fsc_total, bonus, matched_contact_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          work_date=VALUES(work_date),
          well_name=VALUES(well_name),
          load_id=VALUES(load_id),
          dispatched_loader=VALUES(dispatched_loader),
          bol_number=VALUES(bol_number),
          weight=VALUES(weight),
          tons=VALUES(tons),
          trucking_co=VALUES(trucking_co),
          driver_name=VALUES(driver_name),
          miles=VALUES(miles),
          rate=VALUES(rate),
          line_haul=VALUES(line_haul),
          fsc_rate=VALUES(fsc_rate),
          fsc_total=VALUES(fsc_total),
          bonus=VALUES(bonus),
          matched_contact_id=VALUES(matched_contact_id)
      ");
      $insPayout = $mysqli->prepare("
        INSERT INTO driver_payouts
        (payout_date, ticket_number, driver_name, vendor_name, tss_pay, upload_date, driver_contact_id)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          tss_pay=VALUES(tss_pay),
          upload_date=VALUES(upload_date),
          driver_contact_id=VALUES(driver_contact_id)
      ");

      if (!$insRaw || !$insPayout) {
        $errors[] = 'NexTier parser setup error: ' . $mysqli->error;
      } else {
        $summary = [
          'rows_seen' => 0,
          'raw_rows_saved' => 0,
          'payout_rows_inserted' => 0,
          'payout_rows_existing' => 0,
          'matched' => 0,
          'unmatched' => 0,
          'last_week_start' => '',
          'trailer_recon_rows' => 0,
          'trailer_recon_raw_total' => 0.0,
          'trailer_recon_billed_total' => 0.0,
        ];

        $cellText = static function ($value): string {
          if ($value === null) return '';
          if (is_float($value) && abs($value - round($value)) < 0.000001) return (string)(int)round($value);
          return trim((string)$value);
        };

        foreach ($sheetNames as $sheetIndex => $sheetName) {
          $all = $xlsx->rows($sheetIndex);
          if (empty($all)) continue;

          $headerIndex = null;
          $headers = [];
          foreach ($all as $idx => $row) {
            $candidateHeaders = array_map($cellText, $row);
            $iLoad = find_header_index_loose($candidateHeaders, ['Load ID']);
            $iDriver = find_header_index_loose($candidateHeaders, ['Driver Name']);
            $iCompleted = find_header_index_loose($candidateHeaders, ['Completed']);
            $iLineHaul = find_header_index_loose($candidateHeaders, ['Total Line Haul']);
            if ($iLoad !== null && $iDriver !== null && $iCompleted !== null && $iLineHaul !== null) {
              $headerIndex = $idx;
              $headers = $candidateHeaders;
              break;
            }
          }
          if ($headerIndex === null) continue;

          $iWell = find_header_index_loose($headers, ['Well Name']);
          $iLoad = find_header_index_loose($headers, ['Load ID']);
          $iLoader = find_header_index_loose($headers, ['Dispatched Loader']);
          $iBol = find_header_index_loose($headers, ['BOL #', 'BOL']);
          $iWeight = find_header_index_loose($headers, ['Weight']);
          $iTons = find_header_index_loose($headers, ['Tons']);
          $iTrucking = find_header_index_loose($headers, ['Trucking Co.']);
          $iDriver = find_header_index_loose($headers, ['Driver Name']);
          $iMiles = find_header_index_loose($headers, ['Miles']);
          $iRate = find_header_index_loose($headers, ['Rate']);
          $iLineHaul = find_header_index_loose($headers, ['Total Line Haul']);
          $iCompleted = find_header_index_loose($headers, ['Completed']);
          $iFscRate = find_header_index_loose($headers, ['FSC Rate/Mile']);
          $iFscTotal = find_header_index_loose($headers, ['FSC Total']);
          $iBonus = find_header_index_loose($headers, ['Bonus']);
          $iFscIncluded = find_header_index_loose($headers, ['FSC included in line haul total']);
          if ($iLineHaul !== null && $headerIndex > 0) {
            for ($preHeaderIndex = 0; $preHeaderIndex < $headerIndex; $preHeaderIndex++) {
              $preHeaderRow = $all[$preHeaderIndex] ?? [];
              if (!is_nextier_trailer_rental_summary_row($preHeaderRow)) {
                continue;
              }
              $trailerAmount = round(parse_money($preHeaderRow[$iLineHaul] ?? 0), 2);
              if (abs($trailerAmount) < 0.01) {
                continue;
              }
              $trailerWeekStart = nextier_trailer_summary_week_start($preHeaderRow, $iCompleted);
              if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $trailerWeekStart)) {
                continue;
              }
              if (!isset($trailerReconByWeek[$trailerWeekStart])) {
                $trailerReconByWeek[$trailerWeekStart] = ['raw' => 0.0, 'billed' => 0.0, 'rows' => 0];
              }
              $trailerReconByWeek[$trailerWeekStart]['raw'] = round($trailerReconByWeek[$trailerWeekStart]['raw'] + $trailerAmount, 2);
              $trailerReconByWeek[$trailerWeekStart]['billed'] = round($trailerReconByWeek[$trailerWeekStart]['billed'] + abs($trailerAmount), 2);
              $trailerReconByWeek[$trailerWeekStart]['rows']++;
              $summary['trailer_recon_rows']++;
              $summary['trailer_recon_raw_total'] = round((float)$summary['trailer_recon_raw_total'] + $trailerAmount, 2);
              $summary['trailer_recon_billed_total'] = round((float)$summary['trailer_recon_billed_total'] + abs($trailerAmount), 2);
            }
          }

          for ($rowIndex = $headerIndex + 1; $rowIndex < count($all); $rowIndex++) {
            $row = $all[$rowIndex];
            $lineHaul = round(parse_money($row[$iLineHaul] ?? 0), 2);
            if (is_nextier_trailer_rental_summary_row($row) && abs($lineHaul) >= 0.01) {
              $trailerWeekStart = nextier_trailer_summary_week_start($row, $iCompleted);
              if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trailerWeekStart)) {
                if (!isset($trailerReconByWeek[$trailerWeekStart])) {
                  $trailerReconByWeek[$trailerWeekStart] = ['raw' => 0.0, 'billed' => 0.0, 'rows' => 0];
                }
                $trailerReconByWeek[$trailerWeekStart]['raw'] = round($trailerReconByWeek[$trailerWeekStart]['raw'] + $lineHaul, 2);
                $trailerReconByWeek[$trailerWeekStart]['billed'] = round($trailerReconByWeek[$trailerWeekStart]['billed'] + abs($lineHaul), 2);
                $trailerReconByWeek[$trailerWeekStart]['rows']++;
                $summary['trailer_recon_rows']++;
                $summary['trailer_recon_raw_total'] = round((float)$summary['trailer_recon_raw_total'] + $lineHaul, 2);
                $summary['trailer_recon_billed_total'] = round((float)$summary['trailer_recon_billed_total'] + abs($lineHaul), 2);
              }
              continue;
            }
            $workDate = parse_any_date($row[$iCompleted] ?? '', TSS_SOURCE_TIMEZONE);
            $driverName = clean_nextier_driver_name($cellText($row[$iDriver] ?? ''));
            $loadId = $cellText($row[$iLoad] ?? '');
            if ($workDate === null || $driverName === '' || $loadId === '' || abs($lineHaul) < 0.01) {
              continue;
            }

            $summary['rows_seen']++;
            $summary['last_week_start'] = business_sunday_week_start($workDate);
            $wellName = $iWell !== null ? $cellText($row[$iWell] ?? '') : '';
            $loader = $iLoader !== null ? $cellText($row[$iLoader] ?? '') : '';
            $bolNumber = $iBol !== null ? $cellText($row[$iBol] ?? '') : '';
            $weight = $iWeight !== null ? round(parse_money($row[$iWeight] ?? 0), 2) : 0.0;
            $tons = $iTons !== null ? round(parse_money($row[$iTons] ?? 0), 2) : 0.0;
            $truckingCo = $iTrucking !== null ? $cellText($row[$iTrucking] ?? '') : '';
            $miles = $iMiles !== null ? round(parse_money($row[$iMiles] ?? 0), 2) : 0.0;
            $rate = $iRate !== null ? round(parse_money($row[$iRate] ?? 0), 2) : 0.0;
            $fscRate = $iFscRate !== null ? round(parse_money($row[$iFscRate] ?? 0), 4) : 0.0;
            $fscTotal = $iFscTotal !== null ? round(parse_money($row[$iFscTotal] ?? 0), 2) : 0.0;
            $bonus = $iBonus !== null ? round(parse_money($row[$iBonus] ?? 0), 2) : 0.0;
            $totalPay = nextier_total_pay([
              'rate' => $rate,
              'tons' => $tons,
              'line_haul' => $lineHaul,
              'fsc_total' => $fscTotal,
              'bonus' => $bonus,
            ]);
            $ticketNumber = $loadId;
            if ($bolNumber !== '') $ticketNumber .= '-' . $bolNumber;

            $contactId = resolve_driver_contact_from_context($driverCtx, $driverName, '');
            if ($contactId) {
              $summary['matched']++;
              [$aliasFirst, $aliasLast] = split_full_driver_name($driverName);
              if ($aliasFirst !== '' && $aliasLast !== '') {
                $insAlias = $mysqli->prepare(
                  "INSERT IGNORE INTO driver_name_aliases (driver_contact_id, alias_first_name, alias_last_name)
                   VALUES (?,?,?)"
                );
                if ($insAlias) {
                  $insAlias->bind_param('iss', $contactId, $aliasFirst, $aliasLast);
                  $insAlias->execute();
                  $insAlias->close();
                }
              }
            } else {
              $summary['unmatched']++;
            }

            $lineNo = $rowIndex + 1;
            $sheetNameBind = (string)$sheetName;
            $contactIdBind = $contactId ?: null;
            $insRaw->bind_param(
              'ssisssssddssddddddi',
              $today,
              $sheetNameBind,
              $lineNo,
              $workDate,
              $wellName,
              $loadId,
              $loader,
              $bolNumber,
              $weight,
              $tons,
              $truckingCo,
              $driverName,
              $miles,
              $rate,
              $lineHaul,
              $fscRate,
              $fscTotal,
              $bonus,
              $contactIdBind
            );
            if (!$insRaw->execute()) {
              $errors[] = 'NexTier raw insert error (sheet ' . h($sheetNameBind) . ', row ' . $lineNo . '): ' . $insRaw->error;
              break 2;
            }
            if ($insRaw->affected_rows > 0) $summary['raw_rows_saved']++;

            $vendor = 'NexTier';
            $payAsString = number_format($totalPay, 2, '.', '');
            $insPayout->bind_param('ssssssi', $workDate, $ticketNumber, $driverName, $vendor, $payAsString, $today, $contactIdBind);
            if (!$insPayout->execute()) {
              $errors[] = 'NexTier payout insert error (sheet ' . h($sheetNameBind) . ', row ' . $lineNo . '): ' . $insPayout->error;
              break 2;
            }
            if ($insPayout->affected_rows === 1) {
              $summary['payout_rows_inserted']++;
            } else {
              $summary['payout_rows_existing']++;
            }
          }

        }

        $insRaw->close();
        $insPayout->close();

        if (empty($errors)) {
          $success = true;
          if (
            (int)$summary['trailer_recon_rows'] > 0
          ) {
            $appliedPayoutWeekStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$summary['last_week_start'])
              ? (string)$summary['last_week_start']
              : business_sunday_week_start($today);
            $summary['trailer_recon_catchup_adjustments'] = 0;
            foreach ($trailerReconByWeek as $trailerWeekStart => $trailerWeekTotals) {
              save_nextier_trailer_reconciliation_upload(
                $mysqli,
                (string)$trailerWeekStart,
                $appliedPayoutWeekStart,
                $today,
                (float)$trailerWeekTotals['raw'],
                (float)$trailerWeekTotals['billed'],
                (int)$trailerWeekTotals['rows']
              );
              if ((string)$trailerWeekStart !== $appliedPayoutWeekStart) {
                $summary['trailer_recon_catchup_adjustments'] += save_nextier_trailer_catchup_adjustments(
                  $mysqli,
                  (string)$trailerWeekStart,
                  $appliedPayoutWeekStart,
                  $today
                );
              }
            }
          }
          $nextierPayoutSummary = $summary;
          $_SESSION['unmatched_rows'] = [];
        }
      }
    } else {
      $errors[] = 'NexTier parse error: ' . SimpleXLSX::parseError();
    }
  } else {
    // Toggle Pickup vs Delivery for LS Detail flow
    $useRole = in_array($_POST['use_role'] ?? 'pickup', ['pickup','delivery'], true) ? $_POST['use_role'] : 'pickup';
    $_SESSION['use_role'] = $useRole;
    $lsDetailFileHash = null;
    $lsDetailHashReserved = false;

    if (empty($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) {
      $errors[] = "Please upload a valid .xlsx file.";
    } else {
      $tmpName = (string)($_FILES['xlsx_file']['tmp_name'] ?? '');
      $originalName = (string)($_FILES['xlsx_file']['name'] ?? '');
      $hash = ($tmpName !== '') ? hash_file('sha256', $tmpName) : false;
      if (!$hash) {
        $errors[] = 'Unable to fingerprint the LS Detail file for duplicate detection.';
      } elseif (!ensure_ls_detail_upload_registry($mysqli)) {
        $errors[] = 'Unable to verify duplicate LS Detail uploads: ' . $mysqli->error;
      } else {
        $reserve = reserve_ls_detail_upload_hash($mysqli, $hash, $originalName, $today);
        if ($reserve === 'duplicate') {
          $errors[] = 'This LS Detail file has already been uploaded and cannot be uploaded again.';
        } elseif ($reserve === 'error') {
          $errors[] = 'Unable to reserve LS Detail upload fingerprint. Please try again.';
        } else {
          $lsDetailFileHash = $hash;
          $lsDetailHashReserved = true;
        }
      }
    }

    if (empty($errors) && ($xlsx = SimpleXLSX::parse($_FILES['xlsx_file']['tmp_name']))) {
      $all = $xlsx->rows();
      if (count($all) < 2) {
        $errors[] = "The file contains no data.";
      } else {
        $headersRow = $all[0];
        $dataRows   = array_slice($all, 1);

        // Validate required headers only. Extra file columns are allowed and ignored.
        $missing = array_diff($expectedHeaders, $headersRow);
        if ($missing) {
          $errors[] = 'Missing columns: ' . implode(', ', $missing);
        } else {
          $_SESSION['upload_date'] = $today;
          ensure_ls_detail_trailer_override_column($mysqli);
          ensure_ls_detail_total_bonuses_column($mysqli);
          ensure_ls_detail_fuel_surcharge_rate_column($mysqli);
          ensure_ls_detail_fuel_surcharge_type_column($mysqli);
          ensure_ls_detail_broker_fee_override_column($mysqli);
          $trailerPctHeaderIdx = find_first_header_index($headersRow, $optionalTrailerPctHeaders);
          $totalBonusesHeaderIdx = find_first_header_index($headersRow, ['Total Bonuses', 'Total Bonus']);
          $fuelSurchargeRateHeaderIdx = find_first_header_index($headersRow, ['Fuel Surcharge Rate', 'fuel surcharge rate']);
          $fuelSurchargeTypeHeaderIdx = find_first_header_index($headersRow, ['Fuel Surcharge Type', 'fuel surcharge type']);

          // Insert raw rows (audit)
          $colsEsc = array_map(function($h) use ($mysqli){
            return '`' . $mysqli->real_escape_string($h) . '`';
          }, $expectedHeaders);
          $colsEsc[] = '`upload_date`';
          if ($trailerPctHeaderIdx !== null) {
            $colsEsc[] = '`tss_trailer_pct_override`';
          }
          $colsEsc[] = '`total_bonuses`';
          $colsEsc[] = '`fuel_surcharge_rate`';
          $colsEsc[] = '`fuel_surcharge_type`';
          $colList = implode(',', $colsEsc);

          foreach ($dataRows as $row) {
            $vals = [];
            foreach ($expectedHeaders as $h) {
              $idx = array_search($h, $headersRow, true);
              $cellValue = $row[$idx] ?? '';
              if ($h === 'Delivery Date') {
                $cellValue = parse_any_date($cellValue, TSS_SOURCE_TIMEZONE) ?: $cellValue;
              }
              $vals[] = "'" . $mysqli->real_escape_string($cellValue) . "'";
            }
            $vals[] = "'{$today}'";
            if ($trailerPctHeaderIdx !== null) {
              $pctOverride = parse_percent_number($row[$trailerPctHeaderIdx] ?? null);
              $vals[] = ($pctOverride === null) ? "NULL" : "'" . $mysqli->real_escape_string((string)$pctOverride) . "'";
            }
            $totalBonuses = ($totalBonusesHeaderIdx !== null) ? parse_money($row[$totalBonusesHeaderIdx] ?? null) : null;
            $vals[] = ($totalBonuses === null) ? "NULL" : "'" . $mysqli->real_escape_string(number_format($totalBonuses, 2, '.', '')) . "'";
            $fuelSurchargeRate = ($fuelSurchargeRateHeaderIdx !== null) ? parse_money($row[$fuelSurchargeRateHeaderIdx] ?? null) : null;
            $vals[] = ($fuelSurchargeRate === null) ? "NULL" : "'" . $mysqli->real_escape_string(number_format($fuelSurchargeRate, 4, '.', '')) . "'";
            $fuelSurchargeType = normalize_fuel_surcharge_type($fuelSurchargeTypeHeaderIdx !== null ? ($row[$fuelSurchargeTypeHeaderIdx] ?? '') : 'mileage');
            $vals[] = "'" . $mysqli->real_escape_string($fuelSurchargeType) . "'";
            $sqlRaw = "INSERT IGNORE INTO ls_detail_raw ($colList) VALUES (" . implode(',', $vals) . ")";
            if (!$mysqli->query($sqlRaw)) {
              $errors[] = 'Raw insert error: ' . $mysqli->error;
              break;
            }
          }

          if (empty($errors)) {
            // Build contacts index (truck digits and names + aliases)
            $contacts = [];
            $byTruck  = []; // digits => [contacts]
            $namesForId = []; // id => array of normalized names (primary + aliases)

            $qc = $mysqli->query("SELECT id, first_name, last_name, truck_no, alt_truck_no FROM driver_contacts");
            while ($c = $qc->fetch_assoc()) {
              $c['first_name'] = $c['first_name'] ?? '';
              $c['last_name']  = $c['last_name'] ?? '';
              $c['name_norm']  = norm_name($c['first_name'], $c['last_name']);
              $c['truck_norm']     = digits_only($c['truck_no'] ?? '');
              $c['alt_truck_norm'] = digits_only($c['alt_truck_no'] ?? '');
              $contacts[] = $c;
              foreach (array_filter([$c['truck_norm'],$c['alt_truck_norm']]) as $d) {
                $byTruck[$d][] = $c;
              }
              $namesForId[(int)$c['id']] = [$c['name_norm']];
            }
            $qc->close();

            $qa = $mysqli->query("SELECT driver_contact_id, alias_full_norm FROM driver_name_aliases");
            while ($a = $qa->fetch_assoc()) {
              $cid = (int)$a['driver_contact_id'];
              $alias = strtolower(trim($a['alias_full_norm'] ?? ''));
              if ($alias !== '') $namesForId[$cid][] = $alias;
            }
            $qa->close();

            $nameMatchesContact = function($nameNorm, $contactId) use ($namesForId) {
              foreach ($namesForId[$contactId] ?? [] as $cand) {
                if ($cand !== '' && $cand === $nameNorm) return true;
              }
              return false;
            };

            // Prepare payout insert
            $ins = $mysqli->prepare("
              INSERT IGNORE INTO driver_payouts
              (payout_date, ticket_number, driver_name, vendor_name, tss_pay, upload_date, driver_contact_id)
              VALUES (?,?,?,?,?,?,?)
            ");
            if (!$ins) {
              $errors[] = 'Payout prepare error: ' . $mysqli->error;
            } else {
              $unmatched = [];
              $splitRows = [];
              $matchedCount = 0;
              $vendor = 'TSS';

              // Column indexes used repeatedly
              $iDeliveryDate = array_search('Delivery Date', $headersRow, true);
              $iTruckload    = array_search('Truckload ID', $headersRow, true);
              $iCalcRate     = array_search('Calculated Freight Rate (Carrier)', $headersRow, true);
              $iTruck        = array_search('Truck #', $headersRow, true);
              $iPF = array_search('Pickup Driver First Name', $headersRow, true);
              $iPL = array_search('Pickup Driver Last Name',  $headersRow, true);
              $iDF = array_search('Delivery Driver First Name', $headersRow, true);
              $iDL = array_search('Delivery Driver Last Name',  $headersRow, true);

              foreach ($dataRows as $i => $row) {
                $payoutDate   = parse_any_date($row[$iDeliveryDate] ?? '', TSS_SOURCE_TIMEZONE) ?: $today;
                $ticketNumber = (string)($row[$iTruckload] ?? '');
                $tssPay       = (string)($row[$iCalcRate] ?? '');
                $truckDigits  = digits_only($row[$iTruck] ?? '');

                $first = $useRole === 'delivery' ? ($row[$iDF] ?? '') : ($row[$iPF] ?? '');
                $last  = $useRole === 'delivery' ? ($row[$iDL] ?? '') : ($row[$iPL] ?? '');
                $driverName = trim($first . ' ' . $last);
                $nameNorm   = norm_name($first, $last);

                $pickupFirst = trim((string)($row[$iPF] ?? ''));
                $pickupLast = trim((string)($row[$iPL] ?? ''));
                $deliveryFirst = trim((string)($row[$iDF] ?? ''));
                $deliveryLast = trim((string)($row[$iDL] ?? ''));
                $pickupNameNorm = norm_name($pickupFirst, $pickupLast);
                $deliveryNameNorm = norm_name($deliveryFirst, $deliveryLast);

                // If pickup and delivery drivers differ, require split resolution before payout insert.
                if ($pickupNameNorm !== '' && $deliveryNameNorm !== '' && $pickupNameNorm !== $deliveryNameNorm) {
                  $splitRows[] = [
                    'row_index' => count($splitRows),
                    'original_index' => $i,
                    'headers' => $headersRow,
                    'cells' => $row,
                    'ticket_number' => $ticketNumber,
                    'payout_date' => $payoutDate,
                    'upload_date' => $today,
                    'tss_pay' => $tssPay,
                    'truck_digits' => $truckDigits,
                  ];
                  continue;
                }

                // Exact candidates: same truck digits AND name matches (primary or alias)
                $candsTruck = $byTruck[$truckDigits] ?? [];
                $exact = array_values(array_filter($candsTruck, function($c) use ($nameNorm, $nameMatchesContact){
                  return $nameMatchesContact($nameNorm, (int)$c['id']);
                }));

                if (count($exact) === 1) {
                  $cid = (int)$exact[0]['id'];
                  $ins->bind_param('ssssssi', $payoutDate, $ticketNumber, $driverName, $vendor, $tssPay, $today, $cid);
                  if (!$ins->execute()) {
                    $errors[] = 'Payout insert error: ' . $ins->error;
                    break;
                  }
                  // Save alias for matched contact
                  if ($first !== '' && $last !== '') {
                    $insAlias = $mysqli->prepare(
                      "INSERT IGNORE INTO driver_name_aliases (driver_contact_id, alias_first_name, alias_last_name)
                       VALUES (?,?,?)"
                    );
                    if ($insAlias) {
                      $insAlias->bind_param('iss', $cid, $first, $last);
                      $insAlias->execute();
                      $insAlias->close();
                    }
                  }
                  // Audit: mark raw as matched
                  $upd = $mysqli->prepare("
                    UPDATE ls_detail_raw
                       SET matched_contact_id = ?
                     WHERE `Truckload ID` = ? AND `Delivery Date` = ? AND `upload_date` = ?
                     LIMIT 1
                  ");
                  if ($upd) {
                    $upd->bind_param('isss', $cid, $ticketNumber, $payoutDate, $today);
                    $upd->execute(); $upd->close();
                  }
                  $matchedCount++;
                } else {
                  // Build suggestions: same truck first; plus fuzzy name (across aliases) <= 3
                  $sug = [];
                  foreach ($candsTruck as $c) { $sug[$c['id']] = $c; }
                  foreach ($contacts as $c) {
                    $bestDist = 999;
                    foreach ($namesForId[(int)$c['id']] ?? [] as $cand) {
                      if ($cand === '') continue;
                      $bestDist = min($bestDist, lev($nameNorm, $cand));
                    }
                    if ($bestDist <= 3) $sug[$c['id']] = $c;
                  }
                  // Rank
                  $scored = [];
                  foreach ($sug as $c) {
                    $sameTruck = ($truckDigits !== '' && ($c['truck_norm']===$truckDigits || $c['alt_truck_norm']===$truckDigits)) ? 0 : 1;
                    $bestDist = 999;
                    foreach ($namesForId[(int)$c['id']] ?? [] as $cand) {
                      if ($cand === '') continue;
                      $bestDist = min($bestDist, lev($nameNorm, $cand));
                    }
                    $scored[] = ['c'=>$c,'score'=>[$sameTruck,$bestDist]];
                  }
                  usort($scored, fn($a,$b)=> $a['score'] <=> $b['score']);
                  $ordered = array_slice(array_map(fn($x)=>$x['c'], $scored), 0, 10);

                  // Persist unresolved row for cross-upload review
                  $insU = $mysqli->prepare("
                    INSERT INTO ls_unresolved_matches
                    (upload_date, truckload_id, delivery_date, truck_raw, truck_digits, use_role,
                     pickup_first_name, pickup_last_name, delivery_first_name, delivery_last_name, calc_rate, status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,'unresolved')
                    ON DUPLICATE KEY UPDATE status='unresolved'
                  ");

                  if ($insU) {
                    $pickupFirst = $row[$iPF] ?? '';
                    $pickupLast  = $row[$iPL] ?? '';
                    $delivFirst  = $row[$iDF] ?? '';
                    $delivLast   = $row[$iDL] ?? '';
                    $truckRawVal = $row[$iTruck] ?? '';
                    $insU->bind_param(
                      'sssssssssss',
                      $today,
                      $ticketNumber,
                      $payoutDate,
                      $truckRawVal,
                      $truckDigits,
                      $useRole,
                      $pickupFirst,
                      $pickupLast,
                      $delivFirst,
                      $delivLast,
                      $tssPay
                    );
                    $insU->execute();
                    $insU->close();
                  }

                  // Also hold in session for immediate UI resolution
                  $unmatched[] = [
                    'row_index' => count($unmatched),
                    'original_index' => $i,
                    'headers' => $headersRow,
                    'cells'   => $row,
                    'use_role'=> $useRole,
                    'driver_name' => $driverName,
                    'truck_digits'=> $truckDigits,
                    'suggestions' => $ordered
                  ];
                }
              } // foreach row
              $ins->close();

              $_SESSION['unmatched_rows'] = $unmatched;
              $_SESSION['split_rows'] = $splitRows;
              $success = empty($errors);

              // Email summary
              $to      = 'henrymarrero87@gmail.com';
              $subject = 'LS Detail Upload ' . ($success ? 'Success' : 'Error');
              $body    = $success
                ? "Upload succeeded on $today.\nMatched payouts inserted: $matchedCount\nSplit rows needing review: " . count($splitRows) . "\nUnmatched needing review: " . count($unmatched)
                : "Upload errors:\n- " . implode("\n- ", $errors);
              @mail($to, $subject, $body, "From: no-reply@lsonestarroadside.com\r\n");
            }
          }
        }
      }
    } elseif (empty($errors)) {
      $errors[] = 'Parse error: ' . SimpleXLSX::parseError();
    }

    if ($lsDetailHashReserved && $lsDetailFileHash) {
      if (empty($errors) && $success) {
        complete_ls_detail_upload_hash($mysqli, $lsDetailFileHash);
      } else {
        release_ls_detail_upload_hash($mysqli, $lsDetailFileHash);
      }
    }
  }
}

// Keep page state current after any resolve/upload action in this request.
$openUnresolvedCount = get_open_unresolved_count($mysqli);
$openSplitCount = isset($_SESSION['split_rows']) ? count($_SESSION['split_rows']) : 0;
$hasLsDetailForToday = has_ls_detail_upload_for_date($mysqli, $todayDate);
$hasTssPayoutForToday = has_tss_payout_upload_for_date($mysqli, $todayDate);
$rtexWeekOptions = get_rtex_payout_week_options($mysqli);
$nextierWeekOptions = get_nextier_payout_week_options($mysqli);
$nickelrockWeekOptions = get_nickelrock_payout_week_options($mysqli);
$selectedUploadVendor = in_array(($_GET['vendor'] ?? ''), ['tss', 'nextier', 'rtex', 'nickelrock'], true) ? (string)$_GET['vendor'] : 'tss';
if (in_array($lastUploadType, ['nextier_payout', 'nextier_review', 'nextier_misc_adjustment'], true)) {
  $selectedUploadVendor = 'nextier';
}
if (in_array($lastUploadType, ['rtex_payout', 'rtex_review', 'rtex_misc_adjustment'], true)) {
  $selectedUploadVendor = 'rtex';
}
if (in_array($lastUploadType, ['nickelrock_review', 'nickelrock_misc_adjustment'], true)) {
  $selectedUploadVendor = 'nickelrock';
}
if ($lastUploadType === 'tss_review') {
  $selectedUploadVendor = 'tss';
}
if ($lastUploadType === 'vendor_broker_fee') {
  $selectedUploadVendor = lonestar_payout_normalize_vendor_scope((string)($_POST['vendor_scope'] ?? $selectedUploadVendor));
  if ($selectedUploadVendor === 'tss_company') $selectedUploadVendor = 'tss';
}
$vendorBrokerFeeSettings = [];
foreach (lonestar_payout_vendor_order() as $vendorScopeForSettings) {
  $vendorBrokerFeeSettings[$vendorScopeForSettings] = lonestar_vendor_broker_fee_settings($mysqli, $vendorScopeForSettings);
}
$vendorBrokerFeeSettings['tss_company'] = lonestar_vendor_broker_fee_settings($mysqli, 'tss_company');
$tssReviewWeekOptions = get_tss_review_week_options($mysqli);
$tssReviewWeekStart = trim((string)($_POST['tss_week_start'] ?? ($_GET['tss_week_start'] ?? '')));
if ($lastUploadType === 'ls_detail' && !empty($dataRows)) {
  $tssReviewWeekStart = business_sunday_week_start($todayDate);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tssReviewWeekStart)) {
  $tssReviewWeekStart = $tssReviewWeekOptions[0] ?? business_sunday_week_start($todayDate);
}
$tssReviewRows = get_tss_review_rows($mysqli, $tssReviewWeekStart);
$rtexReviewWeekStart = trim((string)($_POST['rtex_week_start'] ?? ($_GET['rtex_week_start'] ?? '')));
if ($lastUploadType === 'rtex_payout' && is_array($rtexPayoutSummary) && !empty($rtexPayoutSummary['last_week_start'])) {
  $rtexReviewWeekStart = (string)$rtexPayoutSummary['last_week_start'];
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rtexReviewWeekStart)) {
  $rtexReviewWeekStart = $rtexWeekOptions[0] ?? business_sunday_week_start($todayDate);
}
$rtexReviewRows = array_values(array_filter(get_rtex_review_rows($mysqli, $rtexReviewWeekStart), static fn($row) => ($row['billing_mode'] ?? 'hourly') === $rtexMode));
$rtexJobRates = rtex_job_rates($mysqli);
$rtexFscSettings = rtex_fsc_settings($mysqli,$rtexReviewWeekStart);
$nextierReviewWeekStart = trim((string)($_POST['nextier_week_start'] ?? ($_GET['nextier_week_start'] ?? '')));
if ($lastUploadType === 'nextier_payout' && is_array($nextierPayoutSummary) && !empty($nextierPayoutSummary['last_week_start'])) {
  $nextierReviewWeekStart = (string)$nextierPayoutSummary['last_week_start'];
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextierReviewWeekStart)) {
  $nextierReviewWeekStart = $nextierWeekOptions[0] ?? business_sunday_week_start($todayDate);
}
$nextierReviewRows = get_nextier_review_rows($mysqli, $nextierReviewWeekStart);
$nextierTrailerReconciliation = get_nextier_trailer_reconciliation($mysqli, $nextierReviewWeekStart);
$nextierTrailerCatchupsApplied = get_nextier_trailer_catchups_applied_to_week($mysqli, $nextierReviewWeekStart);
$nickelrockReviewWeekStart = trim((string)($_POST['nickelrock_week_start'] ?? ($_GET['nickelrock_week_start'] ?? '')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $nickelrockReviewWeekStart)) {
  $nickelrockReviewWeekStart = $nickelrockWeekOptions[0] ?? business_sunday_week_start($todayDate);
}
$nickelrockJobRates = get_nickelrock_job_rates($mysqli);
$nickelrockReviewRows = get_nickelrock_review_rows($mysqli, $nickelrockReviewWeekStart);

function render_vendor_broker_fee_form(array $settings, ?array $rtexFsc = null, string $rtexWeek = ''): void {
  $withFsc = $rtexFsc !== null && ($settings['vendor_scope'] ?? '') === 'rtex';
  $scope = (string)($settings['vendor_scope'] ?? 'tss');
  $label = (string)($settings['label'] ?? strtoupper($scope));
  $mode = (string)($settings['fee_mode'] ?? 'percentage');
  $value = number_format((float)($settings['fee_value'] ?? 0), 2, '.', '');
  ?>
  <form method="post" class="vendor-broker-settings mb-4">
    <input type="hidden" name="action" value="<?= $withFsc ? 'save_rtex_load_settings' : 'save_vendor_broker_fee' ?>">
    <?php if ($withFsc): ?>
      <input type="hidden" name="rtex_mode" value="load">
      <input type="hidden" name="rtex_week_start" value="<?= h($rtexWeek) ?>">
      <input type="hidden" name="rtex_csrf" value="<?= h($_SESSION['rtex_csrf']) ?>">
    <?php endif; ?>
    <input type="hidden" name="vendor_scope" value="<?= h($scope) ?>">
    <div class="row g-3 align-items-end">
      <div class="col-12 col-lg">
        <h3 class="h6 mb-1"><?= h($label) ?> Broker Fee</h3>
      </div>
      <div class="col-12 col-md-3 col-lg-2">
        <label class="form-label">Measure</label>
        <select name="fee_mode" class="form-select">
          <option value="percentage" <?= $mode === 'percentage' ? 'selected' : '' ?>>Percentage</option>
          <option value="flat" <?= $mode === 'flat' ? 'selected' : '' ?>>Dollar Amount</option>
        </select>
      </div>
      <div class="col-12 col-md-3 col-lg-2">
        <label class="form-label">Broker Fee</label>
        <input type="number" name="fee_value" value="<?= h($value) ?>" class="form-control" min="0" step="0.01">
      </div>
      <?php if ($withFsc): ?>
      <div class="col-12 col-md-3 col-lg-2">
        <label for="rtexDriverFscRate" class="form-label">Driver FSC Rate (%)</label>
        <input id="rtexDriverFscRate" type="number" name="driver_fsc_rate" value="<?= h(number_format((float)$rtexFsc['driver_fsc_rate'],2,'.','')) ?>" class="form-control" min="0" max="100" step="0.01" required>
      </div>
      <div class="col-12 col-md-3 col-lg-2">
        <label for="rtexInvoiceFscRate" class="form-label">RTEX Invoice FSC Rate (%)</label>
        <input id="rtexInvoiceFscRate" type="number" name="invoice_fsc_rate" value="<?= h(number_format((float)$rtexFsc['invoice_fsc_rate'],2,'.','')) ?>" class="form-control" min="0" max="100" step="0.01" required>
      </div>
      <?php endif; ?>
      <div class="col-auto">
        <button type="submit" class="btn btn-outline-primary"><?= $withFsc ? 'Save Settings' : 'Save Fee' ?></button>
      </div>
      <?php if ($withFsc): ?>
      <div class="col-12 small text-muted">FSC rates apply to load-based work for <?= h($rtexWeek) ?> through <?= h(business_week_end($rtexWeek)) ?>. Driver FSC is paid separately without broker fees. Invoice FSC appears only in the RTEX invoice export. Each new week starts at 0%.</div>
      <?php endif; ?>
    </div>
  </form>
  <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Upload LS Detail</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body { margin:0; font-family:sans-serif; }
    .page-shell { display:flex; min-height: calc(100vh - var(--banner-h)); }
    .sidebar { width:250px; background:#333; color:#fff; height:calc(100vh - var(--banner-h)); position:fixed; top:var(--banner-h); overflow:auto; transition:transform .3s; }
    .sidebar.collapsed { transform:translateX(-250px); }
    .sidebar a { display:block; color:#fff; padding:15px; text-decoration:none; }
    .sidebar a:hover { background:#444; }
    .main { margin-left:250px; padding:20px; flex:1; min-width:0; }
    .sidebar.collapsed + .main { margin-left:0; }
    .vendor-tabs {
      display: flex;
      flex-wrap: wrap;
      gap: 0;
      align-items: flex-end;
      border-bottom: 1px solid #9ca3af;
      margin: 16px 0 18px;
    }
    .vendor-tab {
      background: #e5e7eb;
      border: 1px solid #9ca3af;
      border-bottom: 0;
      border-radius: 8px 8px 0 0;
      color: #111827;
      font-weight: 600;
      margin: 0 0 -1px -1px;
      padding: 9px 18px;
      text-decoration: none;
      transition: background-color .12s ease, color .12s ease;
    }
    .vendor-tab:first-child { margin-left: 0; }
    .vendor-tab:hover {
      background: #f3f4f6;
      color: #111827;
    }
    .vendor-tab.active {
      background: #ffffff;
      border-color: #9ca3af;
      border-bottom: 1px solid #ffffff;
      position: relative;
      z-index: 1;
    }
    .vendor-broker-settings {
      background: #f8fafc;
      border: 1px solid #d9e2ef;
      border-radius: 8px;
      padding: 14px;
    }
    .tss-broker-fee-row {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
      align-items: stretch;
      margin-bottom: 1.5rem;
    }
    .tss-broker-fee-row .vendor-broker-settings {
      margin-bottom: 0 !important;
      height: 100%;
    }
    .tss-broker-fee-row .vendor-broker-settings .row {
      --bs-gutter-x: .75rem;
      --bs-gutter-y: .5rem;
    }
    .tss-broker-fee-row .vendor-broker-settings h3 {
      white-space: nowrap;
    }
    @media(max-width:768px) {
      .sidebar { transform:translateX(-250px); }
      .sidebar.open { transform:translateX(0); }
      .main{margin:0;}
      .vendor-tab {
        flex: 1 1 50%;
        margin-top: -1px;
        text-align: center;
      }
      .tss-broker-fee-row {
        grid-template-columns: 1fr;
      }
    }

    .modal-lg { max-width: 900px; }
    .candidate { border:1px solid #e5e5e5; border-radius:6px; padding:.5rem .75rem; margin-bottom:.5rem; }
    .candidate .small { color:#666; }
    .nowrap { white-space: nowrap; }
    .table-preview { font-size:.9rem; }
    .misc-adjustment-locked { opacity:.7; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/ls_title.php'; ?>
  <div class="page-shell">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
    
    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger mt-3">
        <strong>Errors:</strong>
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?>
            <li><?= h($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php elseif ($success): ?>
      <div class="alert alert-success mt-3">
        <?php if (!empty($splitResolveSummary)): ?>
          <?= h($splitResolveSummary) ?>
        <?php elseif ($lastUploadType === 'misc_adjustment'): ?>
          Miscellaneous payment adjustment saved.
        <?php elseif ($lastUploadType === 'rtex_misc_adjustment'): ?>
          RTEX miscellaneous payment adjustment saved.
        <?php elseif ($lastUploadType === 'nextier_misc_adjustment'): ?>
          NexTier miscellaneous payment adjustment saved.
        <?php elseif ($lastUploadType === 'nickelrock_misc_adjustment'): ?>
          Nickel Rock miscellaneous payment adjustment saved.
        <?php elseif ($lastUploadType === 'tss_review' && is_array($tssPayoutSummary)): ?>
          <?= h($tssPayoutSummary['review_message'] ?? 'TSS row updated.') ?>
        <?php elseif ($lastUploadType === 'rtex_review' && is_array($rtexPayoutSummary)): ?>
          <?= h($rtexPayoutSummary['review_message'] ?? 'RTEX row updated.') ?>
        <?php elseif ($lastUploadType === 'nextier_review' && is_array($nextierPayoutSummary)): ?>
          <?= h($nextierPayoutSummary['review_message'] ?? 'NexTier row updated.') ?>
        <?php elseif ($lastUploadType === 'nickelrock_review' && is_array($nickelrockPayoutSummary)): ?>
          <?= h($nickelrockPayoutSummary['review_message'] ?? 'Nickel Rock row updated.') ?>
        <?php elseif ($lastUploadType === 'tss_payout' && is_array($tssPayoutSummary)): ?>
          TSS payout file processed.
          Ticket rows: <?= (int)$tssPayoutSummary['ticket_rows'] ?>,
          Misc rows: <?= (int)$tssPayoutSummary['misc_rows'] ?>,
          Trailer usage skipped: <?= (int)($tssPayoutSummary['trailer_usage_skipped'] ?? 0) ?>,
          Misc rows added to payouts: <?= (int)$tssPayoutSummary['misc_inserted_to_payouts'] ?>,
          Misc rows missing driver match: <?= (int)$tssPayoutSummary['misc_unmatched'] ?>.
        <?php elseif ($lastUploadType === 'rtex_payout' && is_array($rtexPayoutSummary)): ?>
          RTEX invoice file processed.
          Rows found: <?= (int)$rtexPayoutSummary['rows_seen'] ?>,
          payout rows added: <?= (int)$rtexPayoutSummary['payout_rows_inserted'] ?>,
          existing payout rows skipped: <?= (int)$rtexPayoutSummary['payout_rows_existing'] ?>,
          matched drivers: <?= (int)$rtexPayoutSummary['matched'] ?>,
          unmatched drivers: <?= (int)$rtexPayoutSummary['unmatched'] ?>,
          surcharge excluded from driver pay: $<?= number_format((float)$rtexPayoutSummary['surcharge_skipped'], 2) ?>.
        <?php elseif ($lastUploadType === 'nextier_payout' && is_array($nextierPayoutSummary)): ?>
          NexTier statement processed.
          Rows found: <?= (int)$nextierPayoutSummary['rows_seen'] ?>,
          payout rows added: <?= (int)$nextierPayoutSummary['payout_rows_inserted'] ?>,
          existing payout rows updated: <?= (int)$nextierPayoutSummary['payout_rows_existing'] ?>,
          matched drivers: <?= (int)$nextierPayoutSummary['matched'] ?>,
          unmatched drivers: <?= (int)$nextierPayoutSummary['unmatched'] ?>.
          <?php if ((int)($nextierPayoutSummary['trailer_recon_rows'] ?? 0) > 0): ?>
            Trailer rental reconciliation rows found: <?= (int)$nextierPayoutSummary['trailer_recon_rows'] ?>,
            billed trailer rental total: $<?= number_format((float)($nextierPayoutSummary['trailer_recon_billed_total'] ?? 0), 2) ?>.
            Catch-up adjustments applied to this payout: <?= (int)($nextierPayoutSummary['trailer_recon_catchup_adjustments'] ?? 0) ?>.
          <?php endif; ?>
        <?php else: ?>
          LS Detail file processed.
          <?= isset($_SESSION['split_rows']) ? count($_SESSION['split_rows']) : 0 ?> split row(s) need review and
          <?= isset($_SESSION['unmatched_rows']) ? count($_SESSION['unmatched_rows']) : 0 ?> unmatched row(s) need review.
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <nav class="vendor-tabs" aria-label="Upload client">
      <?php
        $uploadVendorTabs = [
          'tss' => 'TSS',
          'nextier' => 'NexTier',
          'rtex' => 'RTEX',
          'nickelrock' => 'Nickel Rock',
        ];
      ?>
      <?php foreach ($uploadVendorTabs as $value => $label): ?>
        <?php
          $vendorTabQuery = $_GET;
          $vendorTabQuery['vendor'] = $value;
          $vendorTabUrl = '?' . http_build_query($vendorTabQuery);
          $isActiveVendorTab = $selectedUploadVendor === $value;
        ?>
        <a
          href="<?= h($vendorTabUrl) ?>"
          class="vendor-tab<?= $isActiveVendorTab ? ' active' : '' ?>"
          data-vendor-tab="<?= h($value) ?>"
          <?= $isActiveVendorTab ? 'aria-current="page"' : '' ?>
        ><?= h($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="mt-3 vendor-form" data-vendor="tss">
      <div class="d-flex align-items-center justify-content-between">
        <h2 class="mb-0">Upload “LS Detail” File</h2>
      </div><br />
      <div class="tss-broker-fee-row">
        <?php render_vendor_broker_fee_form($vendorBrokerFeeSettings['tss']); ?>
        <?php render_vendor_broker_fee_form($vendorBrokerFeeSettings['tss_company']); ?>
      </div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="upload_mode" value="ls_detail">
        <div class="row g-3 align-items-end">
          <div class="col-auto">
            <label class="form-label">Select Excel (.xlsx)</label>
            <input type="file" name="xlsx_file" accept=".xlsx" required class="form-control">
          </div>
          <div class="col-auto">
            <label class="form-label">Use driver from</label>
            <select name="use_role" class="form-select">
              <?php $useRoleSel = $_SESSION['use_role'] ?? 'pickup'; ?>
              <option value="pickup"   <?= ($useRoleSel==='pickup')?'selected':'' ?>>Pickup (first/last)</option>
              <option value="delivery" <?= ($useRoleSel==='delivery')?'selected':'' ?>>Delivery (first/last)</option>
            </select>
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-primary">Upload &amp; Process</button>
          </div>
          <div class="col-auto">
            <a class="btn btn-outline-secondary" href="unresolved.php">Open Unresolved Review</a>
          </div>
        </div>
      </form>

      <hr class="my-4">
      <div class="d-flex align-items-center justify-content-between">
        <h2 class="mb-0">Upload “TSS Payout” File</h2>
      </div><br />
      <form method="post" enctype="multipart/form-data" id="tssPayoutForm">
        <input type="hidden" name="upload_mode" value="tss_payout">
        <div class="row g-3 align-items-end">
          <div class="col-auto">
            <label class="form-label">Select Payout Excel (.xlsx)</label>
            <input type="file" name="payout_file" accept=".xlsx" required class="form-control">
          </div>
          <div class="col-12">
            <div class="small text-muted">
              LS Detail uploaded for <?= h($todayDate) ?>: <strong><?= $hasLsDetailForToday ? 'Yes' : 'No' ?></strong>
            </div>
            <div class="small text-muted">
              Open Unresolved Matches: <strong><?= (int)$openUnresolvedCount ?></strong>
            </div>
            <div class="small text-muted">
              Split Rows Pending Review: <strong><?= (int)$openSplitCount ?></strong>
            </div>
          </div>
          <div class="col-auto">
            <button
              type="submit"
              class="btn btn-primary"
              <?= (!$hasLsDetailForToday || $openSplitCount > 0) ? 'disabled aria-disabled="true"' : '' ?>
            >Upload Payout File</button>
          </div>
        </div>
      </form>

      <hr class="my-4">
      <div class="d-flex align-items-center justify-content-between">
        <h2 class="mb-0">Miscellaneous Payment Adjustments</h2>
      </div><br />
      <form method="post" id="miscAdjustmentForm">
        <input type="hidden" name="action" value="save_misc_adjustment">
        <input type="hidden" name="adjustment_vendor" value="TSS">
        <div class="row g-3 align-items-end">
          <div class="col-12">
            <div class="form-check">
              <input
                class="form-check-input"
                type="checkbox"
                id="allowWithoutDailyUploads"
                name="allow_without_daily_uploads"
                value="1"
                <?= !empty($miscAdjustmentForm['allow_without_daily_uploads']) ? 'checked' : '' ?>
              >
              <label class="form-check-label" for="allowWithoutDailyUploads">
                Allow miscellaneous payment adjustments even if today's LS Detail and TSS Payout files were not uploaded.
              </label>
            </div>
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Driver</label>
            <select name="driver_contact_id" class="form-select misc-lockable">
              <option value="">Select driver</option>
              <?php foreach ($driverOptions as $driver): ?>
                <option value="<?= (int)$driver['id'] ?>" <?= ((string)$driver['id'] === (string)$miscAdjustmentForm['driver_contact_id']) ? 'selected' : '' ?>>
                  <?= h($driver['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Payout Week</label>
            <select name="payout_week_start" class="form-select misc-lockable">
              <?php foreach ($payoutWeekOptions as $weekStart): ?>
                <option value="<?= h($weekStart) ?>" <?= $weekStart === $miscAdjustmentForm['payout_week_start'] ? 'selected' : '' ?>>
                  <?= h($weekStart) ?> to <?= h(business_week_end($weekStart)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-2">
            <label class="form-label">Type</label>
            <select name="adjustment_type" class="form-select misc-lockable">
              <option value="misc_payment" <?= $miscAdjustmentForm['adjustment_type'] === 'misc_payment' ? 'selected' : '' ?>>Misc Payment</option>
              <option value="misc_deduction" <?= $miscAdjustmentForm['adjustment_type'] === 'misc_deduction' ? 'selected' : '' ?>>Misc Deduction</option>
            </select>
          </div>
          <div class="col-12 col-md-2">
            <label class="form-label">Amount</label>
            <input type="number" step="0.01" min="0.01" name="amount" value="<?= h($miscAdjustmentForm['amount']) ?>" class="form-control misc-lockable">
          </div>
          <div class="col-12 col-md-2">
            <label class="form-label">Comments</label>
            <input type="text" name="comments" value="<?= h($miscAdjustmentForm['comments']) ?>" class="form-control misc-lockable" placeholder="Explain adjustment">
          </div>
          <div class="col-12">
            <div class="small text-muted">
              LS Detail uploaded for <?= h($todayDate) ?>: <strong><?= $hasLsDetailForToday ? 'Yes' : 'No' ?></strong>,
              TSS Payout uploaded for <?= h($todayDate) ?>: <strong><?= $hasTssPayoutForToday ? 'Yes' : 'No' ?></strong>.
              Adjustments remain locked until both uploads are completed for today unless you enable the override checkbox above.
            </div>
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-primary">Save Adjustment</button>
          </div>
        </div>
      </form>

      <hr class="my-4">
      <div class="d-flex align-items-center justify-content-between">
        <h2 class="mb-0">Driver Bonuses</h2>
      </div><br />
      <form method="get" id="driverBonusesForm">
        <div class="row g-3 align-items-end">
          <div class="col-12">
            <div class="form-check">
              <input
                class="form-check-input"
                type="checkbox"
                id="allowDriverBonusesWithoutDailyUploads"
                name="allow_bonus_without_daily_uploads"
                value="1"
                <?= !empty($bonusReconciliationForm['allow_without_daily_uploads']) ? 'checked' : '' ?>
              >
              <label class="form-check-label" for="allowDriverBonusesWithoutDailyUploads">
                Allow driver bonuses even if today's LS Detail and TSS Payout files were not uploaded.
              </label>
            </div>
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Payout Week</label>
            <select name="bonus_payout_week_start" class="form-select bonus-lockable">
              <?php foreach ($payoutWeekOptions as $weekStart): ?>
                <option value="<?= h($weekStart) ?>" <?= $weekStart === $bonusReconciliationForm['payout_week_start'] ? 'selected' : '' ?>>
                  <?= h($weekStart) ?> to <?= h(business_week_end($weekStart)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-primary">Review Bonuses</button>
          </div>
          <div class="col-12">
            <div class="small text-muted">
              This compares each driver's LS Detail <strong>Total Bonuses</strong> value with the total <strong>Miscellaneous Revenue</strong> rows from the TSS payout upload for the selected week.
              LS Detail uploaded for <?= h($todayDate) ?>: <strong><?= $hasLsDetailForToday ? 'Yes' : 'No' ?></strong>,
              TSS Payout uploaded for <?= h($todayDate) ?>: <strong><?= $hasTssPayoutForToday ? 'Yes' : 'No' ?></strong>.
              Bonus review remains locked until both uploads are completed for today unless you enable the override checkbox above.
            </div>
          </div>
        </div>
      </form>

      <?php if (!$bonusRequiresDailyUploads && empty($bonusReconciliationRows)): ?>
        <div class="alert alert-info mt-3 mb-0">No driver bonus totals were found for the selected payout week.</div>
      <?php elseif ($bonusRequiresDailyUploads && empty($bonusReconciliationForm['allow_without_daily_uploads'])): ?>
        <div class="alert alert-warning mt-3 mb-0">Driver bonus review is locked until both today's LS Detail and TSS Payout files are uploaded, unless you enable the override checkbox above.</div>
      <?php elseif (!empty($bonusReconciliationRows)): ?>
        <div class="table-responsive mt-3">
          <table class="table table-striped table-bordered align-middle">
            <thead>
              <tr>
                <th>#</th>
                <th>Driver</th>
                <th>LS Detail Total Bonuses</th>
                <th>TSS Miscellaneous Revenue</th>
                <th>Difference</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($bonusReconciliationRows as $index => $bonusRow): ?>
                <tr class="<?= $bonusRow['matches'] ? '' : 'table-warning' ?>">
                  <td><?= (int)$index + 1 ?></td>
                  <td><?= h($bonusRow['driver_name']) ?></td>
                  <td>$<?= number_format((float)$bonusRow['ls_total_bonuses'], 2) ?></td>
                  <td>$<?= number_format((float)$bonusRow['tss_bonus_total'], 2) ?></td>
                  <td>$<?= number_format((float)$bonusRow['difference'], 2) ?></td>
                  <td>
                    <?php if ($bonusRow['matches']): ?>
                      <span class="badge bg-success">Matched</span>
                    <?php else: ?>
                      <span class="badge bg-warning text-dark">Mismatch</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <hr class="my-4">
      <div class="d-flex align-items-center justify-content-between">
        <h2 class="mb-0">Fuel Surcharge</h2>
      </div><br />
      <form method="get" id="fuelSurchargeForm">
        <div class="row g-3 align-items-end">
          <div class="col-12 col-md-3">
            <label class="form-label">Payout Week</label>
            <select name="fuel_surcharge_week_start" class="form-select">
              <?php foreach ($payoutWeekOptions as $weekStart): ?>
                <option value="<?= h($weekStart) ?>" <?= $weekStart === $fuelSurchargeForm['payout_week_start'] ? 'selected' : '' ?>>
                  <?= h($weekStart) ?> to <?= h(business_week_end($weekStart)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-primary">Review Fuel Surcharge</button>
          </div>
          <div class="col-12">
            <div class="small text-muted">
              This summarizes LS Detail rows for the selected week where <strong>Fuel Surcharge Rate</strong> is greater than $0.
              Each load is calculated using its <strong>Fuel Surcharge Type</strong>: mileage uses miles × rate, and tonnage uses net tons × rate.
            </div>
          </div>
        </div>
      </form>

      <?php if (empty($fuelSurchargeRows)): ?>
        <div class="alert alert-info mt-3 mb-0">No fuel surcharge rows were found for the selected payout week.</div>
      <?php else: ?>
        <div class="table-responsive mt-3">
          <table class="table table-striped table-bordered align-middle">
            <thead>
              <tr>
                <th>#</th>
                <th>Driver Name</th>
                <th>Truck Number</th>
                <th>Total Miles</th>
                <th>Total Tons</th>
                <th>Total Fuel Surcharge</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($fuelSurchargeRows as $index => $fuelRow): ?>
                <tr>
                  <td><?= (int)$index + 1 ?></td>
                  <td><?= h($fuelRow['driver_name']) ?></td>
                  <td><?= h($fuelRow['truck_number']) ?></td>
                  <td><?= number_format((float)$fuelRow['total_miles'], 2) ?></td>
                  <td><?= number_format((float)$fuelRow['total_tons'], 2) ?></td>
                  <td>$<?= number_format((float)$fuelRow['total_fuel_surcharge'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <th colspan="3" class="text-end">Totals</th>
                <th><?= number_format(array_sum(array_map(static fn($r) => (float)$r['total_miles'], $fuelSurchargeRows)), 2) ?></th>
                <th><?= number_format(array_sum(array_map(static fn($r) => (float)$r['total_tons'], $fuelSurchargeRows)), 2) ?></th>
                <th>$<?= number_format(array_sum(array_map(static fn($r) => (float)$r['total_fuel_surcharge'], $fuelSurchargeRows)), 2) ?></th>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>

      <hr class="my-4">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h2 class="mb-0">TSS Weekly Review</h2>
      </div><br />
      <form method="get" class="row g-3 align-items-end mb-3">
        <input type="hidden" name="vendor" value="tss">
        <div class="col-12 col-md-3">
          <label class="form-label">Payout Week</label>
          <select name="tss_week_start" class="form-select">
            <?php foreach ($tssReviewWeekOptions as $weekStart): ?>
              <option value="<?= h($weekStart) ?>" <?= $weekStart === $tssReviewWeekStart ? 'selected' : '' ?>>
                <?= h($weekStart) ?> to <?= h(business_week_end($weekStart)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-outline-primary">View Week</button>
        </div>
      </form>

      <?php if (empty($tssReviewRows)): ?>
        <div class="alert alert-info">No TSS LS Detail rows were found for this week.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-striped table-bordered table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>#</th>
                <th>Matched Driver</th>
                <th>Driver Type</th>
                <?php foreach ($expectedHeaders as $header): ?>
                  <th><?= h($header) ?></th>
                <?php endforeach; ?>
                <th>TSS Trailer %</th>
                <th>Total Bonuses</th>
                <th>Fuel Surcharge Rate</th>
                <th>Fuel Surcharge Type</th>
                <th>Broker Fee Override %</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($tssReviewRows as $index => $tssRow): ?>
                <?php
                  $tssFormId = 'tssReviewRowForm' . md5((string)($tssRow['upload_date'] ?? '') . '|' . (string)($tssRow['Truckload ID'] ?? '') . '|' . (string)($tssRow['Delivery Date'] ?? '') . '|' . (string)($tssRow['Truck #'] ?? '') . '|' . (string)$index);
                  $brokerOverride = $tssRow['broker_fee_override_pct'] ?? '';
                  $tssMatchedId = (int)($tssRow['matched_contact_id'] ?? 0);
                ?>
                <tr>
                  <td>
                    <?= (int)$index + 1 ?>
                    <form id="<?= h($tssFormId) ?>" method="post"></form>
                    <input form="<?= h($tssFormId) ?>" type="hidden" name="action" value="save_tss_review_row">
                    <input form="<?= h($tssFormId) ?>" type="hidden" name="tss_week_start" value="<?= h($tssReviewWeekStart) ?>">
                    <input form="<?= h($tssFormId) ?>" type="hidden" name="old_truckload_id" value="<?= h($tssRow['Truckload ID'] ?? '') ?>">
                    <input form="<?= h($tssFormId) ?>" type="hidden" name="old_delivery_date" value="<?= h($tssRow['Delivery Date'] ?? '') ?>">
                    <input form="<?= h($tssFormId) ?>" type="hidden" name="old_upload_date" value="<?= h($tssRow['upload_date'] ?? '') ?>">
                    <input form="<?= h($tssFormId) ?>" type="hidden" name="old_truck_raw" value="<?= h($tssRow['Truck #'] ?? '') ?>">
                  </td>
                  <td>
                    <select form="<?= h($tssFormId) ?>" name="matched_contact_id" class="form-select form-select-sm" style="min-width: 180px;">
                      <option value="">Unmatched</option>
                      <?php foreach ($driverOptions as $driver): ?>
                        <option value="<?= (int)$driver['id'] ?>" <?= $tssMatchedId === (int)$driver['id'] ? 'selected' : '' ?>>
                          <?= h($driver['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><?= h($tssRow['matched_driver_type'] ?: '') ?></td>
                  <?php foreach ($expectedHeaders as $header): ?>
                    <?php
                      $cellValue = (string)($tssRow[$header] ?? '');
                      $inputType = $header === 'Delivery Date' ? 'date' : 'text';
                      if ($header === 'Delivery Date') {
                        $cellValue = parse_any_date($cellValue, TSS_SOURCE_TIMEZONE) ?: $cellValue;
                      }
                    ?>
                    <td>
                      <input
                        form="<?= h($tssFormId) ?>"
                        type="<?= h($inputType) ?>"
                        name="ls_detail[<?= h($header) ?>]"
                        value="<?= h($cellValue) ?>"
                        class="form-control form-control-sm"
                        style="min-width: <?= $header === 'Delivery Date' ? '140px' : '150px' ?>;"
                      >
                    </td>
                  <?php endforeach; ?>
                  <td>
                    <input form="<?= h($tssFormId) ?>" type="number" name="tss_trailer_pct_override" value="<?= h(($tssRow['tss_trailer_pct_override'] ?? '') !== '' && $tssRow['tss_trailer_pct_override'] !== null ? number_format((float)$tssRow['tss_trailer_pct_override'], 2, '.', '') : '') ?>" min="0" max="100" step="0.01" class="form-control form-control-sm" style="min-width: 110px;">
                  </td>
                  <td>
                    <input form="<?= h($tssFormId) ?>" type="number" name="total_bonuses" value="<?= h(($tssRow['total_bonuses'] ?? '') !== '' && $tssRow['total_bonuses'] !== null ? number_format((float)$tssRow['total_bonuses'], 2, '.', '') : '') ?>" step="0.01" class="form-control form-control-sm" style="min-width: 120px;">
                  </td>
                  <td>
                    <input form="<?= h($tssFormId) ?>" type="number" name="fuel_surcharge_rate" value="<?= h(($tssRow['fuel_surcharge_rate'] ?? '') !== '' && $tssRow['fuel_surcharge_rate'] !== null ? number_format((float)$tssRow['fuel_surcharge_rate'], 4, '.', '') : '') ?>" step="0.0001" class="form-control form-control-sm" style="min-width: 120px;">
                  </td>
                  <td>
                    <?php $fuelSurchargeType = normalize_fuel_surcharge_type($tssRow['fuel_surcharge_type'] ?? 'mileage'); ?>
                    <select form="<?= h($tssFormId) ?>" name="fuel_surcharge_type" class="form-select form-select-sm" style="min-width: 120px;">
                      <option value="mileage" <?= $fuelSurchargeType === 'mileage' ? 'selected' : '' ?>>Mileage</option>
                      <option value="tonnage" <?= $fuelSurchargeType === 'tonnage' ? 'selected' : '' ?>>Tonnage</option>
                    </select>
                  </td>
                  <td>
                    <input form="<?= h($tssFormId) ?>" type="number" name="broker_fee_override_pct" value="<?= h($brokerOverride !== '' && $brokerOverride !== null ? number_format((float)$brokerOverride, 2, '.', '') : '') ?>" min="0" max="100" step="0.01" class="form-control form-control-sm" style="min-width: 110px;">
                  </td>
                  <td class="nowrap">
                    <button form="<?= h($tssFormId) ?>" type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                  </td>
                </tr>
              <?php endforeach; ?>            </tbody>
            <tfoot>
              <tr>
                <th colspan="13" class="text-end">Totals</th>
                <th>$<?= number_format(array_sum(array_map(static fn($r) => (float)str_replace(',', '', (string)($r['Base Freight Rate (Carrier)'] ?? 0)), $tssReviewRows)), 2) ?></th>
                <th>$<?= number_format(array_sum(array_map(static fn($r) => (float)str_replace(',', '', (string)($r['Calculated Freight Rate (Carrier)'] ?? 0)), $tssReviewRows)), 2) ?></th>
                <th colspan="<?= count($expectedHeaders) - 11 + 5 ?>"></th>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="mt-3 vendor-form d-none" data-vendor="nickelrock" id="nickelrock-section">
      <div class="d-flex align-items-center justify-content-between">
        <h2 class="mb-0">Nickel Rock Manual Entry</h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#nickelrockEntryModal">Add Nickel Rock Row</button>
      </div><br />
      <?php render_vendor_broker_fee_form($vendorBrokerFeeSettings['nickelrock']); ?>
      <div class="border rounded p-3 mt-3 mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <h3 class="h5 mb-0">Nickel Rock Job Rate Key</h3>
          <span class="small text-muted">Job selections automatically apply the standard rate and work order.</span>
        </div>
        <div class="table-responsive mb-3">
          <table class="table table-sm table-bordered align-middle mb-0">
            <thead class="table-light">
              <tr><th>Job Name</th><th style="width:180px">Rate</th><th style="width:180px">Work Order</th><th style="width:160px">Actions</th></tr>
            </thead>
            <tbody>
              <?php if ($nickelrockJobRates): ?>
                <?php foreach ($nickelrockJobRates as $jobRate): ?>
                  <?php $jobRateFormId = 'nickelrockJobRate' . (int)$jobRate['id']; ?>
                  <tr>
                    <td>
                      <form id="<?= h($jobRateFormId) ?>" method="post"></form>
                      <input form="<?= h($jobRateFormId) ?>" type="hidden" name="action" value="save_nickelrock_job_rate">
                      <input form="<?= h($jobRateFormId) ?>" type="hidden" name="job_rate_id" value="<?= (int)$jobRate['id'] ?>">
                      <input form="<?= h($jobRateFormId) ?>" type="text" name="job_name" value="<?= h((string)$jobRate['job_name']) ?>" class="form-control form-control-sm" required>
                    </td>
                    <td><input form="<?= h($jobRateFormId) ?>" type="number" step="0.01" min="0.01" name="rate" value="<?= h(number_format((float)$jobRate['rate'], 2, '.', '')) ?>" class="form-control form-control-sm" required></td>
                    <td><input form="<?= h($jobRateFormId) ?>" type="text" name="work_order" value="<?= h((string)$jobRate['work_order']) ?>" class="form-control form-control-sm" required></td>
                    <td class="text-nowrap">
                      <button form="<?= h($jobRateFormId) ?>" type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                      <button form="<?= h($jobRateFormId) ?>" type="submit" name="action" value="delete_nickelrock_job_rate" class="btn btn-sm btn-outline-danger" formnovalidate onclick="return confirm('Delete this Nickel Rock job rate? Existing payout rows will keep the historical job name.');">Delete</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="4" class="text-muted">No job rates have been added yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <form method="post" class="row g-2 align-items-end">
          <input type="hidden" name="action" value="save_nickelrock_job_rate">
          <div class="col-12 col-md-4">
            <label class="form-label">New Job Name</label>
            <input type="text" name="job_name" class="form-control" required>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label">Rate</label>
            <input type="number" step="0.01" min="0.01" name="rate" class="form-control" required>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label">Work Order</label>
            <input type="text" name="work_order" class="form-control" required>
          </div>
          <div class="col-4 col-md-auto"><button type="submit" class="btn btn-primary">Add Job</button></div>
        </form>
      </div>
      <div class="modal fade" id="nickelrockEntryModal" tabindex="-1" aria-labelledby="nickelrockEntryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
          <form method="post" class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="nickelrockEntryModalLabel">Add Nickel Rock Row</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" name="action" value="add_nickelrock_row">
              <div class="row g-3 align-items-end">
          <div class="col-12 col-md-2">
            <label class="form-label">Delivery Date</label>
            <input type="date" name="work_date" value="<?= h($todayDate) ?>" class="form-control" required>
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Provider Name</label>
            <input type="text" name="provider_name" value="Nickel Rock - Stonehenge Pit" class="form-control" required>
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Job Name</label>
            <select name="job_name" class="form-select nickelrock-job-select" data-rate-target="nickelrockAddRate" data-work-order-target="nickelrockAddWorkOrder" required>
              <option value="">Select job</option>
              <?php foreach ($nickelrockJobRates as $jobRate): ?>
                <option value="<?= h((string)$jobRate['job_name']) ?>" data-rate="<?= h(number_format((float)$jobRate['rate'], 2, '.', '')) ?>" data-work-order="<?= h((string)$jobRate['work_order']) ?>"><?= h((string)$jobRate['job_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-2">
            <label class="form-label">Ticket</label>
            <input type="text" name="ticket_number" class="form-control" required>
          </div>
          <div class="col-12 col-md-2">
            <label class="form-label">Driver</label>
            <select name="matched_contact_id" class="form-select">
              <option value="">Manual name</option>
              <?php foreach ($driverOptions as $driver): ?>
                <option value="<?= (int)$driver['id'] ?>"><?= h($driver['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Manual Driver Name</label>
            <input type="text" name="driver_name" class="form-control">
          </div>
          <div class="col-6 col-md-1">
            <label class="form-label">Tons</label>
            <input type="number" step="0.01" name="tons" class="form-control" required>
          </div>
          <div class="col-6 col-md-1">
            <label class="form-label">Truck</label>
            <input type="text" name="truck_raw" class="form-control">
          </div>
          <div class="col-6 col-md-1">
            <label class="form-label">Rate</label>
            <input id="nickelrockAddRate" type="number" step="0.01" name="rate" value="9.00" class="form-control" readonly required>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label">Pay</label>
            <input type="number" step="0.01" name="total_amount" class="form-control" placeholder="auto">
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label">Work Order</label>
            <input id="nickelrockAddWorkOrder" type="text" name="work_order" class="form-control" readonly>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label">Client #</label>
            <input type="text" name="vendor_number" value="4138" class="form-control">
          </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary">Add Row</button>
            </div>
          </form>
        </div>
      </div>

      <form method="post" enctype="multipart/form-data" class="border rounded p-3 mt-3">
        <input type="hidden" name="action" value="import_nickelrock_bols">
        <div class="row g-3 align-items-end">
          <div class="col-12 col-md-4">
            <label class="form-label">BOL Images / PDFs</label>
            <input type="file" name="nickelrock_bol_files[]" accept=".jpg,.jpeg,.png,.tif,.tiff,.bmp,.webp,.pdf,image/*,application/pdf" multiple class="form-control" required>
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Provider Name Default</label>
            <input type="text" name="provider_name_default" value="Nickel Rock - Stonehenge Pit" class="form-control">
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Job Name</label>
            <select name="job_name_default" class="form-select nickelrock-job-select" data-rate-target="nickelrockImportRate" data-work-order-target="nickelrockImportWorkOrder" required>
              <option value="">Select job</option>
              <?php foreach ($nickelrockJobRates as $jobRate): ?>
                <option value="<?= h((string)$jobRate['job_name']) ?>" data-rate="<?= h(number_format((float)$jobRate['rate'], 2, '.', '')) ?>" data-work-order="<?= h((string)$jobRate['work_order']) ?>"><?= h((string)$jobRate['job_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-1">
            <label class="form-label">Rate</label>
            <input id="nickelrockImportRate" type="number" step="0.01" name="rate_default" value="9.00" class="form-control" readonly>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label">Work Order</label>
            <input id="nickelrockImportWorkOrder" type="text" name="work_order_default" class="form-control" readonly>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label">Client #</label>
            <input type="text" name="vendor_number_default" value="4138" class="form-control">
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-outline-primary">Import BOLs</button>
          </div>
          <div class="col-12">
            <div class="small text-muted">
              OCR import reads ticket number, delivery date, truck, and net tons, then matches the driver by truck when possible. Imported rows remain editable below before export.
            </div>
          </div>
        </div>
      </form>

      <hr class="my-4">
      <div class="d-flex align-items-center justify-content-between">
        <h2 class="mb-0">Miscellaneous Payment Adjustments</h2>
      </div><br />
      <form method="post">
        <input type="hidden" name="action" value="save_misc_adjustment">
        <input type="hidden" name="adjustment_vendor" value="NICKELROCK">
        <div class="row g-3 align-items-end">
          <div class="col-12 col-md-3">
            <label class="form-label">Driver</label>
            <select name="driver_contact_id" class="form-select">
              <option value="">Select driver</option>
              <?php foreach ($driverOptions as $driver): ?>
                <option value="<?= (int)$driver['id'] ?>" <?= ((string)$driver['id'] === (string)$nickelrockMiscAdjustmentForm['driver_contact_id']) ? 'selected' : '' ?>>
                  <?= h($driver['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Payout Week</label>
            <select name="payout_week_start" class="form-select">
              <?php foreach ($nickelrockWeekOptions as $weekStart): ?>
                <option value="<?= h($weekStart) ?>" <?= $weekStart === $nickelrockMiscAdjustmentForm['payout_week_start'] ? 'selected' : '' ?>>
                  <?= h($weekStart) ?> to <?= h(business_week_end($weekStart)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-2">
            <label class="form-label">Type</label>
            <select name="adjustment_type" class="form-select">
              <option value="misc_payment" <?= $nickelrockMiscAdjustmentForm['adjustment_type'] === 'misc_payment' ? 'selected' : '' ?>>Misc Payment</option>
              <option value="misc_deduction" <?= $nickelrockMiscAdjustmentForm['adjustment_type'] === 'misc_deduction' ? 'selected' : '' ?>>Misc Deduction</option>
            </select>
          </div>
          <div class="col-12 col-md-2">
            <label class="form-label">Amount</label>
            <input type="number" step="0.01" min="0.01" name="amount" value="<?= h($nickelrockMiscAdjustmentForm['amount']) ?>" class="form-control">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">Comments</label>
            <input type="text" name="comments" value="<?= h($nickelrockMiscAdjustmentForm['comments']) ?>" class="form-control" placeholder="Explain adjustment">
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-primary">Save Adjustment</button>
          </div>
        </div>
      </form>

      <hr class="my-4">
      <div class="d-flex align-items-center justify-content-between">
        <h2 class="mb-0">Nickel Rock Weekly Review</h2>
      </div><br />
      <form method="get" class="mb-3">
        <input type="hidden" name="vendor" value="nickelrock">
        <div class="row g-3 align-items-end">
          <div class="col-auto">
            <label class="form-label">Week</label>
            <select name="nickelrock_week_start" class="form-select">
              <?php foreach ($nickelrockWeekOptions as $weekStart): ?>
                <option value="<?= h($weekStart) ?>" <?= $weekStart === $nickelrockReviewWeekStart ? 'selected' : '' ?>>
                  <?= h($weekStart) ?> to <?= h(business_week_end($weekStart)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-outline-primary">Review Week</button>
          </div>
        </div>
      </form>
      <?php if (empty($nickelrockReviewRows)): ?>
        <div class="alert alert-info">No Nickel Rock rows were found for this week.</div>
      <?php else: ?>
        <div class="d-flex gap-2 mb-2">
          <form method="post">
            <input type="hidden" name="action" value="export_nickelrock_invoice">
            <input type="hidden" name="nickelrock_week_start" value="<?= h($nickelrockReviewWeekStart) ?>">
            <button type="submit" class="btn btn-success btn-sm">Export Nickel Rock Invoices by Job</button>
          </form>
          <form id="nickelrockBulkDeleteForm" method="post">
            <input type="hidden" name="action" value="delete_nickelrock_rows">
            <input type="hidden" name="nickelrock_week_start" value="<?= h($nickelrockReviewWeekStart) ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete all selected Nickel Rock rows? This will remove them from driver payouts too.');">Delete Selected</button>
          </form>
        </div>
        <div class="table-responsive">
          <table class="table table-striped table-bordered align-middle table-preview">
            <thead>
              <tr>
                <th><input type="checkbox" id="selectAllNickelrockRows" aria-label="Select all Nickel Rock rows"></th>
                <th>Date</th>
                <th>Provider</th>
                <th>Job Name</th>
                <th>Ticket</th>
                <th>Tons</th>
                <th>Driver</th>
                <th>Matched Driver</th>
                <th>Truck</th>
                <th>Rate</th>
                <th>Pay</th>
                <th>Work Order</th>
                <th>Client #</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($nickelrockReviewRows as $index => $nickelRow): ?>
                <?php
                  $nickelFormId = 'nickelrockRowForm' . (int)$nickelRow['id'];
                  $nickelUnmatched = empty($nickelRow['matched_contact_id']);
                ?>
                <tr class="<?= $nickelUnmatched ? 'table-warning' : '' ?>">
                  <td>
                    <input form="nickelrockBulkDeleteForm" type="checkbox" name="nickelrock_row_ids[]" value="<?= (int)$nickelRow['id'] ?>" class="nickelrock-row-checkbox" aria-label="Select Nickel Rock row <?= (int)$index + 1 ?>">
                  </td>
                  <td>
                    <form id="<?= h($nickelFormId) ?>" method="post"></form>
                    <input form="<?= h($nickelFormId) ?>" type="hidden" name="action" value="save_nickelrock_row">
                    <input form="<?= h($nickelFormId) ?>" type="hidden" name="nickelrock_row_id" value="<?= (int)$nickelRow['id'] ?>">
                    <input form="<?= h($nickelFormId) ?>" type="hidden" name="nickelrock_week_start" value="<?= h($nickelrockReviewWeekStart) ?>">
                    <input form="<?= h($nickelFormId) ?>" type="date" name="work_date" value="<?= h($nickelRow['work_date']) ?>" class="form-control form-control-sm">
                  </td>
                  <td><input form="<?= h($nickelFormId) ?>" type="text" name="provider_name" value="<?= h($nickelRow['provider_name']) ?>" class="form-control form-control-sm"></td>
                  <td>
                    <select form="<?= h($nickelFormId) ?>" name="job_name" class="form-select form-select-sm nickelrock-job-select" data-rate-target="nickelrockRate<?= (int)$nickelRow['id'] ?>" data-work-order-target="nickelrockWorkOrder<?= (int)$nickelRow['id'] ?>" required>
                      <option value="">Select job</option>
                      <?php foreach ($nickelrockJobRates as $jobRate): ?>
                        <option value="<?= h((string)$jobRate['job_name']) ?>" data-rate="<?= h(number_format((float)$jobRate['rate'], 2, '.', '')) ?>" data-work-order="<?= h((string)$jobRate['work_order']) ?>" <?= ((string)($nickelRow['job_name'] ?? '') === (string)$jobRate['job_name']) ? 'selected' : '' ?>><?= h((string)$jobRate['job_name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input form="<?= h($nickelFormId) ?>" type="text" name="ticket_number" value="<?= h($nickelRow['ticket_number']) ?>" class="form-control form-control-sm"></td>
                  <td><input form="<?= h($nickelFormId) ?>" type="number" step="0.01" name="tons" value="<?= h(number_format((float)$nickelRow['tons'], 2, '.', '')) ?>" class="form-control form-control-sm"></td>
                  <td><input form="<?= h($nickelFormId) ?>" type="text" name="driver_name" value="<?= h($nickelRow['driver_name']) ?>" class="form-control form-control-sm"></td>
                  <td>
                    <select form="<?= h($nickelFormId) ?>" name="matched_contact_id" class="form-select form-select-sm">
                      <option value="">Unmatched</option>
                      <?php foreach ($driverOptions as $driver): ?>
                        <option value="<?= (int)$driver['id'] ?>" <?= ((int)($nickelRow['matched_contact_id'] ?? 0) === (int)$driver['id']) ? 'selected' : '' ?>>
                          <?= h($driver['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input form="<?= h($nickelFormId) ?>" type="text" name="truck_raw" value="<?= h($nickelRow['truck_raw']) ?>" class="form-control form-control-sm"></td>
                  <td><input id="nickelrockRate<?= (int)$nickelRow['id'] ?>" form="<?= h($nickelFormId) ?>" type="number" step="0.01" name="rate" value="<?= h(number_format((float)$nickelRow['rate'], 2, '.', '')) ?>" class="form-control form-control-sm" readonly></td>
                  <td><input form="<?= h($nickelFormId) ?>" type="number" step="0.01" name="total_amount" value="<?= h(number_format(nickelrock_total_pay($nickelRow), 2, '.', '')) ?>" class="form-control form-control-sm"></td>
                  <td><input id="nickelrockWorkOrder<?= (int)$nickelRow['id'] ?>" form="<?= h($nickelFormId) ?>" type="text" name="work_order" value="<?= h($nickelRow['work_order']) ?>" class="form-control form-control-sm" readonly></td>
                  <td><input form="<?= h($nickelFormId) ?>" type="text" name="vendor_number" value="<?= h($nickelRow['vendor_number']) ?>" class="form-control form-control-sm"></td>
                  <td class="nowrap">
                    <button form="<?= h($nickelFormId) ?>" type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                    <button form="<?= h($nickelFormId) ?>" type="submit" name="action" value="delete_nickelrock_row" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this Nickel Rock row? This will remove it from driver payouts too.');">Delete</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <th colspan="5" class="text-end">Totals</th>
                <th><?= number_format(array_sum(array_map(static fn($r) => (float)$r['tons'], $nickelrockReviewRows)), 2) ?></th>
                <th colspan="4"></th>
                <th>$<?= number_format(array_sum(array_map(static fn($r) => nickelrock_total_pay($r), $nickelrockReviewRows)), 2) ?></th>
                <th colspan="3"></th>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="mt-3 vendor-form d-none" data-vendor="nextier">
      <div class="d-flex align-items-center justify-content-between">
        <h2 class="mb-0">Upload NexTier Weekly Statement</h2>
      </div><br />
      <?php render_vendor_broker_fee_form($vendorBrokerFeeSettings['nextier']); ?>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="upload_mode" value="nextier_payout">
        <div class="row g-3 align-items-end">
          <div class="col-auto">
            <label class="form-label">Select NexTier Excel (.xlsx)</label>
            <input type="file" name="nextier_file" accept=".xlsx" required class="form-control">
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-primary">Upload NexTier Statement</button>
          </div>
          <div class="col-12">
            <div class="small text-muted">
              NexTier rows are saved to driver payouts using Rate x Tons plus Bonus when available, otherwise Total Line Haul minus FSC Total. FSC is itemized separately in reporting.
            </div>
          </div>
        </div>
      </form>

      <hr class="my-4">
      <div class="d-flex align-items-center justify-content-between">
        <h2 class="mb-0">Miscellaneous Payment Adjustments</h2>
      </div><br />
      <form method="post">
        <input type="hidden" name="action" value="save_misc_adjustment">
        <input type="hidden" name="adjustment_vendor" value="NEXTIER">
        <div class="row g-3 align-items-end">
          <div class="col-12 col-md-3">
            <label class="form-label">Driver</label>
            <select name="driver_contact_id" class="form-select">
              <option value="">Select driver</option>
              <?php foreach ($driverOptions as $driver): ?>
                <option value="<?= (int)$driver['id'] ?>" <?= ((string)$driver['id'] === (string)$nextierMiscAdjustmentForm['driver_contact_id']) ? 'selected' : '' ?>>
                  <?= h($driver['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Payout Week</label>
            <select name="payout_week_start" class="form-select">
              <?php foreach ($nextierWeekOptions as $weekStart): ?>
                <option value="<?= h($weekStart) ?>" <?= $weekStart === $nextierMiscAdjustmentForm['payout_week_start'] ? 'selected' : '' ?>>
                  <?= h($weekStart) ?> to <?= h(business_week_end($weekStart)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-2">
            <label class="form-label">Type</label>
            <select name="adjustment_type" class="form-select">
              <option value="misc_payment" <?= $nextierMiscAdjustmentForm['adjustment_type'] === 'misc_payment' ? 'selected' : '' ?>>Misc Payment</option>
              <option value="misc_deduction" <?= $nextierMiscAdjustmentForm['adjustment_type'] === 'misc_deduction' ? 'selected' : '' ?>>Misc Deduction</option>
            </select>
          </div>
          <div class="col-12 col-md-2">
            <label class="form-label">Amount</label>
            <input type="number" step="0.01" min="0.01" name="amount" value="<?= h($nextierMiscAdjustmentForm['amount']) ?>" class="form-control">
          </div>
          <div class="col-12 col-md-2">
            <label class="form-label">Comments</label>
            <input type="text" name="comments" value="<?= h($nextierMiscAdjustmentForm['comments']) ?>" class="form-control" placeholder="Explain adjustment">
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-primary">Save Adjustment</button>
          </div>
        </div>
      </form>

      <hr class="my-4">
      <div class="d-flex align-items-center justify-content-between">
        <h2 class="mb-0">NexTier Weekly Review</h2>
      </div><br />
      <form method="get" class="mb-3">
        <input type="hidden" name="vendor" value="nextier">
        <div class="row g-3 align-items-end">
          <div class="col-12 col-md-3">
            <label class="form-label">Payout Week</label>
            <select name="nextier_week_start" class="form-select">
              <?php foreach ($nextierWeekOptions as $weekStart): ?>
                <option value="<?= h($weekStart) ?>" <?= $weekStart === $nextierReviewWeekStart ? 'selected' : '' ?>>
                  <?= h($weekStart) ?> to <?= h(business_week_end($weekStart)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-primary">Review Week</button>
          </div>
        </div>
      </form>

      <?php
        $nextierReconStatusClass = $nextierTrailerReconciliation['source_row_count'] > 0
          ? ($nextierTrailerReconciliation['is_balanced'] ? 'success' : 'warning')
          : 'secondary';
      ?>
      <div class="border rounded p-3 mb-3 bg-light">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
          <div>
            <h3 class="h5 mb-1">Trailer Rental Reconciliation</h3>
            <div class="text-muted small">
              Compares NexTier trailer rental deductions from the statement against calculated daily trailer rental fees for <?= h($nextierReviewWeekStart) ?> to <?= h($nextierTrailerReconciliation['week_end']) ?>.
            </div>
          </div>
          <span class="badge text-bg-<?= h($nextierReconStatusClass) ?>">
            <?php if ($nextierTrailerReconciliation['source_row_count'] <= 0): ?>
              No statement total
            <?php elseif ($nextierTrailerReconciliation['is_balanced']): ?>
              Balanced
            <?php else: ?>
              Variance
            <?php endif; ?>
          </span>
        </div>
        <div class="row g-3">
          <div class="col-12 col-md-3">
            <div class="small text-muted">Statement Trailer Rental Total</div>
            <div class="fs-5 fw-semibold">$<?= number_format((float)$nextierTrailerReconciliation['billed_trailer_total'], 2) ?></div>
            <div class="small text-muted">
              <?php if ((int)$nextierTrailerReconciliation['source_row_count'] > 0): ?>
                <?= (int)$nextierTrailerReconciliation['source_row_count'] ?> row(s), upload <?= h($nextierTrailerReconciliation['source_upload_date']) ?>
              <?php else: ?>
                Upload a statement with trailer rental summary rows.
              <?php endif; ?>
            </div>
          </div>
          <div class="col-12 col-md-3">
            <div class="small text-muted">Calculated Trailer Rental Total</div>
            <div class="fs-5 fw-semibold">$<?= number_format((float)$nextierTrailerReconciliation['calculated_trailer_total'], 2) ?></div>
            <div class="small text-muted">Based on matched drivers, trailer assignments, fee value, and days.</div>
          </div>
          <div class="col-12 col-md-3">
            <div class="small text-muted">Difference</div>
            <div class="fs-5 fw-semibold <?= abs((float)$nextierTrailerReconciliation['difference']) < 0.01 ? 'text-success' : 'text-danger' ?>">
              $<?= number_format((float)$nextierTrailerReconciliation['difference'], 2) ?>
            </div>
            <div class="small text-muted">Statement total minus calculated total.</div>
          </div>
          <div class="col-12 col-md-3">
            <div class="small text-muted">Raw Statement Sign</div>
            <div class="fs-5 fw-semibold">$<?= number_format((float)$nextierTrailerReconciliation['raw_trailer_total'], 2) ?></div>
            <div class="small text-muted">Stored as shown in the spreadsheet.</div>
          </div>
        </div>

        <?php if (!empty($nextierTrailerCatchupsApplied)): ?>
          <div class="alert alert-info mt-3 mb-0">
            <div class="fw-semibold mb-1">Prior service-week trailer charges applied to this payout</div>
            <div class="table-responsive">
              <table class="table table-sm table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th>Service Week</th>
                    <th class="text-end">Statement Total</th>
                    <th class="text-end">Rows</th>
                    <th>Source Upload</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($nextierTrailerCatchupsApplied as $catchupRow): ?>
                    <tr>
                      <td><?= h($catchupRow['service_week_start']) ?> to <?= h($catchupRow['service_week_end']) ?></td>
                      <td class="text-end">$<?= number_format((float)$catchupRow['billed_trailer_total'], 2) ?></td>
                      <td class="text-end"><?= (int)$catchupRow['source_row_count'] ?></td>
                      <td><?= h($catchupRow['source_upload_date']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php elseif (!empty($nextierTrailerReconciliation['applied_payout_week_start']) && $nextierTrailerReconciliation['applied_payout_week_start'] !== $nextierReviewWeekStart): ?>
          <div class="alert alert-warning mt-3 mb-0">
            This service week's trailer rental charge was applied to payout week <?= h($nextierTrailerReconciliation['applied_payout_week_start']) ?>.
          </div>
        <?php endif; ?>

        <?php if (!empty($nextierTrailerReconciliation['driver_rows'])): ?>
          <details class="mt-3">
            <summary class="fw-semibold">Driver trailer rental calculation detail</summary>
            <div class="table-responsive mt-2">
              <table class="table table-sm table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th>Driver</th>
                    <th>Trailer</th>
                    <th class="text-end">Days</th>
                    <th class="text-end">NexTier Gross</th>
                    <th class="text-end">Calculated Trailer Rental</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($nextierTrailerReconciliation['driver_rows'] as $driverTrailerRow): ?>
                    <tr>
                      <td><?= h($driverTrailerRow['driver_name']) ?></td>
                      <td><?= h($driverTrailerRow['trailer_number'] ?? '') ?></td>
                      <td class="text-end"><?= (int)($driverTrailerRow['days'] ?? 0) ?></td>
                      <td class="text-end">$<?= number_format((float)$driverTrailerRow['gross_total'], 2) ?></td>
                      <td class="text-end">$<?= number_format((float)$driverTrailerRow['calculated_trailer_total'], 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </details>
        <?php endif; ?>
      </div>

      <?php if (empty($nextierReviewRows)): ?>
        <div class="alert alert-info">No NexTier rows were found for this week.</div>
      <?php else: ?>
        <form id="nextierBulkDeleteForm" method="post" class="mb-2">
          <input type="hidden" name="action" value="delete_nextier_rows">
          <input type="hidden" name="nextier_week_start" value="<?= h($nextierReviewWeekStart) ?>">
          <button
            type="submit"
            class="btn btn-sm btn-outline-danger"
            onclick="return confirm('Delete all selected NexTier rows? This will remove them from driver payouts too.');"
          >Delete Selected</button>
        </form>
        <div class="table-responsive">
          <table class="table table-striped table-bordered align-middle table-preview">
            <thead>
              <tr>
                <th>#</th>
                <th><input type="checkbox" id="selectAllNextierRows" aria-label="Select all NexTier rows"></th>
                <th>Date</th>
                <th>Load ID</th>
                <th>BOL #</th>
                <th>Driver</th>
                <th>Matched Driver</th>
                <th>Well</th>
                <th>Tons</th>
                <th>Miles</th>
                <th>Rate</th>
                <th>Line Haul</th>
                <th>FSC Rate</th>
                <th>FSC Total</th>
                <th>Bonus</th>
                <th>Total</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($nextierReviewRows as $index => $nextierRow): ?>
                <?php
                  $nextierFormId = 'nextierRowForm' . (int)$nextierRow['id'];
                  $nextierUnmatched = empty($nextierRow['matched_contact_id']);
                ?>
                <tr class="<?= $nextierUnmatched ? 'table-warning' : '' ?>">
                  <td><?= (int)$index + 1 ?></td>
                  <td>
                    <input
                      form="nextierBulkDeleteForm"
                      type="checkbox"
                      name="nextier_row_ids[]"
                      value="<?= (int)$nextierRow['id'] ?>"
                      class="nextier-row-checkbox"
                      aria-label="Select NexTier row <?= (int)$index + 1 ?>"
                    >
                  </td>
                  <td>
                    <form id="<?= h($nextierFormId) ?>" method="post"></form>
                    <input form="<?= h($nextierFormId) ?>" type="hidden" name="nextier_row_id" value="<?= (int)$nextierRow['id'] ?>">
                    <input form="<?= h($nextierFormId) ?>" type="hidden" name="nextier_week_start" value="<?= h($nextierReviewWeekStart) ?>">
                    <input form="<?= h($nextierFormId) ?>" type="hidden" name="dispatched_loader" value="<?= h($nextierRow['dispatched_loader'] ?? '') ?>">
                    <input form="<?= h($nextierFormId) ?>" type="hidden" name="weight" value="<?= h(number_format((float)$nextierRow['weight'], 2, '.', '')) ?>">
                    <input form="<?= h($nextierFormId) ?>" type="hidden" name="trucking_co" value="<?= h($nextierRow['trucking_co'] ?? '') ?>">
                    <input form="<?= h($nextierFormId) ?>" type="date" name="work_date" value="<?= h($nextierRow['work_date']) ?>" class="form-control form-control-sm">
                  </td>
                  <td><input form="<?= h($nextierFormId) ?>" type="text" name="load_id" value="<?= h($nextierRow['load_id']) ?>" class="form-control form-control-sm"></td>
                  <td><input form="<?= h($nextierFormId) ?>" type="text" name="bol_number" value="<?= h($nextierRow['bol_number']) ?>" class="form-control form-control-sm"></td>
                  <td><input form="<?= h($nextierFormId) ?>" type="text" name="driver_name" value="<?= h($nextierRow['driver_name']) ?>" class="form-control form-control-sm"></td>
                  <td>
                    <select form="<?= h($nextierFormId) ?>" name="matched_contact_id" class="form-select form-select-sm">
                      <option value="">Unmatched</option>
                      <?php foreach ($driverOptions as $driver): ?>
                        <option value="<?= (int)$driver['id'] ?>" <?= ((int)($nextierRow['matched_contact_id'] ?? 0) === (int)$driver['id']) ? 'selected' : '' ?>>
                          <?= h($driver['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input form="<?= h($nextierFormId) ?>" type="text" name="well_name" value="<?= h($nextierRow['well_name']) ?>" class="form-control form-control-sm"></td>
                  <td><input form="<?= h($nextierFormId) ?>" type="number" step="0.01" name="tons" value="<?= h(number_format((float)$nextierRow['tons'], 2, '.', '')) ?>" class="form-control form-control-sm"></td>
                  <td><input form="<?= h($nextierFormId) ?>" type="number" step="0.01" name="miles" value="<?= h(number_format((float)$nextierRow['miles'], 2, '.', '')) ?>" class="form-control form-control-sm"></td>
                  <td><input form="<?= h($nextierFormId) ?>" type="number" step="0.01" name="rate" value="<?= h(number_format((float)$nextierRow['rate'], 2, '.', '')) ?>" class="form-control form-control-sm"></td>
                  <td><input form="<?= h($nextierFormId) ?>" type="number" step="0.01" name="line_haul" value="<?= h(number_format((float)$nextierRow['line_haul'], 2, '.', '')) ?>" class="form-control form-control-sm"></td>
                  <td><input form="<?= h($nextierFormId) ?>" type="number" step="0.0001" name="fsc_rate" value="<?= h(number_format((float)$nextierRow['fsc_rate'], 4, '.', '')) ?>" class="form-control form-control-sm"></td>
                  <td><input form="<?= h($nextierFormId) ?>" type="number" step="0.01" name="fsc_total" value="<?= h(number_format((float)$nextierRow['fsc_total'], 2, '.', '')) ?>" class="form-control form-control-sm"></td>
                  <td><input form="<?= h($nextierFormId) ?>" type="number" step="0.01" name="bonus" value="<?= h(number_format((float)$nextierRow['bonus'], 2, '.', '')) ?>" class="form-control form-control-sm"></td>
                  <td>$<?= h(number_format(nextier_total_pay($nextierRow), 2)) ?></td>
                  <td class="nowrap">
                    <button form="<?= h($nextierFormId) ?>" type="submit" name="action" value="save_nextier_row" class="btn btn-sm btn-outline-primary">Save</button>
                    <button
                      form="<?= h($nextierFormId) ?>"
                      type="submit"
                      name="action"
                      value="delete_nextier_row"
                      class="btn btn-sm btn-outline-danger"
                      onclick="return confirm('Delete this NexTier row? This will remove it from driver payouts too.');"
                    >Delete</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <th colspan="11" class="text-end">Totals</th>
                <th>$<?= number_format(array_sum(array_map(static fn($r) => (float)$r['line_haul'], $nextierReviewRows)), 2) ?></th>
                <th></th>
                <th>$<?= number_format(array_sum(array_map(static fn($r) => (float)$r['fsc_total'], $nextierReviewRows)), 2) ?></th>
                <th>$<?= number_format(array_sum(array_map(static fn($r) => (float)$r['bonus'], $nextierReviewRows)), 2) ?></th>
                <th>$<?= number_format(array_sum(array_map(static fn($r) => nextier_total_pay($r), $nextierReviewRows)), 2) ?></th>
                <th></th>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="mt-3 vendor-form d-none" data-vendor="rtex" id="rtex-section" data-mode="<?= h($rtexMode) ?>">
      <div class="d-flex align-items-center justify-content-between">
        <h2 class="mb-0">RTEX Payout Calculator</h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="<?= $rtexMode === 'load' ? '#rtexLoadEntryModal' : '#rtexEntryModal' ?>">Add RTEX Row</button>
      </div><br />
      <form method="get" class="my-3 d-flex gap-2 align-items-end">
        <input type="hidden" name="vendor" value="rtex">
        <input type="hidden" name="rtex_week_start" value="<?= h($rtexReviewWeekStart) ?>">
        <div><label for="rtexMode" class="form-label">Invoicing Method</label>
          <select id="rtexMode" name="rtex_mode" class="form-select">
            <option value="hourly" <?= $rtexMode === 'hourly' ? 'selected' : '' ?>>Hourly</option>
            <option value="load" <?= $rtexMode === 'load' ? 'selected' : '' ?>>Load-based (tonnage or mileage)</option>
          </select>
        </div>
        <button class="btn btn-outline-primary">Switch Invoicing</button>
      </form>
      <p class="small text-muted">Hourly and load-based work can coexist in the same week. This view controls entry, review, and invoice exports.</p>
      <?php render_vendor_broker_fee_form($vendorBrokerFeeSettings['rtex'], $rtexMode === 'load' ? $rtexFscSettings : null, $rtexReviewWeekStart); ?>
      <?php if ($rtexMode === 'load'): ?>
        <p class="small text-muted">The hourly brokerage fee applies only to hourly rows. Load pay uses the selected job rate; a percentage brokerage setting, if selected above, applies to base freight only, excluding driver FSC.</p>
        <?php require __DIR__ . '/includes/rtex_load_form.php'; ?>
      <?php else: ?>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="upload_mode" value="rtex_payout">
        <div class="row g-3 align-items-end">
          <div class="col-auto">
            <label class="form-label">Select RTEX Excel (.xlsx)</label>
            <input type="file" name="rtex_file" accept=".xlsx" required class="form-control">
          </div>
          <div class="col-auto">
            <label class="form-label">Base Rate Reduction %</label>
            <input type="number" name="rtex_base_rate_reduction_pct" step="0.01" min="0" max="100" value="0" class="form-control">
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-primary">Upload RTEX Invoice</button>
          </div>
          <div class="col-12">
            <div class="small text-muted">
              RTEX rows are saved to driver payouts using each line's Total amount. The invoice 12% surcharge line is excluded from driver pay.
            </div>
          </div>
        </div>
      </form>

      <div class="modal fade" id="rtexEntryModal" tabindex="-1" aria-labelledby="rtexEntryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
          <div class="modal-content">
            <form method="post">
              <input type="hidden" name="action" value="add_rtex_row">
              <input type="hidden" name="rtex_week_start" value="<?= h($rtexReviewWeekStart) ?>">
              <div class="modal-header">
                <h5 class="modal-title" id="rtexEntryModalLabel">Add RTEX Row</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <div class="row g-3">
                  <div class="col-12 col-md-4">
                    <label class="form-label">Date</label>
                    <input type="date" name="work_date" value="<?= h($todayDate) ?>" class="form-control" required>
                  </div>
                  <div class="col-12 col-md-4">
                    <label class="form-label">Ticket #</label>
                    <input type="text" name="ticket_number" class="form-control">
                  </div>
                  <div class="col-12 col-md-4">
                    <label class="form-label">Job #</label>
                    <input type="text" name="job_number" class="form-control">
                  </div>
                  <div class="col-12 col-md-6">
                    <label class="form-label">Matched Driver</label>
                    <select name="matched_contact_id" class="form-select">
                      <option value="">Unmatched</option>
                      <?php foreach ($driverOptions as $driver): ?>
                        <option value="<?= (int)$driver['id'] ?>"><?= h($driver['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-12 col-md-6">
                    <label class="form-label">Driver Name</label>
                    <input type="text" name="driver_name" class="form-control">
                  </div>
                  <div class="col-12 col-md-4">
                    <label class="form-label">Truck</label>
                    <input type="text" name="truck_raw" class="form-control" required>
                  </div>
                  <div class="col-12 col-md-4">
                    <label class="form-label">Start</label>
                    <input type="time" name="start_time" class="form-control">
                  </div>
                  <div class="col-12 col-md-4">
                    <label class="form-label">End</label>
                    <input type="time" name="end_time" class="form-control">
                  </div>
                  <div class="col-12 col-md-4">
                    <label class="form-label">Hours</label>
                    <input type="number" step="0.01" min="0.01" name="hours" class="form-control" required>
                  </div>
                  <div class="col-12 col-md-4">
                    <label class="form-label">Rate</label>
                    <input type="number" step="0.01" min="0" name="rate" class="form-control">
                  </div>
                  <div class="col-12 col-md-4">
                    <label class="form-label">Base Rate Reduction %</label>
                    <input type="number" step="0.01" min="0" max="100" name="rtex_base_rate_reduction_pct" value="0" class="form-control">
                  </div>
                  <div class="col-12 col-md-4">
                    <label class="form-label">Total</label>
                    <input type="number" step="0.01" min="0" name="total_amount" class="form-control">
                  </div>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Row</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <?php endif; ?>

      <hr class="my-4">
      <div class="d-flex align-items-center justify-content-between">
        <h2 class="mb-0">Miscellaneous Payment Adjustments</h2>
      </div><br />
      <form method="post">
        <input type="hidden" name="action" value="save_misc_adjustment">
        <input type="hidden" name="adjustment_vendor" value="RTEX">
        <div class="row g-3 align-items-end">
          <div class="col-12 col-md-3">
            <label class="form-label">Driver</label>
            <select name="driver_contact_id" class="form-select">
              <option value="">Select driver</option>
              <?php foreach ($driverOptions as $driver): ?>
                <option value="<?= (int)$driver['id'] ?>" <?= ((string)$driver['id'] === (string)$rtexMiscAdjustmentForm['driver_contact_id']) ? 'selected' : '' ?>>
                  <?= h($driver['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Payout Week</label>
            <select name="payout_week_start" class="form-select">
              <?php foreach ($rtexWeekOptions as $weekStart): ?>
                <option value="<?= h($weekStart) ?>" <?= $weekStart === $rtexMiscAdjustmentForm['payout_week_start'] ? 'selected' : '' ?>>
                  <?= h($weekStart) ?> to <?= h(business_week_end($weekStart)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-2">
            <label class="form-label">Type</label>
            <select name="adjustment_type" class="form-select">
              <option value="misc_payment" <?= $rtexMiscAdjustmentForm['adjustment_type'] === 'misc_payment' ? 'selected' : '' ?>>Misc Payment</option>
              <option value="misc_deduction" <?= $rtexMiscAdjustmentForm['adjustment_type'] === 'misc_deduction' ? 'selected' : '' ?>>Misc Deduction</option>
            </select>
          </div>
          <div class="col-12 col-md-2">
            <label class="form-label">Amount</label>
            <input type="number" step="0.01" min="0.01" name="amount" value="<?= h($rtexMiscAdjustmentForm['amount']) ?>" class="form-control">
          </div>
          <div class="col-12 col-md-2">
            <label class="form-label">Comments</label>
            <input type="text" name="comments" value="<?= h($rtexMiscAdjustmentForm['comments']) ?>" class="form-control" placeholder="Explain adjustment">
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-primary">Save Adjustment</button>
          </div>
        </div>
      </form>

      <hr class="my-4">
      <div class="d-flex align-items-center justify-content-between">
        <h2 class="mb-0">RTEX Weekly Review — <?= $rtexMode === 'load' ? 'Load-based' : 'Hourly' ?></h2>
      </div><br />
      <form method="get" class="mb-3">
        <input type="hidden" name="vendor" value="rtex">
        <div class="row g-3 align-items-end">
          <div class="col-12 col-md-3">
            <label class="form-label">Payout Week</label>
            <select name="rtex_week_start" class="form-select">
              <?php foreach ($rtexWeekOptions as $weekStart): ?>
                <option value="<?= h($weekStart) ?>" <?= $weekStart === $rtexReviewWeekStart ? 'selected' : '' ?>>
                  <?= h($weekStart) ?> to <?= h(business_week_end($weekStart)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-primary">Review Week</button>
          </div>
        </div>
      </form>

      <?php if ($rtexMode === 'load'): ?>
        <?php require __DIR__ . '/includes/rtex_load_review.php'; ?>
      <?php elseif (empty($rtexReviewRows)): ?>
        <div class="alert alert-info">No RTEX rows were found for this week.</div>
      <?php else: ?>
        <div class="d-flex flex-wrap gap-2 mb-2">
          <form method="post">
            <input type="hidden" name="action" value="export_rtex_invoice">
            <input type="hidden" name="rtex_week_start" value="<?= h($rtexReviewWeekStart) ?>">
            <button type="submit" class="btn btn-sm btn-outline-success">Export RTEX Excel</button>
          </form>
          <form id="rtexBulkDeleteForm" method="post">
            <input type="hidden" name="action" value="delete_rtex_rows">
            <input type="hidden" name="rtex_week_start" value="<?= h($rtexReviewWeekStart) ?>">
            <button
              type="submit"
              class="btn btn-sm btn-outline-danger"
              onclick="return confirm('Delete all selected RTEX rows? This will remove them from driver payouts too.');"
            >Delete Selected</button>
          </form>
        </div>
        <div class="table-responsive">
          <table class="table table-striped table-bordered align-middle table-preview">
            <thead>
              <tr>
                <th>#</th>
                <th><input type="checkbox" id="selectAllRtexRows" aria-label="Select all RTEX rows"></th>
                <th>Date</th>
                <th>Ticket #</th>
                <th>Job #</th>
                <th>Driver</th>
                <th>Matched Driver</th>
                <th>Truck</th>
                <th>Start</th>
                <th>End</th>
                <th>Hours</th>
                <th>Brokerage Fee</th>
                <th>Rate</th>
                <th>Base Reduction %</th>
                <th>Total</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rtexReviewRows as $index => $rtexRow): ?>
                <?php $rtexFormId = 'rtexRowForm' . (int)$rtexRow['id']; ?>
                <tr>
                    <td><?= (int)$index + 1 ?></td>
                    <td>
                      <input
                        form="rtexBulkDeleteForm"
                        type="checkbox"
                        name="rtex_row_ids[]"
                        value="<?= (int)$rtexRow['id'] ?>"
                        class="rtex-row-checkbox"
                        aria-label="Select RTEX row <?= (int)$index + 1 ?>"
                      >
                    </td>
                    <td>
                      <form id="<?= h($rtexFormId) ?>" method="post"></form>
                      <input form="<?= h($rtexFormId) ?>" type="hidden" name="rtex_row_id" value="<?= (int)$rtexRow['id'] ?>">
                      <input form="<?= h($rtexFormId) ?>" type="hidden" name="rtex_week_start" value="<?= h($rtexReviewWeekStart) ?>">
                      <input form="<?= h($rtexFormId) ?>" type="date" name="work_date" value="<?= h($rtexRow['work_date']) ?>" class="form-control form-control-sm">
                    </td>
                    <td><input form="<?= h($rtexFormId) ?>" type="text" name="ticket_number" value="<?= h($rtexRow['ticket_number']) ?>" class="form-control form-control-sm"></td>
                    <td><input form="<?= h($rtexFormId) ?>" type="text" name="job_number" value="<?= h($rtexRow['job_number']) ?>" class="form-control form-control-sm"></td>
                    <td><input form="<?= h($rtexFormId) ?>" type="text" name="driver_name" value="<?= h($rtexRow['driver_name']) ?>" class="form-control form-control-sm"></td>
                    <td>
                      <select form="<?= h($rtexFormId) ?>" name="matched_contact_id" class="form-select form-select-sm">
                        <option value="">Unmatched</option>
                        <?php foreach ($driverOptions as $driver): ?>
                          <option value="<?= (int)$driver['id'] ?>" <?= ((int)($rtexRow['matched_contact_id'] ?? 0) === (int)$driver['id']) ? 'selected' : '' ?>>
                            <?= h($driver['name']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td><input form="<?= h($rtexFormId) ?>" type="text" name="truck_raw" value="<?= h($rtexRow['truck_raw']) ?>" class="form-control form-control-sm"></td>
                    <td><input form="<?= h($rtexFormId) ?>" type="time" name="start_time" value="<?= h(rtex_excel_time($rtexRow['start_time'] ?? '')) ?>" class="form-control form-control-sm"></td>
                    <td><input form="<?= h($rtexFormId) ?>" type="time" name="end_time" value="<?= h(rtex_excel_time($rtexRow['end_time'] ?? '')) ?>" class="form-control form-control-sm"></td>
                    <td><input form="<?= h($rtexFormId) ?>" type="number" step="0.01" name="hours" value="<?= h(number_format((float)$rtexRow['hours'], 2, '.', '')) ?>" class="form-control form-control-sm"></td>
                    <td>$<?= h(number_format(((float)$rtexRow['hours']) * 10, 2)) ?></td>
                    <td><input form="<?= h($rtexFormId) ?>" type="number" step="0.01" name="rate" value="<?= h(number_format((float)$rtexRow['rate'], 2, '.', '')) ?>" class="form-control form-control-sm"></td>
                    <td><input form="<?= h($rtexFormId) ?>" type="number" step="0.01" min="0" max="100" name="rtex_base_rate_reduction_pct" value="0" class="form-control form-control-sm"></td>
                    <td><input form="<?= h($rtexFormId) ?>" type="number" step="0.01" name="total_amount" value="<?= h(number_format((float)$rtexRow['total_amount'], 2, '.', '')) ?>" class="form-control form-control-sm"></td>
                    <td class="nowrap">
                      <button form="<?= h($rtexFormId) ?>" type="submit" name="action" value="save_rtex_row" class="btn btn-sm btn-outline-primary">Save</button>
                      <button
                        form="<?= h($rtexFormId) ?>"
                        type="submit"
                        name="action"
                        value="delete_rtex_row"
                        class="btn btn-sm btn-outline-danger"
                        onclick="return confirm('Delete this RTEX row? This will remove it from driver payouts too.');"
                      >Delete</button>
                    </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <th colspan="10" class="text-end">Totals</th>
                <th><?= number_format(array_sum(array_map(static fn($r) => (float)$r['hours'], $rtexReviewRows)), 2) ?></th>
                <th>$<?= number_format(array_sum(array_map(static fn($r) => ((float)$r['hours']) * 10, $rtexReviewRows)), 2) ?></th>
                <th></th>
                <th></th>
                <th>$<?= number_format(array_sum(array_map(static fn($r) => (float)$r['total_amount'], $rtexReviewRows)), 2) ?></th>
                <th></th>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($dataRows)): ?>
      <h2 class="mt-4">Imported Data Preview</h2>
      <div class="table-responsive">
        <table class="table table-bordered table-sm table-preview">
          <thead><tr>
            <?php foreach ($expectedHeaders as $h): ?>
              <th><?= h($h) ?></th>
            <?php endforeach; ?>
          </tr></thead>
          <tbody>
            <?php foreach ($dataRows as $row): ?>
              <tr>
                <?php foreach ($expectedHeaders as $h):
                  $idx = array_search($h, $headersRow, true);
                ?>
                  <td><?= h($row[$idx] ?? '') ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php
      $splitRows = $_SESSION['split_rows'] ?? [];
      if (!empty($splitRows)):
    ?>
      <h2 class="mt-4">Split Driver Loads (Review Required)</h2>
      <p class="text-muted">Pickup and delivery drivers differ on these rows. Enter split percentages before payout posting.</p>
      <div class="table-responsive">
        <table class="table table-striped align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>Ticket</th>
              <th>Pickup Driver</th>
              <th>Delivery Driver</th>
              <th class="nowrap">Calc Freight Rate</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($splitRows as $sr):
              $idxPF = array_search('Pickup Driver First Name', $sr['headers'], true);
              $idxPL = array_search('Pickup Driver Last Name', $sr['headers'], true);
              $idxDF = array_search('Delivery Driver First Name', $sr['headers'], true);
              $idxDL = array_search('Delivery Driver Last Name', $sr['headers'], true);
              $pickupName = trim((string)($sr['cells'][$idxPF] ?? '') . ' ' . (string)($sr['cells'][$idxPL] ?? ''));
              $deliveryName = trim((string)($sr['cells'][$idxDF] ?? '') . ' ' . (string)($sr['cells'][$idxDL] ?? ''));
            ?>
              <tr>
                <td><?= (int)$sr['row_index'] + 1 ?></td>
                <td><code><?= h($sr['ticket_number'] ?? '') ?></code></td>
                <td><?= h($pickupName !== '' ? $pickupName : '—') ?></td>
                <td><?= h($deliveryName !== '' ? $deliveryName : '—') ?></td>
                <td><?= h($sr['tss_pay'] ?? '') ?></td>
                <td>
                  <button
                    class="btn btn-sm btn-outline-warning"
                    data-bs-toggle="modal"
                    data-bs-target="#splitResolveModal"
                    data-split-index="<?= (int)$sr['row_index'] ?>"
                  >Resolve Split</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php
      // Session-backed unresolved for immediate resolution post-upload
      $unmatched = $_SESSION['unmatched_rows'] ?? [];
      if (!empty($unmatched)):
    ?>
      <h2 class="mt-4">Unmatched Drivers (Review & Match)</h2>
      <p class="text-muted">Click “Resolve” to pick the correct contact or mark as a new driver.</p>
      <div class="table-responsive">
        <table class="table table-striped align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>Driver (From Report)</th>
              <th class="nowrap">Truck Digits</th>
              <th>Quick Candidates</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($unmatched as $u): 
              $idxTruck = array_search('Truck #', $u['headers'], true);
              $idxPF = array_search('Pickup Driver First Name', $u['headers'], true);
              $idxPL = array_search('Pickup Driver Last Name', $u['headers'], true);
              $idxDF = array_search('Delivery Driver First Name', $u['headers'], true);
              $idxDL = array_search('Delivery Driver Last Name', $u['headers'], true);
              $truckRaw = $u['cells'][$idxTruck] ?? '';
              $truckDigits = digits_only($truckRaw);
              $driverDisp = ($u['use_role']==='delivery')
                ? trim(($u['cells'][$idxDF] ?? '').' '.($u['cells'][$idxDL] ?? ''))
                : trim(($u['cells'][$idxPF] ?? '').' '.($u['cells'][$idxPL] ?? ''));
            ?>
              <tr>
                <td><?= (int)$u['row_index'] + 1 ?></td>
                <td><?= h($driverDisp) ?></td>
                <td class="nowrap"><code><?= h($truckDigits ?: '—') ?></code></td>
                <td>
                  <?php if (!empty($u['suggestions'])): ?>
                    <div class="small">
                      <?php foreach ($u['suggestions'] as $c): ?>
                        <div class="candidate">
                          <div><strong><?= h(trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? ''))) ?></strong></div>
                          <div class="small">
                            Truck: <?= h($c['truck_no'] ?? '') ?> 
                            <?php if (!empty($c['alt_truck_no'])): ?>
                              &middot; Alt: <?= h($c['alt_truck_no'] ?? '') ?>
                            <?php endif; ?>
                            &middot; ID: <?= (int)$c['id'] ?>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <span class="text-muted">No obvious candidates</span>
                  <?php endif; ?>
                </td>
                <td>
                  <button 
                    class="btn btn-sm btn-outline-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#resolveModal"
                    data-index="<?= (int)$u['row_index'] ?>"
                  >Resolve</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="modal fade" id="splitResolveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <form method="post">
          <input type="hidden" name="action" value="resolve_split">
          <input type="hidden" name="split_row_index" id="splitRowIndex" value="">
          <div class="modal-header">
            <h5 class="modal-title">Resolve Split Driver Load</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div id="splitDriverDetails" class="mb-3"></div>

            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" id="driversSame" name="drivers_same" value="1">
              <label class="form-check-label" for="driversSame">These are the same driver (false flag). Post as one payout row.</label>
            </div>

            <div id="splitPercentWrap" class="row g-3 mb-3">
              <div class="col-12 col-md-6">
                <label class="form-label">Pickup %</label>
                <input class="form-control" type="number" step="0.01" min="0" max="100" name="pickup_pct" id="pickupPct" value="50.00" required>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">Delivery %</label>
                <input class="form-control" type="number" step="0.01" min="0" max="100" name="delivery_pct" id="deliveryPct" value="50.00" required>
              </div>
              <div class="col-12">
                <div class="small text-muted">Percentages must total 100%.</div>
              </div>
            </div>

          </div>
          <div class="modal-footer">
            <button type="submit" class="btn btn-primary">Save Split</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Resolve Modal (works for both session-backed and DB-backed via hidden fields) -->
  <div class="modal fade" id="resolveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <form method="post">
          <input type="hidden" name="action" value="resolve">
          <!-- Session-backed -->
          <input type="hidden" name="row_index" id="resRowIndex" value="">
          <!-- DB-backed (unused here; used by unresolved.php modal) -->
          <input type="hidden" name="upload_date" id="resUploadDate" value="">
          <input type="hidden" name="delivery_date" id="resDeliveryDate" value="">
          <input type="hidden" name="truckload_id" id="resTruckloadId" value="">

          <div class="modal-header">
            <h5 class="modal-title">Resolve Driver Match</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div id="resDriverDetails" class="mb-3"></div>
            <hr>
            <h6>Select a matching contact</h6>
            <div id="resCandidates"></div>
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" id="markNew" name="mark_new" value="1">
              <label for="markNew" class="form-check-label">This is a new driver (not in contacts)</label>
            </div>
            <div class="mt-2">
              <a id="prefillContactLink" href="#" target="_blank" class="small d-none">Open driver form prefilled</a>
            </div>
          </div>
          <div class="modal-footer">
            <a href="driver_contacts.php" class="btn btn-outline-secondary">Open Contacts</a>
            <button type="submit" class="btn btn-primary">Confirm & Save</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="includes/rtex_load_ui.js?v=<?= filemtime(__DIR__ . '/includes/rtex_load_ui.js') ?>"></script>
  <script>
    const todayDate = <?= json_encode($todayDate) ?>;
    const hasLsDetailForToday = <?= $hasLsDetailForToday ? 'true' : 'false' ?>;
    const hasTssPayoutForToday = <?= $hasTssPayoutForToday ? 'true' : 'false' ?>;
    const openUnresolvedCount = <?= (int)$openUnresolvedCount ?>;
    const openSplitCount = <?= (int)$openSplitCount ?>;
    const selectedUploadVendor = <?= json_encode($selectedUploadVendor) ?>;
    const vendorForms = document.querySelectorAll('.vendor-form');
    function syncVendorForms() {
      vendorForms.forEach(form => {
        form.classList.toggle('d-none', form.dataset.vendor !== selectedUploadVendor);
      });
    }
    syncVendorForms();
    if (['rtex', 'nextier', 'nickelrock'].includes(selectedUploadVendor) && window.location.hash === '') {
      document.querySelector(`[data-vendor="${selectedUploadVendor}"]`)?.scrollIntoView({ block: 'start' });
    }
    const selectAllNickelrockRows = document.getElementById('selectAllNickelrockRows');
    const nickelrockRowCheckboxes = document.querySelectorAll('.nickelrock-row-checkbox');
    selectAllNickelrockRows?.addEventListener('change', () => {
      nickelrockRowCheckboxes.forEach((checkbox) => {
        checkbox.checked = selectAllNickelrockRows.checked;
      });
    });
    document.querySelectorAll('.nickelrock-job-select').forEach((select) => {
      select.addEventListener('change', () => {
        const selectedOption = select.options[select.selectedIndex];
        const rate = selectedOption?.dataset?.rate || '';
        const workOrder = selectedOption?.dataset?.workOrder || '';
        const rateTargetId = select.dataset.rateTarget || '';
        const workOrderTargetId = select.dataset.workOrderTarget || '';
        const rateInput = rateTargetId ? document.getElementById(rateTargetId) : null;
        const workOrderInput = workOrderTargetId ? document.getElementById(workOrderTargetId) : null;
        if (rateInput && rate !== '') {
          rateInput.value = rate;
          rateInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (workOrderInput) {
          workOrderInput.value = workOrder;
          workOrderInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
    });
    const selectAllNextierRows = document.getElementById('selectAllNextierRows');
    const nextierRowCheckboxes = document.querySelectorAll('.nextier-row-checkbox');
    selectAllNextierRows?.addEventListener('change', () => {
      nextierRowCheckboxes.forEach((checkbox) => {
        checkbox.checked = selectAllNextierRows.checked;
      });
    });
    const selectAllRtexRows = document.getElementById('selectAllRtexRows');
    const rtexRowCheckboxes = document.querySelectorAll('.rtex-row-checkbox');
    selectAllRtexRows?.addEventListener('change', () => {
      rtexRowCheckboxes.forEach((checkbox) => {
        checkbox.checked = selectAllRtexRows.checked;
      });
    });

    const tssPayoutForm = document.getElementById('tssPayoutForm');
    tssPayoutForm?.addEventListener('submit', (event) => {
      if (!hasLsDetailForToday) {
        event.preventDefault();
        alert(`We cannot upload this file until an LS Detail file has been successfully uploaded for ${todayDate}.`);
        return;
      }
      if (openUnresolvedCount > 0) {
        event.preventDefault();
        alert('We cannot upload this file until all Open Unresolved Matches have been resolved. Please click the Open Unresolved Review button above to resolve these issues before moving forward.');
        return;
      }
      if (openSplitCount > 0) {
        event.preventDefault();
        alert('We cannot upload this file until all split-driver rows have been reviewed and saved.');
      }
    });

    const miscAdjustmentForm = document.getElementById('miscAdjustmentForm');
    const allowWithoutDailyUploads = document.getElementById('allowWithoutDailyUploads');
    const miscLockableFields = miscAdjustmentForm ? miscAdjustmentForm.querySelectorAll('.misc-lockable') : [];
    const miscSaveButton = miscAdjustmentForm?.querySelector('button[type="submit"]');
    const miscRequiresDailyUploads = !hasLsDetailForToday || !hasTssPayoutForToday;
    const driverBonusesForm = document.getElementById('driverBonusesForm');
    const allowDriverBonusesWithoutDailyUploads = document.getElementById('allowDriverBonusesWithoutDailyUploads');
    const bonusLockableFields = driverBonusesForm ? driverBonusesForm.querySelectorAll('.bonus-lockable') : [];
    const bonusReviewButton = driverBonusesForm?.querySelector('button[type="submit"]');
    const bonusRequiresDailyUploads = !hasLsDetailForToday || !hasTssPayoutForToday;

    function syncMiscAdjustmentLockState() {
      if (!miscAdjustmentForm || !allowWithoutDailyUploads || !miscSaveButton) return;
      const locked = miscRequiresDailyUploads && !allowWithoutDailyUploads.checked;
      miscLockableFields.forEach((field) => {
        field.disabled = locked;
      });
      miscSaveButton.disabled = locked;
      miscSaveButton.setAttribute('aria-disabled', locked ? 'true' : 'false');
      miscAdjustmentForm.classList.toggle('misc-adjustment-locked', locked);
    }

    allowWithoutDailyUploads?.addEventListener('change', syncMiscAdjustmentLockState);
    syncMiscAdjustmentLockState();

    function syncDriverBonusesLockState() {
      if (!driverBonusesForm || !allowDriverBonusesWithoutDailyUploads || !bonusReviewButton) return;
      const locked = bonusRequiresDailyUploads && !allowDriverBonusesWithoutDailyUploads.checked;
      bonusLockableFields.forEach((field) => {
        field.disabled = locked;
      });
      bonusReviewButton.disabled = locked;
      bonusReviewButton.setAttribute('aria-disabled', locked ? 'true' : 'false');
    }

    allowDriverBonusesWithoutDailyUploads?.addEventListener('change', syncDriverBonusesLockState);
    syncDriverBonusesLockState();

    // Modal population from session-stored split rows and unmatched rows
    const splitRows = <?= json_encode($_SESSION['split_rows'] ?? [], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
    const unmatched = <?= json_encode($_SESSION['unmatched_rows'] ?? [], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;

    function esc(str) {
      return (str ?? '').toString()
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    const splitResolveModal = document.getElementById('splitResolveModal');
    splitResolveModal?.addEventListener('show.bs.modal', (ev) => {
      const btn = ev.relatedTarget;
      const idx = parseInt(btn?.getAttribute('data-split-index') || '-1', 10);
      const data = (splitRows && splitRows[idx]) ? splitRows[idx] : null;

      const detailsDiv = document.getElementById('splitDriverDetails');
      const splitRowIndex = document.getElementById('splitRowIndex');
      const pickupPct = document.getElementById('pickupPct');
      const deliveryPct = document.getElementById('deliveryPct');
      const driversSame = document.getElementById('driversSame');

      splitRowIndex.value = (idx >= 0 ? idx : '');
      detailsDiv.innerHTML = '';
      pickupPct.value = '50.00';
      deliveryPct.value = '50.00';
      driversSame.checked = false;

      if (!data) {
        detailsDiv.innerHTML = '<div class="text-danger">Unable to load split row details.</div>';
        return;
      }

      const hdr = data.headers || [];
      const cells = data.cells || [];
      const pickupFirst = cells[hdr.indexOf('Pickup Driver First Name')] || '';
      const pickupLast = cells[hdr.indexOf('Pickup Driver Last Name')] || '';
      const deliveryFirst = cells[hdr.indexOf('Delivery Driver First Name')] || '';
      const deliveryLast = cells[hdr.indexOf('Delivery Driver Last Name')] || '';
      const truckRaw = cells[hdr.indexOf('Truck #')] || '';
      const payoutDate = data.payout_date || (cells[hdr.indexOf('Delivery Date')] || '');
      const ticket = data.ticket_number || (cells[hdr.indexOf('Truckload ID')] || '');
      const amount = data.tss_pay || (cells[hdr.indexOf('Calculated Freight Rate (Carrier)')] || '');

      detailsDiv.innerHTML = `
        <div><strong>Ticket #:</strong> ${esc(ticket)}</div>
        <div><strong>Payout Date:</strong> ${esc(payoutDate)}</div>
        <div><strong>Truck #:</strong> ${esc(truckRaw)} &nbsp; <strong>Digits:</strong> <code>${esc((truckRaw||'').replace(/\\D+/g,''))}</code></div>
        <div><strong>Calculated Freight Rate:</strong> ${esc(amount)}</div>
        <div><strong>Pickup Driver:</strong> ${esc((pickupFirst + ' ' + pickupLast).trim())}</div>
        <div><strong>Delivery Driver:</strong> ${esc((deliveryFirst + ' ' + deliveryLast).trim())}</div>
      `;
    });

    const driversSame = document.getElementById('driversSame');
    driversSame?.addEventListener('change', () => {
      const same = !!driversSame.checked;
      const pickupPct = document.getElementById('pickupPct');
      const deliveryPct = document.getElementById('deliveryPct');

      pickupPct.disabled = same;
      deliveryPct.disabled = same;
      pickupPct.required = !same;
      deliveryPct.required = !same;

      if (same) {
        pickupPct.value = '100.00';
        deliveryPct.value = '0.00';
      } else {
        pickupPct.value = '50.00';
        deliveryPct.value = '50.00';
      }
    });

    const resolveModal = document.getElementById('resolveModal');
    resolveModal?.addEventListener('show.bs.modal', (ev) => {
      const btn = ev.relatedTarget;
      const idx = parseInt(btn?.getAttribute('data-index') || '-1', 10);
      const data = (unmatched && unmatched[idx]) ? unmatched[idx] : null;

      // Clear DB-backed fields (not used on this page)
      document.getElementById('resUploadDate').value = '';
      document.getElementById('resDeliveryDate').value = '';
      document.getElementById('resTruckloadId').value = '';

      document.getElementById('resRowIndex').value = (idx >= 0 ? idx : '');
      const detailsDiv = document.getElementById('resDriverDetails');
      const candsDiv   = document.getElementById('resCandidates');
      const prefillLink = document.getElementById('prefillContactLink');
      const markNewChk  = document.getElementById('markNew');

      detailsDiv.innerHTML = '';
      candsDiv.innerHTML   = '';
      markNewChk.checked = false;
      prefillLink.classList.add('d-none');

      if (!data) {
        detailsDiv.innerHTML = '<div class="text-danger">Unable to load row details.</div>';
        return;
      }

      const hdr = data.headers;
      const cells = data.cells;
      const useRole = data.use_role || 'pickup';

      const pickF = cells[hdr.indexOf('Pickup Driver First Name')] || '';
      const pickL = cells[hdr.indexOf('Pickup Driver Last Name')] || '';
      const delF  = cells[hdr.indexOf('Delivery Driver First Name')] || '';
      const delL  = cells[hdr.indexOf('Delivery Driver Last Name')] || '';
      const driverFirst = useRole === 'delivery' ? delF : pickF;
      const driverLast  = useRole === 'delivery' ? delL : pickL;

      const truckRaw = cells[hdr.indexOf('Truck #')] || '';
      const deliv    = cells[hdr.indexOf('Delivery Date')] || '';
      const ticket   = cells[hdr.indexOf('Truckload ID')] || '';

      detailsDiv.innerHTML = `
        <div><strong>Report Driver:</strong> ${esc((driverFirst + ' ' + driverLast).trim())}</div>
        <div><strong>Truck # (raw):</strong> ${esc(truckRaw)} &nbsp; <strong>Digits:</strong> <code>${esc((truckRaw||'').replace(/\\D+/g,''))}</code></div>
        <div><strong>Delivery Date:</strong> ${esc(deliv)}</div>
        <div><strong>Ticket #:</strong> ${esc(ticket)}</div>
      `;

      // Render candidate radios
      const suggestions = data.suggestions || [];
      if (suggestions.length) {
        suggestions.forEach((c) => {
          const name = (c.first_name||'') + ' ' + (c.last_name||'');
          const truck = c.truck_no || '';
          const alt   = c.alt_truck_no || '';
          const id    = parseInt(c.id || 0, 10);
          const radioId = `cand_${idx}_${id}`;

          const div = document.createElement('div');
          div.className = 'candidate form-check';
          div.innerHTML = `
            <input class="form-check-input" type="radio" name="contact_id" id="${radioId}" value="${id}">
            <label class="form-check-label" for="${radioId}">
              <strong>${esc(name.trim())}</strong>
              <div class="small">Truck: ${esc(truck)} ${alt ? ' · Alt: ' + esc(alt) : ''} · ID: ${id}</div>
            </label>
          `;
          candsDiv.appendChild(div);
        });
      } else {
        candsDiv.innerHTML = '<div class="text-muted">No candidates found. You can mark as a new driver or add a contact.</div>';
      }

      // Prefill link for quick adding a new contact
      const query = new URLSearchParams({
        first_name: driverFirst,
        last_name: driverLast,
        truck_no: (truckRaw || '').replace(/\D+/g,'')
      }).toString();
      prefillLink.href = 'driver_contacts.php?' + query;
      prefillLink.textContent = 'Open driver form prefilled with this info';
      prefillLink.classList.remove('d-none');
    });
  </script>
  </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
