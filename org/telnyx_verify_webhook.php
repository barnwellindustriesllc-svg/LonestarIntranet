<?php
// Telnyx Verify requires a public webhook URL on the Verify Profile. Login
// verification itself is completed through direct API calls; this endpoint
// only acknowledges asynchronous Telnyx events.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET' || $method === 'HEAD') {
    http_response_code(200);
    if ($method !== 'HEAD') {
        echo json_encode(['status' => 'ok']);
    }
    exit;
}

if ($method !== 'POST') {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Read the request so PHP fully consumes the webhook body. No event data is
// persisted because Telnyx remains the source of truth for Verify status.
file_get_contents('php://input');
http_response_code(204);
