<?php
function audit_log_ensure_table(mysqli $mysqli): void {
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS change_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            username VARCHAR(191) NULL,
            user_type VARCHAR(40) NULL,
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(80) NOT NULL,
            entity_id VARCHAR(80) NULL,
            description TEXT NULL,
            change_record TEXT NULL,
            before_data JSON NULL,
            after_data JSON NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_created_at (created_at),
            KEY idx_entity (entity_type, entity_id),
            KEY idx_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $result = $mysqli->query("SHOW COLUMNS FROM change_logs LIKE 'change_record'");
    if ($result && $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE change_logs ADD COLUMN change_record TEXT NULL AFTER description");
    }
    if ($result) {
        $result->close();
    }
}

function audit_log_format_change_record(?array $before = null, ?array $after = null): string {
    if ($before === null && $after === null) {
        return '';
    }

    $formatValue = static function ($value): string {
        if ($value === null || $value === '') {
            return '(blank)';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        return (string)$value;
    };

    $changes = [];
    $keys = array_unique(array_merge(array_keys($before ?? []), array_keys($after ?? [])));
    foreach ($keys as $key) {
        $oldValue = $before[$key] ?? null;
        $newValue = $after[$key] ?? null;
        if ($before !== null && $after !== null && $oldValue == $newValue) {
            continue;
        }
        $label = ucwords(str_replace('_', ' ', (string)$key));
        if ($before === null) {
            $changes[] = $label . ': ' . $formatValue($newValue);
        } elseif ($after === null) {
            $changes[] = $label . ': ' . $formatValue($oldValue) . ' removed';
        } else {
            $changes[] = $label . ': ' . $formatValue($oldValue) . ' -> ' . $formatValue($newValue);
        }
    }

    return implode('; ', $changes);
}

function audit_log_change(
    mysqli $mysqli,
    string $action,
    string $entityType,
    $entityId = null,
    string $description = '',
    ?array $before = null,
    ?array $after = null,
    ?string $changeRecord = null
): void {
    try {
        audit_log_ensure_table($mysqli);
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $username = $_SESSION['username'] ?? null;
        $userType = $_SESSION['user_type'] ?? null;
        $entityIdValue = $entityId === null ? null : (string)$entityId;
        $changeRecord = $changeRecord ?? audit_log_format_change_record($before, $after);
        $beforeJson = $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES);
        $afterJson = $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

        $stmt = $mysqli->prepare(
            'INSERT INTO change_logs
             (user_id, username, user_type, action, entity_type, entity_id, description, change_record, before_data, after_data, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param(
            'issssssssss',
            $userId,
            $username,
            $userType,
            $action,
            $entityType,
            $entityIdValue,
            $description,
            $changeRecord,
            $beforeJson,
            $afterJson,
            $ipAddress
        );
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }
}
