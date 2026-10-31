<?php
declare(strict_types=1);

use Domaintains\NumberSearch\{OperatorIntegration, Runtime};
use Domaintains\NumberSearch\Fixtures\Reference;
require dirname(__DIR__) . '/src/Runtime.php';
require dirname(__DIR__) . '/fixtures/Adapters.php';

$directory = sys_get_temp_dir() . '/domaintains-neutral-' . bin2hex(random_bytes(8));
$now = 2000000.0;
$clock = static function () use (&$now): float { return $now; };
$wait = static function (int $delay) use (&$now): void { $now += $delay / 1000000; };
$config = Reference::config($directory, $clock, $wait);
$session = ['account_id' => 'account-a', 'csrf_token' => 'fixture-token', 'domaintains_number_nonce' => str_repeat('a', 48), 'domaintains_number_nonce_time' => (int)$now];
$server = ['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://portal.example', 'HTTP_X_OPERATOR_CSRF' => 'fixture-token'];
try {
    Runtime::validate($config);
    $integration = new OperatorIntegration($config, Runtime::service($config), $clock);
    $result = $integration->addToSubscription($session, 'sub-a', '0204', '02041234567', $server);
    if (($result['fixture_only'] ?? null) !== true || $result['number'] !== '442041234567') { throw new RuntimeException('Neutral subscription integration failed.'); }
    $events = array_column($config['fixture_state']->events, 0);
    if ($events !== ['billing_preflight', 'provisioning_preflight', 'allocate_activate', 'billing_reconcile']) { throw new RuntimeException('Independent billing/provisioning order failed.'); }
    $template = require dirname(__DIR__) . '/config.example.php';
    if ($template['enabled'] !== false) { throw new RuntimeException('Example must be disabled by default.'); }
    foreach (['customer_access', 'catalog', 'ordering', 'ordering_lifecycle', 'evidence', 'provisioning', 'billing', 'carrier'] as $key) {
        $missing = $config; $missing[$key] = null;
        try { Runtime::validate($missing); throw new DomainException('Missing adapter accepted: ' . $key); }
        catch (RuntimeException $error) { if (!str_contains($error->getMessage(), $key)) { throw $error; } }
    }
    foreach (['billing_preflight', 'provisioning_failure', 'unconfirmed', 'reconciliation'] as $scenario) {
        $isolated = Reference::config($directory, $clock, $wait);
        $isolatedSession = $session;
        if ($scenario === 'billing_preflight') { $isolated['billing']->failPreflight = true; }
        if ($scenario === 'provisioning_failure') { $isolated['provisioning']->fail = true; }
        if ($scenario === 'unconfirmed') { $isolated['provisioning']->confirm = false; }
        if ($scenario === 'reconciliation') { $isolated['billing']->failReconcile = true; }
        $operation = new OperatorIntegration($isolated, Runtime::service($isolated), $clock);
        $rejected = false;
        try { $operation->addToSubscription($isolatedSession, 'sub-a', '0204', '02041234567', $server); }
        catch (DomainException $error) { $rejected = true; }
        if (!$rejected || array_values($isolated['fixture_state']->operations) !== ['requires_review']) { throw new RuntimeException('Partial operation not retained: ' . $scenario); }
        $journal = $isolated['fixture_state']->events;
        if (in_array('billing_reconcile', array_column($journal, 0), true)) { throw new RuntimeException('Failed operation falsely reconciled: ' . $scenario); }
        if (in_array($scenario, ['billing_preflight', 'provisioning_failure'], true) && $isolated['fixture_state']->allocated !== []) { throw new RuntimeException('Failed preflight/provisioning allocated a number.'); }
        if (in_array($scenario, ['unconfirmed', 'reconciliation'], true) && count($isolated['fixture_state']->allocated) !== 1) { throw new RuntimeException('Partial provisioning outcome was lost.'); }
        $rejected = false;
        try { $operation->addToSubscription($isolatedSession, 'sub-a', '0204', '02041234567', $server); }
        catch (DomainException $error) { $rejected = true; }
        if (!$rejected || $isolated['fixture_state']->events !== $journal) { throw new RuntimeException('Partial operation automatically retried.'); }
    }
    echo "Neutral adapter/configuration, independent billing/provisioning, partial-outcome and retry-guard contracts passed.\n";
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    if (is_dir($directory)) { rmdir($directory); }
}