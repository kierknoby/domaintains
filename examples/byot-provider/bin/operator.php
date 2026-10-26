<?php
declare(strict_types=1);

use Domaintains\Byot\Runtime;

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    if (PHP_SAPI !== 'cli') { throw new RuntimeException('Operator CLI only.'); }
    $action = $argv[1] ?? '';
    if (!in_array($action, ['order', 'customer-link', 'rotate', 'status', 'operation', 'set-status'], true)) {
        throw new RuntimeException('Actions: order|customer-link|rotate ACCOUNT PBX SERVICE VOICE[,VOICE]; status|operation SERVICE; set-status SERVICE STATUS');
    }
    $runtime = new Runtime(Runtime::loadConfiguration());
    $service = $argv[2] ?? '';
    if (in_array($action, ['order', 'customer-link', 'rotate'], true)) {
        if (count($argv) !== 6) { throw new RuntimeException('Supply ACCOUNT PBX SERVICE VOICE[,VOICE].'); }
        $result = $action === 'order'
            ? $runtime->order($argv[2], $argv[3], $argv[4], explode(',', $argv[5]), time())
            : $runtime->registry->link($argv[2], $argv[3], $argv[4], explode(',', $argv[5]), time(), $action === 'rotate');
        if ($result['token'] !== null) { fwrite(STDERR, "Deliver the one-time displayed token privately; do not retain terminal output.\n"); }
    } elseif ($action === 'set-status') {
        $runtime->registry->setStatus($service, $argv[3] ?? '');
        $result = $runtime->registry->status($service);
    } elseif ($action === 'operation') {
        $result = $runtime->store->read('operation-' . hash('sha256', $service));
    } else {
        $result = $runtime->registry->status($service);
    }
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Operator request failed. Check private configuration, service ownership/status and command arguments.\n");
    exit(1);
}