<?php
declare(strict_types=1);

namespace Domaintains\Byot;

interface EmergencyJsonTransport
{
    public function send(string $method, string $url, array $headers, ?array $body): array;
}

final class CurlEmergencyTransport implements EmergencyJsonTransport
{
    public function send(string $method, string $url, array $headers, ?array $body): array
    {
        $curl = curl_init($url);
        if ($curl === false) { throw new EmergencyFailure('unavailable'); }
        $bytes = '';
        try {
            curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bytes): int {
                    if (strlen($bytes) + strlen($chunk) > 65536) { return 0; }
                    $bytes .= $chunk;
                    return strlen($chunk);
                }]);
            if ($body !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR)); }
            if (curl_exec($curl) === false) { throw new EmergencyFailure('unavailable'); }
            $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $decoded = $bytes === '' ? [] : json_decode($bytes, true, 16, $code >= 200 && $code < 300 ? JSON_THROW_ON_ERROR : 0);
            if (($code < 200 || $code >= 300) && !is_array($decoded)) { $decoded = []; }
            if (!is_array($decoded)) { throw new EmergencyFailure('malformed'); }
            return ['http_status' => $code, 'body' => $decoded];
        } catch (\Throwable $error) {
            throw new EmergencyFailure('unavailable');
        } finally { curl_close($curl); }
    }
}

final class MappedEmergencyAdapter implements EmergencyRegistrationAdapter
{
    public function __construct(private readonly array $configuration, private readonly EmergencyJsonTransport $transport)
    {
        $url = parse_url($configuration['base_url'] ?? '');
        if (!is_array($url) || ($url['scheme'] ?? null) !== 'https' || !isset($url['host']) || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment']) || !filter_var($url['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new \InvalidArgumentException('Private HTTPS emergency API configuration required.');
        }
        foreach ($configuration['headers'] ?? [] as $header) {
            if (!is_string($header) || strlen($header) > 4096 || preg_match('/[\r\n\x00]/', $header)) { throw new \InvalidArgumentException('Invalid private API header.'); }
        }
        if ($this->minimumInterval() < 1 || $this->minimumInterval() > 86400) { throw new \InvalidArgumentException('Documented provider interval required.'); }
        EmergencyRateLimits::validate($this->rateLimits());
        if (array_diff($configuration['missing_http_statuses'] ?? [], [404, 410]) !== []) {
            throw new \InvalidArgumentException('Ambiguous missing responses require response-body conditions.');
        }
        $rules = $configuration['response_rules'] ?? [];
        if (!is_array($rules) || !array_is_list($rules) || count($rules) > 32) { throw new \InvalidArgumentException('Invalid response rules.'); }
        foreach ($rules as $rule) {
            if (!is_array($rule) || !is_int($rule['http_status'] ?? null) || $rule['http_status'] < 100 || $rule['http_status'] > 599
                || !is_array($rule['operations'] ?? null) || !array_is_list($rule['operations']) || $rule['operations'] === []
                || array_diff($rule['operations'], ['retrieve', 'submit', 'status']) !== []
                || !in_array($rule['outcome'] ?? null, ['unregistered', 'unauthorised', 'invalid_number', 'rejected', 'conflict', 'unavailable'], true)
                || !is_array($rule['when'] ?? null) || !array_is_list($rule['when']) || count($rule['when']) < 1 || count($rule['when']) > 8
                || ($rule['outcome'] === 'unregistered' && $rule['operations'] !== ['retrieve'])) {
                throw new \InvalidArgumentException('Invalid response condition.');
            }
            foreach ($rule['when'] as $condition) {
                if (!is_array($condition) || !is_array($condition['path'] ?? null) || !array_is_list($condition['path'])
                    || count($condition['path']) < 1 || count($condition['path']) > 8 || !array_key_exists('equals', $condition)
                    || (!is_scalar($condition['equals']) && $condition['equals'] !== null)) { throw new \InvalidArgumentException('Invalid response field condition.'); }
                foreach ($condition['path'] as $field) {
                    if (!is_string($field) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $field)) { throw new \InvalidArgumentException('Invalid response field path.'); }
                }
            }
        }
        $stages = $configuration['submission_stages'] ?? ['submit'];
        if (!is_array($stages) || !array_is_list($stages) || count($stages) < 1 || count($stages) > 8
            || (count($stages) > 1 && ($configuration['allow_stage_burst'] ?? false) !== true)) {
            throw new \InvalidArgumentException('Documented multi-stage rate allowance required.');
        }
    }

    public function minimumInterval(): int { return $this->configuration['minimum_interval'] ?? 60; }
    public function rateLimits(): array
    {
        return $this->configuration['rate_limits'] ?? throw new \InvalidArgumentException('Explicit provider rate limits required.');
    }
    public function supportsTypeChange(): bool { return ($this->configuration['supports_type_change'] ?? false) === true; }

    public function retrieve(EmergencyLease $lease): array { return $this->operation('retrieve', $lease); }
    public function status(EmergencyLease $lease): array { return $this->operation('status', $lease); }

    public function submit(EmergencyLease $lease, array $registration, string $revision, string $idempotencyKey): array
    {
        $partial = false;
        try {
            foreach ($this->configuration['submission_stages'] ?? ['submit'] as $stage) {
                if ($partial && $revision === '') { throw new EmergencyFailure('partial', true); }
                $result = EmergencyData::result($this->operation($stage, $lease, $registration, $revision, $idempotencyKey));
                if ($result['status'] === 'rejected') { return $result; }
                $partial = true;
                $revision = $result['revision'];
            }
            if (!isset($result)) { throw new EmergencyFailure('malformed'); }
            return $result;
        } catch (\Throwable $error) {
            if ($error instanceof EmergencyThrottle && !$partial) { throw $error; }
            if ($error instanceof ProviderException && (!$partial || $error->httpStatus === 403)) { throw $error; }
            $uncertain = $partial || !($error instanceof EmergencyFailure) || !in_array($error->reason, ['conflict', 'rejected', 'invalid_number'], true);
            throw new EmergencyFailure($uncertain ? 'partial' : $error->reason, $uncertain);
        }
    }

    private function operation(string $action, EmergencyLease $lease, ?array $registration = null, string $revision = '', string $idempotencyKey = ''): array
    {
        $spec = $this->configuration['operations'][$action] ?? null;
        if (!is_array($spec) || !in_array($spec['method'] ?? null, ['GET', 'POST', 'PUT', 'PATCH'], true)
            || !is_string($spec['path'] ?? null) || !preg_match('~^/[A-Za-z0-9_/{}/.-]+$~D', $spec['path'])
            || str_contains($spec['path'], '..') || !str_contains($spec['path'], '{number}')) { throw new EmergencyFailure('unavailable'); }
        return $lease->request(function (string $number, string $assignment) use ($action, $spec, $registration, $revision, $idempotencyKey): array {
            $url = rtrim($this->configuration['base_url'], '/') . str_replace('{number}', rawurlencode($number), $spec['path']);
            $headers = array_merge(['Accept: application/json', 'Content-Type: application/json'], $this->configuration['headers'] ?? []);
            $body = null;
            if ($registration !== null) {
                $body = [];
                foreach (EmergencyData::FIELDS as $field) {
                    $mapped = $this->configuration['fields'][$field] ?? null;
                    if (!is_string($mapped) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $mapped)) { throw new EmergencyFailure('unavailable'); }
                    $body[$mapped] = $registration[$field];
                }
                $headers[] = 'Idempotency-Key: ' . $idempotencyKey . '-' . $action;
                $headers[] = $revision === '' ? 'If-None-Match: *' : 'If-Match: ' . $revision;
            }
            $reply = $this->transport->send($spec['method'], $url, $headers, $body);
            $http = $reply['http_status'] ?? 0;
            if (!is_int($http) || $http < 100 || $http > 599 || !is_array($reply['body'] ?? null)) { throw new EmergencyFailure('malformed'); }
            if ($http === 401 || $http === 403) { throw new ProviderException(403, 'Emergency provider access denied.'); }
            $outcomes = [];
            $operation = $registration !== null ? 'submit' : $action;
            foreach ($this->configuration['response_rules'] ?? [] as $rule) {
                if ($rule['http_status'] !== $http || !in_array($operation, $rule['operations'], true)) { continue; }
                foreach ($rule['when'] as $condition) {
                    $value = $reply['body'];
                    foreach ($condition['path'] as $field) {
                        if (!is_array($value) || !array_key_exists($field, $value)) { continue 3; }
                        $value = $value[$field];
                    }
                    if ($value !== $condition['equals']) { continue 2; }
                }
                $outcomes[$rule['outcome']] = true;
            }
            if (count($outcomes) > 1) { throw new EmergencyFailure('malformed'); }
            $outcome = array_key_first($outcomes);
            if ($outcome === 'unauthorised') { throw new ProviderException(403, 'Emergency provider access denied.'); }
            if ($outcome === 'unregistered') { return ['status' => 'unregistered', 'revision' => '', 'registration' => null]; }
            if ($outcome !== null) { throw new EmergencyFailure($outcome); }
            if ($action === 'retrieve' && in_array($http, $this->configuration['missing_http_statuses'] ?? [], true)) {
                return ['status' => 'unregistered', 'revision' => '', 'registration' => null];
            }
            if ($http === 409 || $http === 412) { throw new EmergencyFailure('conflict'); }
            if ($http < 200 || $http >= 300 || !is_array($reply['body'] ?? null)) { throw new EmergencyFailure('unavailable', $registration !== null); }
            $value = $reply['body'];
            $remoteState = $value[$this->configuration['status_field'] ?? 'status'] ?? null;
            $state = is_string($remoteState) ? ($this->configuration['statuses'][$remoteState] ?? null) : null;
            if ($state === null) {
                if ($remoteState !== null) { throw new EmergencyFailure('malformed', $registration !== null); }
                $state = $this->configuration['acknowledgements'][$http] ?? null;
                if (!in_array($state, ['received', 'awaiting_validation', 'submitted', 'awaiting_acceptance'], true)) { throw new EmergencyFailure('malformed', $registration !== null); }
            }
            $fields = null;
            $remoteFields = $value[$this->configuration['registration_field'] ?? 'registration'] ?? null;
            if ($remoteFields !== null) {
                if (!is_array($remoteFields)) { throw new EmergencyFailure('malformed'); }
                $fields = [];
                foreach (EmergencyData::FIELDS as $field) { $fields[$field] = $remoteFields[$this->configuration['fields'][$field] ?? $field] ?? ''; }
            }
            return ['status' => $state, 'revision' => $value[$this->configuration['revision_field'] ?? 'revision'] ?? '', 'registration' => $fields];
        });
    }
}