<?php
declare(strict_types=1);

namespace Domaintains\Byot;

final class Orchestrator
{
    public function __construct(
        private readonly StateStore $store,
        private readonly ServiceRegistry $registry,
        private readonly NumberProvider $numbers,
        private readonly PbxEndpointResolver $endpoints,
        private readonly SipEdgeProvisioner $inbound,
        private readonly SipEdgeProvisioner $outbound,
        private readonly array $policies,
        private readonly ?DnsAdapter $dns = null,
    ) {}

    public function prepareOrder(string $service, string $account, array $entitlement): void
    {
        $this->store->transaction('order-' . hash('sha256', $service), function (array $operation, callable $save) use ($service, $account, $entitlement): void {
            $operation = ['service' => $service, 'attempt' => ($operation['attempt'] ?? 0) + 1, 'status' => 'running', 'steps' => []];
            $save($operation);
            try {
                $policy = $this->policy($service, $entitlement);
                foreach ($entitlement['numbers'] as $number) {
                    foreach (['allocate', 'activate', 'destination', 'verify'] as $step) {
                        $operation['steps']['number'] = ['number' => $number, 'step' => $step, 'status' => 'running'];
                        $save($operation);
                        $outcome = match ($step) {
                            'allocate' => $this->numbers->ensureAllocated($number, $account, $service),
                            'activate' => $this->numbers->ensureActive($number, $service),
                            'destination' => $this->numbers->ensureDestination($number, $service, $policy['inbound']['delivery']),
                            'verify' => $this->numbers->verifyDestination($number, $service, $policy['inbound']['delivery']),
                        };
                        if ($step === 'verify' && ($outcome['verified'] ?? false) !== true) { throw new ProviderException(503, 'Order verification failed.'); }
                    }
                }
                $operation['steps']['number']['status'] = 'complete';
                $operation['verified_numbers'] = $entitlement['numbers'];
                $operation['status'] = 'complete';
                $save($operation);
            } catch (\Throwable $error) {
                $operation['status'] = 'failed';
                $save($operation);
                throw new ProviderException(503, 'Order prerequisites could not be verified.');
            }
        });
    }

    public function ensure(string $service, string $publicKey, array $record, array $response, int $now): void
    {
        $this->store->transaction('operation-' . hash('sha256', $service), function (array $operation, callable $save) use ($service, $publicKey, $record, $response, $now): void {
            $started = hrtime(true);
            $clock = static fn(): int => $now + (int)((hrtime(true) - $started) / 1000000000);
            $operation = ['service' => $service, 'revision' => $record['revision'], 'attempt' => ($operation['attempt'] ?? 0) + 1,
                'status' => 'running', 'steps' => [], 'audit' => array_slice($operation['audit'] ?? [], -127)];
            $operation['audit'][] = ['event' => 'started', 'revision' => $record['revision'], 'at' => $now];
            $save($operation);
            $step = function (string $name, callable $action) use (&$operation, $save, $service, $record, $clock): array {
                $this->registry->verifySnapshot($service, $record, $clock());
                $operation['steps'][$name] = ['status' => 'running'];
                $save($operation);
                $result = $action();
                if (!is_array($result) || !in_array($result['status'] ?? null, ['simulated', 'configured', 'verified-config', 'call-tested'], true)) {
                    throw new ProviderException(503, 'Adapter outcome is invalid.');
                }
                $operation['steps'][$name] = ['status' => 'complete', 'outcome' => $result];
                $save($operation);
                return $result;
            };
            $verified = static function (array $outcome): array {
                if (($outcome['verified'] ?? false) !== true) { throw new ProviderException(503, 'Prerequisite verification failed.'); }
                return $outcome;
            };
            try {
                $policy = $this->policy($service, $response);
                $destination = $this->endpoints->resolveAndAuthoriseDestination($service, $publicKey, $record);
                $step('endpoint', static fn(): array => ['status' => 'configured', 'verified' => true]);
                if ($this->dns !== null) {
                    $step('dns.ensure', fn(): array => $this->dns->ensureRecord($service, $destination));
                    $step('dns.verify', fn(): array => $verified($this->dns->verifyRecord($service, $destination)));
                }
                foreach (['allocate', 'activate', 'destination', 'verify'] as $action) {
                    $step('numbers.' . $action, function () use ($action, $response, $record, $service, $policy, $verified): array {
                        $assurance = $action === 'verify' ? 'verified-config' : 'configured';
                        foreach ($response['numbers'] as $number) {
                            $result = match ($action) {
                                'allocate' => $this->numbers->ensureAllocated($number, $record['account'], $service),
                                'activate' => $this->numbers->ensureActive($number, $service),
                                'destination' => $this->numbers->ensureDestination($number, $service, $policy['inbound']['delivery']),
                                'verify' => $verified($this->numbers->verifyDestination($number, $service, $policy['inbound']['delivery'])),
                            };
                            if (!in_array($result['status'] ?? null, ['simulated', 'configured', 'verified-config', 'call-tested'], true)) { throw new ProviderException(503, 'Invalid numbering outcome.'); }
                            if ($result['status'] === 'simulated') { $assurance = 'simulated'; }
                        }
                        return ['status' => $assurance, 'verified' => $action === 'verify', 'count' => count($response['numbers'])];
                    });
                }
                $step('numbers.cleanup', fn(): array => $this->numbers->removeOwnedDestinations($service, $response['numbers']));
                foreach (['inbound' => $this->inbound, 'outbound' => $this->outbound] as $role => $edge) {
                    $plan = ['destination' => $destination, 'numbers' => $response['numbers'], 'max_channels' => $response['max_channels'], 'policy' => $policy[$role]];
                    $step($role . '.trunk', fn(): array => $edge->ensurePbxTrunk($service, $plan));
                    $routes = $step($role . '.routes', fn(): array => $role === 'inbound'
                        ? $edge->ensureInboundNumbers($service, $plan) : $edge->ensureOutboundRoutes($service, $plan));
                    $step($role . '.cleanup', fn(): array => $edge->removeOwned($service, $routes['stale'] ?? []));
                    $step($role . '.apply', fn(): array => $edge->apply($service));
                    $step($role . '.verify', fn(): array => $verified($edge->verify($service, $plan)));
                }
                $this->registry->verifySnapshot($service, $record, $clock());
                $operation['status'] = 'complete';
                $operation['assurance'] = count(array_filter($operation['steps'], static fn(array $step): bool => ($step['outcome']['status'] ?? null) === 'simulated')) > 0 ? 'simulated' : 'verified-config';
                $operation['call_tested'] = false;
                $operation['audit'][] = ['event' => 'complete', 'revision' => $record['revision'], 'at' => $clock()];
                $save($operation);
            } catch (\Throwable $error) {
                $operation['status'] = 'failed';
                $operation['audit'][] = ['event' => 'failed', 'revision' => $record['revision'], 'at' => $clock()];
                foreach ($operation['steps'] as &$outcome) {
                    if ($outcome['status'] === 'running') { $outcome['status'] = 'failed'; }
                }
                unset($outcome);
                $save($operation);
                throw new ProviderException($error instanceof ProviderException && $error->httpStatus === 403 ? 403 : 503,
                    'Provider prerequisites could not be verified.');
            }
        });
    }

    private function policy(string $service, array $response): array
    {
        $policy = $this->policies[$service] ?? null;
        if (!is_array($policy)) { throw new ProviderException(500, 'Explicit edge policy is required.'); }
        foreach (['inbound', 'outbound'] as $role) {
            $value = $policy[$role] ?? [];
            if (($value['auth'] ?? null) !== 'ip' || ($value['registration'] ?? null) !== 'none'
                || !is_string($value['context'] ?? null) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/D', $value['context'])) {
                throw new ProviderException(500, 'Explicit SIP trust policy is required.');
            }
            $edges = array_values(array_filter($response['trunks'], static fn(array $trunk): bool => $trunk['role'] === $role));
            if (count($edges) !== 1) { throw new ProviderException(500, 'Reference orchestration requires one edge per role.'); }
            if ($role === 'inbound') {
                $delivery = $value['delivery'] ?? [];
                if (($delivery['sip_host'] ?? null) !== $edges[0]['sip_host'] || ($delivery['sip_port'] ?? null) !== $edges[0]['sip_port']
                    || !in_array($delivery['transport'] ?? null, ['udp', 'tcp', 'tls'], true)) {
                    throw new ProviderException(500, 'Carrier delivery must target the authorised inbound edge.');
                }
            } else {
                $carrier = $value['carrier'] ?? [];
                if (!is_string($carrier['id'] ?? null) || $carrier['id'] === '' || !is_string($carrier['sip_host'] ?? null)
                    || !ActivationProvider::validSipHost($carrier['sip_host']) || !is_int($carrier['sip_port'] ?? null)
                    || $carrier['sip_port'] < 1 || $carrier['sip_port'] > 65535 || !in_array($carrier['transport'] ?? null, ['udp', 'tcp', 'tls'], true)
                    || !is_array($value['patterns'] ?? null) || !array_is_list($value['patterns']) || count($value['patterns']) < 1 || count($value['patterns']) > 128) {
                    throw new ProviderException(500, 'Explicit carrier and calling policy are required.');
                }
                foreach ($value['patterns'] as $pattern) {
                    if (!is_string($pattern) || strlen($pattern) > 64 || !preg_match('/^(?:[0-9XZN*#+]|\[[0-9XZN-]+\])+[.!]?$/D', $pattern)) {
                        throw new ProviderException(500, 'Invalid operator calling policy.');
                    }
                }
            }
        }
        return $policy;
    }
}