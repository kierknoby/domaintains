<?php
declare(strict_types=1);

define('BYOT_FIXTURES_ONLY', true);
require __DIR__ . '/orchestration_contract.php';

if (($argv[1] ?? '') === '--worker') {
    $input = json_decode(stream_get_contents(STDIN, 16384), true, 32, JSON_THROW_ON_ERROR);
    [$provider] = byot_runtime($input['path'], 'pbx-test');
    echo json_encode(['status' => $provider->process($input['request'], 1000)[0]]) . "\n";
    exit;
}

function byot_parallel(string $path, array $requests): array
{
    $workers = [];
    foreach ($requests as $request) {
        $process = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'extension=sodium', __FILE__, '--worker'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        byot_assert(is_resource($process), 'start isolated worker');
        fwrite($pipes[0], json_encode(['path' => $path, 'request' => $request], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $statuses = [];
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        byot_assert(proc_close($process) === 0, 'worker completes: ' . $error);
        $statuses[] = json_decode($output, true, 8, JSON_THROW_ON_ERROR)['status'];
    }
    sort($statuses);
    return $statuses;
}

foreach (['same-key', 'different-key', 'same-nonce'] as $case) {
    $path = sys_get_temp_dir() . '/domaintains-concurrency-' . bin2hex(random_bytes(8));
    try {
        [$provider, $registry, $store] = byot_runtime($path, 'pbx-test');
        $credential = $registry->link('account-01', 'pbx-01', 'pbx-test', ['voice-01', 'voice-02'], 1000);
        $pair = sodium_crypto_sign_keypair();
        $first = byot_request('pbx-test', $credential['token'], $pair);
        $second = $case === 'same-nonce' ? $first : byot_request('pbx-test', $credential['token'], $case === 'same-key' ? $pair : sodium_crypto_sign_keypair());
        $expected = $case === 'same-key' ? [200, 200] : ($case === 'same-nonce' ? [200, 409] : [200, 403]);
        byot_assert(byot_parallel($path, [$first, $second]) === $expected, 'atomic concurrent ' . $case);
        foreach (['inbound', 'outbound'] as $role) {
            $edge = $store->read('simulated-edge-' . $role);
            byot_assert($edge['applies']['pbx-test'] === 1, 'concurrent repeats apply only changed ' . $role . ' edge');
        }
    } finally { if (is_dir($path)) { byot_clean($path); } }
}
echo "Cross-process activation and key-binding contracts passed.\n";