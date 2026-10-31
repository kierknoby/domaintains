<?php
declare(strict_types=1);

namespace Domaintains\NumberSearch\Fixtures;

use Domaintains\NumberSearch\{AvailabilityCarrier, AvailabilityFailure, BillingIntegration, CustomerAccess,
    CustomerIdentity, OrderingLifecycle, OrderingPlatform, ProvisioningAdapter, SelectionCatalog, SelectionContext, SessionOfferEvidence};

final class State
{
    public array $accounts = ['account-a' => true, 'account-b' => true];
    public array $cart = [
        'item-a' => ['account_id' => 'account-a', 'product_id' => 'voice-plan', 'selection' => ''],
        'item-b' => ['account_id' => 'account-a', 'product_id' => 'voice-plan', 'selection' => ''],
        'other-item' => ['account_id' => 'account-a', 'product_id' => 'other-plan', 'selection' => ''],
    ];
    public array $subscriptions = ['sub-a' => ['account_id' => 'account-a', 'product_id' => 'voice-plan', 'active' => true]];
    public array $orders = [];
    public array $allocated = [];
    public array $operations = [];
    public array $events = [];
}

final class Access implements CustomerAccess
{
    public function __construct(private readonly State $state) {}
    public function current(array $session, array $server): ?CustomerIdentity
    {
        $account = $session['account_id'] ?? null;
        return is_string($account) && isset($this->state->accounts[$account]) ? new CustomerIdentity($account) : null;
    }
    public function requireMutation(CustomerIdentity $customer, array $session, array $server): void
    {
        if (($server['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($session['csrf_token'] ?? null)
            || !is_string($server['HTTP_X_OPERATOR_CSRF'] ?? null) || !hash_equals($session['csrf_token'], $server['HTTP_X_OPERATOR_CSRF'])) {
            throw new AvailabilityFailure(403, 'forbidden');
        }
    }
}

final class Catalog implements SelectionCatalog
{
    public function __construct(private readonly State $state) {}
    public function resolve(CustomerIdentity $customer, string $selector): SelectionContext
    {
        if (str_starts_with($selector, 'cart-')) { $kind = 'cart'; $id = substr($selector, 5); $row = $this->state->cart[$id] ?? null; }
        elseif (str_starts_with($selector, 'update-')) { $kind = 'subscription'; $id = substr($selector, 7); $row = $this->state->subscriptions[$id] ?? null; }
        else { throw new AvailabilityFailure(403, 'forbidden'); }
        if (!is_array($row) || $row['account_id'] !== $customer->accountId || ($kind === 'subscription' && ($row['active'] ?? false) !== true)) {
            throw new AvailabilityFailure(403, 'forbidden');
        }
        return new SelectionContext($kind, $customer->accountId, $row['product_id'], $id);
    }
}

final class Orders implements OrderingPlatform
{
    private array $origins = [];
    public function __construct(private readonly State $state) {}
    public function cartItems(CustomerIdentity $customer): array
    {
        $items = [];
        foreach ($this->state->cart as $id => $row) {
            if ($row['account_id'] === $customer->accountId) { $items[] = new SelectionContext('cart', $customer->accountId, $row['product_id'], $id); }
        }
        return $items;
    }
    public function selection(CustomerIdentity $customer, string $itemId): string
    {
        if (($this->state->cart[$itemId]['account_id'] ?? null) !== $customer->accountId) { throw new AvailabilityFailure(403, 'forbidden'); }
        return $this->state->cart[$itemId]['selection'];
    }
    public function saveSelection(CustomerIdentity $customer, string $itemId, string $canonical): void
    {
        $this->selection($customer, $itemId);
        $this->state->cart[$itemId]['selection'] = $canonical;
    }
    public function createOrderLine(string $orderLineId, string $itemId, array $receipt): void
    {
        $item = $this->state->cart[$itemId] ?? null;
        if ($orderLineId === '' || isset($this->state->orders[$orderLineId]) || isset($this->origins[$orderLineId])
            || !is_array($item) || ($receipt['resource_id'] ?? null) !== $itemId
            || ($receipt['account_id'] ?? null) !== $item['account_id'] || ($receipt['product_id'] ?? null) !== $item['product_id']
            || ($receipt['number'] ?? null) !== $item['selection']) { throw new \DomainException('Fixture order origin is not verified.'); }
        $this->origins[$orderLineId] = ['item_id' => $itemId, 'account_id' => $item['account_id'],
            'product_id' => $item['product_id'], 'selection' => $item['selection']];
        $this->state->orders[$orderLineId] = ['order_line_id' => $orderLineId, 'account_id' => $item['account_id'],
            'product_id' => $item['product_id'], 'selection' => $item['selection'], 'selection_receipt' => $receipt,
            'originating_item_id' => $itemId, 'fixture_approved' => true];
    }
    public function withOrderLine(string $orderLineId, callable $operation): mixed
    {
        $line = $this->state->orders[$orderLineId] ?? null;
        if (!is_array($line) || ($line['fixture_approved'] ?? false) !== true) { throw new \DomainException('Fixture order is not approved.'); }
        $origin = $this->origins[$orderLineId] ?? null;
        if ($origin === null || ($line['originating_item_id'] ?? null) !== $origin['item_id']
            || ($line['account_id'] ?? null) !== $origin['account_id'] || ($line['product_id'] ?? null) !== $origin['product_id']
            || ($line['selection'] ?? null) !== $origin['selection']) { throw new \DomainException('Fixture order origin mapping is missing or changed.'); }
        $receipt = $line['selection_receipt'] ?? null;
        if ($receipt !== null && (!is_array($receipt) || ($receipt['resource_id'] ?? null) !== $origin['item_id'])) {
            throw new \DomainException('Fixture receipt belongs to another item.');
        }
        $line['originating_item_id'] = $origin['item_id'];
        return $operation($line);
    }
}

final class Lifecycle implements OrderingLifecycle
{
    public $validate;
    public $checkout;
    public $allocate;
    public function register(callable $validateSelection, callable $beforeCheckout, callable $beforeAllocation): void
    {
        $this->validate = $validateSelection; $this->checkout = $beforeCheckout; $this->allocate = $beforeAllocation;
    }
}

final class Carrier implements AvailabilityCarrier
{
    public array $calls = [];
    public function __construct(public array $numbers = ['442041234567', '442047654321']) {}
    public function available(string $range, int $size): array
    {
        $this->calls[] = [$range, $size];
        return ['http_code' => 200, 'body' => json_encode($this->numbers, JSON_THROW_ON_ERROR), 'curl_errno' => 0];
    }
}

final class Provisioning implements ProvisioningAdapter
{
    public bool $fail = false;
    public bool $confirm = true;
    public function __construct(private readonly State $state) {}
    public function preflight(SelectionContext $context): void { $this->state->events[] = ['provisioning_preflight', $context->resourceId]; }
    public function allocateAndActivate(SelectionContext $context, string $canonical): array
    {
        if ($this->fail || isset($this->state->allocated[$canonical])) { throw new \DomainException('Fixture provisioning failed or number already allocated.'); }
        $this->state->allocated[$canonical] = $context->scope();
        $this->state->events[] = ['allocate_activate', $context->resourceId, $canonical];
        return ['confirmed' => $this->confirm, 'number' => $canonical, 'fixture_only' => true];
    }
}

final class Billing implements BillingIntegration
{
    public bool $failPreflight = false;
    public bool $failReconcile = false;
    public function __construct(private readonly State $state) {}
    public function withOperation(SelectionContext $context, string $canonical, callable $operation): mixed
    {
        $key = $context->scope() . ':' . $canonical;
        if (isset($this->state->operations[$key])) { throw new \DomainException('Fixture operation cannot be retried.'); }
        $this->state->operations[$key] = 'in_progress';
        try { $result = $operation(); $this->state->operations[$key] = 'completed'; return $result; }
        catch (\Throwable $error) { $this->state->operations[$key] = 'requires_review'; throw $error; }
    }
    public function preflight(SelectionContext $context, string $canonical): void
    {
        $this->state->events[] = ['billing_preflight', $context->resourceId];
        if ($this->failPreflight) { throw new \DomainException('Fixture pricing unavailable.'); }
    }
    public function reconcile(SelectionContext $context, string $canonical, array $provisioned): void
    {
        if ($this->failReconcile) { throw new \DomainException('Fixture reconciliation failed; operator review required.'); }
        $this->state->events[] = ['billing_reconcile', $context->resourceId, $canonical];
    }
}

final class Reference
{
    public static function config(string $directory, ?\Closure $clock = null, ?\Closure $wait = null): array
    {
        $state = new State();
        $config = ['enabled' => true, 'product_id' => 'voice-plan', 'private_state_dir' => $directory,
            'regions_file' => __DIR__ . '/regions.php', 'regions' => require __DIR__ . '/regions.php',
            'allowed_origins' => ['https://portal.example'], 'asset_url' => '/domaintains', 'availability_url' => '/domaintains/availability.php',
            'selection_field_name' => 'selected_number', 'customer_access' => new Access($state), 'catalog' => new Catalog($state),
            'ordering' => new Orders($state), 'ordering_lifecycle' => new Lifecycle(), 'evidence' => new SessionOfferEvidence(),
            'carrier' => new Carrier(), 'provisioning' => new Provisioning($state), 'billing' => new Billing($state), 'fixture_state' => $state];
        if ($clock !== null) { $config['clock'] = $clock; }
        if ($wait !== null) { $config['wait'] = $wait; }
        return $config;
    }
}