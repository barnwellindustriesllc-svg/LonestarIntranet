<?php

if (!function_exists('lonestar_2fa_ensure_schema')) {
    function lonestar_2fa_ensure_schema(mysqli $mysqli, ?string &$error = null): bool {
        try {
            $phoneColumn = $mysqli->query("SHOW COLUMNS FROM users LIKE 'mobile_phone'");
            $hasPhoneColumn = $phoneColumn && $phoneColumn->num_rows > 0;
            if ($phoneColumn) {
                $phoneColumn->free();
            }
            if (!$hasPhoneColumn) {
                $mysqli->query("ALTER TABLE users ADD COLUMN mobile_phone VARCHAR(32) NULL AFTER email");
            }

            $mysqli->query(
                "CREATE TABLE IF NOT EXISTS login_two_factor_challenges (
                  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                  user_id INT NOT NULL,
                  session_token_hash CHAR(64) NOT NULL,
                  code_hash VARCHAR(255) NOT NULL,
                  telnyx_verification_id VARCHAR(64) NULL,
                  phone_last4 VARCHAR(4) NOT NULL DEFAULT '',
                  expires_at DATETIME NOT NULL,
                  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                  sent_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
                  last_sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  consumed_at DATETIME NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_login_2fa_user_created (user_id, created_at),
                  KEY idx_login_2fa_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $telnyxColumn = $mysqli->query(
                "SHOW COLUMNS FROM login_two_factor_challenges LIKE 'telnyx_verification_id'"
            );
            $hasTelnyxColumn = $telnyxColumn && $telnyxColumn->num_rows > 0;
            if ($telnyxColumn) {
                $telnyxColumn->free();
            }
            if (!$hasTelnyxColumn) {
                $mysqli->query(
                    "ALTER TABLE login_two_factor_challenges
                     ADD COLUMN telnyx_verification_id VARCHAR(64) NULL AFTER code_hash"
                );
            }
            return true;
        } catch (Throwable $e) {
            error_log('Two-factor schema setup failed: ' . $e->getMessage());
            $error = 'Two-factor authentication is not configured yet.';
            return false;
        }
    }
}

if (!function_exists('lonestar_2fa_normalize_phone')) {
    function lonestar_2fa_normalize_phone(string $phone): string {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }
        $hasLeadingPlus = strpos($phone, '+') === 0;
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === null || $digits === '') {
            return '';
        }
        if (!$hasLeadingPlus && strlen($digits) === 10) {
            return '+1' . $digits;
        }
        if (!$hasLeadingPlus && strlen($digits) === 11 && $digits[0] === '1') {
            return '+' . $digits;
        }
        if ($hasLeadingPlus && strlen($digits) >= 8 && strlen($digits) <= 15) {
            return '+' . $digits;
        }
        return '';
    }
}

if (!function_exists('lonestar_2fa_mask_phone')) {
    function lonestar_2fa_mask_phone(string $phone): string {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $last4 = substr($digits, -4);
        return $last4 === '' ? 'your mobile phone' : 'the phone ending in ' . $last4;
    }
}

if (!function_exists('lonestar_2fa_csrf_token')) {
    function lonestar_2fa_csrf_token(): string {
        if (empty($_SESSION['login_2fa_csrf'])) {
            $_SESSION['login_2fa_csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['login_2fa_csrf'];
    }
}

if (!function_exists('lonestar_2fa_telnyx_request')) {
    function lonestar_2fa_telnyx_request(
        string $path,
        array $payload,
        ?int &$httpCode = null,
        ?string &$requestError = null
    ): ?array {
        $apiKey = defined('LONESTAR_TELNYX_API_KEY') ? LONESTAR_TELNYX_API_KEY : '';
        $profileId = defined('LONESTAR_TELNYX_VERIFY_PROFILE_ID')
            ? LONESTAR_TELNYX_VERIFY_PROFILE_ID
            : '';
        if ($apiKey === '' || $profileId === '') {
            $requestError = 'missing Telnyx API key or Verify Profile ID';
            return null;
        }
        if (!function_exists('curl_init')) {
            $requestError = 'PHP cURL extension is unavailable';
            return null;
        }

        $json = json_encode($payload);
        if ($json === false) {
            $requestError = 'request payload could not be encoded';
            return null;
        }

        $url = 'https://api.telnyx.com/v2/' . ltrim($path, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $requestError = $curlError !== '' ? $curlError : 'Telnyx returned HTTP ' . $httpCode;
            return null;
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            $requestError = 'Telnyx returned an invalid JSON response';
            return null;
        }
        return $decoded;
    }
}

if (!function_exists('lonestar_2fa_send_sms')) {
    function lonestar_2fa_send_sms(string $phone, ?string &$error = null): ?string {
        $profileId = defined('LONESTAR_TELNYX_VERIFY_PROFILE_ID')
            ? LONESTAR_TELNYX_VERIFY_PROFILE_ID
            : '';
        $httpCode = 0;
        $requestError = '';
        $response = lonestar_2fa_telnyx_request(
            'verifications/sms',
            [
                'phone_number' => $phone,
                'verify_profile_id' => $profileId,
            ],
            $httpCode,
            $requestError
        );
        $verificationId = is_array($response) ? trim((string)($response['data']['id'] ?? '')) : '';
        if ($verificationId === '') {
            $error = $requestError === 'missing Telnyx API key or Verify Profile ID'
                ? 'SMS delivery is not configured.'
                : 'The verification text could not be sent. Please try again.';
            error_log('Login 2FA Telnyx send failed: ' . ($requestError !== '' ? $requestError : 'missing verification ID'));
            return null;
        }
        return $verificationId;
    }
}

if (!function_exists('lonestar_2fa_recent_send_count')) {
    function lonestar_2fa_recent_send_count(mysqli $mysqli, int $userId): int {
        $stmt = $mysqli->prepare(
            "SELECT COALESCE(SUM(sent_count),0)
               FROM login_two_factor_challenges
              WHERE user_id=?
                AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        if (!$stmt) {
            return 99;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($count);
        $total = $stmt->fetch() ? (int)$count : 99;
        $stmt->close();
        return $total;
    }
}

if (!function_exists('lonestar_2fa_create_challenge')) {
    function lonestar_2fa_create_challenge(
        mysqli $mysqli,
        int $userId,
        string $phone,
        ?string &$error = null
    ): ?array {
        if (lonestar_2fa_recent_send_count($mysqli, $userId) >= 5) {
            $error = 'Too many verification texts were requested. Try again in 15 minutes.';
            return null;
        }

        $verificationId = lonestar_2fa_send_sms($phone, $error);
        if ($verificationId === null) {
            return null;
        }

        $sessionToken = bin2hex(random_bytes(32));
        $sessionTokenHash = hash('sha256', $sessionToken);
        // Retained for compatibility with the original schema. Telnyx now
        // generates and validates the OTP, so no code is stored locally.
        $codeHash = '';
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $last4 = substr($digits, -4);
        $expiresAt = date('Y-m-d H:i:s', time() + 600);
        $stmt = $mysqli->prepare(
            "INSERT INTO login_two_factor_challenges
                (user_id, session_token_hash, code_hash, telnyx_verification_id,
                 phone_last4, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            $error = 'The verification request could not be created.';
            return null;
        }
        $stmt->bind_param(
            'isssss',
            $userId,
            $sessionTokenHash,
            $codeHash,
            $verificationId,
            $last4,
            $expiresAt
        );
        if (!$stmt->execute()) {
            $stmt->close();
            $error = 'The verification request could not be created.';
            return null;
        }
        $challengeId = (int)$stmt->insert_id;
        $stmt->close();
        return [
            'challenge_id' => $challengeId,
            'user_id' => $userId,
            'session_token' => $sessionToken,
            'phone' => $phone,
        ];
    }
}

if (!function_exists('lonestar_2fa_load_challenge')) {
    function lonestar_2fa_load_challenge(mysqli $mysqli, array $pending): ?array {
        $challengeId = (int)($pending['challenge_id'] ?? 0);
        $userId = (int)($pending['user_id'] ?? 0);
        $sessionToken = (string)($pending['session_token'] ?? '');
        if ($challengeId <= 0 || $userId <= 0 || $sessionToken === '') {
            return null;
        }
        $stmt = $mysqli->prepare(
            "SELECT session_token_hash, code_hash, telnyx_verification_id, expires_at, attempts,
                    sent_count, last_sent_at, consumed_at
               FROM login_two_factor_challenges
              WHERE id=? AND user_id=?
              LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ii', $challengeId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$row || !hash_equals((string)$row['session_token_hash'], hash('sha256', $sessionToken))) {
            return null;
        }
        return $row;
    }
}

if (!function_exists('lonestar_2fa_verify_challenge')) {
    function lonestar_2fa_verify_challenge(
        mysqli $mysqli,
        array $pending,
        string $code,
        ?string &$error = null
    ): bool {
        $challenge = lonestar_2fa_load_challenge($mysqli, $pending);
        $challengeId = (int)($pending['challenge_id'] ?? 0);
        if (!$challenge || $challenge['consumed_at'] !== null) {
            $error = 'This verification request is no longer valid. Sign in again.';
            return false;
        }
        if (strtotime((string)$challenge['expires_at']) < time()) {
            $error = 'The verification code expired. Request a new code.';
            return false;
        }
        if ((int)$challenge['attempts'] >= 5) {
            $error = 'Too many incorrect attempts. Sign in again.';
            return false;
        }
        if (!preg_match('/^\d{6}$/', $code)) {
            $stmt = $mysqli->prepare(
                'UPDATE login_two_factor_challenges SET attempts=attempts+1 WHERE id=?'
            );
            if ($stmt) {
                $stmt->bind_param('i', $challengeId);
                $stmt->execute();
                $stmt->close();
            }
            $error = 'The verification code is incorrect.';
            return false;
        }

        $verificationId = trim((string)($challenge['telnyx_verification_id'] ?? ''));
        if ($verificationId === '') {
            $error = 'This verification request is no longer valid. Sign in again.';
            return false;
        }
        $httpCode = 0;
        $requestError = '';
        $response = lonestar_2fa_telnyx_request(
            'verifications/' . rawurlencode($verificationId) . '/actions/verify',
            ['code' => $code],
            $httpCode,
            $requestError
        );
        $accepted = is_array($response)
            && strtolower((string)($response['data']['response_code'] ?? '')) === 'accepted';
        if (!$accepted) {
            $stmt = $mysqli->prepare(
                'UPDATE login_two_factor_challenges SET attempts=attempts+1 WHERE id=?'
            );
            if ($stmt) {
                $stmt->bind_param('i', $challengeId);
                $stmt->execute();
                $stmt->close();
            }
            if ($requestError === 'missing Telnyx API key or Verify Profile ID') {
                $error = 'SMS verification is not configured.';
            } elseif ($httpCode >= 500 || $httpCode === 0) {
                $error = 'SMS verification is temporarily unavailable. Please try again.';
            } else {
                $error = 'The verification code is incorrect or expired.';
            }
            error_log('Login 2FA Telnyx verify failed: ' . ($requestError !== '' ? $requestError : 'code was not accepted'));
            return false;
        }
        $stmt = $mysqli->prepare(
            'UPDATE login_two_factor_challenges SET consumed_at=NOW() WHERE id=? AND consumed_at IS NULL'
        );
        if (!$stmt) {
            $error = 'The verification request could not be completed.';
            return false;
        }
        $stmt->bind_param('i', $challengeId);
        $stmt->execute();
        $completed = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$completed) {
            $error = 'This verification code was already used.';
        }
        return $completed;
    }
}

if (!function_exists('lonestar_2fa_resend_challenge')) {
    function lonestar_2fa_resend_challenge(
        mysqli $mysqli,
        array $pending,
        ?string &$error = null
    ): bool {
        $challenge = lonestar_2fa_load_challenge($mysqli, $pending);
        $challengeId = (int)($pending['challenge_id'] ?? 0);
        $userId = (int)($pending['user_id'] ?? 0);
        $phone = (string)($pending['phone'] ?? '');
        if (!$challenge || $challenge['consumed_at'] !== null || $phone === '') {
            $error = 'This verification request is no longer valid. Sign in again.';
            return false;
        }
        if (strtotime((string)$challenge['last_sent_at']) > time() - 60) {
            $error = 'Please wait one minute before requesting another code.';
            return false;
        }
        if ((int)$challenge['sent_count'] >= 5 || lonestar_2fa_recent_send_count($mysqli, $userId) >= 5) {
            $error = 'Too many verification texts were requested. Try again in 15 minutes.';
            return false;
        }

        $verificationId = lonestar_2fa_send_sms($phone, $error);
        if ($verificationId === null) {
            return false;
        }
        $expiresAt = date('Y-m-d H:i:s', time() + 600);
        $stmt = $mysqli->prepare(
            "UPDATE login_two_factor_challenges
                SET telnyx_verification_id=?, expires_at=?, attempts=0,
                    sent_count=sent_count+1, last_sent_at=NOW()
              WHERE id=? AND consumed_at IS NULL"
        );
        if (!$stmt) {
            $error = 'The new verification code could not be saved.';
            return false;
        }
        $stmt->bind_param('ssi', $verificationId, $expiresAt, $challengeId);
        $stmt->execute();
        $updated = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$updated) {
            $error = 'This verification request is no longer valid. Sign in again.';
        }
        return $updated;
    }
}
