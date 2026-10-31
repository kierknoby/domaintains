<?php
declare(strict_types=1);

use Domaintains\NumberSearch\{AvailabilityAudit, AvailabilityCarrier, AvailabilityQueue, AvailabilityService};
require dirname(__DIR__) . '/src/Availability.php';
require dirname(__DIR__) . '/src/Runtime.php';
require dirname(__DIR__) . '/fixtures/Adapters.php';

function search_assert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function search_clean(string $path): void { foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { unlink($path . '/' . $name); } } rmdir($path); }

$directory = sys_get_temp_dir() . '/domaintains-search-' . bin2hex(random_bytes(8));
$now = 2000000.0;
$clock = static function () use (&$now): float { return $now; };
$wait = static function (int $microseconds) use (&$now): void { $now += $microseconds / 1000000; };
$carrier = new class implements AvailabilityCarrier {
    public array $calls = [];
    public array $response = ['02041234567', '442041234567', '442047654321', '01611234567'];
    public int $httpCode = 200;
    public int $curlError = 0;
    public ?string $rawBody = null;
    public ?Closure $duringCall = null;
    public function available(string $range, int $size): array { $this->calls[] = [$range, $size]; if ($this->duringCall) { ($this->duringCall)(); } return ['http_code' => $this->httpCode, 'curl_errno' => $this->curlError, 'body' => $this->rawBody ?? json_encode($this->response)]; }
};
try {
    $regions = ['England' => ['London' => ['0204' => 'London']]];
    $queue = new AvailabilityQueue($directory . '/rate.lock', $clock, $wait, static fn(): bool => false);
    $audit = new AvailabilityAudit($directory);
    $authorise = static function (array $session, array $request): array {
        if (($request['service_id'] ?? '') !== 'example') { throw new \Domaintains\NumberSearch\AvailabilityFailure(403, 'forbidden'); }
        return ['client_id' => 'account-a', 'service_id' => 'sub-a', 'product_id' => 'voice-plan', 'context' => 'existing_subscription', 'selection_scope' => 'test-owner'];
    };
    $service = new AvailabilityService($carrier, $queue, $audit, $regions, ['https://portal.example'], $authorise, $clock,
        new \Domaintains\NumberSearch\SessionOfferEvidence(), static fn(array $state): \Domaintains\NumberSearch\CustomerIdentity => new \Domaintains\NumberSearch\CustomerIdentity('account-a'));
    $config = \Domaintains\NumberSearch\Fixtures\Reference::config($directory, $clock, $wait);
    $config['asset_url'] = '/local-assets';
    $cartSession = ['account_id' => 'account-a'];
    search_assert(\Domaintains\NumberSearch\Runtime::context($config, $cartSession, ['context' => 'cart-item-a'])['product_id'] === 'voice-plan', 'cart membership is server verified');
    foreach ([['context' => 'cart-missing'], ['context' => 'update-missing'], ['context' => 'cart-other-item']] as $invalid) {
        try { \Domaintains\NumberSearch\Runtime::context($config, $cartSession, $invalid); throw new RuntimeException('foreign context accepted'); }
        catch (\Domaintains\NumberSearch\AvailabilityFailure $error) { search_assert($error->status === 403, 'foreign context rejected'); }
    }
    search_assert(\Domaintains\NumberSearch\Runtime::context($config, $cartSession, ['context' => 'update-sub-a'])['service_id'] === 'sub-a', 'active owned subscription accepted');
    try { \Domaintains\NumberSearch\Runtime::context($config, ['account_id' => 'account-b'], ['context' => 'update-sub-a']); throw new RuntimeException('foreign account accepted'); }
    catch (\Domaintains\NumberSearch\AvailabilityFailure $error) { search_assert($error->status === 403, 'catalog ownership is authoritative'); }
    $config['fixture_state']->subscriptions['sub-a']['active'] = false;
    try { \Domaintains\NumberSearch\Runtime::context($config, $cartSession, ['context' => 'update-sub-a']); throw new RuntimeException('inactive subscription accepted'); }
    catch (\Domaintains\NumberSearch\AvailabilityFailure $error) { search_assert($error->status === 403, 'inactive subscription rejected'); }
    $config['fixture_state']->subscriptions['sub-a']['active'] = true;
    $html = \Domaintains\NumberSearch\Runtime::subscriptionPicker($config, $cartSession, 'sub-a');
    search_assert(str_contains($html, '/local-assets/dn-progressive-search.js') && str_contains($html, 'DNNumberPickerMount'), 'subscription uses the canonical shared engine');
    $session = ['account_id' => 'account-a', 'domaintains_number_nonce' => str_repeat('a', 48), 'domaintains_number_nonce_time' => (int)$now];
    $request = ['nonce' => str_repeat('a', 48), 'service_id' => 'example', 'prefix' => '0204', 'action' => 'initial'];
    $server = ['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://portal.example', 'REMOTE_ADDR' => '192.0.2.1'];
    [$status, $body] = $service->handle($session, $request, $server);
    search_assert($status === 200 && count($body['numbers']) === 2, 'canonical duplicate and foreign-area prevention');
    search_assert($carrier->calls === [['0204_______', 20]], 'initial retrieval is exactly one 20-number request');
    $request['action'] = 'refresh';
    search_assert($service->handle($session, $request, $server)[0] === 200 && count($carrier->calls) === 2 && $now >= 2000005, 'refresh makes one request with persistent five-second spacing');
    $request['action'] = 'search'; $request['search'] = '12'; $request['position'] = '5';
    [$status, $body] = $service->handle($session, $request, $server);
    search_assert($status === 200 && count($body['numbers']) === 2, 'broad masked carrier response retained for client-side OR matching');
    search_assert($carrier->calls[2][0] === '0204_____12', 'subscriber-only wildcard construction');
    $carrier->response = [];
    search_assert($service->handle($session, $request, $server)[1]['numbers'] === [], 'empty carrier lists stay empty');
    search_assert($service->verifySelection($session, $request, $server, '02041234567') === '442041234567', 'prior masked offer survives a fresh broad batch without that number');
    try { $service->verifySelection($session, $request, $server, '02049999999'); throw new RuntimeException('forged selection accepted'); }
    catch (\Domaintains\NumberSearch\AvailabilityFailure $error) { search_assert($error->status === 400, 'forged selection fails'); }
    $session['domaintains_search_offers']['test-owner']['0204']['442049999999'] = (int)$now + 1;
    try { $service->verifySelection($session, $request, $server, '02049999999'); throw new RuntimeException('future offer accepted'); }
    catch (\Domaintains\NumberSearch\AvailabilityFailure $error) { search_assert($error->status === 400, 'future evidence fails'); }
    $session['domaintains_search_offers']['test-owner']['0204']['442049999999'] = (int)$now - 1801;
    try { $service->verifySelection($session, $request, $server, '02049999999'); throw new RuntimeException('expired offer accepted'); }
    catch (\Domaintains\NumberSearch\AvailabilityFailure $error) { search_assert($error->status === 400, 'expired evidence fails'); }
    $carrier->response = array_map(static fn(int $index): string => '0204' . str_pad((string)$index, 7, '0', STR_PAD_LEFT), range(0, 29));
    [$status, $body] = $service->handle($session, $request, $server);
    search_assert($status === 200 && count($body['numbers']) === 20 && count(array_filter($body['numbers'], static fn(array $number): bool => $number['recommended'])) === 3, 'twenty offers with exactly three recommendations');
    $carrier->httpCode = 401;
    $carrier->duringCall = static function () use (&$now): void { $now += 2.0; };
    search_assert($service->handle($session, $request, $server)[0] === 502, 'carrier HTTP errors fail safely');
    $failedEvent = json_decode(array_slice(file(glob($directory . '/*.jsonl')[0], FILE_IGNORE_NEW_LINES), -1)[0], true);
    search_assert($failedEvent['queue_ms'] === 5000 && $failedEvent['carrier_ms'] === 2000, 'carrier failures keep queue and transport durations separate');
    $carrier->duringCall = null;
    $carrier->httpCode = 200; $carrier->curlError = 28;
    search_assert($service->handle($session, $request, $server)[0] === 502, 'carrier timeouts fail safely');
    $carrier->curlError = 0; $carrier->rawBody = '{bad';
    search_assert($service->handle($session, $request, $server)[0] === 502, 'invalid JSON fails safely');
    $carrier->rawBody = '{"number":"02041234567"}';
    search_assert($service->handle($session, $request, $server)[0] === 502, 'non-list carrier response fails safely');
    $carrier->rawBody = null;
    $calls = count($carrier->calls);
    foreach ([['nonce' => 'bad'], ['prefix' => '020'], ['search' => 'x'], ['position' => '6'], ['service_id' => 'foreign']] as $invalid) {
        search_assert($service->handle($session, array_replace($request, $invalid), $server)[0] !== 200 && count($carrier->calls) === $calls, 'invalid or unauthorised request never reaches carrier');
    }
    $deniedRequest = array_replace($request, ['service_id' => 'foreign', 'context' => 'update-999']);
    search_assert($service->handle($session, $deniedRequest, $server)[0] === 403, 'denied subscription request');
    $deniedEvent = json_decode(array_slice(file(glob($directory . '/*.jsonl')[0], FILE_IGNORE_NEW_LINES), -1)[0], true);
    search_assert($deniedEvent['action'] === 'search' && $deniedEvent['context'] === 'unverified_subscription'
        && $deniedEvent['client_id'] === 'account-a' && !isset($deniedEvent['service_id']) && !isset($deniedEvent['product_id'])
        && $deniedEvent['carrier_contacted'] === 0, 'authorisation audit preserves action without trusting claimed ownership');
    $session['domaintains_availability_times'] = array_fill(0, 30, (int)$now);
    search_assert($service->handle($session, $request, $server)[0] === 429 && count($carrier->calls) === $calls, 'session restrictions are server-enforced');
    search_assert($service->handle($session, $request, array_replace($server, ['HTTP_ORIGIN' => 'https://foreign.example']))[0] === 403, 'origin restriction');
    foreach ([(int)$now - 86401, (int)$now + 1, 0] as $invalidTime) {
        $expired = $session; $expired['domaintains_number_nonce_time'] = $invalidTime;
        [$status, $response] = $service->handle($expired, $request, $server);
        search_assert($status === 403 && $response['error'] === 'Selection session expired', 'expired/future nonce response is precise');
    }
    $cancelled = new AvailabilityQueue($directory . '/rate.lock', $clock, $wait, static fn(): bool => true);
    try { $cancelled->run(static function (): array { throw new RuntimeException('cancelled request reached carrier'); }); throw new RuntimeException('cancelled queue accepted'); }
    catch (\Domaintains\NumberSearch\AvailabilityFailure $error) { search_assert($error->reason === 'cancelled', 'cancelled queue never contacts carrier'); }
    $holder = fopen($directory . '/rate.lock', 'c+'); flock($holder, LOCK_EX);
    $queueStart = $now;
    try { $queue->run(static function (): array { throw new RuntimeException('overlapping carrier call'); }); throw new RuntimeException('locked queue accepted'); }
    catch (\Domaintains\NumberSearch\AvailabilityFailure $error) { search_assert($error->reason === 'local_queue_timeout' && $now - $queueStart < 15.3, 'independent lock holder blocks all calls with bounded queue'); }
    finally { flock($holder, LOCK_UN); fclose($holder); }
    file_put_contents($directory . '/rate.lock', (string)($now + 1000));
    $queueStart = $now;
    try { $queue->run(static fn(): array => []); throw new RuntimeException('future limiter stamp accepted'); }
    catch (\Domaintains\NumberSearch\AvailabilityFailure $error) { search_assert($error->reason === 'local_queue_timeout' && $now - $queueStart < 15.3, 'spacing wait is bounded too'); }
    unlink($directory . '/rate.lock');
    $GLOBALS['fixture_search_config'] = \Domaintains\NumberSearch\Fixtures\Reference::config($directory, $clock, $wait);
    $GLOBALS['fixture_search_config']['operator_bootstrap'] = static function (): void { $GLOBALS['fixture_bootstrapped'] = true; };
    $configFile = $directory . '/fixture-config.php';
    file_put_contents($configFile, '<?php return $GLOBALS["fixture_search_config"];'); chmod($configFile, 0600);
    putenv('DOMAINTAINS_NUMBER_SEARCH_CONFIG=' . $configFile);
    $_SESSION = ['account_id' => 'account-a', 'csrf_token' => 'fixture-token'];
    \Domaintains\NumberSearch\Runtime::nonce($_SESSION);
    $_SESSION['domaintains_number_nonce_time'] = (int)$now;
    $_SERVER = array_replace($server, ['HTTP_X_OPERATOR_CSRF' => 'fixture-token']);
    require dirname(__DIR__) . '/integration/operator.php';
    $operator = domaintainsOperatorIntegration();
    $operator->register();
    $fixtureLifecycle = $GLOBALS['fixture_search_config']['ordering_lifecycle'];
    search_assert(($GLOBALS['fixture_bootstrapped'] ?? false) === true, 'operator bootstrap is configurable');
    $html = \Domaintains\NumberSearch\Runtime::picker($GLOBALS['fixture_search_config'], $_SESSION,
        ['context' => 'cart-item-a', 'mode' => 'cart', 'targetId' => 'fixture-target', 'selectionFieldName' => 'selected_number']);
    search_assert(str_contains($html, '"selectionFieldName":"selected_number"') && str_contains($html, '"context":"cart-item-a"'), 'configured field binds an opaque catalog item');
    search_assert(is_callable($fixtureLifecycle->validate) && is_callable($fixtureLifecycle->checkout) && is_callable($fixtureLifecycle->allocate), 'operator lifecycle registers all gates');
    require dirname(__DIR__) . '/integration/subscription.php';
    $result = domaintainsOperatorSearchAdd('sub-a', '0204', '02041234567');
    search_assert($result['fixture_only'] === true && array_column($GLOBALS['fixture_search_config']['fixture_state']->events, 0)
        === ['billing_preflight', 'provisioning_preflight', 'allocate_activate', 'billing_reconcile'], 'subscription bridge separates verified provisioning and billing');
    $_SERVER['HTTP_X_OPERATOR_CSRF'] = 'invalid';
    try { domaintainsOperatorSearchAdd('sub-a', '0204', '02041234567'); throw new RuntimeException('invalid form token accepted'); }
    catch (\Domaintains\NumberSearch\AvailabilityFailure $error) { search_assert(count($GLOBALS['fixture_search_config']['fixture_state']->events) === 4, 'invalid CSRF never reaches mutation adapters'); }
    putenv('DOMAINTAINS_NUMBER_SEARCH_CONFIG');
    foreach (glob($directory . '/*.*') as $file) {
        if (str_ends_with($file, '.csv') || str_ends_with($file, '.jsonl')) {
            $data = file_get_contents($file);
            search_assert(!str_contains($data, '442041234567') && !str_contains($data, '02041234567') && !str_contains($data, str_repeat('a', 48)), 'audit omits numbers and nonce');
            search_assert((fileperms($file) & 0777) === 0600, 'private audit permissions');
        }
    }
    $jsonFile = glob($directory . '/*.jsonl')[0];
    $events = array_map(static fn(string $line): array => json_decode($line, true, 16, JSON_THROW_ON_ERROR), file($jsonFile, FILE_IGNORE_NEW_LINES));
    $success = array_values(array_filter($events, static fn(array $event): bool => $event['outcome'] === 'success'))[0];
    foreach (['client_id', 'service_id', 'product_id', 'source_ip', 'action', 'carrier_http', 'carrier_records', 'valid_offers', 'carrier_ms', 'queue_ms', 'elapsed_ms'] as $key) {
        search_assert(array_key_exists($key, $success), 'audit includes required attribution/count/timing field ' . $key);
    }
    $csv = fopen(glob($directory . '/*.csv')[0], 'r');
    search_assert(fgetcsv($csv, null, ',', '"', '') === AvailabilityAudit::FIELDS, 'CSV fixed schema');
    $rows = 0; while (($row = fgetcsv($csv, null, ',', '"', '')) !== false) { search_assert(count($row) === count(AvailabilityAudit::FIELDS), 'parseable CSV cells'); $rows++; } fclose($csv);
    search_assert($rows === count($events), 'one CSV and JSONL record per attempt');
    echo "Availability, offer evidence, session security, audit, five-second spacing, bounded queue and cancellation contracts passed.\n";
} finally { if (is_dir($directory)) { search_clean($directory); } }