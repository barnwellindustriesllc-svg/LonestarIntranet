<?php

function lonestar_trailer_history_ensure_table(mysqli $mysqli): void {
    static $done = false;
    if ($done) {
        return;
    }
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS trailer_assignment_history (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          trailer_id INT UNSIGNED NULL,
          trailer_number VARCHAR(80) NOT NULL,
          driver_contact_id INT UNSIGNED NOT NULL,
          assigned_date DATE NOT NULL,
          removed_date DATE NULL,
          vendor VARCHAR(80) NULL,
          trailer_type VARCHAR(80) NULL,
          trailer_status VARCHAR(80) NULL,
          trailer_fee_mode VARCHAR(20) NULL,
          trailer_fee_value DECIMAL(12,2) NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY idx_driver_dates (driver_contact_id, assigned_date, removed_date),
          KEY idx_trailer_dates (trailer_number, assigned_date, removed_date),
          KEY idx_open_assignment (driver_contact_id, removed_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    lonestar_trailer_history_ensure_snapshot_columns($mysqli);
    lonestar_trailer_history_seed_current($mysqli);
    $done = true;
}

function lonestar_trailer_history_column_exists(mysqli $mysqli, string $column): bool {
    $column = $mysqli->real_escape_string($column);
    $res = $mysqli->query("SHOW COLUMNS FROM trailer_assignment_history LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function lonestar_trailer_history_ensure_snapshot_columns(mysqli $mysqli): void {
    $columns = [
        'vendor' => "ALTER TABLE trailer_assignment_history ADD COLUMN vendor VARCHAR(80) NULL AFTER removed_date",
        'trailer_type' => "ALTER TABLE trailer_assignment_history ADD COLUMN trailer_type VARCHAR(80) NULL AFTER vendor",
        'trailer_status' => "ALTER TABLE trailer_assignment_history ADD COLUMN trailer_status VARCHAR(80) NULL AFTER trailer_type",
        'trailer_fee_mode' => "ALTER TABLE trailer_assignment_history ADD COLUMN trailer_fee_mode VARCHAR(20) NULL AFTER trailer_status",
        'trailer_fee_value' => "ALTER TABLE trailer_assignment_history ADD COLUMN trailer_fee_value DECIMAL(12,2) NULL AFTER trailer_fee_mode",
    ];
    foreach ($columns as $column => $sql) {
        if (!lonestar_trailer_history_column_exists($mysqli, $column)) {
            $mysqli->query($sql);
        }
    }
    $mysqli->query(
        "UPDATE trailer_assignment_history tah
          JOIN trailer_assets ta
            ON (
                (tah.trailer_id IS NOT NULL AND ta.id = tah.trailer_id)
                OR TRIM(COALESCE(ta.trailer_number, '')) = TRIM(COALESCE(tah.trailer_number, ''))
            )
           SET tah.vendor = COALESCE(NULLIF(tah.vendor, ''), ta.vendor),
               tah.trailer_type = COALESCE(NULLIF(tah.trailer_type, ''), ta.trailer_type),
               tah.trailer_status = COALESCE(NULLIF(tah.trailer_status, ''), ta.status),
               tah.trailer_fee_mode = COALESCE(NULLIF(tah.trailer_fee_mode, ''), ta.trailer_fee_mode),
               tah.trailer_fee_value = COALESCE(tah.trailer_fee_value, ta.trailer_fee_value)
         WHERE tah.vendor IS NULL
            OR tah.vendor = ''
            OR tah.trailer_fee_mode IS NULL
            OR tah.trailer_fee_value IS NULL"
    );
}

function lonestar_trailer_history_trailer_vendor(mysqli $mysqli, string $trailerNumber): string {
    $trailerNumber = trim($trailerNumber);
    if ($trailerNumber === '' || strtolower($trailerNumber) === 'own') {
        return '';
    }
    lonestar_trailer_history_ensure_table($mysqli);
    $stmt = $mysqli->prepare("SELECT vendor FROM trailer_assets WHERE TRIM(trailer_number) = TRIM(?) LIMIT 1");
    if (!$stmt) {
        return '';
    }
    $stmt->bind_param('s', $trailerNumber);
    $stmt->execute();
    $stmt->bind_result($vendor);
    $found = $stmt->fetch();
    $stmt->close();
    return $found ? trim((string)$vendor) : '';
}

function lonestar_trailer_history_driver_has_open_vendor(
    mysqli $mysqli,
    int $driverId,
    string $vendor,
    int $excludeHistoryId = 0
): bool {
    $vendor = trim($vendor);
    if ($driverId <= 0 || $vendor === '') {
        return false;
    }
    lonestar_trailer_history_ensure_table($mysqli);
    $sql = "SELECT tah.id
              FROM trailer_assignment_history tah
         LEFT JOIN trailer_assets ta
                ON (
                    (tah.trailer_id IS NOT NULL AND ta.id = tah.trailer_id)
                    OR TRIM(COALESCE(ta.trailer_number, '')) = TRIM(COALESCE(tah.trailer_number, ''))
                )
             WHERE tah.driver_contact_id = ?
               AND tah.removed_date IS NULL
               AND LOWER(TRIM(COALESCE(NULLIF(tah.vendor, ''), ta.vendor))) = LOWER(TRIM(?))";
    if ($excludeHistoryId > 0) {
        $sql .= " AND tah.id <> ?";
    }
    $sql .= " LIMIT 1";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return false;
    }
    if ($excludeHistoryId > 0) {
        $stmt->bind_param('isi', $driverId, $vendor, $excludeHistoryId);
    } else {
        $stmt->bind_param('is', $driverId, $vendor);
    }
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function lonestar_trailer_history_trailer_is_open_assigned(
    mysqli $mysqli,
    string $trailerNumber,
    int $excludeHistoryId = 0
): bool {
    $trailerNumber = trim($trailerNumber);
    if ($trailerNumber === '' || strtolower($trailerNumber) === 'own') {
        return false;
    }
    lonestar_trailer_history_ensure_table($mysqli);
    $sql = "SELECT id
              FROM trailer_assignment_history
             WHERE removed_date IS NULL
               AND TRIM(trailer_number) = TRIM(?)";
    if ($excludeHistoryId > 0) {
        $sql .= " AND id <> ?";
    }
    $sql .= " LIMIT 1";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return false;
    }
    if ($excludeHistoryId > 0) {
        $stmt->bind_param('si', $trailerNumber, $excludeHistoryId);
    } else {
        $stmt->bind_param('s', $trailerNumber);
    }
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function lonestar_trailer_history_seed_current(mysqli $mysqli): void {
    $mysqli->query(
        "INSERT INTO trailer_assignment_history
            (trailer_id, trailer_number, driver_contact_id, assigned_date, removed_date, vendor, trailer_type, trailer_status, trailer_fee_mode, trailer_fee_value)
         SELECT ta.id,
                TRIM(dc.trailer_no),
                dc.id,
                COALESCE(dc.trailer_received_date, CURDATE()),
                NULL,
                ta.vendor,
                ta.trailer_type,
                ta.status,
                ta.trailer_fee_mode,
                ta.trailer_fee_value
           FROM driver_contacts dc
           LEFT JOIN trailer_assets ta
                  ON TRIM(COALESCE(ta.trailer_number, '')) = TRIM(COALESCE(dc.trailer_no, ''))
          WHERE TRIM(COALESCE(dc.trailer_no, '')) <> ''
            AND LOWER(TRIM(COALESCE(dc.trailer_no, ''))) <> 'own'
            AND COALESCE(dc.is_disabled, 0) = 0
            AND NOT EXISTS (
                SELECT 1
                  FROM trailer_assignment_history tah
                 WHERE tah.driver_contact_id = dc.id
                   AND tah.removed_date IS NULL
            )"
    );
}

function lonestar_trailer_history_close_open(
    mysqli $mysqli,
    int $driverId,
    string $removedDate,
    string $trailerNumber = ''
): void {
    if ($driverId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $removedDate)) {
        return;
    }
    lonestar_trailer_history_ensure_table($mysqli);
    $sql = "UPDATE trailer_assignment_history
               SET removed_date = ?,
                   updated_at = NOW()
             WHERE driver_contact_id = ?
               AND removed_date IS NULL";
    if (trim($trailerNumber) !== '') {
        $sql .= " AND TRIM(trailer_number) = TRIM(?)";
    }
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return;
    }
    if (trim($trailerNumber) !== '') {
        $stmt->bind_param('sis', $removedDate, $driverId, $trailerNumber);
    } else {
        $stmt->bind_param('si', $removedDate, $driverId);
    }
    $stmt->execute();
    $stmt->close();
}

function lonestar_trailer_history_open_assignment(
    mysqli $mysqli,
    int $driverId,
    string $trailerNumber,
    string $assignedDate
): void {
    $trailerNumber = trim($trailerNumber);
    if (
        $driverId <= 0
        || $trailerNumber === ''
        || strtolower($trailerNumber) === 'own'
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $assignedDate)
    ) {
        return;
    }
    lonestar_trailer_history_ensure_table($mysqli);
    $vendor = lonestar_trailer_history_trailer_vendor($mysqli, $trailerNumber);
    if ($vendor !== '' && lonestar_trailer_history_driver_has_open_vendor($mysqli, $driverId, $vendor)) {
        return;
    }
    if (lonestar_trailer_history_trailer_is_open_assigned($mysqli, $trailerNumber)) {
        return;
    }
    $trailerId = null;
    $snapshotVendor = null;
    $snapshotType = null;
    $snapshotStatus = null;
    $snapshotFeeMode = null;
    $snapshotFeeValue = null;
    $lookup = $mysqli->prepare(
        "SELECT id, vendor, trailer_type, status, trailer_fee_mode, trailer_fee_value
           FROM trailer_assets
          WHERE TRIM(trailer_number) = TRIM(?)
          LIMIT 1"
    );
    if ($lookup) {
        $lookup->bind_param('s', $trailerNumber);
        $lookup->execute();
        $lookup->bind_result($foundTrailerId, $foundVendor, $foundType, $foundStatus, $foundFeeMode, $foundFeeValue);
        if ($lookup->fetch()) {
            $trailerId = (int)$foundTrailerId;
            $snapshotVendor = $foundVendor !== null ? (string)$foundVendor : null;
            $snapshotType = $foundType !== null ? (string)$foundType : null;
            $snapshotStatus = $foundStatus !== null ? (string)$foundStatus : null;
            $snapshotFeeMode = $foundFeeMode !== null ? (string)$foundFeeMode : null;
            $snapshotFeeValue = $foundFeeValue !== null ? (float)$foundFeeValue : null;
        }
        $lookup->close();
    }

    $stmt = $mysqli->prepare(
        "INSERT INTO trailer_assignment_history
            (trailer_id, trailer_number, driver_contact_id, assigned_date, removed_date, vendor, trailer_type, trailer_status, trailer_fee_mode, trailer_fee_value)
         VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param(
        'isisssssd',
        $trailerId,
        $trailerNumber,
        $driverId,
        $assignedDate,
        $snapshotVendor,
        $snapshotType,
        $snapshotStatus,
        $snapshotFeeMode,
        $snapshotFeeValue
    );
    $stmt->execute();
    $stmt->close();
}

function lonestar_trailer_history_sync_driver_assignment(
    mysqli $mysqli,
    int $driverId,
    string $oldTrailer,
    string $newTrailer,
    string $assignedDate,
    string $removedDate = ''
): void {
    $oldTrailer = trim($oldTrailer);
    $newTrailer = trim($newTrailer);
    $oldBillable = $oldTrailer !== '' && strtolower($oldTrailer) !== 'own';
    $newBillable = $newTrailer !== '' && strtolower($newTrailer) !== 'own';
    if (!$oldBillable && !$newBillable) {
        return;
    }
    if ($assignedDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $assignedDate)) {
        $assignedDate = date('Y-m-d');
    }
    if ($removedDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $removedDate)) {
        $removedDate = $assignedDate;
    }
    if ($oldBillable && strcasecmp($oldTrailer, $newTrailer) !== 0) {
        lonestar_trailer_history_close_open($mysqli, $driverId, $removedDate, $oldTrailer);
    }
    if ($oldBillable && $newBillable && strcasecmp($oldTrailer, $newTrailer) === 0) {
        lonestar_trailer_history_ensure_table($mysqli);
        $stmt = $mysqli->prepare(
            "UPDATE trailer_assignment_history
                SET assigned_date = ?,
                    updated_at = NOW()
              WHERE driver_contact_id = ?
                AND removed_date IS NULL
                AND TRIM(trailer_number) = TRIM(?)"
        );
        if ($stmt) {
            $stmt->bind_param('sis', $assignedDate, $driverId, $newTrailer);
            $stmt->execute();
            $stmt->close();
        }
    }
    if ($newBillable) {
        lonestar_trailer_history_open_assignment($mysqli, $driverId, $newTrailer, $assignedDate);
    }
}
