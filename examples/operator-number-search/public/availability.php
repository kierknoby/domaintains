<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Runtime.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header_remove('X-Powered-By');
try {
    $config = \Domaintains\NumberSearch\Runtime::configuration();
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
        $status = 413; $body = ['ok' => false, 'error' => 'Availability request too large'];
    } else {
        [$status, $body] = \Domaintains\NumberSearch\Runtime::service($config)->handle($_SESSION, $_POST, $_SERVER);
    }
} catch (\Throwable $error) {
    error_log('DOMAINTAINS availability: private integration unavailable');
    $status = 503; $body = ['ok' => false, 'error' => 'Availability temporarily unavailable'];
}
http_response_code($status);
if ($status === 429 || $status === 503) { header('Retry-After: ' . ($status === 429 ? '60' : '5')); }
if ($status === 405) { header('Allow: POST'); }
echo json_encode($body, JSON_UNESCAPED_SLASHES);