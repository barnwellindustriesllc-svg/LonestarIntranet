<?php
// App environment configuration
// Set LONESTAR_APP_ENV=UAT on the UAT server to reuse this same code in UAT and PROD.
$appEnv = strtoupper(trim(getenv('LONESTAR_APP_ENV') ?: 'PROD'));
$isUatEnv = $appEnv === 'UAT';

if (!$isUatEnv) {
    $uatDetectionText = strtolower(implode(' ', array_filter([
        $_SERVER['HTTP_HOST'] ?? '',
        $_SERVER['REQUEST_URI'] ?? '',
        $_SERVER['SCRIPT_NAME'] ?? '',
        $_SERVER['DOCUMENT_ROOT'] ?? '',
        __DIR__,
    ])));
    $isUatEnv = strpos($uatDetectionText, 'uat') !== false;
}

if (!defined('LONESTAR_APP_ENV')) {
    define('LONESTAR_APP_ENV', $isUatEnv ? 'UAT' : 'PROD');
}
if (!defined('LONESTAR_IS_UAT')) {
    define('LONESTAR_IS_UAT', LONESTAR_APP_ENV === 'UAT');
}
if (!defined('LONESTAR_ENV_LABEL')) {
    define('LONESTAR_ENV_LABEL', LONESTAR_IS_UAT ? 'UAT' : '');
}

// Disabled by default. Set LONESTAR_2FA_ENABLED=1 only after the active
// environment's Telnyx API key and Verify Profile ID have been configured.
if (!defined('LONESTAR_2FA_ENABLED')) {
    define('LONESTAR_2FA_ENABLED', filter_var(getenv('LONESTAR_2FA_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN));
}

if (LONESTAR_IS_UAT) {
    $host     = getenv('LONESTAR_DB_HOST_UAT') ?: '127.0.0.1';
    $dbname   = getenv('LONESTAR_DB_NAME_UAT') ?: 'lonestar_uat';
    $username = getenv('LONESTAR_DB_USER_UAT') ?: 'lonestar_uat_user';
    $password = getenv('LONESTAR_DB_PASSWORD_UAT') ?: '';
} else {
    $host     = getenv('LONESTAR_DB_HOST_PROD') ?: '127.0.0.1';
    $dbname   = getenv('LONESTAR_DB_NAME_PROD') ?: 'lonestar_prod';
    $username = getenv('LONESTAR_DB_USER_PROD') ?: 'lonestar_prod_user';
    $password = getenv('LONESTAR_DB_PASSWORD_PROD') ?: '';
}

// Create MySQLi connection
$mysqli = new mysqli($host, $username, $password, $dbname);
if ($mysqli->connect_errno) {
    die('Connect failed: ' . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');

// Mapbox configuration. Server environment variables can override these defaults.
$mapboxPublicToken = getenv('MAPBOX_PUBLIC_TOKEN') ?: '';
$mapboxStyle = getenv('MAPBOX_STYLE') ?: 'mapbox://styles/mapbox/streets-v12';

// Mercury Bank API configuration
// Prefer setting MERCURY_API_TOKEN on the server so the secret is not stored in source.
$mercuryApiBaseUrl = getenv('MERCURY_API_BASE_URL') ?: 'https://api.mercury.com/api/v1';
$mercuryApiToken = getenv('MERCURY_API_TOKEN') ?: '';

// Twilio SMS configuration. Server environment variables take precedence.
if (!defined('LONESTAR_TWILIO_ACCOUNT_SID')) {
    define('LONESTAR_TWILIO_ACCOUNT_SID', getenv('TWILIO_ACCOUNT_SID') ?: '');
}
if (!defined('LONESTAR_TWILIO_AUTH_TOKEN')) {
    define('LONESTAR_TWILIO_AUTH_TOKEN', getenv('TWILIO_AUTH_TOKEN') ?: '');
}
if (!defined('LONESTAR_TWILIO_FROM_NUMBER')) {
    define('LONESTAR_TWILIO_FROM_NUMBER', getenv('TWILIO_FROM_NUMBER') ?: '');
}

// Telnyx Verify configuration. Keep UAT and production credentials/profile
// IDs separate; the active values follow the environment already detected at
// the top of this file.
$telnyxApiKeyEnv = LONESTAR_IS_UAT ? 'TELNYX_API_KEY_UAT' : 'TELNYX_API_KEY_PROD';
$telnyxProfileEnv = LONESTAR_IS_UAT ? 'TELNYX_VERIFY_PROFILE_ID_UAT' : 'TELNYX_VERIFY_PROFILE_ID_PROD';
if (!defined('LONESTAR_TELNYX_API_KEY')) {
    define('LONESTAR_TELNYX_API_KEY', trim((string)(getenv($telnyxApiKeyEnv) ?: '')));
}
if (!defined('LONESTAR_TELNYX_VERIFY_PROFILE_ID')) {
    define('LONESTAR_TELNYX_VERIFY_PROFILE_ID', trim((string)(getenv($telnyxProfileEnv) ?: '')));
}
if (!defined('LONESTAR_TELNYX_VERIFY_PROFILE_NAME')) {
    define(
        'LONESTAR_TELNYX_VERIFY_PROFILE_NAME',
        LONESTAR_IS_UAT ? 'LoneStar Roadside Login - UAT' : 'LoneStar Roadside Login - Prod'
    );
}
if (!defined('LONESTAR_TELNYX_VERIFY_WEBHOOK_URL')) {
    define(
        'LONESTAR_TELNYX_VERIFY_WEBHOOK_URL',
        LONESTAR_IS_UAT
            ? 'https://chalweb.com/uat/lonestar/telnyx_verify_webhook.php'
            : 'https://lonestarroadsidetx.com/telnyx_verify_webhook.php'
    );
}

?>
