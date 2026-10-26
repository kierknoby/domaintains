<?php
declare(strict_types=1);

namespace Domaintains\Byot;

final class Runtime
{
    public readonly ServiceRegistry $registry;
    public readonly ActivationProvider $provider;
    public readonly StateStore $store;
    private readonly Orchestrator $orchestrator;

    public function __construct(array $configuration, ?BillingAdapter $billing = null, ?NumberProvider $numbers = null,
        ?PbxEndpointResolver $endpoints = null, ?SipEdgeProvisioner $inbound = null, ?SipEdgeProvisioner $outbound = null,
        ?DnsAdapter $dns = null, ?ServiceRegistry $registry = null)
    {
        $path = $configuration['private_state_dir'] ?? null;
        if (!is_string($path) || str_starts_with($path . '/', dirname(__DIR__) . '/public/')) {
            throw new \RuntimeException('Private state must be outside the web root.');
        }
        $this->store = new StateStore($path);
        $this->registry = $registry ?? new LocalRegistry($this->store, $billing ?? new LocalBilling($configuration['billing'] ?? []), $configuration['plans'] ?? []);
        $orchestrator = new Orchestrator($this->store, $this->registry, $numbers ?? new SimulatedNumbers($this->store),
            $endpoints ?? new ConfiguredEndpointResolver($configuration['destinations'] ?? []),
            $inbound ?? new SimulatedEdge($this->store, 'inbound'), $outbound ?? new SimulatedEdge($this->store, 'outbound'),
            $configuration['policies'] ?? [], $dns ?? (($configuration['simulate_dns'] ?? false) === true ? new SimulatedDns($this->store) : null));
        $this->orchestrator = $orchestrator;
        $this->provider = new ActivationProvider($configuration, new ReplayStore($path), $this->registry, $orchestrator);
    }

    public function order(string $account, string $pbx, string $service, array $voices, int $now): array
    {
        return $this->registry->link($account, $pbx, $service, $voices, $now, false,
            fn(array $entitlement) => $this->orchestrator->prepareOrder($service, $account, $entitlement));
    }

    public static function loadConfiguration(): array
    {
        $path = getenv('DOMAINTAINS_BYOT_CONFIG');
        if (!is_string($path) || !str_starts_with($path, '/') || !is_file($path) || is_link($path) || !is_readable($path)) {
            throw new \RuntimeException('Private configuration unavailable.');
        }
        $stat = lstat($path);
        $real = realpath($path);
        if ($stat === false || ($stat['mode'] & 0077) !== 0 || $stat['nlink'] !== 1 || $real === false
            || str_starts_with($real, dirname(__DIR__) . '/public/')) {
            throw new \RuntimeException('Private configuration permissions or location rejected.');
        }
        $configuration = require $path;
        if (!is_array($configuration)) { throw new \RuntimeException('Invalid private configuration.'); }
        return $configuration;
    }
}