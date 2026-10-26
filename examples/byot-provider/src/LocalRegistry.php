<?php
declare(strict_types=1);

namespace Domaintains\Byot;

final class LocalBilling implements BillingAdapter
{
    public function __construct(private readonly array $records) {}

    public function pbx(string $account, string $pbx): array
    {
        return $this->owned('pbx', $account, $pbx);
    }

    public function voice(string $account, string $voice): array
    {
        return $this->owned('voice', $account, $voice);
    }

    private function owned(string $kind, string $account, string $id): array
    {
        $record = $this->records[$kind][$id] ?? null;
        if (!is_array($record) || ($record['account'] ?? null) !== $account || ($record['status'] ?? null) !== 'active') {
            throw new ProviderException(403, 'Service ownership or status rejected.');
        }
        return $record;
    }
}

final class LocalRegistry implements ServiceRegistry
{
    public function __construct(private readonly StateStore $store, private readonly BillingAdapter $billing, private readonly array $plans) {}

    public function link(string $account, string $pbx, string $service, array $voices, int $now, bool $rotate = false, ?callable $beforeIssue = null): array
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $service) || $account === '' || $pbx === ''
            || $voices === [] || !array_is_list($voices) || count($voices) > 100) {
            throw new ProviderException(400, 'Invalid service link.');
        }
        foreach ($voices as $voice) {
            if (!is_string($voice) || $voice === '') { throw new ProviderException(400, 'Invalid voice service.'); }
        }
        $voices = array_values(array_unique($voices));
        sort($voices, SORT_STRING);
        $entitlement = $this->entitlement($account, $pbx, $service, $voices);
        return $this->store->transaction('registry', function (array $state, callable $save) use ($account, $pbx, $service, $voices, $now, $rotate, $entitlement, $beforeIssue): array {
            $services = $state['services'] ?? [];
            foreach ($services as $otherService => $other) {
                if (($otherService === $service && ($other['account'] !== $account || $other['pbx'] !== $pbx))
                    || ($otherService !== $service && $other['pbx'] === $pbx)
                    || ($otherService !== $service && array_intersect($voices, $other['voices']) !== [])) {
                    throw new ProviderException(409, 'Service is already linked.');
                }
            }
            $previous = $services[$service] ?? null;
            if ($previous !== null && $previous['status'] !== 'active' && !$rotate) {
                throw new ProviderException(403, 'Explicit reissue is required for an inactive service.');
            }
            if ($previous !== null && $previous['expires_at'] <= $now && !$rotate) {
                throw new ProviderException(403, 'Explicit reissue is required for an expired credential.');
            }
            if ($beforeIssue !== null) { $beforeIssue($entitlement); }
            $reuse = !$rotate && $previous !== null && $previous['expires_at'] > $now;
            $token = $reuse ? null : rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $record = $entitlement + [
                'account' => $account, 'pbx' => $pbx, 'voices' => $voices, 'status' => 'active',
                'revision' => ($previous['revision'] ?? 0) + 1,
                'token_sha256' => $reuse ? $previous['token_sha256'] : hash('sha256', $token),
                'expires_at' => $reuse ? $previous['expires_at'] : $now + 86400,
                'public_key' => $previous['public_key'] ?? '',
            ];
            $services[$service] = $record;
            $audit = array_slice($state['audit'] ?? [], -127);
            $audit[] = ['event' => $reuse ? 'linked' : 'credential-issued', 'service' => $service, 'revision' => $record['revision'], 'at' => $now];
            $save(['version' => 1, 'services' => $services, 'audit' => $audit]);
            return ['token' => $token, 'reused' => $reuse, 'expires_at' => $record['expires_at'], 'revision' => $record['revision']];
        });
    }

    public function setStatus(string $service, string $status): void
    {
        if (!in_array($status, ['active', 'paused', 'suspended', 'revoked', 'cancelled'], true)) {
            throw new ProviderException(400, 'Unsupported service status.');
        }
        $this->store->transaction('registry', static function (array $state, callable $save) use ($service, $status): void {
            if (!isset($state['services'][$service])) { throw new ProviderException(404, 'Unknown service.'); }
            if ($status === 'active' && in_array($state['services'][$service]['status'], ['revoked', 'cancelled'], true)) {
                throw new ProviderException(403, 'Revoked credentials require explicit rotation.');
            }
            $state['services'][$service]['status'] = $status;
            $state['services'][$service]['revision']++;
            $state['audit'] = array_slice($state['audit'] ?? [], -127);
            $state['audit'][] = ['event' => 'status-' . $status, 'service' => $service, 'revision' => $state['services'][$service]['revision'], 'at' => time()];
            $save($state);
        });
    }

    public function status(string $service): array
    {
        $record = $this->store->read('registry')['services'][$service] ?? null;
        if (!is_array($record)) { throw new ProviderException(404, 'Unknown service.'); }
        unset($record['token_sha256']);
        return $record;
    }

    public function authorise(string $service, string $tokenHash, int $now): array
    {
        $record = $this->store->read('registry')['services'][$service] ?? null;
        if (!is_array($record) || !hash_equals($record['token_sha256'], $tokenHash)) {
            throw new ProviderException(403, 'Activation not authorised.');
        }
        $this->verifySnapshot($service, $record, $now);
        return $record;
    }

    public function verifySnapshot(string $service, array $record, int $now): void
    {
        $current = $this->store->read('registry')['services'][$service] ?? null;
        if (!is_array($current) || $current['revision'] !== $record['revision'] || $current['status'] !== 'active'
            || !is_int($current['expires_at']) || $current['expires_at'] <= $now) {
            throw new ProviderException(403, 'Activation not authorised.');
        }
        if ($this->entitlement($current['account'], $current['pbx'], $service, $current['voices']) !== array_intersect_key($current, array_flip(['profile', 'domaintains_hostname', 'numbers', 'max_channels', 'trunks']))) {
            throw new ProviderException(403, 'Entitlements changed; relink the service.');
        }
    }

    private function entitlement(string $account, string $pbx, string $service, array $voices): array
    {
        $pbxRecord = $this->billing->pbx($account, $pbx);
        if (($pbxRecord['service'] ?? null) !== $service || !isset($this->plans[$service])) {
            throw new ProviderException(403, 'PBX identity is not linked to this service.');
        }
        $numbers = [];
        $channels = 0;
        foreach ($voices as $voice) {
            $record = $this->billing->voice($account, $voice);
            if (!isset($record['numbers'], $record['max_channels']) || !is_array($record['numbers']) || !array_is_list($record['numbers'])
                || !is_int($record['max_channels']) || $record['max_channels'] < 1 || $record['max_channels'] > 500) {
                throw new ProviderException(500, 'Invalid billing entitlements.');
            }
            $channels += $record['max_channels'];
            foreach ($record['numbers'] as $number) {
                if (!is_string($number) || !preg_match('/^[0-9]{1,32}$/D', $number)) {
                    throw new ProviderException(500, 'Invalid billing entitlements.');
                }
                $numbers['n' . $number] = $number;
            }
        }
        if ($channels > 500 || count($numbers) > 10000) { throw new ProviderException(500, 'Entitlements exceed supported limits.'); }
        $numbers = array_values($numbers);
        sort($numbers, SORT_STRING);
        $plan = $this->plans[$service];
        return ['profile' => $plan['profile'], 'domaintains_hostname' => $plan['domaintains_hostname'],
            'numbers' => $numbers, 'max_channels' => $channels, 'trunks' => $plan['trunks']];
    }
}