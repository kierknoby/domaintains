<?php
declare(strict_types=1);

use Domaintains\Byot\{ActivationProvider, ConfiguredEndpointResolver, LocalBilling, LocalRegistry, Orchestrator, ReplayStore, SimulatedDns, SimulatedEdge, SimulatedNumbers, StateStore};

require_once __DIR__ . '/local_contract.php';
require_once __DIR__ . '/../src/ReplayStore.php';
require_once __DIR__ . '/../src/Simulation.php';
require_once __DIR__ . '/../src/Orchestrator.php';

function byot_policies(): array
{
    return ['inbound' => ['auth' => 'ip', 'registration' => 'none', 'context' => 'from-pbx',
        'delivery' => ['sip_host' => 'inbound.example', 'sip_port' => 5060, 'transport' => 'udp']],
        'outbound' => ['auth' => 'ip', 'registration' => 'none', 'context' => 'from-pbx',
            'carrier' => ['id' => 'carrier-01', 'sip_host' => 'carrier.example', 'sip_port' => 5060, 'transport' => 'udp'],
            'patterns' => ['12025550XXX']]];
}

function byot_runtime(string $path, string $service, array $failures = [], ?array $billing = null): array
{
    $store = new StateStore($path);
    $registry = new LocalRegistry($store, new LocalBilling($billing ?? byot_fixture($service)), [$service => byot_plan()]);
    $orchestrator = new Orchestrator($store, $registry, new SimulatedNumbers($store, $failures),
        new ConfiguredEndpointResolver([$service => ['account' => 'account-01', 'pbx' => 'pbx-01', 'sip_host' => 'pbx.example', 'sip_port' => 5060, 'transport' => 'udp']], $failures),
        new SimulatedEdge($store, 'inbound', $failures), new SimulatedEdge($store, 'outbound', $failures),
        [$service => byot_policies()], new SimulatedDns($store, $failures));
    return [new ActivationProvider(['max_request_age' => 300], new ReplayStore($path), $registry, $orchestrator), $registry, $store];
}

function byot_request(string $service, string $token, string $pair, int $now = 1000): array
{
    $claim = ['token' => $token, 'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
        'timestamp' => $now, 'nonce' => base64_encode(random_bytes(24)), 'service' => $service];
    return ['action' => 'activate', 'claim' => $claim, 'signature' => base64_encode(sodium_crypto_sign_detached(json_encode($claim, JSON_UNESCAPED_SLASHES), sodium_crypto_sign_secretkey($pair)))];
}

if (defined('BYOT_FIXTURES_ONLY')) { return; }

$path = sys_get_temp_dir() . '/domaintains-orchestration-' . bin2hex(random_bytes(8));
try {
    [$provider, $registry, $store] = byot_runtime($path, 'pbx-test');
    $credential = $registry->link('account-01', 'pbx-01', 'pbx-test', ['voice-01', 'voice-02'], 1000);
    $pair = sodium_crypto_sign_keypair();
    $request = byot_request('pbx-test', $credential['token'], $pair);
    [$status, $response] = $provider->process($request, 1000);
    byot_assert($status === 200 && $response['max_channels'] === 5, 'all prerequisites must precede success');
    byot_assert($provider->process($request, 1000)[0] === 409, 'replay rejected through complete path');
    $fabric = [];
    foreach (['simulated-edge-inbound', 'simulated-edge-outbound', 'simulated-numbers', 'simulated-dns'] as $key) { $fabric[$key] = $store->read($key); }
    byot_assert($provider->process(byot_request('pbx-test', $credential['token'], $pair), 1000)[0] === 200, 'repeat activation verifies idempotently');
    foreach ($fabric as $key => $value) { byot_assert($store->read($key) === $value, 'repeat must not mutate ' . $key); }
    byot_assert($provider->process(byot_request('pbx-test', $credential['token'], sodium_crypto_sign_keypair()), 1000)[0] === 403, 'wrong key fails before adapter changes');
    $registry->link('account-01', 'pbx-01', 'pbx-test', ['voice-01'], 1001);
    byot_assert($provider->process(byot_request('pbx-test', $credential['token'], $pair, 1001), 1001)[0] === 200, 'entitlement changes reconcile');
    byot_assert(!isset($store->read('simulated-edge-inbound')['objects']['did:12025550102']), 'owned stale DID removed');
    byot_assert($store->read('simulated-numbers')['numbers']['n12025550102']['destination'] === null, 'removed number delivery cleared, allocation retained');
    byot_assert($store->read('operation-' . hash('sha256', 'pbx-test'))['status'] === 'complete', 'durable successful result');
    byot_assert($store->read('operation-' . hash('sha256', 'pbx-test'))['assurance'] === 'simulated'
        && !$store->read('operation-' . hash('sha256', 'pbx-test'))['call_tested'], 'simulation never claims real connectivity');
    byot_assert(!str_contains(json_encode($store->read('operation-' . hash('sha256', 'pbx-test'))), $credential['token']), 'operation records omit token');
    echo "Orchestration contracts passed.\n";
} finally { if (is_dir($path)) { byot_clean($path); } }

foreach (['endpoint.resolve', 'dns.ensure', 'dns.verify', 'number.allocate', 'number.activate', 'number.destination', 'number.verify', 'number.cleanup',
    'inbound.trunk', 'inbound.numbers', 'inbound.cleanup', 'inbound.apply', 'inbound.verify', 'outbound.trunk', 'outbound.routes', 'outbound.cleanup', 'outbound.apply', 'outbound.verify'] as $failure) {
    $path = sys_get_temp_dir() . '/domaintains-failure-' . bin2hex(random_bytes(8));
    try {
        [$provider, $registry, $store] = byot_runtime($path, 'pbx-test', [$failure]);
        $credential = $registry->link('account-01', 'pbx-01', 'pbx-test', ['voice-01'], 1000);
        $pair = sodium_crypto_sign_keypair();
        byot_assert($provider->process(byot_request('pbx-test', $credential['token'], $pair), 1000)[0] !== 200, $failure . ' must stop success');
        byot_assert($store->read('operation-' . hash('sha256', 'pbx-test'))['status'] === 'failed', 'failed outcome persisted');
        [$retry] = byot_runtime($path, 'pbx-test');
        byot_assert($retry->process(byot_request('pbx-test', $credential['token'], $pair), 1000)[0] === 200, $failure . ' is recoverable with a fresh nonce and same bound key');
    } finally { if (is_dir($path)) { byot_clean($path); } }
}
echo "Adapter failure and recovery contracts passed.\n";

foreach (['unmanaged', 'drifted', 'foreign-number', 'billing-changed'] as $case) {
    $path = sys_get_temp_dir() . '/domaintains-conflict-' . bin2hex(random_bytes(8));
    try {
        [$provider, $registry, $store] = byot_runtime($path, 'pbx-test');
        $credential = $registry->link('account-01', 'pbx-01', 'pbx-test', ['voice-01'], 1000);
        $pair = sodium_crypto_sign_keypair();
        if ($case === 'unmanaged') {
            $store->transaction('simulated-edge-inbound', static function (array $state, callable $save): void {
                $save(['objects' => ['did:12025550101' => ['owner' => 'administrator', 'spec' => ['trunk' => 'local']]]]);
            });
        } elseif ($case === 'drifted') {
            byot_assert($provider->process(byot_request('pbx-test', $credential['token'], $pair), 1000)[0] === 200, 'initial drift fixture');
            $store->transaction('simulated-edge-inbound', static function (array $state, callable $save): void {
                $state['objects']['did:12025550101']['spec']['trunk'] = 'administrator-edited';
                $save($state);
            });
        } elseif ($case === 'foreign-number') {
            $store->transaction('simulated-numbers', static function (array $state, callable $save): void {
                $save(['numbers' => ['n12025550101' => ['account' => 'account-02', 'service' => 'other-service', 'active' => true, 'destination' => null]]]);
            });
        } else {
            $billing = byot_fixture('pbx-test');
            $billing['voice']['voice-01']['status'] = 'cancelled';
            [$provider] = byot_runtime($path, 'pbx-test', [], $billing);
        }
        $key = $case === 'foreign-number' ? 'simulated-numbers' : 'simulated-edge-inbound';
        $before = $store->read($key);
        byot_assert($provider->process(byot_request('pbx-test', $credential['token'], $pair), 1000)[0] !== 200, $case . ' must reject');
        $after = $store->read($key);
        byot_assert(in_array($case, ['unmanaged', 'drifted'], true)
            ? $after['objects']['did:12025550101'] === $before['objects']['did:12025550101'] : $after === $before,
            $case . ' preserves conflicting object');
    } finally { if (is_dir($path)) { byot_clean($path); } }
}

$path = sys_get_temp_dir() . '/domaintains-order-failure-' . bin2hex(random_bytes(8));
try {
    $store = new StateStore($path);
    $registry = new LocalRegistry($store, new LocalBilling(byot_fixture('pbx-test')), ['pbx-test' => byot_plan()]);
    $orchestrator = new Orchestrator($store, $registry, new SimulatedNumbers($store, ['number.verify']), new ConfiguredEndpointResolver([]),
        new SimulatedEdge($store, 'inbound'), new SimulatedEdge($store, 'outbound'), ['pbx-test' => byot_policies()]);
    byot_reject(fn() => $registry->link('account-01', 'pbx-01', 'pbx-test', ['voice-01'], 1000, false,
        fn(array $entitlement) => $orchestrator->prepareOrder('pbx-test', 'account-01', $entitlement)));
    byot_assert($store->read('registry') === [], 'failed order issues no credential');
    byot_assert($store->read('order-' . hash('sha256', 'pbx-test'))['status'] === 'failed', 'failed order remains recoverable');
} finally { if (is_dir($path)) { byot_clean($path); } }

$path = sys_get_temp_dir() . '/domaintains-throttle-' . bin2hex(random_bytes(8));
try {
    $replay = new ReplayStore($path);
    for ($requestIndex = 0; $requestIndex < 600; $requestIndex++) { $replay->throttle(1000); }
    try { $replay->throttle(1000); throw new RuntimeException('Missing throttling.'); }
    catch (\Domaintains\Byot\ProviderException $error) { byot_assert($error->httpStatus === 429, 'bounded application throttling'); }
    $replay->throttle(1061);
    $publicKey = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
    $replay->consume('service-01', base64_encode(random_bytes(24)), $publicKey, 1061, 300, '');
    byot_reject(fn() => $replay->consume('service-02', base64_encode(random_bytes(24)), $publicKey, 1061, 300, ''));
} finally { if (is_dir($path)) { byot_clean($path); } }
echo "Ownership, authoritative status, order failure and throttling contracts passed.\n";