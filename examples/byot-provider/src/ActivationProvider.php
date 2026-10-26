<?php
declare(strict_types=1);

namespace Domaintains\Byot;

final class ProviderException extends \RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message)
    {
        parent::__construct($message);
    }
}

/** Reference implementation of the compatible DOMAINTAINS activation wire contract. */
final class ActivationProvider
{
    private const MAX_RESPONSE_BYTES = 1048576;
    private const MAX_TRUNKS = 64;
    private const MAX_NUMBERS = 10000;
    private const MAX_DESCRIPTION_BYTES = 1024;

    public function __construct(private readonly array $configuration, private readonly ReplayStore $replayStore,
        private readonly ?ServiceRegistry $registry = null, private readonly ?Orchestrator $orchestrator = null)
    {
    }

    /** @return array{0:int,1:array<string,mixed>} HTTP status and JSON response */
    public function process(array $request, ?int $now = null): array
    {
        try {
            if (strlen(json_encode($request, JSON_THROW_ON_ERROR)) > 16384) {
                throw new ProviderException(413, 'Activation request too large.');
            }
            return [200, $this->activate($request, $now ?? time())];
        } catch (ProviderException $e) {
            return [$e->httpStatus, ['ok' => false, 'message' => $e->getMessage()]];
        } catch (\Throwable $e) {
            // Never expose private configuration, tokens or implementation details.
            return [500, ['ok' => false, 'message' => 'Provider activation unavailable.']];
        }
    }

    private function activate(array $request, int $now): array
    {
        $this->replayStore->throttle($now);
        if (($request['action'] ?? null) !== 'activate' || !isset($request['claim']) || !is_array($request['claim'])
            || !isset($request['signature']) || !is_string($request['signature'])) {
            throw new ProviderException(400, 'Invalid activation request.');
        }
        $claim = $request['claim'];
        foreach (['token', 'public_key', 'nonce', 'service'] as $key) {
            if (!isset($claim[$key]) || !is_string($claim[$key]) || $claim[$key] === '') {
                throw new ProviderException(400, 'Activation claim incomplete.');
            }
        }
        if (!isset($claim['timestamp']) || !is_int($claim['timestamp'])) {
            throw new ProviderException(400, 'Activation timestamp invalid.');
        }
        $token = $claim['token'];
        $keyEncoded = $claim['public_key'];
        $nonce = $claim['nonce'];
        $service = $claim['service'];
        $timestamp = $claim['timestamp'];
        $signature = $request['signature'];
        $maxAge = $this->configuration['max_request_age'] ?? 300;
        if (!is_int($maxAge) || $maxAge < 30 || $maxAge > 3600) {
            throw new ProviderException(500, 'Invalid provider configuration.');
        }
        if (strlen($token) > 512 || strlen($keyEncoded) > 128 || strlen($nonce) > 64 || strlen($signature) > 128
            || strlen($service) > 253 || $timestamp > $now + 5 || $timestamp < $now - $maxAge) {
            throw new ProviderException(400, 'Activation claim invalid or expired.');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $service)) {
            throw new ProviderException(400, 'Unsupported service identity.');
        }
        $key = base64_decode($keyEncoded, true);
        $nonceBytes = base64_decode($nonce, true);
        $signatureBytes = base64_decode($signature, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $nonceBytes === false || strlen($nonceBytes) !== 24
            || $signatureBytes === false || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES
            || base64_encode($key) !== $keyEncoded || base64_encode($nonceBytes) !== $nonce || base64_encode($signatureBytes) !== $signature) {
            throw new ProviderException(400, 'Invalid signature data.');
        }
        // Exact canonical key order expected by the DOMAINTAINS activation protocol.
        $canonical = json_encode([
            'token' => $token,
            'public_key' => $keyEncoded,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'service' => $service,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!sodium_crypto_sign_verify_detached($signatureBytes, $canonical, $key)) {
            throw new ProviderException(403, 'Activation signature rejected.');
        }

        $services = $this->configuration['services'] ?? [];
        $record = $this->registry !== null ? $this->registry->authorise($service, hash('sha256', $token), $now) : ($services[$service] ?? null);
        if (!is_array($record)) {
            throw new ProviderException(403, 'Activation not authorised.');
        }
        if (($this->registry === null && ($record['enabled'] ?? false) !== true) || !isset($record['token_sha256'])
            || !is_string($record['token_sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $record['token_sha256'])
            || !hash_equals($record['token_sha256'], hash('sha256', $token))) {
            throw new ProviderException(403, 'Activation not authorised.');
        }
        // A static example may set an expiry. External billing integrations must
        // independently check authoritative status and revocation on each request.
        if (array_key_exists('expires_at', $record) && $record['expires_at'] !== null) {
            if (!is_int($record['expires_at']) || $now > $record['expires_at']) {
                throw new ProviderException(403, 'Activation not authorised.');
            }
        }
        $response = $this->authorisedConfiguration($service, $record);
        // Reject oversized entitlements BEFORE pinning a key or consuming a nonce.
        if (strlen(json_encode(['ok' => true, 'message' => 'DOMAINTAINS activated'] + $response,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) > self::MAX_RESPONSE_BYTES) {
            throw new ProviderException(500, 'Provider response exceeds the supported limit.');
        }
        $pin = $record['public_key'] ?? '';
        if (!is_string($pin)) {
            throw new ProviderException(500, 'Invalid provider configuration.');
        }
        $this->replayStore->consume($service, $nonce, $keyEncoded, $now, $maxAge, $pin);
        if ($this->registry !== null) {
            if ($this->orchestrator === null) { throw new ProviderException(503, 'Provider orchestration unavailable.'); }
            $this->orchestrator->ensure($service, $keyEncoded, $record, $response, $now);
            $this->registry->verifySnapshot($service, $record, $now);
        }
        return ['ok' => true, 'message' => 'DOMAINTAINS activated'] + $response;
    }

    /** @return array<string,mixed> */
    private function authorisedConfiguration(string $service, array $record): array
    {
        foreach (['profile', 'domaintains_hostname'] as $field) {
            if (!isset($record[$field]) || !is_string($record[$field]) || trim($record[$field]) === ''
                || strlen($record[$field]) > self::MAX_DESCRIPTION_BYTES
                || preg_match('/[\x00-\x1F\x7F]/', $record[$field])) {
                throw new ProviderException(500, 'Invalid provider service configuration.');
            }
        }
        $channels = $record['max_channels'] ?? null;
        if (!is_int($channels) || $channels < 1 || $channels > 500) {
            throw new ProviderException(500, 'Invalid provider service configuration.');
        }
        $numbers = $record['numbers'] ?? null;
        if (!is_array($numbers) || array_values($numbers) !== $numbers || count($numbers) > self::MAX_NUMBERS
            || count($numbers) !== count(array_unique($numbers, SORT_REGULAR))) {
            throw new ProviderException(500, 'Invalid provider service configuration.');
        }
        foreach ($numbers as $number) {
            if (!is_string($number) || !preg_match('/^[0-9]{1,32}$/D', $number)) {
                throw new ProviderException(500, 'Invalid provider service configuration.');
            }
        }
        $trunks = $record['trunks'] ?? null;
        if (!is_array($trunks) || count($trunks) < 1 || count($trunks) > self::MAX_TRUNKS
            || array_values($trunks) !== $trunks) {
            throw new ProviderException(500, 'Invalid provider service configuration.');
        }
        $seen = [];
        foreach ($trunks as $trunk) {
            if (!is_array($trunk) || !isset($trunk['role'], $trunk['sip_host'], $trunk['sip_port'])
                || !in_array($trunk['role'], ['inbound', 'outbound'], true)
                || !is_string($trunk['sip_host']) || !self::validSipHost($trunk['sip_host'])
                || !is_int($trunk['sip_port']) || $trunk['sip_port'] < 1 || $trunk['sip_port'] > 65535) {
                throw new ProviderException(500, 'Invalid provider service configuration.');
            }
            $identity = json_encode([$trunk['role'], $trunk['sip_host'], $trunk['sip_port']]);
            if (isset($seen[$identity])) {
                throw new ProviderException(500, 'Invalid provider service configuration.');
            }
            $seen[$identity] = true;
        }
        return [
            'service' => $service,
            'profile' => $record['profile'],
            'domaintains_hostname' => $record['domaintains_hostname'],
            'numbers' => $numbers,
            'max_channels' => $channels,
            'trunks' => $trunks,
        ];
    }

    /** Validate an address literal or a DNS name before it reaches PJSIP. */
    public static function validSipHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 254 || preg_match('/[\x00-\x20\x7F]/', $host)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
            return true;
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }
        if ($host === '' || str_ends_with($host, '.') || strlen($host) > 253) {
            return false;
        }
        if (preg_match('/^[0-9.]+$/D', $host)) {
            return false;
        }
        foreach (explode('.', $host) as $label) {
            if (strlen($label) > 63 || !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/D', $label)) {
                return false;
            }
        }
        return true;
    }

}
