<?php
declare(strict_types=1);

use Domaintains\Byot\EmergencyApplication;

require dirname(__DIR__) . '/src/bootstrap.php';

EmergencyApplication::headers();
header('Content-Type: application/json; charset=utf-8');

try {
    EmergencyApplication::startSession();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        echo '{"ok":false,"message":"POST required."}';
        exit;
    }
    if (!preg_match('/^application\/json(?:\s*;.*)?$/iD', $_SERVER['CONTENT_TYPE'] ?? '') || (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
        http_response_code(400);
        echo '{"ok":false,"message":"Invalid emergency request."}';
        exit;
    }
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if (!is_string($raw) || strlen($raw) > 16384) { throw new RuntimeException('Invalid request.'); }
    $request = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
    if (!is_array($request)) { throw new RuntimeException('Invalid request.'); }
    $application = EmergencyApplication::load();
    [$status, $response] = $application->controller->handle($application->authentication->current(), $_SESSION['emergency_csrf'] ?? '', $request);
    http_response_code($status);
    if (isset($response['retry_after']) && $response['retry_after'] > 0) { header('Retry-After: ' . $response['retry_after']); }
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(503);
    echo '{"ok":false,"message":"Emergency information is unavailable. Please retry."}';
}