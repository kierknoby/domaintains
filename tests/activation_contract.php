<?php

declare(strict_types=1);

if (!interface_exists('BMO')) {
	interface BMO {}
}
if (!function_exists('_')) {
	function _($message) {
		return $message;
	}
}

require_once dirname(__DIR__) . '/Domaintains.class.php';

class FreePBX {
	public static $module;
	public static function Domaintains() { return self::$module; }
	public static function Modules() { throw new RuntimeException('Synthetic metadata unavailable.'); }
}

class ActivationContractDialplan {
	public $_exts = [];
	public function add($context, $extension, $label, $command): void {
		$this->_exts[$context][' ' . $extension . ' '][] = ['cmd' => $command];
	}
}

class ActivationContractSilentDialplan extends ActivationContractDialplan {
	public function add($context, $extension, $label, $command): void {}
}

class ActivationContractApplication {
	protected $data;
	public function __construct($data = '') { $this->data = $data; }
}
class ext_answer extends ActivationContractApplication { public function output() { return 'Answer'; } }
class ext_wait extends ActivationContractApplication { public function output() { return 'Wait(' . $this->data . ')'; } }
class ext_playtones extends ActivationContractApplication { public function output() { return 'Playtones(' . $this->data . ')'; } }
class ext_stopplaytones extends ActivationContractApplication { public function output() { return 'StopPlaytones'; } }
class ext_hangup extends ActivationContractApplication { public function output() { return 'Hangup(' . $this->data . ')'; } }

function activation_assert(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

class ActivationContractCoreApi {
	public $trunks = [];
	public $details = [];
	public $addCalls = 0;
	public $lastTrunkSettings = [];
	public $postWasIsolated = false;
	public $dids = [];
	public $didAddCalls = 0;
	public $failDID = false;
	public $corruptDID = false;
	public $editCalls = 0;
	public $didException = null;
	public $failEdit = false;
	public $trunkListCalls = 0;
	public $corruptMaxChannelsAfterListCalls = null;

	public function getAllDIDs(): array {
		if ($this->didException !== null) { throw $this->didException; }
		return $this->dids;
	}
	public function addDID(array $settings): bool {
		if ($this->failDID) { return false; }
		$this->didAddCalls++;
		if ($this->corruptDID) { $settings['destination'] = 'unrelated,s,1'; }
		$this->dids[] = $settings;
		return true;
	}
	public function deleteTrunk($id, $technology, $edit): bool {
		$this->trunks = array_values(array_filter($this->trunks, function ($trunk) use ($id) { return $trunk['trunkid'] !== $id; }));
		unset($this->details[$id]);
		return true;
	}

	public function listTrunks(): array {
		$this->trunkListCalls++;
		$trunks = $this->trunks;
		if ($this->corruptMaxChannelsAfterListCalls !== null && $this->trunkListCalls > $this->corruptMaxChannelsAfterListCalls) {
			foreach ($trunks as &$trunk) {
				unset($trunk['maxchans']);
			}
			unset($trunk);
		}
		return $trunks;
	}

	public function getTrunkDetails($trunkId) {
		return isset($this->details[(string)$trunkId]) ? $this->details[(string)$trunkId] : false;
	}

	public function checkPJSIPsettings(array $settings, array $posts): array {
		return array_merge([
			'username' => '',
			'auth_username' => '',
			'secret' => '',
			'authentication' => 'outbound',
			'registration' => 'send',
			'sendrpid' => 'no',
			'context' => 'from-pstn',
		], $settings, isset($posts['imports']) ? $posts['imports'] : []);
	}

	public function addTrunk(string $name, string $technology, array $settings, bool $edit = false): string {
		if ($edit && $this->failEdit) { throw new RuntimeException('Synthetic managed trunk edit failure.'); }
		if ($edit) { $this->editCalls++; } else { $this->addCalls++; }
		$this->postWasIsolated = empty($_POST);
		$this->lastTrunkSettings = $settings;
		$trunkId = $edit ? $settings['trunknum'] : 'synthetic-trunk-' . $this->addCalls;
		$this->trunks[] = [
			'trunkid' => $trunkId,
			'name' => $name,
			'tech' => $technology,
			'disabled' => 'off',
			'maxchans' => isset($settings['maxchans']) ? (string)$settings['maxchans'] : '',
		];
		$details = $settings;
		unset($details['maxchans']);
		$this->details[$trunkId] = $details;
		return $trunkId;
	}

	public function seedManagedTrunk(string $host = 'sip.example.invalid', int $port = 5060, array $overrides = [], string $role = 'outbound', string $trunkId = 'synthetic-trunk-seeded'): void {
		$marker = $role === 'outbound' ? 'OUT' : 'IN';
		$trunkName = 'DOMAINTAINS-' . $marker . '-' . substr(hash('sha256', json_encode([$role, $host, $port], JSON_UNESCAPED_SLASHES)), 0, 12);
		$this->trunks[] = ['trunkid' => $trunkId, 'name' => $trunkName, 'tech' => 'pjsip', 'disabled' => 'off', 'maxchans' => '5'];
		$this->details[$trunkId] = array_merge([
			'trunk_name' => $trunkName,
			'sip_server' => $host,
			'sip_server_port' => (string)$port,
			'context' => 'from-pstn',
			'sendrpid' => 'no',
			'authentication' => 'off',
			'registration' => 'none',
			'username' => '',
			'auth_username' => '',
			'secret' => '',
		], $overrides);
	}
}

class ActivationContractFirewallApi {
	public $networkMaps = [];
	public $addCalls = 0;

	public function get_networkmaps() {
		return $this->networkMaps;
	}

	public function addNetworkToZone(string $host, string $zone): void {
		$this->addCalls++;
		if (!is_array($this->networkMaps)) {
			$this->networkMaps = [];
		}
		$this->networkMaps[$host] = $zone;
	}
}

class ActivationContractRoutingApi {
	public $coreApi = null;
	public $routes = [];
	public $patterns = [];
	public $trunks = [];
	public $addCalls = 0;
	public $failAdd = false;
	private $transactionSnapshot = null;

	public function listAll(): array {
		return $this->routes;
	}

	public function getRoutePatternsById($routeId): array {
		return isset($this->patterns[(string)$routeId]) ? $this->patterns[(string)$routeId] : [];
	}

	public function getRouteTrunksById($routeId): array {
		return isset($this->trunks[(string)$routeId]) ? $this->trunks[(string)$routeId] : [];
	}

	public function add($name, $outcid, $outcidMode, $password, $emergency, $intracompany, $moh, $timeGroup, $patterns, $trunks, $sequence) {
		$this->addCalls++;
		$routeId = 'synthetic-route-' . $this->addCalls;
		if ($sequence === 'bottom') {
			$sequence = 0;
			foreach ($this->routes as $route) {
				$sequence = max($sequence, (int)$route['seq'] + 1);
			}
		}
		$this->routes[] = ['route_id' => $routeId, 'name' => $name, 'seq' => $sequence];
		$this->patterns[$routeId] = $patterns;
		$this->trunks[$routeId] = $trunks;
		if ($this->failAdd) {
			throw new RuntimeException('Synthetic route creation failure after partial writes.');
		}
		return $routeId;
	}

	public function beginTransaction(): bool {
		$this->transactionSnapshot = [$this->routes, $this->patterns, $this->trunks, $this->coreApi->trunks, $this->coreApi->details];
		return true;
	}

	public function commit(): bool {
		$this->transactionSnapshot = null;
		return true;
	}

	public function inTransaction(): bool {
		return $this->transactionSnapshot !== null;
	}

	public function rollBack(): bool {
		if ($this->transactionSnapshot === null) {
			return false;
		}
		list($this->routes, $this->patterns, $this->trunks, $this->coreApi->trunks, $this->coreApi->details) = $this->transactionSnapshot;
		$this->transactionSnapshot = null;
		return true;
	}
}

class ActivationContractModule extends \FreePBX\modules\Domaintains {
	private $directory;
	private $responses;
	private $lastResponse = '';
	private $requests = [];
	private $core;
	private $routing;
	private $firewall;
	public $localHostnameValue = 'my-12345678';
	public $activationHostWasLocalBeforeRequest = false;
	public $reloadRequests = 0;
	public $remoteException = null;
	public $reloadException = null;
	public $failDialplanVerification = false;

	public function __construct(string $directory, array $responses, $core = null, $routing = null, $firewall = null) {
		parent::__construct(new stdClass());
		$this->directory = $directory;
		$this->responses = $responses;
		$this->core = $core === null ? new ActivationContractCoreApi() : $core;
		$this->routing = $routing === null ? new ActivationContractRoutingApi() : $routing;
		$this->routing->coreApi = $this->core;
		$this->firewall = $firewall === null ? new ActivationContractFirewallApi() : $firewall;
		FreePBX::$module = $this;
	}

	protected function dialplanBuilder() {
		return $this->failDialplanVerification ? new ActivationContractSilentDialplan() : new ActivationContractDialplan();
	}

	protected function storageDirectory(): string {
		return $this->directory;
	}

	protected function coreApi() {
		return $this->core;
	}

	protected function routingApi() {
		return $this->routing;
	}

	protected function databaseApi() {
		return $this->routing;
	}

	protected function firewallApi() {
		return $this->firewall;
 	}

	protected function requestConfigurationReload(): void {
		$this->reloadRequests++;
		if ($this->reloadException !== null) { throw $this->reloadException; }
	}

	protected function supportsSodium(): bool {
		return true;
	}

	protected function localHostname(): string {
		return $this->localHostnameValue;
	}

	protected function supportsHttpsTransport(): bool {
		return true;
	}

	protected function loadOrCreateSigningKeypair(bool $allowCreate = true): array {
		$keyPath = $this->directory . '/signing.key';
		if (is_file($keyPath)) {
			return ['synthetic-public-key', (string)file_get_contents($keyPath)];
		}
		if (file_put_contents($keyPath, 'synthetic-test-signing-key') === false || !chmod($keyPath, 0600)) {
			throw new RuntimeException('Unable to create synthetic signing key.');
		}
		return ['synthetic-public-key', 'synthetic-test-signing-key'];
	}

	protected function signActivationClaim(string $claim, string $secretKey): string {
		return hash('sha512', $secretKey . $claim, true);
	}

	public $clock = null;

	protected function currentTime(): int {
		return $this->clock === null ? parent::currentTime() : $this->clock++;
	}

	protected function signatureLength(): int {
		return 64;
	}

	protected function clearSigningSecret(string &$secretKey): void {
		$secretKey = '';
	}

	protected function sendActivationRequest(string $request): string {
		$this->requests[] = $request;
		if ($this->remoteException !== null) { throw $this->remoteException; }
		$this->activationHostWasLocalBeforeRequest = isset($this->firewall->networkMaps['my-connect.freepbxhosting.uk']) && $this->firewall->networkMaps['my-connect.freepbxhosting.uk'] === 'internal';
		if ($this->responses !== []) {
			$this->lastResponse = (string)array_shift($this->responses);
		}
		return $this->lastResponse;
	}

	public function queueResponse(string $response): void {
		$this->responses[] = $response;
	}

	public function requests(): array {
		return $this->requests;
	}

	public function routePatternPolicy(string $service): array {
		return $this->outboundRoutePatterns($service);
	}

	public function coreFixture() {
		return $this->core;
	}

	public function routingFixture() {
		return $this->routing;
	}

	public function firewallFixture() {
		return $this->firewall;
	}
}

function activation_fixture_response(array $overrides = []): string {
	$values = [
		'ok' => true,
		'service' => 'my-12345678',
		'profile' => 'test-profile',
		'domaintains_hostname' => 'pbx.example.invalid',
		'max_channels' => 5,
		'numbers' => ['447700900123', '447700900124'],
		'trunks' => [
			['role' => 'outbound', 'sip_host' => 'sip.example.invalid', 'sip_port' => 5060],
		],
	];
	return json_encode(array_merge($values, $overrides), JSON_UNESCAPED_SLASHES);
}

function activation_temp_directory(): string {
	$directory = sys_get_temp_dir() . '/domaintains-contract-' . bin2hex(random_bytes(8));
	if (!mkdir($directory, 0700)) {
		throw new RuntimeException('Unable to create contract test directory.');
	}
	return $directory;
}

function activation_cleanup(string $directory): void {
	foreach (scandir($directory) as $entry) {
		if ($entry !== '.' && $entry !== '..') {
			unlink($directory . '/' . $entry);
		}
	}
	rmdir($directory);
}

function activation_attempt(string $response): array {
	$directory = activation_temp_directory();
	try {
		$module = new ActivationContractModule($directory, [$response]);
		$result = $module->activate('synthetic-test-token');
		return [$result, $directory, $module];
	} catch (\Throwable $e) {
		activation_cleanup($directory);
		throw $e;
	}
}

$originalPost = $_POST;
$_POST = ['activation_key' => 'synthetic-test-token'];
list($result, $directory, $module) = activation_attempt(activation_fixture_response());
activation_assert($_POST === ['activation_key' => 'synthetic-test-token'], 'activation should restore the caller request after the Core API call');
$_POST = $originalPost;
activation_assert($result['success'] === true, 'ok=true with all required flat fields should activate');
$stored = json_decode((string)file_get_contents($directory . '/state.json'), true);
foreach (['service', 'trunks', 'profile', 'domaintains_hostname', 'numbers', 'max_channels'] as $field) {
	activation_assert(array_key_exists($field, $stored), 'persisted state should include required field ' . $field);
}
activation_assert($stored['max_channels'] === 5, 'persisted state should contain provider-authorized max_channels');
activation_assert($stored['provisioned'] === true, 'fresh local provisioning should set provisioned only after verification');
activation_assert($module->coreFixture()->addCalls === 1, 'fresh provisioning should create one PJSIP trunk');
activation_assert($module->coreFixture()->postWasIsolated, 'Core trunk creation must not receive the activation POST body');
activation_assert(!isset($module->coreFixture()->lastTrunkSettings['activation_key']), 'activation key must not be forwarded to the Core trunk API');
activation_assert($module->routingFixture()->addCalls === 1, 'fresh provisioning should create one outbound route');
activation_assert($module->reloadRequests === 1, 'fresh provisioning should request one reload after configuration changes');
activation_assert($module->firewallFixture()->addCalls === 1 && $module->activationHostWasLocalBeforeRequest, 'activation host should be ensured in the Firewall internal zone before HTTP activation');
$trunkRecord = $module->coreFixture()->trunks[0];
$trunkSettings = $module->coreFixture()->getTrunkDetails($trunkRecord['trunkid']);
activation_assert($trunkRecord['tech'] === 'pjsip', 'managed trunk should use PJSIP');
activation_assert(!array_key_exists('maxchans', $trunkSettings), 'technology-specific trunk details must not supply the main-record maxchans field');
activation_assert($trunkSettings['authentication'] === 'off' && $trunkSettings['registration'] === 'none', 'managed trunk must render both FreePBX GUI None selections without authentication or registration');
activation_assert($trunkSettings['sip_server'] === 'sip.example.invalid' && $trunkSettings['sip_server_port'] === '5060', 'managed trunk should use the authorized SIP server and port');
activation_assert($trunkRecord['maxchans'] === '5', 'new outbound managed trunk should use the provider-authorized channel limit');
activation_assert($trunkSettings['context'] === 'from-pstn' && $trunkSettings['sendrpid'] === 'no', 'managed trunk should apply the required context and sendrpid policy');
$routeTrunks = array_values($module->routingFixture()->trunks);
activation_assert(count($routeTrunks) === 1 && count($routeTrunks[0]) === 1 && $routeTrunks[0][0] === $trunkRecord['trunkid'], 'outbound route must use only the DOMAINTAINS trunk');
$firstClaim = json_decode($module->requests()[0], true)['claim'];
activation_assert(array_keys($firstClaim) === ['token', 'public_key', 'timestamp', 'nonce', 'service'] && $firstClaim['service'] === 'my-12345678', 'signed claim must append the validated local service identity in the canonical field order');
$storedFiles = file_get_contents($directory . '/state.json') . file_get_contents($directory . '/signing.key');
activation_assert(strpos($storedFiles, 'synthetic-test-token') === false, 'successful activation must not persist the plaintext activation token');
activation_cleanup($directory);

$directory = activation_temp_directory();
$opaqueMetadataModule = new ActivationContractModule($directory, [activation_fixture_response(['opaque_provider_metadata' => ['ignored' => true]])]);
$opaqueMetadataResult = $opaqueMetadataModule->activate('synthetic-test-token');
$opaqueMetadataState = json_decode((string)file_get_contents($directory . '/state.json'), true);
activation_assert($opaqueMetadataResult['success'] === true, 'extra opaque provider metadata should be ignored');
activation_assert(!array_key_exists('opaque_provider_metadata', $opaqueMetadataState), 'opaque provider metadata must not be persisted');
activation_cleanup($directory);

$directory = activation_temp_directory();
$emptyFirewall = new ActivationContractFirewallApi();
$emptyFirewall->networkMaps = false;
$emptyFirewallModule = new ActivationContractModule($directory, [activation_fixture_response()], null, null, $emptyFirewall);
$emptyFirewallResult = $emptyFirewallModule->activate('synthetic-test-token');
activation_assert($emptyFirewallResult['success'] === true, 'an unset Firewall map should be treated as empty on first activation');
activation_assert($emptyFirewall->networkMaps['my-connect.freepbxhosting.uk'] === 'internal', 'first activation should add the service host to the local zone');
activation_cleanup($directory);

$directory = activation_temp_directory();
$conflictingFirewall = new ActivationContractFirewallApi();
$conflictingFirewall->networkMaps['my-connect.freepbxhosting.uk'] = 'other';
$firewallConflictModule = new ActivationContractModule($directory, [activation_fixture_response()], null, null, $conflictingFirewall);
$firewallConflictResult = $firewallConflictModule->activate('synthetic-test-token');
activation_assert($firewallConflictResult['success'] === false, 'conflicting Firewall zone assignment must fail closed');
activation_assert(count($firewallConflictModule->requests()) === 0 && $conflictingFirewall->addCalls === 0, 'Firewall conflict must neither contact my-connect nor change the existing zone');
activation_cleanup($directory);

$directory = activation_temp_directory();
$multiTrunkResponse = activation_fixture_response([
	'trunks' => [
		['role' => 'outbound', 'sip_host' => 'outbound-a.example.invalid', 'sip_port' => 5060],
		['role' => 'inbound', 'sip_host' => 'inbound.example.invalid', 'sip_port' => 5061],
		['role' => 'outbound', 'sip_host' => 'outbound-b.example.invalid', 'sip_port' => 5060],
	],
]);
$multiTrunkModule = new ActivationContractModule($directory, [$multiTrunkResponse]);
$multiTrunkResult = $multiTrunkModule->activate('synthetic-test-token');
$multiTrunkRouteTrunks = array_values($multiTrunkModule->routingFixture()->trunks)[0];
activation_assert($multiTrunkResult['success'] === true, 'all authorized PBX-facing trunks should reconcile');
activation_assert(count($multiTrunkModule->coreFixture()->trunks) === 3, 'one managed PJSIP trunk should be created per authorized entry');
activation_assert(count($multiTrunkRouteTrunks) === 2, 'only outbound-role trunks should participate in the outbound route');
$allManagedTrunks = $multiTrunkModule->coreFixture()->trunks;
activation_assert(count(array_unique(array_column($allManagedTrunks, 'name'))) === 3, 'same-role authorized trunks sharing a SIP port must receive distinct compatibility hash names');
foreach ($allManagedTrunks as $managedTrunk) {
	$settings = $multiTrunkModule->coreFixture()->getTrunkDetails($managedTrunk['trunkid']);
	activation_assert($managedTrunk['maxchans'] === '5', 'inbound and outbound managed trunks must both use the authorized channel limit');
	activation_assert($settings['authentication'] === 'off' && $settings['registration'] === 'none', 'every inbound and outbound managed trunk must select None / None in the FreePBX GUI');
	activation_assert($settings['auth_username'] === '' && $settings['username'] === '' && $settings['secret'] === '', 'every managed trunk must have blank authentication credentials');
	activation_assert($settings['context'] === 'from-pstn' && $settings['sendrpid'] === 'no', 'every managed trunk must preserve the required context and sendrpid policy');
}
activation_assert($multiTrunkRouteTrunks === [$allManagedTrunks[0]['trunkid'], $allManagedTrunks[2]['trunkid']], 'outbound route must include both outbound-role trunks in authorized order and exclude inbound trunks');
activation_assert($multiTrunkModule->activate('synthetic-test-token')['success'], 'multiple same-role same-port trunks should remain uniquely identifiable on retry');
activation_assert($multiTrunkModule->coreFixture()->addCalls === 3 && $multiTrunkModule->coreFixture()->editCalls === 0 && $multiTrunkModule->routingFixture()->addCalls === 1, 'repeat reconciliation must not duplicate same-port trunks or their route');
activation_cleanup($directory);

$policyDirectory = activation_temp_directory();
$policyModule = new ActivationContractModule($policyDirectory, []);
$routePatterns = $policyModule->routePatternPolicy('my-12345678');
$expectedPairs = [
	['12345678*44', '0', 'Z.'],
	['12345678*', '+', '44Z.'],
	['12345678*', '', '44Z.'],
	['12345678*', '', '00Z.'],
	['12345678*', '', '101'],
	['12345678*', '', '105'],
	['12345678*', '', '111'],
	['12345678*', '', '112'],
	['12345678*', '', '116XXX'],
	['12345678*', '', '119'],
	['12345678*', '', '159'],
	['12345678*', '', '195'],
	['12345678*', '', '999'],
];
activation_assert(count($routePatterns) === count($expectedPairs), 'route policy should contain all 13 required patterns');
foreach ($expectedPairs as $index => $expected) {
	$actual = $routePatterns[$index];
	activation_assert([$actual['prepend_digits'], $actual['match_pattern_prefix'], $actual['match_pattern_pass']] === $expected, 'route pattern tuple should match policy at index ' . $index);
	activation_assert($actual['match_cid'] === '', 'route patterns must not restrict caller ID');
}
$applyPattern = function (string $dialedNumber, array $pattern): string {
	$prefix = $pattern['match_pattern_prefix'];
	activation_assert($prefix === '' || substr($dialedNumber, 0, strlen($prefix)) === $prefix, 'dialed number should match configured prefix');
	return $pattern['prepend_digits'] . substr($dialedNumber, strlen($prefix));
};
activation_assert($applyPattern('07700900123', $routePatterns[0]) === '12345678*447700900123', '0-prefix national dialing should prepend the tag and country code after stripping the leading zero');
activation_assert($applyPattern('+447700900123', $routePatterns[1]) === '12345678*447700900123', '+44 dialing should use the exact tag/prefix transformation');
activation_assert($applyPattern('447700900123', $routePatterns[2]) === '12345678*447700900123', '44 dialing should use the exact tag/prefix transformation');
activation_assert($applyPattern('00447700900123', $routePatterns[3]) === '12345678*00447700900123', '00 dialing should use the exact tag/prefix transformation');
foreach (array_slice($routePatterns, 4) as $pattern) {
	activation_assert($applyPattern($pattern['match_pattern_pass'], $pattern) === '12345678*' . $pattern['match_pattern_pass'], 'special service numbers should receive the tag prepend unchanged');
}
activation_cleanup($policyDirectory);

$directory = activation_temp_directory();
$repeatModule = new ActivationContractModule($directory, [activation_fixture_response(), activation_fixture_response()]);
$repeatFirst = $repeatModule->activate('synthetic-test-token');
$repeatSecond = $repeatModule->activate('synthetic-test-token');
activation_assert($repeatFirst['success'] === true && $repeatSecond['success'] === true, 'repeated local provisioning should be idempotent');
activation_assert($repeatModule->coreFixture()->addCalls === 1 && $repeatModule->routingFixture()->addCalls === 1, 'idempotent repeat must not duplicate trunk or route');
activation_assert($repeatModule->reloadRequests === 1, 'idempotent repeat must not request another reload');
activation_cleanup($directory);

$directory = activation_temp_directory();
$seededCore = new ActivationContractCoreApi();
$seededCore->seedManagedTrunk();
$seededRouting = new ActivationContractRoutingApi();
$seededRouting->routes[] = ['route_id' => 'synthetic-unrelated-route', 'name' => 'Existing Route', 'seq' => 0];
$seededModule = new ActivationContractModule($directory, [activation_fixture_response()], $seededCore, $seededRouting);
$seededResult = $seededModule->activate('synthetic-test-token');
activation_assert($seededResult['success'] === true, 'an existing correct module-owned trunk should be reused');
activation_assert($seededCore->addCalls === 0 && $seededModule->routingFixture()->addCalls === 1, 'correct existing trunk should not be duplicated');
activation_assert($seededRouting->routes[0]['name'] === 'Existing Route' && $seededRouting->routes[0]['seq'] === 0, 'creating the module route should preserve unrelated route order');
activation_assert($seededRouting->routes[1]['seq'] === 1, 'new module route should be appended at the bottom');
activation_cleanup($directory);

$directory = activation_temp_directory();
$conflictingCore = new ActivationContractCoreApi();
$conflictingCore->seedManagedTrunk('conflict.example.invalid', 5060, ['authentication' => 'outbound']);
$conflictingModule = new ActivationContractModule($directory, [activation_fixture_response()], $conflictingCore, new ActivationContractRoutingApi());
$conflictingResult = $conflictingModule->activate('synthetic-test-token');
$conflictingState = json_decode((string)file_get_contents($directory . '/state.json'), true);
activation_assert($conflictingResult['success'] === false, 'conflicting module-owned trunk must fail closed');
activation_assert($conflictingState['provisioned'] === false, 'conflicting trunk must not mark the service provisioned');
activation_assert($conflictingModule->routingFixture()->addCalls === 0 && $conflictingModule->reloadRequests === 1, 'a trunk conflict must not modify routes; only the new test destination requests reload');
activation_cleanup($directory);

$directory = activation_temp_directory();
$disabledCore = new ActivationContractCoreApi();
$disabledCore->seedManagedTrunk();
$disabledCore->trunks[0]['disabled'] = 'on';
$disabledModule = new ActivationContractModule($directory, [activation_fixture_response()], $disabledCore, new ActivationContractRoutingApi());
$disabledResult = $disabledModule->activate('synthetic-test-token');
$disabledState = json_decode((string)file_get_contents($directory . '/state.json'), true);
activation_assert($disabledResult['success'] === false && $disabledState['provisioned'] === false, 'a disabled managed trunk must not verify as healthy');
activation_assert($disabledResult['message'] === 'The module-owned trunk conflicts with the authorized configuration.' && $disabledResult['stage'] === 'local', 'managed-trunk conflict must return its exact safe local diagnostic');
activation_assert($disabledModule->routingFixture()->addCalls === 0, 'a disabled managed trunk must prevent route creation');
activation_cleanup($directory);

$directory = activation_temp_directory();
$routeConflictCore = new ActivationContractCoreApi();
$routeConflictRouting = new ActivationContractRoutingApi();
$routeConflictRouting->routes[] = ['route_id' => 'synthetic-managed-route', 'name' => 'DOMAINTAINS-Outbound', 'seq' => 0];
$routeConflictRouting->patterns['synthetic-managed-route'] = [['prepend_digits' => 'wrong', 'match_pattern_prefix' => '', 'match_pattern_pass' => '44Z.', 'match_cid' => '']];
$routeConflictRouting->trunks['synthetic-managed-route'] = ['unrelated-trunk'];
$routeConflictModule = new ActivationContractModule($directory, [activation_fixture_response()], $routeConflictCore, $routeConflictRouting);
$routeConflictResult = $routeConflictModule->activate('synthetic-test-token');
$routeConflictState = json_decode((string)file_get_contents($directory . '/state.json'), true);
activation_assert($routeConflictResult['success'] === false && $routeConflictState['provisioned'] === false, 'conflicting managed route must fail closed without provisioning');
activation_assert($routeConflictRouting->addCalls === 0, 'conflicting managed route must not be replaced');
activation_cleanup($directory);

$directory = activation_temp_directory();
$retryCore = new ActivationContractCoreApi();
$retryRouting = new ActivationContractRoutingApi();
$retryRouting->failAdd = true;
$localRetry = new ActivationContractModule($directory, [activation_fixture_response()], $retryCore, $retryRouting);
$failedProvisioning = $localRetry->activate('synthetic-test-token');
$pendingState = json_decode((string)file_get_contents($directory . '/state.json'), true);
$signingKeyBeforeLocalRetry = file_get_contents($directory . '/signing.key');
activation_assert($failedProvisioning['success'] === false && $pendingState['provisioned'] === false, 'local provisioning failure must retain authorized state without provisioning status');
activation_assert(isset($pendingState['trunks'][0]['sip_host']) && $signingKeyBeforeLocalRetry !== false, 'local failure must retain authorized trunks and signing key for retry');
activation_assert($localRetry->routingFixture()->routes === [], 'failed route creation must roll back partial route writes');
$pendingStateBeforeWrongKey = file_get_contents($directory . '/state.json');
$wrongPendingKeyResult = $localRetry->activate('synthetic-other-token');
activation_assert($wrongPendingKeyResult['success'] === false, 'different activation input must not hijack pending identity');
activation_assert(count($localRetry->requests()) === 1 && file_get_contents($directory . '/state.json') === $pendingStateBeforeWrongKey, 'wrong pending key must neither call the remote endpoint nor alter state');
$retryRouting->failAdd = false;
$successfulProvisioning = $localRetry->activate('synthetic-test-token');
$completedState = json_decode((string)file_get_contents($directory . '/state.json'), true);
activation_assert($successfulProvisioning['success'] === true && $completedState['provisioned'] === true, 'retry after local failure should complete provisioning');
activation_assert(count($localRetry->requests()) === 2, 'pending retry must refresh provider state exactly once');
activation_assert(file_get_contents($directory . '/signing.key') === $signingKeyBeforeLocalRetry, 'local retry must preserve the signing keypair');
activation_assert($localRetry->reloadRequests === 2, 'each attempt that changes local configuration should request reload once');
activation_cleanup($directory);

list($result, $directory) = activation_attempt(json_encode(['success' => true, 'configuration' => (object)[]]));
activation_assert($result['success'] === false, 'success=true without ok must be rejected');
activation_cleanup($directory);

$missingField = json_decode(activation_fixture_response(), true);
unset($missingField['trunks'][0]['sip_port']);
list($result, $directory) = activation_attempt(json_encode($missingField));
activation_assert($result['success'] === false, 'response missing a required field must be rejected');
activation_cleanup($directory);

list($result, $directory) = activation_attempt('{malformed');
activation_assert($result['success'] === false, 'malformed JSON must be rejected');
activation_cleanup($directory);

list($result, $directory) = activation_attempt(activation_fixture_response(['ok' => false]));
activation_assert($result['success'] === false, 'ok=false must be rejected');
activation_cleanup($directory);

$directory = activation_temp_directory();
$retryModule = new ActivationContractModule($directory, ['{malformed', activation_fixture_response()]);
$firstResult = $retryModule->activate('synthetic-test-token');
$keyPath = $directory . '/signing.key';
$keyBeforeRetry = file_get_contents($keyPath);
$secondResult = $retryModule->activate('synthetic-test-token');
$keyAfterRetry = file_get_contents($keyPath);
$firstRequest = json_decode($retryModule->requests()[0], true);
$secondRequest = json_decode($retryModule->requests()[1], true);
activation_assert($firstResult['success'] === false && $secondResult['success'] === true, 'activation should be retryable after a failed response');
activation_assert($keyBeforeRetry === $keyAfterRetry, 'retry must preserve the signing keypair');
activation_assert($firstRequest['claim']['public_key'] === $secondRequest['claim']['public_key'], 'retry must use the same public key');
activation_cleanup($directory);

$directory = activation_temp_directory();
$identityModule = new ActivationContractModule($directory, [
	activation_fixture_response(),
]);
$initialResult = $identityModule->activate('synthetic-test-token');
$stateBeforeRetry = file_get_contents($directory . '/state.json');
$sameIdentityResult = $identityModule->activate('synthetic-test-token');
$stateAfterSameIdentity = file_get_contents($directory . '/state.json');
$differentIdentityResult = $identityModule->activate('synthetic-other-token');
$stateAfterDifferentIdentity = file_get_contents($directory . '/state.json');
activation_assert($initialResult['success'] === true && $sameIdentityResult['success'] === true, 'same service identity activation should be idempotent');
$refreshedIdentityState = json_decode($stateAfterSameIdentity, true);
$initialIdentityState = json_decode($stateBeforeRetry, true);
unset($refreshedIdentityState['activated_at'], $initialIdentityState['activated_at']);
activation_assert($refreshedIdentityState === $initialIdentityState, 'unchanged provider refresh must yield equivalent persisted state');
activation_assert($differentIdentityResult['success'] === false && $differentIdentityResult['stage'] === 'local', 'different service identity must be rejected locally');
activation_assert($stateAfterSameIdentity === $stateAfterDifferentIdentity, 'different identity must not replace persisted state');
activation_assert(count($identityModule->requests()) === 2, 'same identity retry refreshes once; identity rejection must not call the remote endpoint');
activation_cleanup($directory);

foreach ([['447700900123', '447700900123'], [447700900123], [''], ['+447700900123'], ['44770090012a'], '447700900123'] as $invalidNumbers) {
	list($result, $directory) = activation_attempt(activation_fixture_response(['numbers' => $invalidNumbers]));
	activation_assert($result['success'] === false && !file_exists($directory . '/state.json'), 'invalid or duplicate authorized numbers must fail before local provisioning');
	activation_cleanup($directory);
}
$missingNumbers = json_decode(activation_fixture_response(), true);
unset($missingNumbers['numbers']);
list($result, $directory) = activation_attempt(json_encode($missingNumbers));
activation_assert($result['success'] === false, 'missing numbers must not be invented by the PBX');
activation_cleanup($directory);

foreach ([null, '5', 0, -1, 501, 5.5] as $invalidMaxChannels) {
	$invalidEntitlement = json_decode(activation_fixture_response(), true);
	if ($invalidMaxChannels === null) {
		unset($invalidEntitlement['max_channels']);
	} else {
		$invalidEntitlement['max_channels'] = $invalidMaxChannels;
	}
	list($result, $directory) = activation_attempt(json_encode($invalidEntitlement));
	activation_assert($result['success'] === false && !file_exists($directory . '/state.json'), 'missing, non-integer, zero, negative, or out-of-range max_channels must be rejected');
	activation_cleanup($directory);
}

foreach ([1, 500] as $validMaxChannels) {
	list($result, $directory, $boundaryModule) = activation_attempt(activation_fixture_response(['max_channels' => $validMaxChannels]));
	$boundaryState = json_decode(file_get_contents($directory . '/state.json'), true);
	$boundaryTrunk = $boundaryModule->coreFixture()->trunks[0];
	activation_assert($result['success'] && $boundaryState['max_channels'] === $validMaxChannels, 'max_channels boundary ' . $validMaxChannels . ' must be accepted and persisted');
	activation_assert($boundaryTrunk['maxchans'] === (string)$validMaxChannels, 'accepted max_channels boundary must be applied to the managed trunk');
	activation_cleanup($directory);
}

foreach ([
	'my-12345678' => 'my-12345678',
	'my-12345678.example.invalid' => 'my-12345678',
	" \tMY-12345678.Example.invalid \n" => 'my-12345678',
] as $localHostname => $expectedService) {
	$directory = activation_temp_directory();
	$hostnameModule = new ActivationContractModule($directory, [activation_fixture_response()]);
	$hostnameModule->localHostnameValue = $localHostname;
	$hostnameResult = $hostnameModule->activate('synthetic-test-token');
	$hostnameClaim = json_decode($hostnameModule->requests()[0], true)['claim'];
	activation_assert($hostnameResult['success'] && $hostnameClaim['service'] === $expectedService, 'hostname should normalize to the first lowercase service label');
	activation_cleanup($directory);
}

$directory = activation_temp_directory();
$invalidHostModule = new ActivationContractModule($directory, [activation_fixture_response()]);
$invalidHostModule->localHostnameValue = 'pbx.example.invalid';
$invalidHostResult = $invalidHostModule->activate('synthetic-test-token');
activation_assert(!$invalidHostResult['success'] && $invalidHostResult['stage'] === 'local' && $invalidHostModule->requests() === [], 'invalid local service identity must fail before sending an activation claim');
activation_assert(!file_exists($directory . '/signing.key') && $invalidHostModule->firewallFixture()->addCalls === 0, 'invalid local service identity must not create identity state or modify the firewall');
activation_cleanup($directory);

$directory = activation_temp_directory();
$legacyStateModule = new ActivationContractModule($directory, [activation_fixture_response()]);
activation_assert($legacyStateModule->activate('synthetic-test-token')['success'], 'fixture activation should create current retained state');
$legacyState = json_decode(file_get_contents($directory . '/state.json'), true);
unset($legacyState['max_channels']);
file_put_contents($directory . '/state.json', json_encode($legacyState, JSON_UNESCAPED_SLASHES) . "\n");
$legacyStateBeforeRetry = file_get_contents($directory . '/state.json');
$legacyStateRequestCount = count($legacyStateModule->requests());
$legacyStateResult = $legacyStateModule->activate('synthetic-test-token');
activation_assert(!$legacyStateResult['success'] && $legacyStateResult['stage'] === 'local-state', 'retained state predating required max_channels must be classified as a local-state failure');
activation_assert(count($legacyStateModule->requests()) === $legacyStateRequestCount && file_get_contents($directory . '/state.json') === $legacyStateBeforeRetry, 'legacy state must fail closed without a provider request or an invented entitlement');
activation_assert(strpos(json_encode($legacyStateResult), 'synthetic-test-token') === false && strpos(json_encode($legacyStateResult), $directory) === false, 'legacy-state failure output must not expose activation input or filesystem paths');
activation_cleanup($directory);

$directory = activation_temp_directory();
$mismatchedProviderService = 'my-87654321';
activation_assert((bool)preg_match('/^my-[0-9]{8}$/D', $mismatchedProviderService), 'mismatch fixture must pass basic service identity format validation');
$mismatchedServiceModule = new ActivationContractModule($directory, [activation_fixture_response(['service' => $mismatchedProviderService])]);
$mismatchedServiceResult = $mismatchedServiceModule->activate('synthetic-test-token');
activation_assert(!$mismatchedServiceResult['success'] && $mismatchedServiceResult['stage'] === 'remote', 'valid but different provider service identity must be rejected');
activation_assert(json_decode($mismatchedServiceModule->requests()[0], true)['claim']['service'] === 'my-12345678', 'mismatch claim must retain the local synthetic service identity');
activation_assert(!file_exists($directory . '/state.json'), 'mismatched service response must not be persisted');
activation_cleanup($directory);

$directory = activation_temp_directory();
$driftCore = new ActivationContractCoreApi();
$driftResponse = activation_fixture_response(['trunks' => [
	['role' => 'inbound', 'sip_host' => 'inbound.example.invalid', 'sip_port' => 5060],
	['role' => 'outbound', 'sip_host' => 'outbound.example.invalid', 'sip_port' => 5062],
]]);
$adminTrunk = ['trunkid' => 'synthetic-admin', 'name' => 'Administrator Link', 'tech' => 'pjsip', 'disabled' => 'off', 'maxchans' => '17'];
$driftCore->trunks[] = $adminTrunk;
$driftCore->details['synthetic-admin'] = ['trunk_name' => 'Administrator Link', 'sip_server' => 'admin.example.invalid'];
$adminTrunkDetails = $driftCore->details['synthetic-admin'];
$driftModule = new ActivationContractModule($directory, [$driftResponse], $driftCore);
activation_assert($driftModule->activate('synthetic-test-token')['success'], 'both authorized trunk roles should initially provision');
foreach ($driftCore->trunks as $index => $managedTrunk) {
	if ($managedTrunk['trunkid'] !== 'synthetic-admin') {
		$driftCore->trunks[$index]['maxchans'] = '9';
	}
}
$driftModule->queueResponse($driftResponse);
activation_assert($driftModule->activate('synthetic-test-token')['success'], 'provider refresh should repair manual channel-limit drift');
activation_assert($driftCore->editCalls === 2, 'wrong maxchans on inbound and outbound managed trunks must be repaired in place');
foreach (array_filter($driftCore->trunks, function ($trunk) { return $trunk['trunkid'] !== 'synthetic-admin'; }) as $managedTrunk) {
	activation_assert($managedTrunk['maxchans'] === '5', 'reconciled managed trunks must have the authorized maxchans');
}
activation_assert($driftCore->details['synthetic-admin'] === $adminTrunkDetails && in_array($adminTrunk, $driftCore->trunks, true), 'channel-limit reconciliation must not modify administrator-owned trunks');
$editCountAfterRepair = $driftCore->editCalls;
$driftModule->queueResponse($driftResponse);
activation_assert($driftModule->activate('synthetic-test-token')['success'] && $driftCore->editCalls === $editCountAfterRepair, 'correct maxchans must not cause unnecessary managed trunk edits');
activation_cleanup($directory);

$directory = activation_temp_directory();
$verificationCore = new ActivationContractCoreApi();
$verificationCore->corruptMaxChannelsAfterListCalls = 3;
$verificationModule = new ActivationContractModule($directory, [activation_fixture_response()], $verificationCore);
$verificationResult = $verificationModule->activate('synthetic-test-token');
activation_assert(!$verificationResult['success'] && $verificationResult['stage'] === 'local' && $verificationResult['message'] === 'A local PJSIP trunk failed verification.', 'final local verification must reject a managed trunk missing its main-record maxchans');
activation_assert(json_decode(file_get_contents($directory . '/state.json'), true)['provisioned'] === false, 'failed final channel-limit verification must leave activation pending');
activation_cleanup($directory);

$directory = activation_temp_directory();
$inboundCore = new ActivationContractCoreApi();
$inboundModule = new ActivationContractModule($directory, [activation_fixture_response()], $inboundCore);
activation_assert($inboundModule->activate('synthetic-test-token')['success'], 'authorized inbound routes should provision');
activation_assert($inboundCore->didAddCalls === 2 && count($inboundCore->dids) === 2, 'create one exact inbound route per authorized number');
foreach ($inboundCore->dids as $route) {
	activation_assert(in_array($route['extension'], ['447700900123', '447700900124'], true), 'DID must be provider authorized');
	activation_assert($route['destination'] === 'domaintains-test,s,1' && $route['cidnum'] === '', 'inbound routes must point to the local test destination, never a trunk');
	activation_assert($route['description'] === 'DOMAINTAINS Inbound ' . $route['extension'], 'inbound descriptions must be deterministic');
}
$markerBefore = file_get_contents($directory . '/test-destination.json');
activation_assert($inboundModule->testDestinationRegistered(), 'test destination should be registered before provisioning completes');
$destinations = domaintains_destinations();
activation_assert($destinations[0]['destination'] === 'domaintains-test,s,1' && $destinations[0]['description'] === 'DOMAINTAINS Test', 'FreePBX destination hook should expose the module-owned answering endpoint');
activation_assert(domaintains_getdestinfo('domaintains-test,s,1')['description'] === 'DOMAINTAINS Test', 'FreePBX destination metadata should resolve');
foreach (['16', '17'] as $freepbxVersion) {
	$ext = new ActivationContractDialplan();
	domaintains_get_config('asterisk');
	$steps = array_map(function ($step) { return $step['cmd']->output(); }, $ext->_exts['domaintains-test'][' s ']);
	activation_assert($steps === ['Answer', 'Wait(1)', 'Playtones(1000/200,0/200,1000/200,0/200,1000/400)', 'Wait(2)', 'StopPlaytones', 'Hangup()'], 'FreePBX ' . $freepbxVersion . ' hook should contribute deterministic answering tones and clean hangup');
	$guiSettings = $inboundCore->lastTrunkSettings;
	activation_assert($guiSettings['authentication'] === 'off' && $guiSettings['registration'] === 'none', 'FreePBX ' . $freepbxVersion . ' None radio predicates must both select');
	activation_assert($guiSettings['username'] === '' && $guiSettings['auth_username'] === '' && $guiSettings['secret'] === '', 'GUI-normalized settings must contain no credentials');
}
activation_assert($inboundModule->activate('synthetic-test-token')['success'], 'repeat inbound provisioning should succeed');
activation_assert($inboundCore->didAddCalls === 2 && $inboundModule->reloadRequests === 1, 'inbound routes and test destination should be idempotent without another reload');
activation_assert(file_get_contents($directory . '/test-destination.json') === $markerBefore && count($inboundModule->requests()) === 2, 'test registration should not repeat; retry refreshes provider state once');
$localStatus = $inboundModule->getStatus();
activation_assert($localStatus['inbound_numbers'] === 2 && $localStatus['test_destination'] === 'ready', 'read-only status should expose local counts and readiness');
file_put_contents($directory . '/test-destination.json', '{"version":2}');
activation_assert(!$inboundModule->activate('synthetic-test-token')['success'], 'a corrupt test destination must fail closed even on a previously provisioned installation');
activation_assert(json_decode(file_get_contents($directory . '/state.json'), true)['provisioned'] === false, 'failed full re-verification must return persisted state to pending');
file_put_contents($directory . '/test-destination.json', '{"version":1}');
activation_assert($inboundModule->activate('synthetic-test-token')['success'] && count($inboundModule->requests()) === 4, 'repaired destination registration should complete after a provider refresh');
activation_cleanup($directory);

$directory = activation_temp_directory();
$existingDIDCore = new ActivationContractCoreApi();
$existingDIDCore->dids[] = ['extension' => '447700900123', 'cidnum' => '', 'destination' => 'domaintains-test,s,1', 'description' => 'DOMAINTAINS Inbound 447700900123'];
$existingDIDCore->dids[] = ['extension' => '447700900199', 'cidnum' => '', 'destination' => 'unrelated,s,1', 'description' => 'Administrator Route'];
$unrelatedBefore = $existingDIDCore->dids[1];
$existingDIDModule = new ActivationContractModule($directory, [activation_fixture_response()], $existingDIDCore);
activation_assert($existingDIDModule->activate('synthetic-test-token')['success'] && $existingDIDCore->didAddCalls === 1, 'an existing correct inbound route must be reused');
activation_assert($existingDIDCore->dids[1] === $unrelatedBefore, 'unrelated administrator inbound route must not be modified');
activation_cleanup($directory);

$directory = activation_temp_directory();
$conflictDIDCore = new ActivationContractCoreApi();
$conflictDIDCore->dids[] = ['extension' => '447700900123', 'cidnum' => '', 'destination' => 'unrelated,s,1', 'description' => 'Administrator Route'];
$conflictBefore = $conflictDIDCore->dids;
$conflictDIDModule = new ActivationContractModule($directory, [activation_fixture_response()], $conflictDIDCore);
activation_assert(!$conflictDIDModule->activate('synthetic-test-token')['success'], 'an unrelated destination for an authorized DID must fail closed');
$pendingDIDState = json_decode(file_get_contents($directory . '/state.json'), true);
activation_assert($pendingDIDState['provisioned'] === false && $conflictDIDCore->dids === $conflictBefore, 'DID conflict must leave pending state and administrator routing unchanged');
activation_cleanup($directory);

$directory = activation_temp_directory();
$retryDIDCore = new ActivationContractCoreApi();
$retryDIDCore->failDID = true;
$retryDIDModule = new ActivationContractModule($directory, [activation_fixture_response()], $retryDIDCore);
activation_assert(!$retryDIDModule->activate('synthetic-test-token')['success'], 'inbound creation failure must not claim full provisioning');
activation_assert(json_decode(file_get_contents($directory . '/state.json'), true)['provisioned'] === false, 'all stages must verify before provisioned=true');
$retryDIDKey = file_get_contents($directory . '/signing.key');
$retryDIDCore->failDID = false;
activation_assert($retryDIDModule->activate('synthetic-test-token')['success'], 'inbound failure must be locally retryable');
activation_assert(count($retryDIDModule->requests()) === 2 && file_get_contents($directory . '/signing.key') === $retryDIDKey, 'inbound retry must retain key and refresh provider state once');
activation_cleanup($directory);

$directory = activation_temp_directory();
$badDIDCore = new ActivationContractCoreApi();
$badDIDCore->corruptDID = true;
$badDIDModule = new ActivationContractModule($directory, [activation_fixture_response()], $badDIDCore);
activation_assert(!$badDIDModule->activate('synthetic-test-token')['success'], 'incorrect API-created inbound destination must fail verification');
activation_assert(json_decode(file_get_contents($directory . '/state.json'), true)['provisioned'] === false, 'failed inbound verification must retain pending state');
activation_cleanup($directory);

$directory = activation_temp_directory();
$legacyCore = new ActivationContractCoreApi();
$legacyCore->seedManagedTrunk('sip.example.invalid', 5060, ['authentication' => 'none']);
$legacyId = $legacyCore->trunks[0]['trunkid'];
$legacyModule = new ActivationContractModule($directory, [activation_fixture_response()], $legacyCore);
activation_assert($legacyModule->activate('synthetic-test-token')['success'], 'legacy managed None authentication should normalize to the GUI off value');
activation_assert($legacyCore->editCalls === 1 && $legacyCore->getTrunkDetails($legacyId)['authentication'] === 'off', 'normalization must retain the same managed trunk ID and select Authentication None');
activation_assert($legacyCore->getTrunkDetails($legacyId)['registration'] === 'none', 'normalization must retain Registration None');
activation_cleanup($directory);

$sensitiveExceptionText = 'synthetic-test-token synthetic-signature synthetic-secret /synthetic-private-path raw-synthetic-response #0 synthetic trace';
$directory = activation_temp_directory();
$dialplanErrorModule = new ActivationContractModule($directory, [activation_fixture_response()]);
$dialplanErrorModule->failDialplanVerification = true;
$dialplanErrorResult = $dialplanErrorModule->activate('synthetic-test-token');
activation_assert($dialplanErrorResult === ['success' => false, 'message' => 'Test answering dialplan failed verification.', 'stage' => 'local'], 'test answering verification must return its exact safe diagnostic');
activation_assert($dialplanErrorModule->getStatus()['last_error'] === $dialplanErrorResult['message'], 'test answering failure should remain inspectable in persisted status');
$dialplanErrorModule->failDialplanVerification = false;
activation_assert($dialplanErrorModule->activate('synthetic-test-token')['success'] && count($dialplanErrorModule->requests()) === 2, 'test answering failure should be retryable');
activation_cleanup($directory);

$directory = activation_temp_directory();
$remoteErrorModule = new ActivationContractModule($directory, []);
$remoteErrorModule->remoteException = new RuntimeException($sensitiveExceptionText);
$remoteErrorResult = $remoteErrorModule->activate('synthetic-test-token');
activation_assert($remoteErrorResult === ['success' => false, 'message' => 'Unable to complete activation with the DOMAINTAINS service.', 'stage' => 'remote'], 'remote errors must remain generic and structured');
activation_assert(!file_exists($directory . '/state.json'), 'remote failure must not persist raw response or exception detail');
activation_cleanup($directory);

$directory = activation_temp_directory();
$diagnosticCore = new ActivationContractCoreApi();
$diagnosticCore->dids[] = ['extension' => '447700900123', 'cidnum' => '', 'destination' => 'unrelated,s,1', 'description' => 'Administrator Route'];
$diagnosticModule = new ActivationContractModule($directory, [activation_fixture_response()], $diagnosticCore);
$diagnosticModule->reloadException = new RuntimeException($sensitiveExceptionText);
$diagnosticResult = $diagnosticModule->activate('synthetic-test-token');
$expectedLocalError = 'An existing DID route conflicts with the authorized inbound route.';
activation_assert($diagnosticResult === ['success' => false, 'message' => $expectedLocalError, 'stage' => 'local'], 'local conflict must retain its exact safe message even when the follow-up reload request fails');
$diagnosticState = json_decode(file_get_contents($directory . '/state.json'), true);
activation_assert($diagnosticState['provisioned'] === false && $diagnosticState['last_error'] === $expectedLocalError && $diagnosticState['last_error_stage'] === 'local', 'persist safe error and local stage while retaining pending authorization');
$diagnosticStatus = $diagnosticModule->getStatus();
activation_assert($diagnosticStatus['last_error'] === $expectedLocalError && $diagnosticStatus['last_error_stage'] === 'local', 'status must expose the persisted safe diagnostic');
$diagnosticCore->dids = [];
$diagnosticModule->reloadException = null;
activation_assert($diagnosticModule->activate('synthetic-test-token')['success'], 'local diagnostic failure should remain retryable');
$recoveredState = json_decode(file_get_contents($directory . '/state.json'), true);
activation_assert(!array_key_exists('last_error', $recoveredState) && !array_key_exists('last_error_stage', $recoveredState), 'successful retry must clear both stored error fields');
$recoveredStatus = $diagnosticModule->getStatus();
activation_assert(!array_key_exists('last_error', $recoveredStatus) && count($diagnosticModule->requests()) === 2, 'successful retry must clear status after one provider refresh');
activation_cleanup($directory);

foreach ([new RuntimeException($sensitiveExceptionText), new LogicException('An existing DID route conflicts with the authorized inbound route.')] as $unsafeException) {
	$directory = activation_temp_directory();
	$unsafeCore = new ActivationContractCoreApi();
	$unsafeCore->didException = $unsafeException;
	$unsafeModule = new ActivationContractModule($directory, [activation_fixture_response()], $unsafeCore);
	$unsafeResult = $unsafeModule->activate('synthetic-test-token');
	activation_assert($unsafeResult === ['success' => false, 'message' => 'Local DOMAINTAINS reconciliation failed.', 'stage' => 'local'], 'unknown text and non-RuntimeException errors must not be surfaced');
	$unsafeStatus = $unsafeModule->getStatus();
	activation_assert($unsafeStatus['last_error'] === 'Local DOMAINTAINS reconciliation failed.', 'persistent status must expose only allowlisted messages');
	foreach (['synthetic-test-token', 'synthetic-signature', 'synthetic-secret', '/synthetic-private-path', 'raw-synthetic-response', '#0', 'RuntimeException', 'LogicException'] as $sensitiveMarker) {
		activation_assert(strpos(json_encode([$unsafeResult, $unsafeStatus]), $sensitiveMarker) === false, 'diagnostics must not contain ' . $sensitiveMarker);
	}
	$unsafeState = json_decode(file_get_contents($directory . '/state.json'), true);
	$unsafeState['last_error'] = $sensitiveExceptionText;
	file_put_contents($directory . '/state.json', json_encode($unsafeState));
	activation_assert($unsafeModule->getStatus()['last_error'] === 'Local DOMAINTAINS reconciliation failed.', 'status must sanitize a non-allowlisted persisted error');
	activation_cleanup($directory);
}

$directory = activation_temp_directory();
$migrationCore = new ActivationContractCoreApi();
$migrationCore->seedManagedTrunk('legacy-in.example.invalid', 5060, ['authentication' => 'none', 'username' => 'synthetic-old-user', 'auth_username' => 'synthetic-old-user', 'secret' => 'synthetic-old-secret'], 'inbound', 'synthetic-legacy-in');
$migrationCore->seedManagedTrunk('legacy-out.example.invalid', 5062, ['authentication' => '', 'codecs' => 'ulaw,alaw'], 'outbound', 'synthetic-legacy-out');
$legacyNamesBefore = array_column($migrationCore->trunks, 'name', 'trunkid');
$migrationCore->seedManagedTrunk('duplicate-out.example.invalid', 5062, [], 'outbound', 'synthetic-duplicate-out');
$administratorTrunk = ['trunkid' => 'synthetic-administrator', 'name' => 'DOMAINTAINS Administrator Link', 'tech' => 'pjsip', 'disabled' => 'off'];
$migrationCore->trunks[] = $administratorTrunk;
$migrationCore->details['synthetic-administrator'] = ['trunk_name' => 'DOMAINTAINS Administrator Link', 'sip_server' => 'administrator.example.invalid', 'authentication' => 'outbound'];
$administratorDetailsBefore = $migrationCore->details['synthetic-administrator'];
$migrationRouting = new ActivationContractRoutingApi();
$migrationRouting->routes = [
	['route_id' => 'synthetic-admin-route', 'name' => 'Administrator Route', 'seq' => 0],
	['route_id' => 'synthetic-managed-route', 'name' => 'DOMAINTAINS-Outbound', 'seq' => 1],
];
$migrationRouting->trunks['synthetic-managed-route'] = ['synthetic-legacy-out'];
$migrationResponse = activation_fixture_response(['trunks' => [
	['role' => 'inbound', 'sip_host' => 'current-in.example.invalid', 'sip_port' => 5060],
	['role' => 'outbound', 'sip_host' => 'current-out.example.invalid', 'sip_port' => 5062],
]]);
$migrationModule = new ActivationContractModule($directory, [$migrationResponse], $migrationCore, $migrationRouting);
$migrationRouting->patterns['synthetic-managed-route'] = $migrationModule->routePatternPolicy('my-12345678');
$routesBeforeMigration = [$migrationRouting->routes, $migrationRouting->patterns, $migrationRouting->trunks];
$ambiguousResult = $migrationModule->activate('synthetic-test-token');
activation_assert(!$ambiguousResult['success'] && $ambiguousResult['stage'] === 'local' && $ambiguousResult['message'] === 'Ambiguous managed trunk upgrade mapping.', 'duplicate legacy managed trunks must fail closed with a safe diagnostic');
activation_assert($migrationCore->editCalls === 0 && $migrationCore->addCalls === 0, 'ambiguous role mapping must not edit or create trunks');
activation_assert([$migrationRouting->routes, $migrationRouting->patterns, $migrationRouting->trunks] === $routesBeforeMigration, 'ambiguous mapping must not change any route data');
$retainedState = json_decode(file_get_contents($directory . '/state.json'), true);
$retainedState['last_error'] = 'An unexpected module-owned trunk conflicts with the authorized trunk set.';
$retainedState['last_error_stage'] = 'local';
file_put_contents($directory . '/state.json', json_encode($retainedState));
$migrationCore->trunks = array_values(array_filter($migrationCore->trunks, function ($trunk) { return $trunk['trunkid'] !== 'synthetic-duplicate-out'; }));
unset($migrationCore->details['synthetic-duplicate-out']);
$migratedResult = $migrationModule->activate('synthetic-test-token');
activation_assert($migratedResult['success'], 'unambiguous legacy inbound/outbound trunks should migrate on local retry');
activation_assert(count($migrationModule->requests()) === 2 && $migrationCore->addCalls === 0 && $migrationCore->editCalls === 2, 'migration must preserve IDs without creating duplicate trunks');
foreach (['synthetic-legacy-in' => ['current-in.example.invalid', '5060'], 'synthetic-legacy-out' => ['current-out.example.invalid', '5062']] as $id => $expectedEndpoint) {
	$details = $migrationCore->getTrunkDetails($id);
	activation_assert($details['trunk_name'] === $legacyNamesBefore[$id], 'valid legacy managed names must remain unchanged');
	activation_assert([$details['sip_server'], $details['sip_server_port']] === $expectedEndpoint, 'legacy trunk ID must receive the currently authorized SIP host and port');
	activation_assert($details['authentication'] === 'off' && $details['registration'] === 'none', 'none or blank legacy authentication must migrate to GUI None / None');
	activation_assert($details['username'] === '' && $details['auth_username'] === '' && $details['secret'] === '', 'migration must explicitly clear SIP authentication credentials');
	activation_assert($details['context'] === 'from-pstn' && $details['sendrpid'] === 'no' && $details['disabletrunk'] === 'off', 'migration must preserve the required enabled PJSIP policy');
}
activation_assert($migrationCore->details['synthetic-legacy-out']['codec'] === ['ulaw' => true, 'alaw' => true], 'migration should preserve existing codec choices through the Core API');
activation_assert($migrationCore->details['synthetic-administrator'] === $administratorDetailsBefore && in_array($administratorTrunk, $migrationCore->trunks, true), 'an administrator trunk merely containing DOMAINTAINS must remain untouched');
activation_assert($migrationRouting->addCalls === 0 && [$migrationRouting->routes, $migrationRouting->patterns, $migrationRouting->trunks] === $routesBeforeMigration, 'migration must preserve the existing outbound ID reference and all route ordering/data');
$migratedState = json_decode(file_get_contents($directory . '/state.json'), true);
activation_assert($migratedState['provisioned'] && !isset($migratedState['last_error'], $migratedState['last_error_stage']), 'successful migration must clear the retained 0.3.1-dev error after full verification');
activation_assert($migrationCore->didAddCalls === 2 && $migrationModule->testDestinationRegistered(), 'migration must continue through authorized inbound routes and test destination reconciliation');
$migrationEdits = $migrationCore->editCalls;
$migrationReloads = $migrationModule->reloadRequests;
activation_assert($migrationModule->activate('synthetic-test-token')['success'], 'migrated legacy names should remain recognized on subsequent attempts');
activation_assert($migrationCore->editCalls === $migrationEdits && $migrationModule->reloadRequests === $migrationReloads, 'already-correct migrated trunks must be idempotent');
activation_cleanup($directory);

$stableCore = new ActivationContractCoreApi();
$stableRouting = new ActivationContractRoutingApi();
$directory = activation_temp_directory();
$stableModule = new ActivationContractModule($directory, [$migrationResponse], $stableCore, $stableRouting);
activation_assert($stableModule->activate('synthetic-test-token')['success'], 'fresh installation should retain current hash-naming compatibility');
$stableNamesBefore = array_column($stableCore->trunks, 'name', 'trunkid');
$expectedCompatibilityNames = [];
foreach (json_decode($migrationResponse, true)['trunks'] as $authorizedTrunk) {
	$marker = $authorizedTrunk['role'] === 'outbound' ? 'OUT' : 'IN';
	$tuple = json_encode([$authorizedTrunk['role'], $authorizedTrunk['sip_host'], $authorizedTrunk['sip_port']], JSON_UNESCAPED_SLASHES);
	$expectedCompatibilityNames[] = 'DOMAINTAINS-' . $marker . '-' . substr(hash('sha256', $tuple), 0, 12);
}
activation_assert(array_values($stableNamesBefore) === $expectedCompatibilityNames, 'host-derived identity is current/legacy compatibility behavior, not the long-term ownership identifier');
activation_cleanup($directory);
$directory = activation_temp_directory();
$changedHostResponse = activation_fixture_response(['trunks' => [
	['role' => 'inbound', 'sip_host' => 'updated-in.example.invalid', 'sip_port' => 5060],
	['role' => 'outbound', 'sip_host' => 'updated-out.example.invalid', 'sip_port' => 5062],
]]);
$changedHostModule = new ActivationContractModule($directory, [$changedHostResponse], $stableCore, $stableRouting);
activation_assert($changedHostModule->activate('synthetic-test-token')['success'], 'changed authorized hosts should reconcile in place when each role has one target');
activation_assert($stableCore->addCalls === 2 && array_column($stableCore->trunks, 'name', 'trunkid') === $stableNamesBefore, 'provider host changes must not create duplicate trunks or change names/IDs');
activation_assert($stableRouting->addCalls === 1, 'provider host changes must not recreate the existing outbound route');
activation_cleanup($directory);

$directory = activation_temp_directory();
$failedMigrationCore = new ActivationContractCoreApi();
$failedMigrationCore->seedManagedTrunk('legacy-out.example.invalid', 5060, ['authentication' => 'none']);
$beforeFailedMigration = [$failedMigrationCore->trunks, $failedMigrationCore->details];
$failedMigrationCore->failEdit = true;
$failedMigrationModule = new ActivationContractModule($directory, [activation_fixture_response()], $failedMigrationCore);
activation_assert(!$failedMigrationModule->activate('synthetic-test-token')['success'], 'Core edit failure must leave migration pending');
activation_assert([$failedMigrationCore->trunks, $failedMigrationCore->details] === $beforeFailedMigration, 'failed in-place edit must roll back rather than lose the existing trunk');
$failedMigrationCore->failEdit = false;
activation_assert($failedMigrationModule->activate('synthetic-test-token')['success'] && count($failedMigrationModule->requests()) === 2, 'failed migration should retry with refreshed authorization');
activation_cleanup($directory);

$directory = activation_temp_directory();
$ambiguousRoleCore = new ActivationContractCoreApi();
$ambiguousRoleCore->seedManagedTrunk('legacy-out.example.invalid', 5060, ['authentication' => 'none']);
$ambiguousRoleBefore = [$ambiguousRoleCore->trunks, $ambiguousRoleCore->details];
$ambiguousRoleResponse = activation_fixture_response(['trunks' => [
	['role' => 'outbound', 'sip_host' => 'primary.example.invalid', 'sip_port' => 5060],
	['role' => 'outbound', 'sip_host' => 'secondary.example.invalid', 'sip_port' => 5060],
]]);
$ambiguousRoleModule = new ActivationContractModule($directory, [$ambiguousRoleResponse], $ambiguousRoleCore);
$ambiguousRoleResult = $ambiguousRoleModule->activate('synthetic-test-token');
activation_assert(!$ambiguousRoleResult['success'] && $ambiguousRoleResult['stage'] === 'local' && $ambiguousRoleResult['message'] === 'Ambiguous managed trunk upgrade mapping.', 'one legacy trunk must not be guessed into one of multiple same-role authorized targets');
activation_assert($ambiguousRoleCore->addCalls === 0 && $ambiguousRoleCore->editCalls === 0 && [$ambiguousRoleCore->trunks, $ambiguousRoleCore->details] === $ambiguousRoleBefore, 'ambiguous multi-target legacy mapping must not create duplicates or modify the existing trunk');
activation_assert($ambiguousRoleModule->routingFixture()->addCalls === 0, 'ambiguous legacy role mapping must not create outbound routing');
activation_cleanup($directory);

// 0.3.3-dev: retained activation state performs a full, authoritative provider refresh.
$directory = activation_temp_directory();
$refreshCore = new ActivationContractCoreApi();
$refreshModule = new ActivationContractModule($directory, [activation_fixture_response()], $refreshCore);
$refreshModule->clock = 1700000000;
activation_assert($refreshModule->activate('synthetic-test-token')['success'] && count($refreshModule->requests()) === 1, 'first activation must send exactly one request');
$knownGoodState = file_get_contents($directory . '/state.json');
$knownGood = json_decode($knownGoodState, true);
$refreshKey = file_get_contents($directory . '/signing.key');

$tamperedFingerprint = $knownGood;
$tamperedFingerprint['public_key_fingerprint'] = hash('sha256', 'synthetic-other-public-key');
file_put_contents($directory . '/state.json', json_encode($tamperedFingerprint));
$wrongSigningResult = $refreshModule->activate('synthetic-test-token');
activation_assert(!$wrongSigningResult['success'] && $wrongSigningResult['stage'] === 'local' && count($refreshModule->requests()) === 1, 'wrong signing identity must fail locally before any request');
file_put_contents($directory . '/state.json', $knownGoodState);
$wrongKeyResult = $refreshModule->activate('synthetic-other-token');
activation_assert(!$wrongKeyResult['success'] && $wrongKeyResult['stage'] === 'local' && count($refreshModule->requests()) === 1, 'wrong activation key must fail locally before any request');
activation_assert(file_get_contents($directory . '/state.json') === $knownGoodState, 'local identity rejection must not alter retained state');

$refreshCountsBefore = [$refreshCore->addCalls, $refreshCore->editCalls, $refreshCore->didAddCalls, $refreshModule->reloadRequests, $refreshModule->routingFixture()->addCalls];
$refreshModule->remoteException = new RuntimeException($sensitiveExceptionText);
$remoteRefreshResult = $refreshModule->activate('synthetic-test-token');
$refreshModule->remoteException = null;
activation_assert($remoteRefreshResult === ['success' => false, 'message' => 'Unable to complete activation with the DOMAINTAINS service.', 'stage' => 'remote'], 'remote refresh failure must be generic and remote-staged');
activation_assert(file_get_contents($directory . '/state.json') === $knownGoodState, 'remote refresh failure must leave last known-good state intact');
activation_assert([$refreshCore->addCalls, $refreshCore->editCalls, $refreshCore->didAddCalls, $refreshModule->reloadRequests, $refreshModule->routingFixture()->addCalls] === $refreshCountsBefore, 'remote refresh failure must not run local reconciliation');
activation_assert(count($refreshModule->requests()) === 2, 'remote refresh attempt must have sent one request');

$refreshedResponse = activation_fixture_response([
	'numbers' => ['447700900125'],
	'trunks' => [['role' => 'outbound', 'sip_host' => 'refreshed.example.invalid', 'sip_port' => 5062]],
]);
$refreshModule->queueResponse($refreshedResponse);
$refreshCore->failDID = true;
$localAfterRefresh = $refreshModule->activate('synthetic-test-token');
activation_assert(!$localAfterRefresh['success'] && $localAfterRefresh['stage'] === 'local', 'local failure after refresh must be local-staged');
$refreshedPending = json_decode(file_get_contents($directory . '/state.json'), true);
activation_assert($refreshedPending['numbers'] === ['447700900125'] && $refreshedPending['trunks'][0]['sip_host'] === 'refreshed.example.invalid', 'refreshed provider values must replace retained numbers and trunks');
activation_assert($refreshedPending['provisioned'] === false && $refreshedPending['last_error_stage'] === 'local' && is_string($refreshedPending['last_error']), 'local failure must persist refreshed state as pending with a safe error');
activation_assert($refreshedPending['public_key_fingerprint'] === $knownGood['public_key_fingerprint'] && $refreshedPending['activation_key_fingerprint'] === $knownGood['activation_key_fingerprint'], 'refresh must preserve identity fingerprints');
activation_assert($refreshedPending['activated_at'] > $knownGood['activated_at'], 'refresh must update activated_at');
$refreshedTrunk = $refreshCore->getTrunkDetails($refreshCore->trunks[0]['trunkid']);
activation_assert(count($refreshCore->trunks) === 1 && [$refreshedTrunk['sip_server'], $refreshedTrunk['sip_server_port']] === ['refreshed.example.invalid', '5062'], 'changed SIP endpoint must reach local reconciliation in place');

$refreshCore->failDID = false;
$recoveredRefresh = $refreshModule->activate('synthetic-test-token');
activation_assert($recoveredRefresh['success'] && count($refreshModule->requests()) === 4, 'later retry must refresh again and succeed');
$recoveredRefreshState = json_decode(file_get_contents($directory . '/state.json'), true);
activation_assert($recoveredRefreshState['provisioned'] === true && !isset($recoveredRefreshState['last_error'], $recoveredRefreshState['last_error_stage']), 'successful retry must clear the persisted error');
activation_assert(in_array('447700900125', array_column($refreshCore->dids, 'extension'), true), 'changed number must reach local reconciliation');

$claims = array_map(function ($request) { return json_decode($request, true); }, $refreshModule->requests());
for ($i = 1; $i < count($claims); $i++) {
	activation_assert($claims[$i]['claim']['public_key'] === $claims[0]['claim']['public_key'], 'refresh must use the same public signing identity');
	activation_assert($claims[$i]['claim']['token'] === 'synthetic-test-token', 'refresh claim must carry the entered activation key');
	activation_assert($claims[$i]['claim']['service'] === 'my-12345678', 'refresh claim must retain the validated local service identity');
	activation_assert($claims[$i]['claim']['timestamp'] > $claims[$i - 1]['claim']['timestamp'], 'refresh must use a fresh timestamp');
	activation_assert($claims[$i]['claim']['nonce'] !== $claims[$i - 1]['claim']['nonce'], 'refresh must use a fresh nonce');
	activation_assert($claims[$i]['signature'] !== $claims[$i - 1]['signature'], 'refresh must use a fresh signature');
}
activation_assert(file_get_contents($directory . '/signing.key') === $refreshKey, 'refresh must not replace the signing key');

$diagnosticOutput = json_encode([$wrongSigningResult, $wrongKeyResult, $remoteRefreshResult, $localAfterRefresh, $refreshModule->getStatus(), $refreshedPending], JSON_UNESCAPED_SLASHES);
foreach (array_merge(['synthetic-test-token', 'synthetic-test-signing-key', 'synthetic-signature', 'synthetic-secret', '/synthetic-private-path', 'raw-synthetic-response', '#0', 'RuntimeException', $directory], array_column($claims, 'signature'), array_map(function ($claim) { return $claim['claim']['nonce']; }, $claims)) as $sensitiveMarker) {
	activation_assert(strpos($diagnosticOutput, $sensitiveMarker) === false, 'refresh diagnostics must not contain secrets or request material');
}
activation_cleanup($directory);

echo "Activation contract passed.\n";
