<?php
declare(strict_types=1);

use Domaintains\Byot\{LocalBilling, LocalRegistry, ProviderException, StateStore};

require_once __DIR__ . '/../src/Contracts.php';
require_once __DIR__ . '/../src/StateStore.php';
require_once __DIR__ . '/../src/ActivationProvider.php';
require_once __DIR__ . '/../src/LocalRegistry.php';

function byot_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function byot_reject(callable $operation): void
{
    try { $operation(); } catch (ProviderException $error) { return; }
    throw new RuntimeException('Expected provider rejection.');
}

function byot_clean(string $path): void
{
    foreach (scandir($path) as $name) {
        if ($name === '.' || $name === '..') { continue; }
        $child = $path . '/' . $name;
        if (is_dir($child) && !is_link($child)) { byot_clean($child); } else { unlink($child); }
    }
    rmdir($path);
}

function byot_fixture(string $service): array
{
    return [
        'pbx' => ['pbx-01' => ['account' => 'account-01', 'status' => 'active', 'service' => $service]],
        'voice' => [
            'voice-01' => ['account' => 'account-01', 'status' => 'active', 'max_channels' => 2, 'numbers' => ['12025550101']],
            'voice-02' => ['account' => 'account-01', 'status' => 'active', 'max_channels' => 3, 'numbers' => ['12025550101', '12025550102']],
            'foreign' => ['account' => 'account-02', 'status' => 'active', 'max_channels' => 1, 'numbers' => []],
            'inactive' => ['account' => 'account-01', 'status' => 'suspended', 'max_channels' => 1, 'numbers' => []],
        ],
    ];
}

function byot_plan(): array
{
    return ['profile' => 'simulation', 'domaintains_hostname' => 'pbx.example', 'trunks' => [
        ['role' => 'inbound', 'sip_host' => 'inbound.example', 'sip_port' => 5060],
        ['role' => 'outbound', 'sip_host' => 'outbound.example', 'sip_port' => 5060],
    ]];
}

if (defined('BYOT_FIXTURES_ONLY')) { return; }

$path = sys_get_temp_dir() . '/domaintains-local-' . bin2hex(random_bytes(8));
try {
    $store = new StateStore($path);
    $registry = new LocalRegistry($store, new LocalBilling(byot_fixture('pbx-test')), ['pbx-test' => byot_plan()]);
    $issued = $registry->link('account-01', 'pbx-01', 'pbx-test', ['voice-01', 'voice-02', 'voice-01'], 1000);
    $record = $registry->authorise('pbx-test', hash('sha256', $issued['token']), 1000);
    byot_assert($record['max_channels'] === 5 && $record['numbers'] === ['12025550101', '12025550102'], 'aggregate distinct voice entitlements without duplicate numbers or channels');
    $reused = $registry->link('account-01', 'pbx-01', 'pbx-test', ['voice-02', 'voice-01'], 1001);
    byot_assert($reused['reused'] && $reused['token'] === null, 'valid credential reused without plaintext storage');
    byot_assert(!str_contains(file_get_contents($path . '/registry.json'), $issued['token']), 'token must not persist');
    byot_reject(fn() => $registry->link('account-02', 'pbx-01', 'pbx-test', ['voice-01'], 1000));
    byot_reject(fn() => $registry->link('account-01', 'pbx-01', 'pbx-test', ['foreign'], 1000));
    byot_reject(fn() => $registry->link('account-01', 'pbx-01', 'pbx-test', ['inactive'], 1000));
    $rotated = $registry->link('account-01', 'pbx-01', 'pbx-test', ['voice-01'], 1002, true);
    byot_reject(fn() => $registry->authorise('pbx-test', hash('sha256', $issued['token']), 1002));
    byot_reject(fn() => $registry->authorise('pbx-test', hash('sha256', $rotated['token']), 90000));
    $registry->setStatus('pbx-test', 'revoked');
    byot_reject(fn() => $registry->setStatus('pbx-test', 'active'));
    byot_reject(fn() => $registry->link('account-01', 'pbx-01', 'pbx-test', ['voice-01'], 1003));
    byot_reject(fn() => $registry->authorise('pbx-test', hash('sha256', $rotated['token']), 1003));
    byot_assert(!isset($registry->status('pbx-test')['token_sha256']), 'status must redact token verifier');
    foreach ([['max_channels' => '2'], ['max_channels' => 501], ['numbers' => ["12025550101\n"]]] as $invalid) {
        $billing = byot_fixture('pbx-malformed');
        $billing['voice']['voice-01'] = array_replace($billing['voice']['voice-01'], $invalid);
        $bad = new LocalRegistry($store, new LocalBilling($billing), ['pbx-malformed' => byot_plan()]);
        byot_reject(fn() => $bad->link('account-01', 'pbx-01', 'pbx-malformed', ['voice-01'], 1000));
    }
    echo "Local registry contracts passed.\n";
} finally {
    if (is_dir($path)) { byot_clean($path); }
}