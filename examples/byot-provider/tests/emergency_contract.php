<?php
declare(strict_types=1);

use Domaintains\Byot\{CustomerIdentity, EmergencyLease, LocalBilling, LocalEmergencyAuthority, LocalRegistry, ProviderException, StateStore};

if (!defined('BYOT_FIXTURES_ONLY')) { define('BYOT_FIXTURES_ONLY', true); }
require_once __DIR__ . '/local_contract.php';
require_once __DIR__ . '/../src/Emergency.php';
require_once __DIR__ . '/../src/EmergencyJsonAdapter.php';
require_once __DIR__ . '/../src/EmergencyApplication.php';

class EmergencyFixtureAdapter implements \Domaintains\Byot\EmergencyRegistrationAdapter
{
    public int $calls = 0;
    public array $submitted = [];
    public array $reply = ['status' => 'unregistered', 'revision' => '', 'registration' => null];
    public bool $changeType = true;
    public ?array $policy = null;
    public function minimumInterval(): int { return 30; }
    public function rateLimits(): array { return $this->policy ?? ['provider' => 'synthetic', 'rules' => array_map(
        static fn(string $action): array => ['scope' => 'number', 'operations' => [$action], 'limit' => 1, 'window' => 30], ['retrieve', 'submit', 'status'])]; }
    public function supportsTypeChange(): bool { return $this->changeType; }
    public function retrieve(EmergencyLease $lease): array { return $lease->request(function (): array { $this->calls++; return $this->reply; }); }
    public function status(EmergencyLease $lease): array { return $this->retrieve($lease); }
    public function submit(EmergencyLease $lease, array $registration, string $revision, string $idempotencyKey): array
    {
        return $lease->request(function () use ($registration): array { $this->calls++; $this->submitted[] = $registration; return ['status' => 'submitted', 'revision' => 'v2', 'registration' => null]; });
    }
}

class EmergencyMutableBilling implements \Domaintains\Byot\EmergencyBillingAdapter
{
    public function __construct(public array $records) {}
    public function pbx(string $account, string $pbx): array { return (new LocalBilling($this->records))->pbx($account, $pbx); }
    public function voice(string $account, string $voice): array { return (new LocalBilling($this->records))->voice($account, $voice); }
    public function withEmergencyAssignments(string $account, string $service, string $pbx, array $voices, callable $operation): mixed { return $operation(); }
    public function emergencyAssignment(string $account, string $service, string $pbx, string $number): string { return (new LocalBilling($this->records))->emergencyAssignment($account, $service, $pbx, $number); }
}

function emergency_billing_fixture(string $service): array
{
    return byot_fixture($service) + ['number_assignments' => [
        '12025550101' => ['account' => 'account-01', 'service' => $service, 'pbx' => 'pbx-01', 'status' => 'active', 'revision' => 1],
        '12025550102' => ['account' => 'account-02', 'service' => 'service-b', 'pbx' => 'pbx-02', 'status' => 'active', 'revision' => 1],
        '12025550103' => ['account' => 'account-01', 'service' => 'service-c', 'pbx' => 'pbx-03', 'status' => 'active', 'revision' => 1],
    ]];
}

class EmergencyFixtureTransport implements \Domaintains\Byot\EmergencyJsonTransport
{
    public array $requests = [];
    public array $responses = [];
    public $afterRequest = null;
    public function send(string $method, string $url, array $headers, ?array $body): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if ($this->afterRequest !== null) { ($this->afterRequest)(); }
        $result = array_shift($this->responses);
        if ($result instanceof Throwable) { throw $result; }
        return $result ?? ['http_status' => 202, 'body' => ['state' => 'queued', 'version' => 'v2']];
    }
}

function emergency_mapping(): array
{
    $fields = array_combine(\Domaintains\Byot\EmergencyData::FIELDS, \Domaintains\Byot\EmergencyData::FIELDS);
    return ['base_url' => 'https://carrier.example', 'headers' => ['Authorization: Bearer synthetic-provider-credential'],
        'rate_limits' => ['provider' => 'synthetic-carrier', 'rules' => array_map(static fn(string $action): array =>
            ['scope' => 'number', 'operations' => [$action], 'limit' => $action === 'submit' ? 2 : 1, 'window' => 30], ['retrieve', 'submit', 'status'])],
        'minimum_interval' => 30, 'supports_type_change' => true, 'fields' => $fields, 'status_field' => 'state', 'revision_field' => 'version',
        'statuses' => ['queued' => 'awaiting_acceptance', 'live' => 'registered', 'declined' => 'rejected'],
        'missing_http_statuses' => [404], 'acknowledgements' => [202 => 'submitted'],
        'operations' => ['retrieve' => ['method' => 'GET', 'path' => '/emergency/{number}'],
            'status' => ['method' => 'GET', 'path' => '/emergency/{number}/status'],
            'submit' => ['method' => 'PUT', 'path' => '/emergency/{number}'],
            'commit' => ['method' => 'POST', 'path' => '/emergency/{number}/commit']]];
}

class EmergencyLockedBilling extends EmergencyMutableBilling
{
    public function __construct(private readonly StateStore $store)
    {
        parent::__construct($store->read('emergency-assignments')['records']);
    }

    public function withEmergencyAssignments(string $account, string $service, string $pbx, array $voices, callable $operation): mixed
    {
        return $this->store->transaction('emergency-assignments', function (array $state) use ($operation): mixed {
            $this->records = $state['records'];
            return $operation();
        });
    }
}

function emergency_worker(array $input): array
{
    $process = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'extension=sodium', __FILE__, '--worker'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    byot_assert(is_resource($process), 'start emergency worker');
    fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    return [$process, $pipes];
}

function emergency_finish(array $worker): array
{
    [$process, $pipes] = $worker;
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    byot_assert(proc_close($process) === 0, 'emergency worker completes: ' . $error);
    return json_decode($output, true, 16, JSON_THROW_ON_ERROR);
}

if (($argv[1] ?? '') === '--worker') {
    $input = json_decode(stream_get_contents(STDIN, 16384), true, 16, JSON_THROW_ON_ERROR);
    $store = new StateStore($input['path']);
    if (isset($input['move'])) {
        $lock = fopen($input['path'] . '/emergency-assignments.lock', 'r+');
        byot_assert(!flock($lock, LOCK_EX | LOCK_NB), 'assignment writer cannot bypass the active ownership lock');
        fclose($lock);
        echo "blocked\n";
        flush();
        $store->transaction('emergency-assignments', static function (array $state, callable $save) use ($input): void {
            $state['records']['number_assignments']['12025550101'] = $input['move'];
            $save($state);
        });
        echo json_encode(['moved' => true]);
    } else {
        $authority = new LocalEmergencyAuthority($store, new EmergencyLockedBilling($store), str_repeat('k', 32));
        $adapter = new EmergencyFixtureAdapter();
        $adapter->policy = $input['policy'] ?? null;
        $controller = new \Domaintains\Byot\EmergencyController($authority, $adapter, $store, str_repeat('i', 32));
        $customer = new CustomerIdentity($input['account'], 'worker-customer', time() + 3600);
        echo json_encode($controller->handle($customer, str_repeat('c', 64), $input['request'], $input['now']), JSON_THROW_ON_ERROR);
    }
    exit;
}

$path = sys_get_temp_dir() . '/domaintains-emergency-' . bin2hex(random_bytes(8));
try {
    $store = new StateStore($path);
    $billing = new EmergencyMutableBilling(emergency_billing_fixture('pbx-test'));
    byot_assert(!(new LocalBilling($billing->records)) instanceof \Domaintains\Byot\EmergencyBillingAdapter, 'non-locking reference billing cannot satisfy production emergency authority');
    $registry = new LocalRegistry($store, $billing, ['pbx-test' => byot_plan()]);
    $registry->link('account-01', 'pbx-01', 'pbx-test', ['voice-01'], time());
    $authority = new LocalEmergencyAuthority($store, $billing, str_repeat('k', 32));
    $customer = new CustomerIdentity('account-01', 'customer-01', time() + 3600);
    $items = $authority->withLease($customer, 'pbx-test', 'pbx-01', null, static fn(array $items): array => $items);
    byot_assert(count($items) === 1 && $items[0]['label'] === '12025550101', 'only trusted entitled numbers returned');
    $calls = 0;
    $operation = static function (EmergencyLease $lease) use (&$calls): void {
        $lease->request(static function (string $number) use (&$calls): void { $calls++; });
    };
    foreach ([['account-02', 'pbx-test', 'pbx-01', $items[0]['id']], ['account-01', 'foreign-service', 'pbx-01', $items[0]['id']],
        ['account-01', 'pbx-test', 'other-pbx', $items[0]['id']], ['account-01', 'pbx-test', 'pbx-01', '12025550101']] as [$account, $service, $pbx, $id]) {
        byot_reject(fn() => $authority->withLease(new CustomerIdentity($account, 'subject', time() + 3600), $service, $pbx, $id, $operation));
        byot_assert($calls === 0, 'denied ownership must make no provider requests');
    }
    $authority->withLease($customer, 'pbx-test', 'pbx-01', $items[0]['id'], $operation);
    byot_assert($calls === 1, 'authorised operation resolves actual telephone number server-side');
    $closedLease = $authority->withLease($customer, 'pbx-test', 'pbx-01', $items[0]['id'], static fn(EmergencyLease $lease): EmergencyLease => $lease);
    byot_reject(fn() => $closedLease->request(static function () use (&$calls): void { $calls++; }));
    byot_assert($calls === 1, 'escaped lease cannot operate after assignment lock is released');
    $adapter = new EmergencyFixtureAdapter();
    $controller = new \Domaintains\Byot\EmergencyController($authority, $adapter, $store, str_repeat('i', 32));
    $csrf = str_repeat('c', 64);
    $request = ['action' => 'retrieve', 'csrf' => $csrf, 'service' => 'pbx-test', 'pbx' => 'pbx-01', 'number_id' => $items[0]['id']];
    foreach ([array_replace($request, ['csrf' => 'bad']), $request + ['account' => 'account-02'], $request + ['number' => '12025550199'],
        $request + ['carrier_url' => 'https://attacker.example'], array_replace($request, ['pbx' => 'other-pbx'])] as $denied) {
        byot_assert($controller->handle($customer, $csrf, $denied)[0] !== 200 && $adapter->calls === 0, 'tampering denied before provider request');
    }
    [$code, $retrieved] = $controller->handle($customer, $csrf, $request, 1000);
    byot_assert($code === 200 && $retrieved['status'] === 'unregistered' && isset($retrieved['intent']), 'legitimate missing registration is editable');
    byot_assert($controller->handle($customer, $csrf, $request, 1001)[0] === 429 && $adapter->calls === 1, 'rate limits are server-side');
    $fields = array_fill_keys(\Domaintains\Byot\EmergencyData::FIELDS, '');
    $fields = array_replace($fields, ['name' => 'Synthetic Business', 'address_line_1' => '1 Example Street', 'locality' => 'Example', 'postcode' => 'EX1 1AA', 'country' => 'GB']);
    $submit = array_replace($request, ['action' => 'submit', 'registration' => $fields, 'type' => 'business', 'confirmed' => false, 'intent' => $retrieved['intent']]);
    byot_assert($controller->handle($customer, $csrf, $submit, 1001)[0] === 400 && $adapter->calls === 1, 'confirmation required on server');
    $submit['confirmed'] = true;
    [$code, $submitted] = $controller->handle($customer, $csrf, $submit, 1031);
    byot_assert($code === 200 && $submitted['status'] === 'submitted' && !isset($submitted['registration']), 'accepted submission is not registered and does not echo personal data');
    byot_assert($controller->handle($customer, $csrf, $submit, 1033)[0] === 409 && $adapter->calls === 2, 'submission replay makes no provider request');
    byot_assert(!str_contains(file_get_contents($path . '/emergency-control.json'), 'Example Street'), 'private metadata contains no address mirror');
    foreach (['title', 'forename', 'honours'] as $personal) {
        byot_assert(\Domaintains\Byot\EmergencyData::type(array_replace($fields, [$personal => 'Synthetic'])) === 'individual', 'individual detection uses ' . $personal);
    }
    byot_assert(\Domaintains\Byot\EmergencyData::type($fields) === 'business', 'empty personal fields detect business');
    $adapter->reply = ['status' => 'registered', 'revision' => 'v3', 'registration' => array_replace($fields, ['business_suffix' => 'An Unlisted Suffix'])];
    [, $fresh] = $controller->handle($customer, $csrf, $request, 1062);
    $incompatible = array_replace($submit, ['intent' => $fresh['intent'], 'registration' => array_replace($fields, ['forename' => 'Synthetic'])]);
    $callsBefore = $adapter->calls;
    byot_assert($controller->handle($customer, $csrf, $incompatible, 1032)[0] === 400 && $adapter->calls === $callsBefore, 'business cannot silently retain personal fields');
    $incompatible['type'] = 'individual';
    $incompatible['registration']['business_suffix'] = 'Ltd';
    byot_assert($controller->handle($customer, $csrf, $incompatible, 1032)[0] === 400 && $adapter->calls === $callsBefore, 'individual cannot silently retain a business suffix');
    $adapter->changeType = false;
    $incompatible['registration']['business_suffix'] = '';
    byot_assert($controller->handle($customer, $csrf, $incompatible, 1032)[0] === 400 && $adapter->calls === $callsBefore, 'unsupported type change denied server-side before provider');
    $adapter->changeType = true;
    $switched = $controller->handle($customer, $csrf, $incompatible, 1093);
    byot_assert($switched[0] === 200 && $adapter->submitted[1]['forename'] === 'Synthetic' && $adapter->submitted[1]['business_suffix'] === '', 'supported type changes carry explicit cleared incompatible fields');
    $adapter->reply = ['status' => 'registered', 'revision' => 'v4', 'registration' => $fields];
    [, $status] = $controller->handle($customer, $csrf, array_replace($request, ['action' => 'status']), 1124);
    byot_assert($status['status'] === 'registered' && !isset($status['registration']), 'only explicit provider registration status is shown, without echoing address');
    $store->transaction('registry', static function (array $state, callable $save): void { $state['services']['pbx-test']['expires_at'] = time() - 1; $save($state); });
    byot_assert(count($authority->contexts($customer)) === 1, 'activation token expiry is not customer authentication or commercial service expiry');
    $oldSession = $_SESSION ?? [];
    $_SESSION['domaintains_customer'] = ['account' => 'account-01', 'subject' => 'customer-a', 'expires_at' => time() + 3600];
    $authenticated = new \Domaintains\Byot\TrustedSessionCustomer();
    byot_assert($authenticated->current()->account === 'account-01', 'trusted portal session supplies account, not request parameters');
    $_SESSION['domaintains_customer']['expires_at'] = time() - 1;
    byot_assert($authenticated->current() === null, 'expired portal session cannot authenticate');
    $_SESSION = $oldSession;
    $integration = require __DIR__ . '/../emergency.integration.example.php';
    $example = require __DIR__ . '/../emergency.config.example.php';
    $example['intent_key_base64'] = base64_encode(random_bytes(32));
    $configurationRejected = false;
    try { $integration(['private_state_dir' => $path, 'billing' => emergency_billing_fixture('pbx-test'), 'emergency' => $example]); }
    catch (RuntimeException $error) { $configurationRejected = true; }
    byot_assert($configurationRejected, 'reference factory requires an explicit authoritative assignment adapter');
    $example['billing_adapter'] = $billing;
    $application = $integration(['private_state_dir' => $path, 'billing' => emergency_billing_fixture('pbx-test'), 'emergency' => $example]);
    byot_assert($application instanceof \Domaintains\Byot\EmergencyApplication
        && $application->controller->handle(null, $csrf, $request)[0] === 401, 'documented composition runs and fails closed without customer authentication');
    $uncertainAdapter = new class extends EmergencyFixtureAdapter {
        public function submit(EmergencyLease $lease, array $registration, string $revision, string $idempotencyKey): array
        {
            parent::submit($lease, $registration, $revision, $idempotencyKey);
            throw new RuntimeException('synthetic-private-credential 1 Example Street');
        }
    };
    $uncertainController = new \Domaintains\Byot\EmergencyController($authority, $uncertainAdapter, $store, str_repeat('i', 32));
    [, $fresh] = $uncertainController->handle($customer, $csrf, $request, 1155);
    [$code, $uncertain] = $uncertainController->handle($customer, $csrf, array_replace($submit, ['intent' => $fresh['intent']]), 1186);
    byot_assert($code === 503 && $uncertain['partial'] === true && str_contains($uncertain['message'], 'Check current status')
        && !str_contains(json_encode($uncertain), 'synthetic-private-credential'), 'unexpected mutation exception requires status verification, not blind replay');
    echo "Emergency authority isolation contracts passed.\n";
} finally { if (is_dir($path)) { byot_clean($path); } }

$path = sys_get_temp_dir() . '/domaintains-emergency-isolation-' . bin2hex(random_bytes(8));
try {
    $store = new StateStore($path);
    $records = emergency_billing_fixture('service-a');
    $records['pbx']['pbx-02'] = ['account' => 'account-02', 'status' => 'active', 'service' => 'service-b'];
    $records['pbx']['pbx-03'] = ['account' => 'account-01', 'status' => 'active', 'service' => 'service-c'];
    $records['voice']['voice-b'] = ['account' => 'account-02', 'status' => 'active', 'numbers' => ['12025550102'], 'max_channels' => 1];
    $records['voice']['voice-c'] = ['account' => 'account-01', 'status' => 'active', 'numbers' => ['12025550103'], 'max_channels' => 1];
    $billing = new EmergencyMutableBilling($records);
    $registry = new LocalRegistry($store, $billing, ['service-a' => byot_plan(), 'service-b' => byot_plan(), 'service-c' => byot_plan()]);
    $registry->link('account-01', 'pbx-01', 'service-a', ['voice-01'], time());
    $registry->link('account-02', 'pbx-02', 'service-b', ['voice-b'], time());
    $registry->link('account-01', 'pbx-03', 'service-c', ['voice-c'], time());
    $authority = new LocalEmergencyAuthority($store, $billing, str_repeat('k', 32));
    $adapter = new EmergencyFixtureAdapter();
    $controller = new \Domaintains\Byot\EmergencyController($authority, $adapter, $store, str_repeat('i', 32));
    $customer = new CustomerIdentity('account-01', 'customer-a', time() + 3600);
    $customerB = new CustomerIdentity('account-02', 'customer-b', time() + 3600);
    $csrf = str_repeat('c', 64);
    $list = static fn(CustomerIdentity $identity, string $service, string $pbx): array => $authority->withLease($identity, $service, $pbx, null, static fn(array $items): array => $items);
    $idA = $list($customer, 'service-a', 'pbx-01')[0]['id'];
    $idB = $list($customerB, 'service-b', 'pbx-02')[0]['id'];
    $idC = $list($customer, 'service-c', 'pbx-03')[0]['id'];
    $base = ['csrf' => $csrf, 'service' => 'service-a', 'pbx' => 'pbx-01', 'number_id' => $idA];
    [$code, $visible] = $controller->handle($customer, $csrf, ['action' => 'contexts', 'csrf' => $csrf]);
    byot_assert($code === 200 && array_column($visible['contexts'], 'service') === ['service-a', 'service-c'] && $adapter->calls === 0, 'context discovery excludes foreign services without carrier requests');
    foreach (['retrieve', 'status', 'submit'] as $action) {
        $request = $base + ['action' => $action];
        if ($action === 'submit') { $request += ['confirmed' => true, 'type' => 'business', 'registration' => $fields, 'intent' => 'forged']; }
        foreach ([array_replace($request, ['number_id' => $idB]), array_replace($request, ['pbx' => 'pbx-02']),
            array_replace($request, ['service' => 'service-b']), array_replace($request, ['service' => 'service-b', 'pbx' => 'pbx-02', 'number_id' => $idB]),
            array_replace($request, ['number_id' => $idC]), array_replace($request, ['service' => 'service-c']),
            array_replace($request, ['csrf' => '']), $request + ['account' => 'account-02'], $request + ['number' => '12025550102']] as $denied) {
            byot_assert($controller->handle($customer, $csrf, $denied, 1000)[0] !== 200 && $adapter->calls === 0, $action . ' rejects tampering with zero provider operations');
        }
    }
    foreach (['suspended', 'cancelled', 'paused', 'revoked'] as $status) {
        $store->transaction('registry', static function (array $state, callable $save) use ($status): void { $state['services']['service-a']['status'] = $status; $save($state); });
        byot_assert($controller->handle($customer, $csrf, $base + ['action' => 'retrieve'])[0] === 403 && $adapter->calls === 0, 'inactive service denies before provider');
    }
    $store->transaction('registry', static function (array $state, callable $save): void { $state['services']['service-a']['status'] = 'active'; $save($state); });
    foreach (['detached', 'reassigned', 'expired', 'wrong-pbx', 'inactive', 'assignment-revoked', 'assignment-reassigned', 'assignment-customer', 'assignment-service'] as $case) {
        $billing->records = $records;
        if ($case === 'detached') { $billing->records['voice']['voice-01']['numbers'] = []; }
        if ($case === 'reassigned') { $billing->records['voice']['voice-01']['account'] = 'account-02'; }
        if ($case === 'expired') { $billing->records['voice']['voice-01']['expires_at'] = time() - 1; }
        if ($case === 'wrong-pbx') { $billing->records['voice']['voice-01']['pbx'] = 'pbx-03'; }
        if ($case === 'inactive') { $billing->records['voice']['voice-01']['status'] = 'suspended'; }
        if ($case === 'assignment-revoked') { $billing->records['number_assignments']['12025550101']['status'] = 'revoked'; }
        if ($case === 'assignment-reassigned') { $billing->records['number_assignments']['12025550101']['pbx'] = 'pbx-03'; }
        if ($case === 'assignment-customer') { $billing->records['number_assignments']['12025550101']['account'] = 'account-02'; }
        if ($case === 'assignment-service') { $billing->records['number_assignments']['12025550101']['service'] = 'service-c'; }
        byot_assert($controller->handle($customer, $csrf, $base + ['action' => 'retrieve'])[0] === 403 && $adapter->calls === 0, $case . ' denies before provider');
    }
    $billing->records = $records;
    byot_assert($controller->handle(null, $csrf, $base + ['action' => 'retrieve'])[0] === 401, 'activation key is not customer authentication');
    byot_assert($controller->handle(new CustomerIdentity('account-01', 'customer-a', time() - 1), $csrf, $base + ['action' => 'retrieve'])[0] === 401 && $adapter->calls === 0, 'expired customer session denied');
    $transport = new EmergencyFixtureTransport();
    $mapping = emergency_mapping();
    $mapping['submission_stages'] = ['submit', 'commit'];
    $mapping['allow_stage_burst'] = true;
    $mapped = new \Domaintains\Byot\MappedEmergencyAdapter($mapping, $transport);
    $controller = new \Domaintains\Byot\EmergencyController($authority, $mapped, $store, str_repeat('i', 32));
    $transport->responses = [['http_status' => 404, 'body' => []]];
    [$code, $retrieved] = $controller->handle($customer, $csrf, $base + ['action' => 'retrieve'], 1000);
    byot_assert($code === 200 && $retrieved['status'] === 'unregistered', 'mapped legitimate missing record');
    $request = $base + ['action' => 'submit', 'confirmed' => true, 'type' => 'business', 'registration' => $fields, 'intent' => $retrieved['intent']];
    foreach (['account' => 'account-02', 'service' => 'service-c', 'pbx' => 'pbx-03'] as $field => $value) {
        $transport->afterRequest = static function () use ($billing, $field, $value): void { $billing->records['number_assignments']['12025550101'][$field] = $value; };
        $before = count($transport->requests);
        [$code, $denied] = $controller->handle($customer, $csrf, $request, $moveTime = ($moveTime ?? 1000) + 31);
        byot_assert($code === 403 && count($transport->requests) === $before + 1, $field . ' reassignment during first mutation blocks subsequent stages');
        byot_assert(!str_contains(json_encode($denied), 'Example Street'), 'reassignment response discloses no address');
        $billing->records = $records;
        $transport->afterRequest = null;
        $transport->responses = [['http_status' => 404, 'body' => []]];
        [, $retrieved] = $controller->handle($customer, $csrf, $base + ['action' => 'retrieve'], $moveTime + 30);
        $request['intent'] = $retrieved['intent'];
    }
    $billing->records = $records;
    $transport->afterRequest = null;
    $transport->responses = [['http_status' => 404, 'body' => []]];
    [, $retrieved] = $controller->handle($customer, $csrf, $base + ['action' => 'retrieve'], 1200);
    $request['intent'] = $retrieved['intent'];
    $transport->responses = [['http_status' => 202, 'body' => ['state' => 'queued', 'version' => 'v2']], new RuntimeException('private-token address Example Street https://private.example')];
    [$code, $partial] = $controller->handle($customer, $csrf, $request, 1231);
    byot_assert($code === 502 && $partial['partial'] === true && !str_contains(json_encode($partial), 'private-token') && !str_contains(json_encode($partial), 'Example Street'), 'partial failure is actionable and redacted');
    $transport->responses = [['http_status' => 200, 'body' => ['state' => 'surprise', 'registration' => ['name' => 'Sensitive Example Street']]]];
    [$code, $malformed] = $controller->handle($customer, $csrf, $base + ['action' => 'retrieve'], 1262);
    byot_assert($code === 502 && !isset($malformed['registration']) && !str_contains(json_encode($malformed), 'Sensitive'), 'malformed provider response never displays stale or raw data');
    $oldErrorLog = ini_get('error_log');
    ini_set('error_log', $path . '/privacy-errors.log');
    ob_start();
    try {
        $transport->responses = [new RuntimeException('synthetic-private-credential 1 Example Street https://private.example')];
        [$code, $privateError] = $controller->handle($customer, $csrf, $base + ['action' => 'retrieve'], 1293);
        byot_assert($code === 503 && !str_contains(json_encode($privateError), 'synthetic-private-credential') && !str_contains(json_encode($privateError), 'Example Street'), 'unexpected provider exceptions are fully redacted');
    } finally { $output = ob_get_clean(); ini_set('error_log', $oldErrorLog); }
    byot_assert($output === '' && (!file_exists($path . '/privacy-errors.log') || file_get_contents($path . '/privacy-errors.log') === ''), 'controller logs or prints no emergency information');
    $mapping['acknowledgements'] = [200 => 'registered'];
    $ackAdapter = new \Domaintains\Byot\MappedEmergencyAdapter($mapping, $transport);
    $ackController = new \Domaintains\Byot\EmergencyController($authority, $ackAdapter, $store, str_repeat('i', 32));
    $transport->responses = [['http_status' => 200, 'body' => []]];
    byot_assert($ackController->handle($customer, $csrf, $base + ['action' => 'status'], 1324)[0] === 502, 'HTTP acknowledgement cannot be mapped to registered without explicit provider status');
    $mapping['response_rules'] = array_map(static fn(string $code, string $outcome): array => [
        'http_status' => 400, 'operations' => ['retrieve'], 'when' => [['path' => ['error', 'code'], 'equals' => $code]], 'outcome' => $outcome,
    ], ['NO_RECORD', 'BAD_NUMBER', 'DENIED'], ['unregistered', 'invalid_number', 'unauthorised']);
    $bodyAdapter = new \Domaintains\Byot\MappedEmergencyAdapter($mapping, $transport);
    $bodyController = new \Domaintains\Byot\EmergencyController($authority, $bodyAdapter, $store, str_repeat('i', 32));
    foreach (['NO_RECORD' => 200, 'BAD_NUMBER' => 422, 'DENIED' => 403, 'OTHER' => 502] as $meaning => $expected) {
        $transport->responses = [['http_status' => 400, 'body' => ['error' => ['code' => $meaning], 'private' => 'Sensitive Example Street']]];
        [$code, $result] = $bodyController->handle($customer, $csrf, $base + ['action' => 'retrieve'], $responseTime = ($responseTime ?? 1324) + 31);
        byot_assert($code === $expected && !str_contains(json_encode($result), 'Sensitive'), 'HTTP 400 meaning ' . $meaning . ' interpreted without private data disclosure');
        byot_assert($meaning === 'NO_RECORD' ? $result['status'] === 'unregistered' : !isset($result['registration']), 'only explicit missing-record body permits an empty editable form');
    }
    foreach ([['error' => []], ['error' => ['code' => ['NO_RECORD']]], ['error' => ['code' => null]]] as $body) {
        $transport->responses = [['http_status' => 400, 'body' => $body]];
        byot_assert($bodyController->handle($customer, $csrf, $base + ['action' => 'retrieve'], $responseTime += 31)[0] === 502, 'absent or malformed predicate field fails closed');
    }
    $ambiguousMapping = $mapping;
    $ambiguousMapping['response_rules'][] = array_replace($mapping['response_rules'][0], ['outcome' => 'unauthorised']);
    $ambiguous = new \Domaintains\Byot\EmergencyController($authority, new \Domaintains\Byot\MappedEmergencyAdapter($ambiguousMapping, $transport), $store, str_repeat('i', 32));
    $transport->responses = [['http_status' => 400, 'body' => ['error' => ['code' => 'NO_RECORD']]]];
    byot_assert($ambiguous->handle($customer, $csrf, $base + ['action' => 'retrieve'], $responseTime += 31)[0] === 502, 'conflicting response interpretations fail closed');
    $transport->responses = [['http_status' => 403, 'body' => ['error' => ['code' => 'NO_RECORD']]]];
    byot_assert($bodyController->handle($customer, $csrf, $base + ['action' => 'retrieve'], $responseTime += 31)[0] === 403, 'HTTP authorisation denial cannot become a missing record');
    $stageMapping = emergency_mapping();
    $stageMapping['rate_limits']['provider'] = 'stage-test';
    $stageMapping['rate_limits']['rules'][1]['limit'] = 1;
    $stageMapping['submission_stages'] = ['submit', 'commit'];
    $stageMapping['allow_stage_burst'] = true;
    $stageController = new \Domaintains\Byot\EmergencyController($authority, new \Domaintains\Byot\MappedEmergencyAdapter($stageMapping, $transport), $store, str_repeat('i', 32));
    $transport->responses = [['http_status' => 404, 'body' => []]];
    [, $fresh] = $stageController->handle($customer, $csrf, $base + ['action' => 'retrieve'], $responseTime += 31);
    $stageRequest = $base + ['action' => 'submit', 'confirmed' => true, 'type' => 'business', 'registration' => $fields, 'intent' => $fresh['intent']];
    $before = count($transport->requests);
    $transport->responses = [['http_status' => 202, 'body' => ['state' => 'queued', 'version' => 'new-version']]];
    [$code, $result] = $stageController->handle($customer, $csrf, $stageRequest, $responseTime);
    byot_assert($code === 502 && $result['partial'] && count($transport->requests) === $before + 1, 'each stage is rate limited before HTTP access');
    byot_assert($stageController->handle($customer, $csrf, $stageRequest, $responseTime + 31)[0] === 409 && count($transport->requests) === $before + 1, 'mapped replay preserves conflict status with zero network requests');
    $stageMapping['rate_limits']['rules'][1]['limit'] = 2;
    $stageController = new \Domaintains\Byot\EmergencyController($authority, new \Domaintains\Byot\MappedEmergencyAdapter($stageMapping, $transport), $store, str_repeat('i', 32));
    $transport->responses = [['http_status' => 404, 'body' => []]];
    [, $fresh] = $stageController->handle($customer, $csrf, $base + ['action' => 'retrieve'], $responseTime += 62);
    $stageRequest['intent'] = $fresh['intent'];
    $before = count($transport->requests);
    $transport->responses = [['http_status' => 202, 'body' => ['state' => 'queued', 'version' => 'next-version']], ['http_status' => 202, 'body' => ['state' => 'queued', 'version' => 'final-version']]];
    byot_assert($stageController->handle($customer, $csrf, $stageRequest, $responseTime)[0] === 200
        && count($transport->requests) === $before + 2 && in_array('If-Match: next-version', $transport->requests[$before + 1]['headers'], true), 'documented stage burst uses updated provider revision');
    $transport->responses = [['http_status' => 404, 'body' => []]];
    [, $fresh] = $stageController->handle($customer, $csrf, $base + ['action' => 'retrieve'], $responseTime += 31);
    $stageRequest['intent'] = $fresh['intent'];
    $transport->responses = [['http_status' => 200, 'body' => ['state' => 'declined', 'version' => 'rejected-version']]];
    $before = count($transport->requests);
    [$code, $result] = $stageController->handle($customer, $csrf, $stageRequest, $responseTime);
    byot_assert($code === 200 && $result['status'] === 'rejected' && count($transport->requests) === $before + 1, 'explicit rejection stops the remaining mutation stages');
    foreach (glob($path . '/*.json') as $file) {
        $data = file_get_contents($file);
        byot_assert(!str_contains($data, 'Example Street') && !str_contains($data, 'synthetic-provider-credential'), 'no address or carrier credentials in persisted state');
    }
    echo "Emergency customer isolation, reassignment and privacy contracts passed.\n";
} finally { if (is_dir($path)) { byot_clean($path); } }

$path = sys_get_temp_dir() . '/domaintains-emergency-concurrent-' . bin2hex(random_bytes(8));
try {
    $store = new StateStore($path);
    $records = emergency_billing_fixture('service-a');
    $records['voice']['voice-01']['numbers'][] = '12025550104';
    $records['number_assignments']['12025550104'] = $records['number_assignments']['12025550101'];
    $records['pbx']['pbx-02'] = ['account' => 'account-02', 'status' => 'active', 'service' => 'service-b'];
    $records['voice']['voice-b'] = ['account' => 'account-02', 'status' => 'active', 'numbers' => ['12025550102'], 'max_channels' => 1];
    $store->transaction('emergency-assignments', static fn(array $state, callable $save) => $save(['records' => $records]));
    $registry = new LocalRegistry($store, new LocalBilling($records), ['service-a' => byot_plan(), 'service-b' => byot_plan()]);
    $registry->link('account-01', 'pbx-01', 'service-a', ['voice-01'], time());
    $registry->link('account-02', 'pbx-02', 'service-b', ['voice-b'], time());
    $billing = new EmergencyLockedBilling($store);
    $authority = new LocalEmergencyAuthority($store, $billing, str_repeat('k', 32));
    $customer = new CustomerIdentity('account-01', 'worker-customer', time() + 3600);
    $customerB = new CustomerIdentity('account-02', 'worker-customer', time() + 3600);
    $numbers = $authority->withLease($customer, 'service-a', 'pbx-01', null, static fn(array $items): array => $items);
    $numbersB = $authority->withLease($customerB, 'service-b', 'pbx-02', null, static fn(array $items): array => $items);
    $request = ['csrf' => str_repeat('c', 64), 'action' => 'retrieve', 'service' => 'service-a', 'pbx' => 'pbx-01', 'number_id' => $numbers[0]['id']];
    $input = ['path' => $path, 'account' => 'account-01', 'request' => $request, 'now' => 1000];
    $workerA = emergency_worker($input);
    $workerB = emergency_worker($input);
    $codes = [emergency_finish($workerA)[0], emergency_finish($workerB)[0]];
    sort($codes);
    byot_assert($codes === [200, 429], 'same-number quota enforced atomically across concurrent workers');
    $other = ['path' => $path, 'account' => 'account-02', 'request' => array_replace($request, ['service' => 'service-b', 'pbx' => 'pbx-02', 'number_id' => $numbersB[0]['id']]), 'now' => 1031];
    $workerA = emergency_worker(array_replace($input, ['now' => 1031]));
    $workerB = emergency_worker($other);
    byot_assert(emergency_finish($workerA)[0] === 200 && emergency_finish($workerB)[0] === 200, 'concurrent customers do not consume each other number quotas');
    $workerA = emergency_worker(array_replace($input, ['now' => 1062]));
    $workerB = emergency_worker(array_replace($input, ['now' => 1062, 'request' => array_replace($request, ['number_id' => $numbers[1]['id']])]));
    byot_assert(emergency_finish($workerA)[0] === 200 && emergency_finish($workerB)[0] === 200, 'same customer can retrieve two numbers concurrently');
    $adapter = new EmergencyFixtureAdapter();
    $controller = new \Domaintains\Byot\EmergencyController($authority, $adapter, $store, str_repeat('i', 32));
    [, $fresh] = $controller->handle($customer, str_repeat('c', 64), $request, 1093);
    $submit = array_replace($request, ['action' => 'submit', 'intent' => $fresh['intent'], 'confirmed' => true, 'type' => 'business', 'registration' => $fields]);
    byot_assert($controller->handle($customer, str_repeat('c', 64), $submit, 1093)[0] === 200, 'retrieve then submit correction immediately without cross-operation delay');
    foreach (['provider', 'account'] as $scope) {
        $policy = ['provider' => 'concurrent-' . $scope, 'rules' => [['scope' => $scope, 'operations' => ['retrieve', 'submit', 'status'], 'limit' => 1, 'window' => 60]]];
        $workerA = emergency_worker(array_replace($input, ['now' => 1100, 'policy' => $policy]));
        $second = $scope === 'provider' ? $other : array_replace($input, ['request' => array_replace($request, ['number_id' => $numbers[1]['id']])]);
        $workerB = emergency_worker(array_replace($second, ['now' => 1100, 'policy' => $policy]));
        $codes = [emergency_finish($workerA)[0], emergency_finish($workerB)[0]];
        sort($codes);
        byot_assert($codes === [200, 429], $scope . ' aggregate quota is atomic across concurrent customers/numbers');
    }
    foreach (['provider', 'account'] as $scope) {
        $policy = ['provider' => 'quota-' . $scope, 'rules' => [['scope' => $scope, 'operations' => ['retrieve', 'submit', 'status'], 'limit' => 1, 'window' => 60]]];
        $store->transaction('quota-' . $scope, static function (array $state, callable $save) use ($policy): void {
            \Domaintains\Byot\EmergencyRateLimits::reserve($state, $policy, 'account-01', 'number-a', 'retrieve', 1000, str_repeat('q', 32));
            $save($state);
        });
        $limited = false;
        try {
            $store->transaction('quota-' . $scope, static function (array $state) use ($policy): void {
                \Domaintains\Byot\EmergencyRateLimits::reserve($state, $policy, 'account-01', 'number-b', 'submit', 1001, str_repeat('q', 32));
            });
        } catch (\Domaintains\Byot\EmergencyThrottle $error) { $limited = $error->delays['submit'] === 59; }
        byot_assert($limited, $scope . ' aggregate quota enforces documented cross-operation restrictions');
        if ($scope === 'account') {
            $store->transaction('quota-account', static function (array $state) use ($policy): void {
                \Domaintains\Byot\EmergencyRateLimits::reserve($state, $policy, 'account-02', 'number-c', 'submit', 1001, str_repeat('q', 32));
            });
        }
    }
    $writer = null;
    $move = ['account' => 'account-02', 'service' => 'service-b', 'pbx' => 'pbx-02', 'status' => 'active', 'revision' => 2];
    $authority->withLease($customer, 'service-a', 'pbx-01', $numbers[0]['id'], function (EmergencyLease $lease) use ($path, $move, &$writer): void {
        $lease->request(function () use ($path, $move, &$writer): array {
            $writer = emergency_worker(['path' => $path, 'move' => $move]);
            byot_assert(trim(fgets($writer[1][1])) === 'blocked', 'concurrent assignment writer blocked for entire provider request');
            return [];
        });
    });
    byot_assert(emergency_finish($writer)['moved'] === true, 'assignment move commits only after request releases its ownership lease');
    $callsBefore = $adapter->calls;
    foreach (['retrieve', 'status', 'submit'] as $action) {
        $denied = array_replace($submit, ['action' => $action]);
        if ($action !== 'submit') { $denied = array_intersect_key($denied, $request); }
        byot_assert($controller->handle($customer, str_repeat('c', 64), $denied, 1200)[0] === 403 && $adapter->calls === $callsBefore, 'old customer cannot access individually moved number');
    }
    byot_assert($controller->handle($customer, str_repeat('c', 64), array_replace($request, ['number_id' => $numbers[1]['id']]), 1200)[0] === 200, 'moving one number leaves other numbers on the same product accessible');
    $store->transaction('emergency-assignments', static function (array $state, callable $save): void {
        $state['records']['voice']['voice-01']['numbers'] = ['12025550104'];
        $state['records']['voice']['voice-b']['numbers'][] = '12025550101';
        $save($state);
    });
    $registry = new LocalRegistry($store, new LocalBilling($store->read('emergency-assignments')['records']), ['service-a' => byot_plan(), 'service-b' => byot_plan()]);
    $registry->link('account-02', 'pbx-02', 'service-b', ['voice-b'], time());
    $moved = $authority->withLease($customerB, 'service-b', 'pbx-02', null, static fn(array $items): array => $items);
    $movedId = array_column($moved, 'id', 'label')['12025550101'];
    $newOwnerRequest = array_replace($request, ['service' => 'service-b', 'pbx' => 'pbx-02', 'number_id' => $movedId]);
    byot_assert($controller->handle($customerB, str_repeat('c', 64), $newOwnerRequest, 1231)[0] === 200, 'new owner accesses individually moved number only after current assignment and product entitlements agree');
    byot_assert($controller->handle($customerB, str_repeat('c', 64), array_replace($newOwnerRequest, ['number_id' => $numbers[0]['id']]), 1262)[0] === 403, 'old selector cannot be reused even by the new customer');
    echo "Emergency concurrent-customer, scoped-quota and assignment-lock contracts passed.\n";
} finally { if (is_dir($path)) { byot_clean($path); } }