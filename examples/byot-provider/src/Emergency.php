<?php
declare(strict_types=1);

namespace Domaintains\Byot;

final class CustomerIdentity
{
    public function __construct(public readonly string $account, public readonly string $subject, public readonly int $expiresAt)
    {
        if ($account === '' || $subject === '') { throw new \InvalidArgumentException('Authenticated customer required.'); }
    }
}

final class EmergencyLease
{
    private bool $active = true;
    private ?\Closure $limiter = null;

    public function __construct(private readonly string $number, private readonly \Closure $guard, public readonly string $assignment, public readonly string $rateNumber = '')
    {
    }

    public function limit(\Closure $limiter): void { $this->limiter = $limiter; }

    public function request(callable $request): mixed
    {
        if (!$this->active) { throw new ProviderException(403, 'Emergency access not authorised.'); }
        ($this->guard)();
        if ($this->limiter !== null) { ($this->limiter)(); }
        $result = $request($this->number, $this->assignment);
        ($this->guard)();
        return $result;
    }

    public function close(): void { $this->active = false; }
}

final class LocalEmergencyAuthority implements EmergencyAuthority
{
    public function __construct(private readonly StateStore $store, private readonly EmergencyBillingAdapter $billing, private readonly string $identifierKey)
    {
        if (strlen($identifierKey) < 32) { throw new \InvalidArgumentException('Private identifier key required.'); }
    }

    public function contexts(CustomerIdentity $customer): array
    {
        if ($customer->expiresAt <= time()) { throw new ProviderException(403, 'Emergency access not authorised.'); }
        return $this->store->transaction('registry', function (array $state) use ($customer): array {
            $contexts = [];
            foreach ($state['services'] ?? [] as $service => $record) {
                if ($record['account'] !== $customer->account || $record['status'] !== 'active') { continue; }
                try {
                    $pbx = $this->billing->pbx($customer->account, $record['pbx']);
                    if (($pbx['service'] ?? null) !== $service || (isset($pbx['expires_at']) && $pbx['expires_at'] <= time())) { continue; }
                    $contexts[] = ['service' => $service, 'pbx' => $record['pbx']];
                } catch (ProviderException $error) {
                    if ($error->httpStatus !== 403) { throw $error; }
                }
            }
            return $contexts;
        });
    }

    public function withLease(CustomerIdentity $customer, string $service, string $pbx, ?string $numberId, callable $operation): mixed
    {
        return $this->store->transaction('registry', function (array $state) use ($customer, $service, $pbx, $numberId, $operation): mixed {
            $record = $state['services'][$service] ?? null;
            if (!is_array($record) || $customer->expiresAt <= time() || $record['account'] !== $customer->account
                || $record['pbx'] !== $pbx || $record['status'] !== 'active') {
                throw new ProviderException(403, 'Emergency access not authorised.');
            }
            return $this->billing->withEmergencyAssignments($customer->account, $service, $pbx, $record['voices'], function () use ($customer, $service, $pbx, $numberId, $operation, $record): mixed {
            $resolve = function () use ($customer, $service, $pbx, $record): array {
                if ($customer->expiresAt <= time() || !is_array($record) || $record['account'] !== $customer->account
                    || $record['pbx'] !== $pbx || $record['status'] !== 'active') {
                    throw new ProviderException(403, 'Emergency access not authorised.');
                }
                $pbxRecord = $this->billing->pbx($customer->account, $pbx);
                if (($pbxRecord['service'] ?? null) !== $service || (isset($pbxRecord['expires_at']) && $pbxRecord['expires_at'] <= time())) {
                    throw new ProviderException(403, 'Emergency access not authorised.');
                }
                $numbers = [];
                foreach ($record['voices'] as $voice) {
                    try { $entitlement = $this->billing->voice($customer->account, $voice); }
                    catch (ProviderException $error) {
                        if ($error->httpStatus !== 403) { throw $error; }
                        continue;
                    }
                    if ((isset($entitlement['pbx']) && $entitlement['pbx'] !== $pbx)
                        || (isset($entitlement['expires_at']) && $entitlement['expires_at'] <= time())) {
                        continue;
                    }
                    foreach ($entitlement['numbers'] ?? [] as $number) {
                        if (!is_string($number) || !preg_match('/^[0-9]{1,32}$/D', $number)) {
                            throw new ProviderException(403, 'Emergency access not authorised.');
                        }
                        if (!in_array($number, $record['numbers'], true)) { continue; }
                        try { $assignment = $this->billing->emergencyAssignment($customer->account, $service, $pbx, $number); }
                        catch (ProviderException $error) {
                            if ($error->httpStatus !== 403) { throw $error; }
                            continue;
                        }
                        $identity = [$customer->account, $service, $pbx, $voice, $number, $record['revision'], $entitlement['revision'] ?? 0, $assignment];
                        $id = hash_hmac('sha256', json_encode($identity, JSON_THROW_ON_ERROR), $this->identifierKey);
                        if (!in_array($number, $numbers, true)) { $numbers[$id] = $number; }
                    }
                }
                return $numbers;
            };
            $numbers = $resolve();
            if ($numberId === null) {
                $items = [];
                foreach ($numbers as $id => $number) { $items[] = ['id' => $id, 'label' => $number]; }
                return $operation($items);
            }
            if (!isset($numbers[$numberId])) { throw new ProviderException(403, 'Emergency access not authorised.'); }
            $number = $numbers[$numberId];
            $guard = static function () use ($resolve, $numberId, $number): void {
                if (($resolve()[$numberId] ?? null) !== $number) { throw new ProviderException(403, 'Emergency access not authorised.'); }
            };
            $lease = new EmergencyLease($number, $guard, $numberId, hash_hmac('sha256', $number, $this->identifierKey));
            try { return $operation($lease); }
            finally { $lease->close(); }
            });
        });
    }
}

final class EmergencyFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $partial = false)
    {
        parent::__construct('Emergency provider operation failed.');
    }
}

final class EmergencyThrottle extends \RuntimeException
{
    public function __construct(public readonly array $delays) { parent::__construct('Provider operation rate limited.'); }
}

final class EmergencyRateLimits
{
    public static function validate(array $policy): void
    {
        if (!is_string($policy['provider'] ?? null) || $policy['provider'] === '' || strlen($policy['provider']) > 200
            || !is_array($policy['rules'] ?? null) || !array_is_list($policy['rules']) || count($policy['rules']) < 1 || count($policy['rules']) > 32) {
            throw new \InvalidArgumentException('Explicit provider rate policy required.');
        }
        foreach ($policy['rules'] as $rule) {
            if (!is_array($rule) || !in_array($rule['scope'] ?? null, ['provider', 'account', 'number'], true)
                || !is_int($rule['limit'] ?? null) || $rule['limit'] < 1 || $rule['limit'] > 10000
                || !is_int($rule['window'] ?? null) || $rule['window'] < 1 || $rule['window'] > 86400
                || !is_array($rule['operations'] ?? null) || !array_is_list($rule['operations']) || $rule['operations'] === []
                || array_diff($rule['operations'], ['retrieve', 'submit', 'status']) !== []) {
                throw new \InvalidArgumentException('Invalid provider rate rule.');
            }
        }
        foreach (['retrieve', 'submit', 'status'] as $action) {
            if (!array_filter($policy['rules'], static fn(array $rule): bool => in_array($action, $rule['operations'], true))) {
                throw new \InvalidArgumentException('Each provider operation requires a rate rule.');
            }
        }
    }

    public static function reserve(array &$state, array $policy, string $account, string $number, string $action, int $now, string $key): array
    {
        foreach ($state['buckets'] ?? [] as $id => $bucket) {
            if (($bucket['expires'] ?? 0) <= $now) { unset($state['buckets'][$id]); }
        }
        $buckets = [];
        foreach ($policy['rules'] as $index => $rule) {
            $scope = match ($rule['scope']) { 'provider' => '', 'account' => $account, 'number' => $number };
            $id = hash_hmac('sha256', json_encode([$policy['provider'], $index, $rule, $scope], JSON_THROW_ON_ERROR), $key);
            $events = array_values(array_filter($state['buckets'][$id]['events'] ?? [], static fn(int $event): bool => $event > $now - $rule['window']));
            $buckets[] = [$id, $rule, $events];
        }
        $delays = self::delays($buckets, $now);
        if ($delays[$action] > 0) { throw new EmergencyThrottle($delays); }
        if (count($state['buckets'] ?? []) + count($buckets) > 100000) { throw new EmergencyThrottle(array_fill_keys(['retrieve', 'submit', 'status'], 60)); }
        foreach ($buckets as &$bucket) {
            [$id, $rule, $events] = $bucket;
            if (in_array($action, $rule['operations'], true)) {
                $events[] = $now;
                $state['buckets'][$id] = ['events' => $events, 'expires' => $now + $rule['window']];
                $bucket[2] = $events;
            }
        }
        unset($bucket);
        return self::delays($buckets, $now);
    }

    private static function delays(array $buckets, int $now): array
    {
        $delays = array_fill_keys(['retrieve', 'submit', 'status'], 0);
        foreach ($buckets as [$id, $rule, $events]) {
            if (count($events) >= $rule['limit']) {
                $wait = max(0, $events[count($events) - $rule['limit']] + $rule['window'] - $now);
                foreach ($rule['operations'] as $action) { $delays[$action] = max($delays[$action], $wait); }
            }
        }
        return $delays;
    }
}

final class EmergencyData
{
    public const FIELDS = ['title', 'forename', 'honours', 'name', 'business_suffix', 'address_line_1', 'address_line_2', 'locality', 'region', 'postcode', 'country'];
    public const STATES = ['unregistered', 'received', 'awaiting_validation', 'submitted', 'awaiting_acceptance', 'registered', 'rejected'];

    public static function fields(array $fields, bool $submission): array
    {
        if (array_diff(array_keys($fields), self::FIELDS) !== []) { throw new EmergencyFailure('invalid'); }
        $result = [];
        foreach (self::FIELDS as $field) {
            $value = $fields[$field] ?? '';
            if (!is_string($value) || strlen($value) > 200 || preg_match('/[\x00-\x1F\x7F]/', $value) || !preg_match('//u', $value)) {
                throw new EmergencyFailure('invalid');
            }
            $result[$field] = trim($value);
        }
        if ($submission) {
            foreach (['name', 'address_line_1', 'locality', 'postcode', 'country'] as $required) {
                if ($result[$required] === '') { throw new EmergencyFailure('invalid'); }
            }
            if (!preg_match('/^[A-Z]{2}$/D', $result['country'])) { throw new EmergencyFailure('invalid'); }
        }
        return $result;
    }

    public static function type(array $fields): string
    {
        return $fields['title'] !== '' || $fields['forename'] !== '' || $fields['honours'] !== '' ? 'individual' : 'business';
    }

    public static function result(array $result, bool $requireDetails = false): array
    {
        if (!in_array($result['status'] ?? null, self::STATES, true) || !is_string($result['revision'] ?? null)
            || strlen($result['revision']) > 200 || preg_match('/[\x00-\x1F\x7F]/', $result['revision'])) {
            throw new EmergencyFailure('malformed');
        }
        $fields = $result['registration'] ?? null;
        if ($fields !== null) {
            if (!is_array($fields)) { throw new EmergencyFailure('malformed'); }
            try { $fields = self::fields($fields, false); }
            catch (EmergencyFailure $error) { throw new EmergencyFailure('malformed'); }
            if (self::type($fields) === 'individual' && $fields['business_suffix'] !== '') { throw new EmergencyFailure('malformed'); }
        }
        if ($requireDetails && $result['status'] !== 'unregistered' && $fields === null) { throw new EmergencyFailure('malformed'); }
        if ($requireDetails && $result['status'] === 'registered' && ($fields === null || $fields['name'] === '' || $fields['address_line_1'] === '' || $fields['country'] === '')) {
            throw new EmergencyFailure('malformed');
        }
        return ['status' => $result['status'], 'revision' => $result['revision'], 'registration' => $fields];
    }
}

final class EmergencyController
{
    public function __construct(private readonly EmergencyAuthority $authority, private readonly EmergencyRegistrationAdapter $adapter,
        private readonly StateStore $store, private readonly string $intentKey)
    {
        EmergencyRateLimits::validate($adapter->rateLimits());
        if (strlen($intentKey) < 32 || $adapter->minimumInterval() < 1 || $adapter->minimumInterval() > 86400) {
            throw new \InvalidArgumentException('Private intent key and documented query interval required.');
        }
    }

    public function handle(?CustomerIdentity $customer, string $csrf, array $request, ?int $now = null): array
    {
        $now ??= time();
        $started = time();
        $delays = array_fill_keys(['retrieve', 'submit', 'status'], 0);
        try {
            if ($customer === null || $customer->expiresAt <= time()) { throw new ProviderException(401, 'Customer authentication required.'); }
            if (strlen($csrf) < 32 || !is_string($request['csrf'] ?? null) || !hash_equals($csrf, $request['csrf'])) {
                throw new ProviderException(403, 'Emergency request could not be verified.');
            }
            $action = $request['action'] ?? null;
            if ($action === 'contexts') {
                if (array_diff(array_keys($request), ['action', 'csrf']) !== []) { throw new ProviderException(400, 'Unexpected emergency request fields.'); }
                return [200, ['ok' => true, 'contexts' => $this->authority->contexts($customer)]];
            }
            if (!in_array($action, ['list', 'retrieve', 'submit', 'status'], true)) { throw new ProviderException(400, 'Invalid emergency request.'); }
            $allowed = ['csrf', 'action', 'service', 'pbx', 'number_id'];
            if ($action === 'submit') { $allowed = array_merge($allowed, ['confirmed', 'type', 'registration', 'intent']); }
            if (array_diff(array_keys($request), $allowed) !== []) { throw new ProviderException(400, 'Unexpected emergency request fields.'); }
            foreach (['service', 'pbx'] as $field) {
                if (!is_string($request[$field] ?? null) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $request[$field])) {
                    throw new ProviderException(400, 'Invalid emergency resource.');
                }
            }
            $id = $action === 'list' ? null : ($request['number_id'] ?? null);
            if ($action !== 'list' && (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D', $id))) { throw new ProviderException(403, 'Emergency access not authorised.'); }
            $fields = null;
            if ($action === 'submit') {
                if (($request['confirmed'] ?? null) !== true) { throw new ProviderException(400, 'Confirm the accuracy of the emergency address.'); }
                if (!is_array($request['registration'] ?? null) || !in_array($request['type'] ?? null, ['individual', 'business'], true)) { throw new EmergencyFailure('invalid'); }
                $fields = EmergencyData::fields($request['registration'], true);
                if (($request['type'] === 'business' && EmergencyData::type($fields) !== 'business')
                    || ($request['type'] === 'individual' && ($fields['business_suffix'] !== '' || EmergencyData::type($fields) !== 'individual'))) {
                    throw new EmergencyFailure('incompatible');
                }
            }
            $result = $this->authority->withLease($customer, $request['service'], $request['pbx'], $id,
                function (array|EmergencyLease $lease) use ($action, $request, $customer, $fields, $now, $started, &$delays): array {
                    if (is_array($lease)) { return ['ok' => true, 'numbers' => $lease]; }
                    $intent = null;
                    if ($action === 'submit') {
                        $intent = $this->verifyIntent($request['intent'] ?? null, $customer, $request, $now);
                        if (!$this->adapter->supportsTypeChange() && $intent['type'] !== 'unregistered' && $intent['type'] !== $request['type']) { throw new EmergencyFailure('type_locked'); }
                    }
                    $first = true;
                    $reservedAt = time();
                    $lease->limit(function () use ($action, $customer, $intent, $lease, $now, $started, &$first, &$delays, &$reservedAt): void {
                        $reservedAt = time();
                        $reservationNow = $now + max(0, $reservedAt - $started);
                        $delays = $this->store->transaction('emergency-control', function (array $state, callable $save) use ($action, $customer, $intent, $lease, $reservationNow, &$first): array {
                            foreach ($state['used'] ?? [] as $key => $expiry) { if (!is_int($expiry) || $expiry < $reservationNow) { unset($state['used'][$key]); } }
                            if ($intent !== null && $first) {
                                if ($intent['expires'] <= $reservationNow || isset($state['used'][$intent['nonce']])) { throw new ProviderException(409, 'Retrieve current details before retrying.'); }
                                if (count($state['used'] ?? []) >= 10000) { throw new ProviderException(429, 'Emergency registration is temporarily busy.'); }
                            }
                            $waits = EmergencyRateLimits::reserve($state, $this->adapter->rateLimits(), $customer->account, $lease->rateNumber, $action, $reservationNow, $this->intentKey);
                            if ($intent !== null && $first) { $state['used'][$intent['nonce']] = $intent['expires']; }
                            unset($state['next_provider_at']);
                            $save($state);
                            $first = false;
                            return $waits;
                        });
                    });
                    $response = match ($action) {
                        'retrieve' => $this->adapter->retrieve($lease),
                        'status' => $this->adapter->status($lease),
                        'submit' => $this->adapter->submit($lease, $fields, $intent['revision'], $intent['nonce']),
                    };
                    $response = EmergencyData::result($response, $action === 'retrieve');
                    $remaining = array_map(static fn(int $wait): int => max(0, $wait - max(0, time() - $reservedAt)), $delays);
                    $output = ['ok' => true, 'status' => $response['status'], 'retry_after_by_operation' => $remaining];
                    if ($action === 'retrieve') {
                        $registration = $response['registration'] ?? array_fill_keys(EmergencyData::FIELDS, '');
                        $output['registration'] = $registration;
                        $output['type'] = EmergencyData::type($registration);
                        $output['can_change_type'] = $this->adapter->supportsTypeChange() || $response['status'] === 'unregistered';
                        $output['intent'] = $this->intent($customer, $request, $response['revision'], $response['status'] === 'unregistered' ? 'unregistered' : $output['type'], $now + max(0, time() - $started));
                    }
                    return $output;
                });
            return [200, $result];
        } catch (EmergencyThrottle $error) {
            return [429, ['ok' => false, 'message' => 'Please wait before retrying this provider operation.',
                'retry_after' => $error->delays[$request['action']] ?? 60, 'retry_after_by_operation' => $error->delays]];
        } catch (ProviderException $error) {
            $messages = [401 => 'Customer authentication required.', 403 => 'Emergency access not authorised.',
                400 => 'Check the request and confirm the accuracy of the emergency address.',
                409 => 'This form is no longer usable. Retrieve current provider details before retrying.',
                429 => 'Please wait before retrying emergency registration.'];
            return [$error->httpStatus, ['ok' => false, 'message' => $messages[$error->httpStatus] ?? 'Emergency information is unavailable. Please retry.',
                'retry_after' => $error->httpStatus === 429 ? $this->adapter->minimumInterval() : 0]];
        } catch (EmergencyFailure $error) {
            $messages = ['invalid' => 'Check the required fields and country code.', 'incompatible' => 'Clear fields that do not belong to the selected registration type.',
                'type_locked' => 'This provider requires assisted changes to registration type.',
                'conflict' => 'Provider details changed. Retrieve current information before editing.',
                'invalid_number' => 'The provider does not recognise this authorised number. Contact support.',
                'rejected' => 'The provider rejected the information. Check the address or contact support.',
                'partial' => 'Some information may have reached the provider. Check current status before retrying.'];
            $badInput = in_array($error->reason, ['invalid', 'incompatible', 'type_locked', 'invalid_number'], true);
            $partial = $error->partial || (($request['action'] ?? null) === 'submit' && !$badInput && !in_array($error->reason, ['conflict', 'rejected'], true));
            return [$error->reason === 'invalid_number' ? 422 : ($badInput ? 400 : 502), ['ok' => false, 'message' => $partial ? $messages['partial'] : ($messages[$error->reason] ?? 'Emergency information is unavailable. Please retry.'),
                'partial' => $partial, 'retry_after' => $this->adapter->minimumInterval()]];
        } catch (\Throwable $error) {
            $partial = ($request['action'] ?? null) === 'submit';
            return [503, ['ok' => false, 'message' => $partial ? 'Some information may have reached the provider. Check current status before retrying.' : 'Emergency information is unavailable. Please retry.',
                'partial' => $partial, 'retry_after' => $this->adapter->minimumInterval()]];
        }
    }

    private function intent(CustomerIdentity $customer, array $request, string $revision, string $type, int $now): string
    {
        $payload = ['account' => $customer->account, 'subject' => $customer->subject, 'service' => $request['service'], 'pbx' => $request['pbx'],
            'number_id' => $request['number_id'], 'revision' => $revision, 'type' => $type, 'expires' => $now + 900, 'nonce' => bin2hex(random_bytes(24))];
        $encoded = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
        return $encoded . '.' . hash_hmac('sha256', $encoded, $this->intentKey);
    }

    private function verifyIntent(mixed $token, CustomerIdentity $customer, array $request, int $now): array
    {
        if (!is_string($token) || strlen($token) > 4096 || substr_count($token, '.') !== 1) { throw new ProviderException(409, 'Invalid form.'); }
        [$encoded, $mac] = explode('.', $token);
        if (!hash_equals(hash_hmac('sha256', $encoded, $this->intentKey), $mac)) { throw new ProviderException(409, 'Invalid form.'); }
        $intent = json_decode((string)base64_decode($encoded, true), true, 8, JSON_THROW_ON_ERROR);
        foreach (['service', 'pbx', 'number_id'] as $field) {
            if (($intent[$field] ?? null) !== $request[$field]) { throw new ProviderException(403, 'Invalid form.'); }
        }
        if (($intent['account'] ?? null) !== $customer->account || ($intent['subject'] ?? null) !== $customer->subject
            || ($intent['expires'] ?? 0) <= $now) { throw new ProviderException(409, 'Invalid form.'); }
        return $intent;
    }
}