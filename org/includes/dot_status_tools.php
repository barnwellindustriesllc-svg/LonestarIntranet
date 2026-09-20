<?php
require_once __DIR__ . '/../dot/config.php';
require_once __DIR__ . '/../dot/safer_client.php';

if (!function_exists('dot_status_api_available')) {
    function dot_status_api_available(): bool {
        return defined('SAFER_API_KEY') && SAFER_API_KEY !== 'YOUR_API_KEY_HERE';
    }
}

if (!function_exists('dot_status_normalize_number')) {
    function dot_status_normalize_number($value): string {
        return preg_replace('/\D+/', '', (string)$value);
    }
}

if (!function_exists('dot_status_get_field')) {
    function dot_status_get_field(array $data, array $keys, string $default = ''): string {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return (string)$data[$key];
            }
        }
        return $default;
    }
}

if (!function_exists('dot_status_latest_inspection_date')) {
    function dot_status_latest_inspection_date(array $inspectionRecords): ?string {
        $latest = null;
        foreach ($inspectionRecords as $rec) {
            $date = trim((string)($rec['inspection_date'] ?? ''));
            if ($date === '') {
                continue;
            }
            $ts = strtotime($date);
            if ($ts && ($latest === null || $ts > $latest)) {
                $latest = $ts;
            }
        }
        return $latest ? date('Y-m-d', $latest) : null;
    }
}

if (!function_exists('dot_status_count_inspections_with_violations')) {
    function dot_status_count_inspections_with_violations(array $inspectionRecords): int {
        $count = 0;
        foreach ($inspectionRecords as $rec) {
            $viol = $rec['violation_totals']['basic'] ?? 0;
            if (is_numeric($viol) && (int)$viol > 0) {
                $count++;
            }
        }
        return $count;
    }
}

if (!function_exists('dot_status_is_good_standing')) {
    function dot_status_is_good_standing(array $snapshot): bool {
        $operatingStatus = strtoupper(trim((string)($snapshot['operating_status'] ?? '')));
        $outOfServiceDate = trim((string)($snapshot['out_of_service_date'] ?? ''));

        if ($operatingStatus === '') {
            return false;
        }
        if ($outOfServiceDate !== '') {
            return false;
        }
        if (strpos($operatingStatus, 'NOT AUTHORIZED') !== false) {
            return false;
        }
        if (strpos($operatingStatus, 'OUT OF SERVICE') !== false) {
            return false;
        }

        return strpos($operatingStatus, 'AUTHORIZED') !== false;
    }
}

if (!function_exists('dot_status_ensure_cache_table')) {
    function dot_status_ensure_cache_table(mysqli $mysqli): void {
        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS dot_status_cache (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                dot_number VARCHAR(20) NOT NULL,
                legal_name VARCHAR(255) NULL,
                dba_name VARCHAR(255) NULL,
                entity_type VARCHAR(100) NULL,
                usdot_status VARCHAR(80) NULL,
                operating_status VARCHAR(150) NULL,
                out_of_service_date VARCHAR(30) NULL,
                last_inspection_date DATE NULL,
                crashes_total INT UNSIGNED NOT NULL DEFAULT 0,
                violations_total INT UNSIGNED NOT NULL DEFAULT 0,
                inspections_with_violations INT UNSIGNED NOT NULL DEFAULT 0,
                out_of_service_percent VARCHAR(30) NULL,
                safer_latest_update VARCHAR(50) NULL,
                good_standing TINYINT(1) NOT NULL DEFAULT 0,
                api_error TEXT NULL,
                checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_dot_number (dot_number),
                KEY idx_checked_at (checked_at),
                KEY idx_good_standing (good_standing)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        foreach ([
            'usdot_status' => "ALTER TABLE dot_status_cache ADD COLUMN usdot_status VARCHAR(80) NULL AFTER entity_type",
            'out_of_service_date' => "ALTER TABLE dot_status_cache ADD COLUMN out_of_service_date VARCHAR(30) NULL AFTER operating_status",
        ] as $column => $alterSql) {
            $res = $mysqli->query("SHOW COLUMNS FROM dot_status_cache LIKE '{$column}'");
            $exists = $res && $res->num_rows > 0;
            if ($res) $res->free();
            if (!$exists) $mysqli->query($alterSql);
        }
    }
}

if (!function_exists('dot_status_build_row')) {
    function dot_status_build_row(string $dotNumber, array $snapshot, array $inspectionsData, array $violationsData): array {
        $inspectionRecords = $inspectionsData['inspection_records'] ?? [];
        $violationRecords = $violationsData['violation_records'] ?? [];
        $outOfServiceDate = dot_status_get_field($snapshot, ['out_of_service_date']);
        $usdotStatus = strtoupper(trim(dot_status_get_field($snapshot, ['usdot_status', 'usdotStatus', 'status'])));
        if ($usdotStatus === '' && !empty($snapshot)) {
            $usdotStatus = $outOfServiceDate !== '' ? 'INACTIVE' : 'ACTIVE';
        }

        return [
            'dot_number' => $dotNumber,
            'legal_name' => dot_status_get_field($snapshot, ['legal_name']),
            'dba_name' => dot_status_get_field($snapshot, ['dba_name']),
            'entity_type' => dot_status_get_field($snapshot, ['entity_type']),
            'usdot_status' => $usdotStatus,
            'operating_status' => dot_status_get_field($snapshot, ['operating_authority_status', 'authority_status', 'operating_status']),
            'out_of_service_date' => $outOfServiceDate,
            'last_inspection_date' => dot_status_latest_inspection_date($inspectionRecords),
            'crashes_total' => (int)dot_status_get_field($snapshot['united_states_crashes'] ?? [], ['total'], '0'),
            'violations_total' => count($violationRecords),
            'inspections_with_violations' => dot_status_count_inspections_with_violations($inspectionRecords),
            'out_of_service_percent' => dot_status_get_field($snapshot['united_states_inspections']['vehicle'] ?? [], ['out_of_service_percent']),
            'safer_latest_update' => dot_status_get_field($snapshot, ['latest_update']),
            'good_standing' => dot_status_is_good_standing($snapshot) ? 1 : 0,
            'api_error' => null,
            'checked_at' => date('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('dot_status_upsert_row')) {
    function dot_status_upsert_row(mysqli $mysqli, array $row): void {
        $stmt = $mysqli->prepare(
            "INSERT INTO dot_status_cache
                (dot_number, legal_name, dba_name, entity_type, usdot_status, operating_status, out_of_service_date, last_inspection_date,
                 crashes_total, violations_total, inspections_with_violations, out_of_service_percent,
                 safer_latest_update, good_standing, api_error, checked_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 legal_name = VALUES(legal_name),
                 dba_name = VALUES(dba_name),
                 entity_type = VALUES(entity_type),
                 usdot_status = VALUES(usdot_status),
                 operating_status = VALUES(operating_status),
                 out_of_service_date = VALUES(out_of_service_date),
                 last_inspection_date = VALUES(last_inspection_date),
                 crashes_total = VALUES(crashes_total),
                 violations_total = VALUES(violations_total),
                 inspections_with_violations = VALUES(inspections_with_violations),
                 out_of_service_percent = VALUES(out_of_service_percent),
                 safer_latest_update = VALUES(safer_latest_update),
                 good_standing = VALUES(good_standing),
                 api_error = VALUES(api_error),
                 checked_at = VALUES(checked_at)"
        );
        $stmt->bind_param(
            'ssssssssiiississ',
            $row['dot_number'],
            $row['legal_name'],
            $row['dba_name'],
            $row['entity_type'],
            $row['usdot_status'],
            $row['operating_status'],
            $row['out_of_service_date'],
            $row['last_inspection_date'],
            $row['crashes_total'],
            $row['violations_total'],
            $row['inspections_with_violations'],
            $row['out_of_service_percent'],
            $row['safer_latest_update'],
            $row['good_standing'],
            $row['api_error'],
            $row['checked_at']
        );
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('dot_status_record_error')) {
    function dot_status_record_error(mysqli $mysqli, string $dotNumber, string $errorMessage): void {
        $checkedAt = date('Y-m-d H:i:s');
        $stmt = $mysqli->prepare(
            "INSERT INTO dot_status_cache (dot_number, api_error, checked_at)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE api_error = VALUES(api_error), checked_at = VALUES(checked_at)"
        );
        $stmt->bind_param('sss', $dotNumber, $errorMessage, $checkedAt);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('dot_status_fetch_cached_map')) {
    function dot_status_fetch_cached_map(mysqli $mysqli, array $dotNumbers): array {
        $dotNumbers = array_values(array_filter(array_unique(array_map('dot_status_normalize_number', $dotNumbers))));
        if (empty($dotNumbers)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($dotNumbers), '?'));
        $types = str_repeat('s', count($dotNumbers));
        $sql = "SELECT dot_number, legal_name, dba_name, entity_type, usdot_status, operating_status, out_of_service_date, last_inspection_date,
                       crashes_total, violations_total, inspections_with_violations, out_of_service_percent,
                       safer_latest_update, good_standing, api_error, checked_at
                  FROM dot_status_cache
                 WHERE dot_number IN ($placeholders)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param($types, ...$dotNumbers);
        $stmt->execute();
        $res = $stmt->get_result();

        $map = [];
        while ($row = $res->fetch_assoc()) {
            $map[(string)$row['dot_number']] = $row;
        }
        $stmt->close();

        return $map;
    }
}

if (!function_exists('dot_status_refresh_single')) {
    function dot_status_refresh_single(mysqli $mysqli, string $dotNumber, bool $forceRefresh = false): void {
        $dotNumber = dot_status_normalize_number($dotNumber);
        if ($dotNumber === '' || !dot_status_api_available()) {
            return;
        }

        $cacheSuffix = $forceRefresh ? null : $dotNumber;
        $snapshot = safer_call('/v2/usdot/snapshot/' . rawurlencode($dotNumber), $cacheSuffix ? 'snap_' . $cacheSuffix : null);
        $inspectionsData = safer_call('/v3/history/inspection/' . rawurlencode($dotNumber), $cacheSuffix ? 'hist_ins_' . $cacheSuffix : null);
        $violationsData = safer_call('/v3/history/violation/' . rawurlencode($dotNumber), $cacheSuffix ? 'hist_vio_' . $cacheSuffix : null);

        dot_status_upsert_row($mysqli, dot_status_build_row($dotNumber, $snapshot, $inspectionsData, $violationsData));
    }
}

if (!function_exists('dot_status_get_map')) {
    function dot_status_get_map(mysqli $mysqli, array $dotNumbers, bool $refreshStale = false, bool $forceRefresh = false): array {
        dot_status_ensure_cache_table($mysqli);

        $dotNumbers = array_values(array_filter(array_unique(array_map('dot_status_normalize_number', $dotNumbers))));
        if (empty($dotNumbers)) {
            return [];
        }

        $map = dot_status_fetch_cached_map($mysqli, $dotNumbers);
        if ($refreshStale && dot_status_api_available()) {
            $today = date('Y-m-d');
            foreach ($dotNumbers as $dotNumber) {
                $cached = $map[$dotNumber] ?? null;
                $checkedDate = !empty($cached['checked_at']) ? substr((string)$cached['checked_at'], 0, 10) : '';
                $needsRefresh = $forceRefresh || !$cached || $checkedDate !== $today
                    || trim((string)($cached['usdot_status'] ?? '')) === '';
                if (!$needsRefresh) {
                    continue;
                }
                try {
                    dot_status_refresh_single($mysqli, $dotNumber, $forceRefresh);
                } catch (Throwable $e) {
                    dot_status_record_error($mysqli, $dotNumber, $e->getMessage());
                }
            }
            $map = dot_status_fetch_cached_map($mysqli, $dotNumbers);
        }

        return $map;
    }
}
