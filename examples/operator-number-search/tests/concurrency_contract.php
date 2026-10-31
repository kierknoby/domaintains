<?php
declare(strict_types=1);

use Domaintains\NumberSearch\{AvailabilityAudit, AvailabilityCarrier, AvailabilityQueue, AvailabilityService};
require dirname(__DIR__) . '/src/Availability.php';

if (($argv[1] ?? '') === 'worker') {
    $directory = $argv[2]; $worker = (int)$argv[3]; $now = 2000000.0;
    $clock = static function () use (&$now): float { return $now; };
    $wait = static function (int $delay) use (&$now): void {
        echo "BLOCKED\n"; flush();
        if (trim((string)fgets(STDIN)) !== 'CONTINUE') { throw new RuntimeException('Missing parent synchronisation.'); }
        $now += 5.0;
    };
    $carrier = new class($worker, $clock) implements AvailabilityCarrier {
        public function __construct(private int $worker, private Closure $clock) {}
        public function available(string $range, int $size): array {
            echo 'ENTER ' . ($this->clock)() . "\n"; flush();
            if (trim((string)fgets(STDIN)) !== 'RELEASE') { throw new RuntimeException('Missing carrier synchronisation.'); }
            return ['http_code' => 200, 'body' => '["442041234567"]'];
        }
    };
    $service = new AvailabilityService($carrier, new AvailabilityQueue($directory . '/shared.lock', $clock, $wait, static fn(): bool => false),
        new AvailabilityAudit($directory), ['England' => ['London' => ['0204' => 'Fixture']]], ['https://portal.example'],
        static fn(array $session, array $request): array => ['client_id' => $worker + 1, 'product_id' => 20, 'context' => 'cart', 'selection_scope' => 'worker-' . $worker], $clock);
    $session = ['uid' => $worker + 1, 'domaintains_number_nonce' => str_repeat((string)($worker + 1), 48), 'domaintains_number_nonce_time' => (int)$now];
    [$status] = $service->handle($session, ['nonce' => $session['domaintains_number_nonce'], 'prefix' => '0204', 'action' => 'initial'],
        ['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://portal.example']);
    echo 'DONE ' . $status . "\n";
    exit($status === 200 ? 0 : 1);
}

function concurrent_assert(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
$directory = sys_get_temp_dir() . '/domaintains-concurrency-' . bin2hex(random_bytes(8));
$processes = []; $pipes = [];
try {
    for ($worker = 0; $worker < 2; $worker++) {
        $processes[$worker] = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', __FILE__, 'worker', $directory, (string)$worker],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$worker]);
        concurrent_assert(is_resource($processes[$worker]), 'worker could not start');
        stream_set_timeout($pipes[$worker][1], 10);
        if ($worker === 0) { concurrent_assert(trim((string)fgets($pipes[0][1])) === 'ENTER 2000000', 'first customer did not enter carrier'); }
        else { concurrent_assert(trim((string)fgets($pipes[1][1])) === 'BLOCKED', 'second customer overlapped the first carrier request'); }
    }
    fwrite($pipes[0][0], "RELEASE\n"); fflush($pipes[0][0]);
    concurrent_assert(trim((string)fgets($pipes[0][1])) === 'DONE 200', 'first customer failed');
    fwrite($pipes[1][0], "CONTINUE\n"); fflush($pipes[1][0]);
    concurrent_assert(trim((string)fgets($pipes[1][1])) === 'ENTER 2000005', 'shared five-second start spacing was not honoured');
    fwrite($pipes[1][0], "RELEASE\n"); fflush($pipes[1][0]);
    concurrent_assert(trim((string)fgets($pipes[1][1])) === 'DONE 200', 'second customer failed');
    foreach ($processes as $worker => $process) {
        $errors = stream_get_contents($pipes[$worker][2]);
        foreach ($pipes[$worker] as $pipe) { fclose($pipe); }
        concurrent_assert(proc_close($process) === 0, 'worker error: ' . $errors);
        unset($processes[$worker]);
    }
    $events = array_map(static fn(string $line): array => json_decode($line, true, 16, JSON_THROW_ON_ERROR), file(glob($directory . '/*.jsonl')[0], FILE_IGNORE_NEW_LINES));
    concurrent_assert(count($events) === 2 && $events[0]['client_id'] !== $events[1]['client_id'], 'concurrent audit attribution lost');
    concurrent_assert($events[1]['queue_ms'] === 5000 && $events[1]['carrier_contacted'] === 1, 'queue and carrier audit inaccurate');
    echo "Two independent PHP customers: real shared-lock exclusion, virtual-clock five-second spacing and concurrent audit contracts passed.\n";
} finally {
    foreach ($processes as $process) { if (is_resource($process)) { proc_terminate($process); proc_close($process); } }
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    if (is_dir($directory)) { rmdir($directory); }
}