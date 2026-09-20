<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/config.php';

$vendor = $_GET['vendor'] ?? '';
$path = $_GET['path'] ?? '';
$vendorMap = [
    'sanddrive' => [
        'name' => 'Sand Drive',
        'base' => 'https://sanddrive.tssands.com',
        'start' => '/analytics'
    ],
    'rtswex' => [
        'name' => 'RTS/WEX',
        'base' => 'https://emgr.efsllc.com',
        'start' => '/'
    ]
];

if (!isset($vendorMap[$vendor])) {
    http_response_code(404);
    echo 'Unknown vendor';
    exit;
}

$vendorInfo = $vendorMap[$vendor];
$targetPath = trim(urldecode($path));
$targetUrl = $targetPath !== '' ? resolveTargetUrl($vendorInfo['base'], $targetPath) : $vendorInfo['base'] . $vendorInfo['start'];

$targetHost = parse_url($targetUrl, PHP_URL_HOST);
$vendorHost = parse_url($vendorInfo['base'], PHP_URL_HOST);
if ($targetHost === null || $targetHost === false || strcasecmp($targetHost, $vendorHost) !== 0) {
    http_response_code(400);
    echo 'Invalid target URL';
    exit;
}

$currentVendorUrl = $targetUrl;

$cookieFile = sys_get_temp_dir() . '/vendor_proxy_' . session_id() . '_' . preg_replace('/[^a-z0-9_-]+/i', '_', $vendor) . '.cookies.txt';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestBody = file_get_contents('php://input');
$requestHeaders = buildRequestHeaders();

$curlOptions = [
    CURLOPT_URL => $targetUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 20,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_USERAGENT => $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    CURLOPT_ENCODING => '',
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 45,
];

if ($method === 'POST') {
    $curlOptions[CURLOPT_POST] = true;
    $curlOptions[CURLOPT_POSTFIELDS] = $requestBody;
} elseif ($method !== 'GET') {
    $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
    if ($requestBody !== false && $requestBody !== '') {
        $curlOptions[CURLOPT_POSTFIELDS] = $requestBody;
    }
}

if (!empty($requestHeaders)) {
    $curlOptions[CURLOPT_HTTPHEADER] = $requestHeaders;
}

$ch = curl_init();
curl_setopt_array($ch, $curlOptions);

$response = curl_exec($ch);
if ($response === false) {
    http_response_code(500);
    echo 'Client resource proxy error: ' . curl_error($ch);
    curl_close($ch);
    exit;
}

$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerText = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);
curl_close($ch);

$headers = parseHeaders($headerText);
$contentType = $headers['content-type'] ?? 'application/octet-stream';
$actualType = strtolower(explode(';', $contentType, 2)[0]);

http_response_code($httpCode);
$allowedHeaders = ['content-type', 'cache-control', 'expires', 'pragma', 'last-modified', 'content-language'];
foreach ($allowedHeaders as $name) {
    if (isset($headers[$name])) {
        header($name . ': ' . $headers[$name]);
    }
}

if (strpos($actualType, 'text/html') !== false) {
    $body = rewriteHtml($body, $vendorInfo, $vendor, $currentVendorUrl);
}

echo $body;
exit;

function resolveTargetUrl(string $base, string $path): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    if (strpos($path, '//') === 0) {
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $path;
    }

    if ($path === '') {
        return rtrim($base, '/') . '/';
    }

    if ($path[0] !== '/') {
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    return rtrim($base, '/') . $path;
}

function parseHeaders(string $headerText): array
{
    $headers = [];
    $blocks = preg_split('/\r?\n\r?\n/', trim($headerText));
    $lastBlock = end($blocks);
    $lines = preg_split('/\r?\n/', $lastBlock);
    foreach ($lines as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }
    return $headers;
}

function rewriteHtml(string $html, array $vendorInfo, string $vendor, string $currentVendorUrl): string
{
    $proxyBase = basename($_SERVER['PHP_SELF']) . '?vendor=' . urlencode($vendor) . '&path=';
    $currentProxyAction = $proxyBase . urlencode($currentVendorUrl);

    $html = preg_replace_callback('/<form\b([^>]*)>/i', function ($matches) use ($currentProxyAction) {
        $attrs = $matches[1];
        if (preg_match('/\baction\s*=\s*(["\'])(.*?)\1/i', $attrs, $actionMatch)) {
            $actionValue = trim($actionMatch[2]);
            if ($actionValue === '' || $actionValue === '#') {
                $attrs = preg_replace('/\baction\s*=\s*(["\'])(.*?)\1/i', 'action="' . $currentProxyAction . '"', $attrs, 1);
            }
        } else {
            $attrs = ' action="' . $currentProxyAction . '"' . $attrs;
        }
        return '<form' . $attrs . '>';
    }, $html);

    $html = preg_replace_callback('/\b(href|src|action)=(["\'])(.*?)\2/i', function ($matches) use ($vendorInfo, $proxyBase, $currentVendorUrl) {
        $attrName = $matches[1];
        $quote = $matches[2];
        $url = $matches[3];
        $proxyUrl = rewriteUrl($url, $vendorInfo, $proxyBase, $currentVendorUrl);
        return $proxyUrl ? $attrName . '=' . $quote . $proxyUrl . $quote : $matches[0];
    }, $html);

    $html = preg_replace_callback('/\bsrcset=(["\'])(.*?)\1/i', function ($matches) use ($vendorInfo, $proxyBase) {
        $parts = array_map('trim', explode(',', $matches[2]));
        $rewritten = array_map(function ($part) use ($vendorInfo, $proxyBase) {
            if (preg_match('/^\s*(\S+)(\s+.*)?$/', $part, $sub)) {
                $url = $sub[1];
                $suffix = $sub[2] ?? '';
                $proxy = rewriteUrl($url, $vendorInfo, $proxyBase);
                return $proxy ? $proxy . $suffix : $part;
            }
            return $part;
        }, $parts);
        return 'srcset=' . $matches[1] . implode(', ', $rewritten) . $matches[1];
    }, $html);

    $html = preg_replace_callback('/url\(\s*(["\']?)(\/(?:[^\)"\']+))\1\s*\)/i', function ($matches) use ($vendorInfo, $proxyBase) {
        $proxyUrl = rewriteUrl($matches[2], $vendorInfo, $proxyBase);
        return $proxyUrl ? 'url(' . $matches[1] . $proxyUrl . $matches[1] . ')' : $matches[0];
    }, $html);

    return $html;
}

function rewriteUrl(string $url, array $vendorInfo, string $proxyBase, string $currentVendorUrl): ?string
{
    $trimmed = trim($url);
    if ($trimmed === '' || preg_match('#^(?:mailto:|tel:|javascript:|data:|#)#i', $trimmed)) {
        return null;
    }

    if ($trimmed[0] === '#') {
        return null;
    }

    if (strpos($trimmed, '//') === 0) {
        $scheme = parse_url($vendorInfo['base'], PHP_URL_SCHEME) ?: 'https';
        $trimmed = $scheme . ':' . $trimmed;
    }

    if (preg_match('#^https?://#i', $trimmed)) {
        if (!isVendorUrl($trimmed, $vendorInfo)) {
            return null;
        }
        $absoluteUrl = $trimmed;
    } elseif ($trimmed[0] === '/') {
        $absoluteUrl = resolveTargetUrl($vendorInfo['base'], $trimmed);
    } elseif ($trimmed[0] === '?') {
        $absoluteUrl = resolveTargetUrl($currentVendorUrl, $trimmed);
    } else {
        $absoluteUrl = resolveRelativeUrl($currentVendorUrl, $trimmed);
    }

    $parsed = parse_url($absoluteUrl);
    if ($parsed === false) {
        return null;
    }

    if (!isVendorUrl($absoluteUrl, $vendorInfo)) {
        return null;
    }

    $path = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
    return $proxyBase . urlencode($path);
}

function isVendorUrl(string $url, array $vendorInfo): bool
{
    $host = parse_url($url, PHP_URL_HOST);
    if ($host === false || $host === null) {
        return true;
    }
    return strcasecmp($host, parse_url($vendorInfo['base'], PHP_URL_HOST)) === 0;
}

function resolveRelativeUrl(string $baseUrl, string $relative): string
{
    $baseParts = parse_url($baseUrl);
    if ($baseParts === false) {
        return $relative;
    }

    $scheme = $baseParts['scheme'] ?? 'https';
    $host = $baseParts['host'] ?? parse_url($relative, PHP_URL_HOST);
    $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
    $path = $baseParts['path'] ?? '/';

    if ($relative[0] === '?') {
        return $scheme . '://' . $host . $port . ($path !== '' ? $path : '/') . $relative;
    }

    $baseDir = preg_replace('#/[^/]*$#', '/', $path);
    $combined = $baseDir . $relative;
    $normalized = preg_replace('#(/\./)|(/[^/]+/\.\./)#', '/', $combined);
    $segments = explode('/', $normalized);
    $resolved = [];
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($resolved);
            continue;
        }
        $resolved[] = $segment;
    }
    $resolvedPath = '/' . implode('/', $resolved);
    return $scheme . '://' . $host . $port . $resolvedPath;
}

function buildRequestHeaders(): array
{
    $headers = [];
    $allowed = [
        'HTTP_ACCEPT' => 'Accept',
        'CONTENT_TYPE' => 'Content-Type',
        'CONTENT_LENGTH' => 'Content-Length',
        'HTTP_ACCEPT_LANGUAGE' => 'Accept-Language',
        'HTTP_ACCEPT_ENCODING' => 'Accept-Encoding',
        'HTTP_REFERER' => 'Referer',
        'HTTP_ORIGIN' => 'Origin',
        'HTTP_USER_AGENT' => 'User-Agent',
        'HTTP_X_REQUESTED_WITH' => 'X-Requested-With',
    ];

    foreach ($allowed as $serverKey => $headerName) {
        if (!empty($_SERVER[$serverKey])) {
            $headers[] = $headerName . ': ' . $_SERVER[$serverKey];
        }
    }
    return $headers;
}
