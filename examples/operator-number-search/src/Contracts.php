<?php
declare(strict_types=1);

namespace Domaintains\NumberSearch;

final class CustomerIdentity
{
    public function __construct(public readonly string $accountId)
    {
        if ($accountId === '' || strlen($accountId) > 200) { throw new \InvalidArgumentException('Stable operator account identity required.'); }
    }
}

final class SelectionContext
{
    public function __construct(public readonly string $kind, public readonly string $accountId,
        public readonly string $productId, public readonly string $resourceId)
    {
        if (!in_array($kind, ['cart', 'subscription', 'order'], true) || $accountId === '' || $productId === '' || $resourceId === '') {
            throw new \InvalidArgumentException('Authoritative selection context required.');
        }
    }

    public function scope(): string
    {
        return hash('sha256', json_encode([$this->kind, $this->accountId, $this->productId, $this->resourceId], JSON_THROW_ON_ERROR));
    }

    public function attribution(): array
    {
        $record = ['client_id' => $this->accountId, 'product_id' => $this->productId,
            'context' => $this->kind, 'selection_scope' => $this->scope()];
        if ($this->kind === 'subscription') { $record['service_id'] = $this->resourceId; }
        return $record;
    }
}

interface CustomerAccess
{
    public function current(array $session, array $server): ?CustomerIdentity;
    public function requireMutation(CustomerIdentity $customer, array $session, array $server): void;
}

interface SelectionCatalog
{
    public function resolve(CustomerIdentity $customer, string $selector): SelectionContext;
}

interface OrderingPlatform
{
    public function cartItems(CustomerIdentity $customer): array;
    public function selection(CustomerIdentity $customer, string $itemId): string;
    public function saveSelection(CustomerIdentity $customer, string $itemId, string $canonical): void;
    public function withOrderLine(string $orderLineId, callable $operation): mixed;
}

interface OrderingLifecycle
{
    public function register(callable $validateSelection, callable $beforeCheckout, callable $beforeAllocation): void;
}

interface OfferEvidence
{
    public function seenAt(array $state, string $scope, string $prefix, string $canonical): ?int;
    public function remember(array &$state, string $scope, string $prefix, array $numbers, int $now): int;
    public function clear(array &$state): void;
}

interface ProvisioningAdapter
{
    public function preflight(SelectionContext $context): void;
    public function allocateAndActivate(SelectionContext $context, string $canonical): array;
}

interface BillingIntegration
{
    public function withOperation(SelectionContext $context, string $canonical, callable $operation): mixed;
    public function preflight(SelectionContext $context, string $canonical): void;
    public function reconcile(SelectionContext $context, string $canonical, array $provisioned): void;
}