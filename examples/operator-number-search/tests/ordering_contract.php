<?php
declare(strict_types=1);

use Domaintains\NumberSearch\{AvailabilityFailure, OperatorIntegration, Runtime};
use Domaintains\NumberSearch\Fixtures\Reference;
require dirname(__DIR__) . '/src/Runtime.php';
require dirname(__DIR__) . '/fixtures/Adapters.php';

function ordering_assert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function ordering_reject(callable $operation, string $message): void {
    try { $operation(); } catch (AvailabilityFailure | DomainException $error) { return; }
    throw new RuntimeException($message);
}

$directory = sys_get_temp_dir() . '/domaintains-ordering-' . bin2hex(random_bytes(8));
$now = 2000000.0;
$clock = static function () use (&$now): float { return $now; };
$wait = static function (int $delay) use (&$now): void { $now += $delay / 1000000; };
$config = Reference::config($directory, $clock, $wait);
$state = $config['fixture_state'];
$carrier = $config['carrier'];
$_SESSION = ['account_id' => 'account-a', 'csrf_token' => 'fixture-token',
    'domaintains_number_nonce' => str_repeat('a', 48), 'domaintains_number_nonce_time' => (int)$now];
$_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://portal.example', 'HTTP_X_OPERATOR_CSRF' => 'fixture-token'];
try {
    $availability = Runtime::service($config);
    $integration = new OperatorIntegration($config, $availability, $clock);
    $integration->register();
    $lifecycle = $config['ordering_lifecycle'];
    $scopeA = Runtime::context($config, $_SESSION, ['context' => 'cart-item-a'])['selection_scope'];
    $scopeB = Runtime::context($config, $_SESSION, ['context' => 'cart-item-b'])['selection_scope'];
    ordering_assert($scopeA !== $scopeB, 'stable item scopes differ');
    $request = ['context' => 'cart-item-a', 'nonce' => str_repeat('a', 48), 'prefix' => '0204', 'action' => 'search', 'search' => '12', 'position' => '5'];
    ordering_assert($availability->handle($_SESSION, $request, $_SERVER)[0] === 200 && $state->allocated === [] && $state->events === [], 'search never allocates or bills');
    $state->cart['item-a']['unrelated_metadata'] = 'retained';
    $carrier->numbers = [];
    ordering_assert(($lifecycle->validate)('item-a', '02041234567') === '442041234567' && $state->cart['item-a']['unrelated_metadata'] === 'retained', 'canonical selection preserves operator metadata');
    ordering_reject(static fn() => ($lifecycle->validate)('item-b', '02041234567'), 'another item borrowed scoped evidence');
    ordering_reject(static fn() => ($lifecycle->validate)('other-item', '02041234567'), 'wrong product accepted');
    $request['context'] = 'cart-item-b'; $carrier->numbers = ['442047654321'];
    ordering_assert($availability->handle($_SESSION, $request, $_SERVER)[0] === 200, 'second item searches independently');
    ($lifecycle->validate)('item-b', '442047654321');
    $carrier->numbers = [];
    $receipts = ($lifecycle->checkout)();
    ordering_assert(count($receipts) === 2 && $receipts[0]['account_id'] === 'account-a' && $receipts[0]['resource_id'] === 'item-a'
        && $receipts[0]['number'] === '442041234567' && $receipts[1]['number'] === '442047654321', 'checkout preserves opaque account/item identities and selections');
    ordering_assert($receipts[0]['offered_at'] < $receipts[0]['verified_at'] && $state->allocated === [] && $state->events === [], 'checkout retains original evidence age without allocating or billing');
    $state->cart['item-b']['selection'] = '442041234567'; $carrier->numbers = ['442041234567', '442047654321'];
    ordering_reject(static fn() => ($lifecycle->checkout)(), 'duplicate checkout accepted');
    ordering_assert($state->allocated === [], 'failed checkout cannot allocate');
    $state->cart['item-b']['selection'] = '442047654321'; $carrier->numbers = [];
    $state->cart = array_reverse($state->cart, true);
    ordering_assert(Runtime::context($config, $_SESSION, ['context' => 'cart-item-a'])['selection_scope'] === $scopeA, 'evidence survives cart reordering');
    $orders = $config['ordering'];
    $orders->createOrderLine('line-a', 'item-a', $receipts[0]);
    $line = $state->orders['line-a'];
    $state->orders['cross-item'] = array_replace($line, ['order_line_id' => 'cross-item', 'originating_item_id' => 'item-b']);
    ordering_reject(static fn() => ($lifecycle->allocate)('cross-item'), 'item A receipt authorised an unrelated item B order line');
    ordering_reject(static fn() => $orders->createOrderLine('wrong-origin-create', 'item-b', $receipts[0]), 'receipt from item A bound to item B during order creation');
    $orders->createOrderLine('line-b', 'item-b', $receipts[1]);
    $state->orders['line-b']['selection_receipt'] = $receipts[0];
    ordering_reject(static fn() => ($lifecycle->allocate)('line-b'), 'verified item B mapping accepted item A receipt');
    $carrier->numbers = ['442041234567'];
    $originCases = [array_replace($line, ['originating_item_id' => 'item-b']), array_diff_key($line, ['originating_item_id' => true])];
    $missingResource = $line;
    unset($missingResource['selection_receipt']['resource_id']);
    $originCases[] = $missingResource;
    $calls = count($carrier->calls);
    foreach ($originCases as $invalidLine) {
        ordering_reject(static fn() => $availability->verifyOrderSelection($invalidLine, '0204', '442041234567'), 'fresh carrier offer bypassed invalid receipt/source mapping');
    }
    ordering_assert(count($carrier->calls) === $calls, 'invalid source mapping fails before transport');
    $carrier->numbers = [];
    $orders->createOrderLine('changed-origin', 'item-a', $receipts[0]);
    $state->orders['changed-origin']['originating_item_id'] = 'item-b';
    ordering_reject(static fn() => ($lifecycle->allocate)('changed-origin'), 'immutable order source mapping was changed');
    ordering_reject(static fn() => $orders->createOrderLine('changed-origin', 'item-b', $receipts[1]), 'existing order source rebound to another item');
    $orders->createOrderLine('missing-origin', 'item-a', $receipts[0]);
    unset($state->orders['missing-origin']['originating_item_id']);
    ordering_reject(static fn() => ($lifecycle->allocate)('missing-origin'), 'order with missing source mapping accepted');
    ordering_assert($state->allocated === [], 'cross-item receipt cannot allocate or activate');
    ordering_reject(static fn() => ($lifecycle->allocate)('missing'), 'unknown order accepted');
    $orders->createOrderLine('unapproved', 'item-a', $receipts[0]);
    $state->orders['unapproved']['fixture_approved'] = false;
    ordering_reject(static fn() => ($lifecycle->allocate)('unapproved'), 'unapproved order accepted');
    $orders->createOrderLine('wrong-product', 'item-a', $receipts[0]);
    $state->orders['wrong-product']['product_id'] = 'other-plan';
    ordering_reject(static fn() => ($lifecycle->allocate)('wrong-product'), 'wrong order product accepted');
    $orders->createOrderLine('wrong-account', 'item-a', $receipts[0]);
    $state->orders['wrong-account']['account_id'] = 'account-b';
    ordering_reject(static fn() => ($lifecycle->allocate)('wrong-account'), 'foreign account borrowed receipt');
    foreach (['offered_at', 'verified_at'] as $timestamp) {
        $id = 'expired-' . $timestamp;
        $orders->createOrderLine($id, 'item-a', $receipts[0]);
        $state->orders[$id]['selection_receipt'][$timestamp] = (int)$now - 1801;
        ordering_reject(static fn() => ($lifecycle->allocate)($id), 'expired carrier evidence renewed through checkout');
    }
    ordering_assert($state->allocated === [], 'failed receipt verification never mutates numbers');
    $now += 60.0;
    unset($state->cart['item-a']);
    $result = ($lifecycle->allocate)('line-a');
    ordering_assert($result['fixture_only'] === true && $result['number'] === '442041234567', 'authoritative verified order reaches configured provisioning');
    $events = array_values(array_filter($state->events, static fn(array $event): bool => $event[1] === 'line-a'));
    ordering_assert(array_column($events, 0) === ['billing_preflight', 'provisioning_preflight', 'allocate_activate', 'billing_reconcile'], 'billing/provisioning guards precede mutation and reconciliation follows confirmation');
    ordering_reject(static fn() => ($lifecycle->allocate)('line-a'), 'completed order automatically retried');
    $_SESSION['account_id'] = 'account-b';
    ordering_reject(static fn() => ($lifecycle->validate)('item-a', '442041234567'), 'account change reused evidence');
    unset($_SESSION['account_id']);
    ordering_reject(static fn() => Runtime::context($config, $_SESSION, ['context' => 'cart-item-a']), 'anonymous cart accepted');
    echo "Neutral ordering, scoped ownership, checkout evidence, stable identities and authoritative allocation contracts passed.\n";
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    if (is_dir($directory)) { rmdir($directory); }
}