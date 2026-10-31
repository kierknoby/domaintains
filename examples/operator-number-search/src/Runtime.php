<?php
declare(strict_types=1);

namespace Domaintains\NumberSearch;

require_once __DIR__ . '/Availability.php';
require_once __DIR__ . '/MagratheaAvailability.php';
require_once __DIR__ . '/Integration.php';

final class Runtime
{
    public static function privatePath(string $path, bool $outsideWebroot = true): void
    {
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $root = ($documentRoot !== '' ? realpath($documentRoot) : false) ?: realpath(dirname(__DIR__) . '/public');
        if ($path === '' || !str_starts_with($path, '/') || realpath($path) !== $path || is_link($path)
            || ($outsideWebroot && $root && ($path === $root || str_starts_with($path, $root . '/')))) { throw new \RuntimeException('Canonical private path required.'); }
    }

    public static function configuration(): array
    {
        $file = getenv('DOMAINTAINS_NUMBER_SEARCH_CONFIG') ?: '';
        self::privatePath($file);
        if (!is_file($file) || (fileperms($file) & 0077) !== 0) { throw new \RuntimeException('Restrictive private configuration required.'); }
        $config = require $file;
        if (!is_array($config) || ($config['enabled'] ?? false) !== true) { throw new \RuntimeException('Number search is not configured.'); }
        self::validate($config);
        if (isset($config['operator_bootstrap'])) { ($config['operator_bootstrap'])(); }
        self::privatePath($config['regions_file'] ?? '');
        $config['regions'] = require $config['regions_file'];
        if (!is_array($config['regions']) || !$config['regions']) { throw new \RuntimeException('Area-code directory unavailable.'); }
        PrivateFiles::directory($config['private_state_dir'] ?? '');
        self::privatePath($config['private_state_dir']);
        return $config;
    }

    public static function validate(array $config): void
    {
        if (!is_string($config['product_id'] ?? null) || $config['product_id'] === '') { throw new \RuntimeException('Opaque operator product ID required.'); }
        foreach (['asset_url', 'availability_url'] as $name) {
            if (!is_string($config[$name] ?? null) || !str_starts_with($config[$name], '/') || str_starts_with($config[$name], '//')
                || preg_match('/[\s<>"\x00-\x1f]/', $config[$name])) { throw new \RuntimeException('Same-origin asset paths required.'); }
        }
        if (empty($config['allowed_origins']) || !is_array($config['allowed_origins'])) { throw new \RuntimeException('Explicit HTTPS origins required.'); }
        foreach ($config['allowed_origins'] as $origin) {
            if (!is_string($origin) || !preg_match('~^https://[^/]+$~D', $origin)) { throw new \RuntimeException('Invalid allowed origin.'); }
        }
        $contracts = ['customer_access' => CustomerAccess::class, 'catalog' => SelectionCatalog::class,
            'ordering' => OrderingPlatform::class, 'ordering_lifecycle' => OrderingLifecycle::class,
            'evidence' => OfferEvidence::class, 'provisioning' => ProvisioningAdapter::class,
            'billing' => BillingIntegration::class, 'carrier' => AvailabilityCarrier::class];
        foreach ($contracts as $key => $contract) {
            if (!(($config[$key] ?? null) instanceof $contract)) { throw new \RuntimeException('Operator adapter required: ' . $key); }
        }
        if (isset($config['operator_bootstrap']) && !is_callable($config['operator_bootstrap'])) { throw new \RuntimeException('Invalid operator bootstrap.'); }
    }

    public static function nonce(array &$session, OfferEvidence $evidence = new SessionOfferEvidence(), ?int $now = null): string
    {
        $now ??= time();
        $time = $session['domaintains_number_nonce_time'] ?? 0;
        if (!is_string($session['domaintains_number_nonce'] ?? null) || strlen($session['domaintains_number_nonce']) < 32
            || !is_int($time) || $time <= 0 || $time > $now || $now - $time > 86400) {
            $session['domaintains_number_nonce'] = bin2hex(random_bytes(24));
            $session['domaintains_number_nonce_time'] = $now;
            $evidence->clear($session);
        }
        return $session['domaintains_number_nonce'];
    }

    public static function customer(array $config, array $session, array $server = []): CustomerIdentity
    {
        $customer = $config['customer_access']->current($session, $server);
        if (!$customer instanceof CustomerIdentity) { throw new AvailabilityFailure(403, 'forbidden'); }
        return $customer;
    }

    public static function selectionContext(array $config, array $session, string $selector, array $server = []): SelectionContext
    {
        $customer = self::customer($config, $session, $server);
        $context = $config['catalog']->resolve($customer, $selector);
        if ($context->accountId !== $customer->accountId || $context->productId !== $config['product_id']) { throw new AvailabilityFailure(403, 'forbidden'); }
        return $context;
    }

    public static function context(array $config, array $session, array $request, array $server = []): array
    {
        if (!is_string($request['context'] ?? null)) { throw new AvailabilityFailure(403, 'forbidden'); }
        return self::selectionContext($config, $session, $request['context'], $server)->attribution();
    }

    public static function service(array $config): AvailabilityService
    {
        $clock = $config['clock'] ?? static fn(): float => microtime(true);
        $queue = new AvailabilityQueue($config['private_state_dir'] . '/availability-rate.lock', $clock,
            $config['wait'] ?? static fn(int $microseconds) => usleep($microseconds),
            $config['cancelled'] ?? static fn(): bool => connection_aborted() !== 0);
        return new AvailabilityService($config['carrier'], $queue, new AvailabilityAudit($config['private_state_dir']),
            $config['regions'], $config['allowed_origins'], static fn(array $session, array $request): array => self::context($config, $session, $request, $_SERVER),
            $clock, $config['evidence'], static fn(array $session): ?CustomerIdentity => $config['customer_access']->current($session, $_SERVER));
    }

    public static function picker(array $config, array &$session, array $binding): string
    {
        self::selectionContext($config, $session, $binding['context'] ?? '', $_SERVER);
        if (($binding['mode'] ?? null) === 'cart') { $binding['selectionFieldName'] ??= $config['selection_field_name'] ?? ''; }
        $binding['nonce'] = self::nonce($session, $config['evidence'], isset($config['clock']) ? (int)($config['clock'])() : null);
        $binding['regions'] = $config['regions'];
        $binding['availabilityUrl'] = $config['availability_url'];
        $json = json_encode($binding, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $asset = htmlspecialchars($config['asset_url'], ENT_QUOTES, 'UTF-8');
        return '<script src="' . $asset . '/dn-progressive-search.js"></script><script src="' . $asset
            . '/number-picker.js"></script><script>DNNumberPickerMount(' . $json . ');</script>';
    }

    public static function subscriptionPicker(array $config, array &$session, string $serviceId): string
    {
        $id = bin2hex($serviceId);
        return self::picker($config, $session, ['context' => 'update-' . $serviceId, 'mode' => 'subscription',
            'targetId' => 'dn-number-picker-target-' . $id, 'fieldId' => 'dn-number-to-add-' . $id,
            'rangeId' => 'dn-number-range-' . $id, 'submitId' => 'dn-number-submit-' . $id]);
    }
}