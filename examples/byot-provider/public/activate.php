<?php
declare(strict_types=1);

use Domaintains\Byot\Runtime;

require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'message' => 'POST required.']);
}
if (!preg_match('/^application\/json(?:\s*;.*)?$/iD', $_SERVER['CONTENT_TYPE'] ?? '')) {
    respond(415, ['ok' => false, 'message' => 'JSON content type required.']);
}
// Do not trust arbitrary X-Forwarded-Proto headers from internet clients.
// If behind a trusted reverse proxy, configure the web server to set HTTPS=on.
if (($_SERVER['HTTPS'] ?? '') !== 'on' && ($_SERVER['HTTPS'] ?? '') !== '1') {
    respond(403, ['ok' => false, 'message' => 'HTTPS required.']);
}
$size = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($size > 16384) {
    respond(413, ['ok' => false, 'message' => 'Activation request too large.']);
}
$raw = file_get_contents('php://input', false, null, 0, 16385);
if (!is_string($raw) || $raw === '' || strlen($raw) > 16384) {
    respond(400, ['ok' => false, 'message' => 'Invalid activation request.']);
}
$request = json_decode($raw, true);
if (!is_array($request)) {
    respond(400, ['ok' => false, 'message' => 'Invalid activation request.']);
}
try {
    $runtime = new Runtime(Runtime::loadConfiguration());
    [$status, $response] = $runtime->provider->process($request);
    respond($status, $response);
} catch (Throwable $error) {
    respond(503, ['ok' => false, 'message' => 'Provider unavailable.']);
}
