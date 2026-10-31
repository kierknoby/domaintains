<?php
declare(strict_types=1);

namespace Domaintains\NumberSearch;

require_once __DIR__ . '/Contracts.php';

final class OperatorIntegration
{
    public function __construct(private readonly array $config, private readonly AvailabilityService $availability,
        private readonly \Closure $clock)
    {
    }

    private function prefix(string $canonical): string
    {
        $national = '0' . substr($canonical, 2);
        $matches = [];
        foreach ($this->config['regions'] as $areas) {
            foreach ($areas as $codes) {
                foreach ($codes as $prefix => $label) {
                    if (str_starts_with($national, (string)$prefix)) { $matches[] = (string)$prefix; }
                }
            }
        }
        usort($matches, static fn(string $first, string $second): int => strlen($second) <=> strlen($first));
        if (!$matches) { throw new AvailabilityFailure(400, 'invalid_prefix'); }
        return $matches[0];
    }

    private function mutation(array $session, array $server): CustomerIdentity
    {
        $customer = Runtime::customer($this->config, $session, $server);
        $this->config['customer_access']->requireMutation($customer, $session, $server);
        return $customer;
    }

    public function validateSelection(array &$session, string $itemId, string $selection, array $server): string
    {
        $customer = $this->mutation($session, $server);
        $request = ['context' => 'cart-' . $itemId, 'nonce' => $session['domaintains_number_nonce'] ?? ''];
        $context = Runtime::selectionContext($this->config, $session, $request['context'], $server);
        if ($context->kind !== 'cart' || $context->resourceId !== $itemId) { throw new AvailabilityFailure(403, 'forbidden'); }
        $canonical = AvailabilityService::canonical($selection);
        $request['prefix'] = $this->prefix($canonical);
        $canonical = $this->availability->verifySelection($session, $request, $server, $canonical);
        $current = Runtime::selectionContext($this->config, $session, $request['context'], $server);
        if ($current->scope() !== $context->scope()) { throw new AvailabilityFailure(403, 'forbidden'); }
        $this->config['ordering']->saveSelection($customer, $itemId, $canonical);
        return $canonical;
    }

    public function beforeCheckout(array &$session, array $server): array
    {
        $customer = $this->mutation($session, $server);
        $receipts = [];
        $seen = [];
        foreach ($this->config['ordering']->cartItems($customer) as $item) {
            if (!$item instanceof SelectionContext || $item->kind !== 'cart' || $item->accountId !== $customer->accountId) { throw new AvailabilityFailure(403, 'forbidden'); }
            if ($item->productId !== $this->config['product_id']) { continue; }
            $number = $this->validateSelection($session, $item->resourceId, $this->config['ordering']->selection($customer, $item->resourceId), $server);
            if (isset($seen[$number])) { throw new AvailabilityFailure(400, 'duplicate_cart_number'); }
            $seen[$number] = true;
            $offeredAt = $this->config['evidence']->seenAt($session, $item->scope(), $this->prefix($number), $number);
            if ($offeredAt === null) { throw new AvailabilityFailure(400, 'selection_unverified'); }
            $receipts[] = ['resource_id' => $item->resourceId, 'account_id' => $customer->accountId,
                'product_id' => $item->productId, 'number' => $number, 'offered_at' => $offeredAt, 'verified_at' => (int)($this->clock)()];
        }
        return $receipts;
    }

    private function fulfil(SelectionContext $context, string $canonical, callable $verify): mixed
    {
        return $this->config['billing']->withOperation($context, $canonical, function () use ($context, $canonical, $verify): array {
            $this->config['billing']->preflight($context, $canonical);
            $this->config['provisioning']->preflight($context);
            $verify();
            $result = $this->config['provisioning']->allocateAndActivate($context, $canonical);
            if (($result['confirmed'] ?? null) !== true || ($result['number'] ?? null) !== $canonical) { throw new \DomainException('Provisioning confirmation incomplete; operator review required.'); }
            $this->config['billing']->reconcile($context, $canonical, $result);
            return $result;
        });
    }

    public function addToSubscription(array &$session, string $subscriptionId, string $prefix, string $selection, array $server): mixed
    {
        $this->mutation($session, $server);
        $selector = 'update-' . $subscriptionId;
        $context = Runtime::selectionContext($this->config, $session, $selector, $server);
        if ($context->kind !== 'subscription' || $context->resourceId !== $subscriptionId) { throw new AvailabilityFailure(403, 'forbidden'); }
        $canonical = AvailabilityService::canonical($selection);
        return $this->fulfil($context, $canonical, function () use (&$session, $selector, $prefix, $canonical, $server, $context): void {
            $this->availability->verifySelection($session, ['context' => $selector, 'prefix' => $prefix,
                'nonce' => $session['domaintains_number_nonce'] ?? ''], $server, $canonical);
            $current = Runtime::selectionContext($this->config, $session, $selector, $server);
            if ($current->scope() !== $context->scope()) { throw new AvailabilityFailure(403, 'forbidden'); }
        });
    }

    public function beforeAllocation(string $orderLineId): mixed
    {
        return $this->config['ordering']->withOrderLine($orderLineId, function (array $line) use ($orderLineId): mixed {
            if (($line['order_line_id'] ?? null) !== $orderLineId || ($line['product_id'] ?? null) !== $this->config['product_id']
                || !is_string($line['account_id'] ?? null) || $line['account_id'] === '' || !is_string($line['selection'] ?? null)) {
                throw new AvailabilityFailure(403, 'forbidden');
            }
            $context = new SelectionContext('order', $line['account_id'], $line['product_id'], $orderLineId);
            $canonical = AvailabilityService::canonical($line['selection']);
            return $this->fulfil($context, $canonical, function () use ($line, $canonical): void {
                $this->availability->verifyOrderSelection($line, $this->prefix($canonical), $canonical);
            });
        });
    }

    public function register(): void
    {
        $this->config['ordering_lifecycle']->register(
            fn(string $itemId, string $selection): string => $this->validateSelection($_SESSION, $itemId, $selection, $_SERVER),
            fn(): array => $this->beforeCheckout($_SESSION, $_SERVER),
            fn(string $orderLineId): mixed => $this->beforeAllocation($orderLineId)
        );
    }
}