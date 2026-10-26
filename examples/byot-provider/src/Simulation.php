<?php
declare(strict_types=1);

namespace Domaintains\Byot;

final class SimulatedNumbers implements NumberProvider
{
    public function __construct(private readonly StateStore $store, private readonly array $failures = []) {}

    public function ensureAllocated(string $number, string $account, string $service): array
    {
        return $this->change('allocate', $number, $service, static function (array $resource) use ($account, $service): array {
            if ($resource !== [] && ($resource['account'] !== $account || $resource['service'] !== $service)) {
                throw new ProviderException(409, 'Number is assigned to another service.');
            }
            return $resource ?: ['account' => $account, 'service' => $service, 'active' => false, 'destination' => null];
        }, true);
    }

    public function ensureActive(string $number, string $service): array
    {
        return $this->change('activate', $number, $service, static function (array $resource): array {
            $resource['active'] = true;
            return $resource;
        });
    }

    public function ensureDestination(string $number, string $service, array $destination): array
    {
        return $this->change('destination', $number, $service, static function (array $resource) use ($destination): array {
            if (!$resource['active']) { throw new ProviderException(409, 'Number is not active.'); }
            $resource['destination'] = $destination;
            return $resource;
        });
    }

    public function verifyDestination(string $number, string $service, array $destination): array
    {
        $this->fault('verify');
        $resource = $this->store->read('simulated-numbers')['numbers']['n' . $number] ?? null;
        if (!is_array($resource) || $resource['service'] !== $service || !$resource['active'] || $resource['destination'] !== $destination) {
            throw new ProviderException(503, 'Number destination verification failed.');
        }
        return ['status' => 'simulated', 'verified' => true];
    }

    public function removeOwnedDestinations(string $service, array $numbers): array
    {
        $this->fault('cleanup');
        return $this->store->transaction('simulated-numbers', static function (array $state, callable $save) use ($service, $numbers): array {
            $changed = false;
            foreach ($state['numbers'] ?? [] as $key => $resource) {
                if ($resource['service'] === $service && !in_array(substr($key, 1), $numbers, true) && $resource['destination'] !== null) {
                    $state['numbers'][$key]['destination'] = null;
                    $changed = true;
                }
            }
            if ($changed) { $save($state); }
            return ['status' => 'simulated', 'verified' => true, 'changed' => $changed];
        });
    }

    private function change(string $step, string $number, string $service, callable $operation, bool $create = false): array
    {
        $this->fault($step);
        return $this->store->transaction('simulated-numbers', static function (array $state, callable $save) use ($number, $service, $operation, $create): array {
            $key = 'n' . $number;
            $before = $state['numbers'][$key] ?? [];
            if ((!$create && $before === []) || ($before !== [] && ($before['service'] ?? null) !== $service)) {
                throw new ProviderException(409, 'Number ownership rejected.');
            }
            $after = $operation($before);
            if ($before !== $after) {
                $state['numbers'][$key] = $after;
                $save($state);
            }
            return ['status' => 'simulated', 'verified' => false, 'changed' => $before !== $after];
        });
    }

    private function fault(string $step): void
    {
        if (in_array('number.' . $step, $this->failures, true)) { throw new ProviderException(503, 'Simulated numbering failure.'); }
    }
}

final class ConfiguredEndpointResolver implements PbxEndpointResolver
{
    public function __construct(private readonly array $destinations, private readonly array $failures = []) {}

    public function resolveAndAuthoriseDestination(string $service, string $publicKey, array $record): array
    {
        $destination = $this->destinations[$service] ?? null;
        if (in_array('endpoint.resolve', $this->failures, true) || !is_array($destination)
            || ($destination['account'] ?? null) !== $record['account'] || ($destination['pbx'] ?? null) !== $record['pbx']
            || (isset($destination['public_key']) && !hash_equals($destination['public_key'], $publicKey))
            || !is_string($destination['sip_host'] ?? null) || !ActivationProvider::validSipHost($destination['sip_host'])
            || !is_int($destination['sip_port'] ?? null) || $destination['sip_port'] < 1 || $destination['sip_port'] > 65535
            || !in_array($destination['transport'] ?? null, ['udp', 'tcp', 'tls'], true)) {
            throw new ProviderException(403, 'PBX destination not authorised.');
        }
        return ['sip_host' => $destination['sip_host'], 'sip_port' => $destination['sip_port'], 'transport' => $destination['transport']];
    }
}

final class SimulatedDns implements DnsAdapter
{
    public function __construct(private readonly StateStore $store, private readonly array $failures = []) {}

    public function ensureRecord(string $service, array $destination): array
    {
        if (in_array('dns.ensure', $this->failures, true)) { throw new ProviderException(503, 'Simulated DNS failure.'); }
        return $this->store->transaction('simulated-dns', static function (array $state, callable $save) use ($service, $destination): array {
            $changed = ($state[$service] ?? null) !== $destination;
            if ($changed) { $state[$service] = $destination; $save($state); }
            return ['status' => 'simulated', 'verified' => false, 'changed' => $changed];
        });
    }

    public function verifyRecord(string $service, array $destination): array
    {
        if (in_array('dns.verify', $this->failures, true) || ($this->store->read('simulated-dns')[$service] ?? null) !== $destination) {
            throw new ProviderException(503, 'DNS verification failed.');
        }
        return ['status' => 'simulated', 'verified' => true];
    }
}

final class SimulatedEdge implements SipEdgeProvisioner
{
    private readonly string $key;

    public function __construct(private readonly StateStore $store, private readonly string $role, private readonly array $failures = [])
    {
        if (!in_array($role, ['inbound', 'outbound'], true)) { throw new \InvalidArgumentException('Invalid edge role.'); }
        $this->key = 'simulated-edge-' . $role;
    }

    public function ensurePbxTrunk(string $service, array $plan): array
    {
        return $this->ensure('trunk', $service, ['trunk:' . $service => [
            'destination' => $plan['destination'], 'max_channels' => $plan['max_channels'],
            'auth' => $plan['policy']['auth'], 'registration' => $plan['policy']['registration'],
            'context' => $plan['policy']['context'], 'role' => $this->role,
        ]]);
    }

    public function ensureInboundNumbers(string $service, array $plan): array
    {
        if ($this->role !== 'inbound') { throw new ProviderException(500, 'Wrong edge role.'); }
        $desired = [];
        foreach ($plan['numbers'] as $number) { $desired['did:' . $number] = ['number' => $number, 'trunk' => 'trunk:' . $service]; }
        return $this->ensure('numbers', $service, $desired, 'did:');
    }

    public function ensureOutboundRoutes(string $service, array $plan): array
    {
        if ($this->role !== 'outbound') { throw new ProviderException(500, 'Wrong edge role.'); }
        return $this->ensure('routes', $service, ['route:' . $service => [
            'trunk' => 'trunk:' . $service, 'carrier' => $plan['policy']['carrier'],
            'patterns' => $plan['policy']['patterns'], 'max_channels' => $plan['max_channels'],
        ]], 'route:');
    }

    public function apply(string $service): array
    {
        $this->fault('apply');
        return $this->store->transaction($this->key, static function (array $state, callable $save) use ($service): array {
            $changed = ($state['dirty'][$service] ?? false) === true;
            if ($changed) {
                $state['dirty'][$service] = false;
                $state['applies'][$service] = ($state['applies'][$service] ?? 0) + 1;
                $save($state);
            }
            return ['status' => 'simulated', 'verified' => false, 'changed' => $changed];
        });
    }

    public function verify(string $service, array $plan): array
    {
        $this->fault('verify');
        $state = $this->store->read($this->key);
        $expected = ['trunk:' . $service => [
            'destination' => $plan['destination'], 'max_channels' => $plan['max_channels'],
            'auth' => $plan['policy']['auth'], 'registration' => $plan['policy']['registration'],
            'context' => $plan['policy']['context'], 'role' => $this->role,
        ]];
        if ($this->role === 'inbound') {
            foreach ($plan['numbers'] as $number) { $expected['did:' . $number] = ['number' => $number, 'trunk' => 'trunk:' . $service]; }
        } else {
            $expected['route:' . $service] = ['trunk' => 'trunk:' . $service, 'carrier' => $plan['policy']['carrier'],
                'patterns' => $plan['policy']['patterns'], 'max_channels' => $plan['max_channels']];
        }
        if ($state['dirty'][$service] ?? false) { throw new ProviderException(503, 'Edge changes have not been applied.'); }
        foreach ($state['objects'] ?? [] as $id => $object) {
            if (($object['owner'] ?? null) === $service && !isset($expected[$id])) { throw new ProviderException(503, 'Stale edge resource remains.'); }
        }
        foreach ($expected as $id => $spec) {
            $object = $state['objects'][$id] ?? [];
            $this->owned($object, $service);
            if ($object['spec'] !== $spec) { throw new ProviderException(503, 'Edge configuration verification failed.'); }
        }
        return ['status' => 'simulated', 'verified' => true, 'resources' => array_keys($expected)];
    }

    public function removeOwned(string $service, array $identities): array
    {
        $this->fault('cleanup');
        return $this->store->transaction($this->key, function (array $state, callable $save) use ($service, $identities): array {
            foreach ($identities as $id) {
                $this->owned($state['objects'][$id] ?? [], $service);
                unset($state['objects'][$id]);
            }
            if ($identities !== []) { $state['dirty'][$service] = true; $save($state); }
            return ['status' => 'simulated', 'verified' => false, 'changed' => $identities !== []];
        });
    }

    private function ensure(string $step, string $service, array $desired, string $prefix = ''): array
    {
        $this->fault($step);
        return $this->store->transaction($this->key, function (array $state, callable $save) use ($service, $desired, $prefix): array {
            $changed = false;
            $stale = [];
            foreach ($state['objects'] ?? [] as $id => $object) {
                if ($prefix !== '' && str_starts_with($id, $prefix) && ($object['owner'] ?? null) === $service && !isset($desired[$id])) {
                    $this->owned($object, $service);
                    $stale[] = $id;
                }
            }
            foreach ($desired as $id => $spec) {
                $previous = $state['objects'][$id] ?? null;
                if ($previous !== null) { $this->owned($previous, $service); }
                $object = ['owner' => $service, 'spec' => $spec, 'fingerprint' => hash('sha256', json_encode($spec, JSON_THROW_ON_ERROR))];
                if ($object !== $previous) { $state['objects'][$id] = $object; $changed = true; }
            }
            if ($changed) { $state['dirty'][$service] = true; $save($state); }
            return ['status' => 'simulated', 'verified' => false, 'changed' => $changed, 'resources' => array_keys($desired), 'stale' => $stale];
        });
    }

    private function owned(array $object, string $service): void
    {
        if (($object['owner'] ?? null) !== $service || !isset($object['spec'], $object['fingerprint'])
            || !hash_equals($object['fingerprint'], hash('sha256', json_encode($object['spec'], JSON_THROW_ON_ERROR)))) {
            throw new ProviderException(409, 'Unmanaged or modified edge resource.');
        }
    }

    private function fault(string $step): void
    {
        if (in_array($this->role . '.' . $step, $this->failures, true)) { throw new ProviderException(503, 'Simulated SIP edge failure.'); }
    }
}