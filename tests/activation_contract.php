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

	public function getAllDIDs(): array { return $this->dids; }
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
		return $this->trunks;
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
		if ($edit) { $this->editCalls++; } else { $this->addCalls++; }
		$this->postWasIsolated = empty($_POST);
		$this->lastTrunkSettings = $settings;
		$trunkId = $edit ? $settings['trunknum'] : 'synthetic-trunk-' . $this->addCalls;
		$this->trunks[] = ['trunkid' => $trunkId, 'name' => $name, 'tech' => $technology, 'disabled' => 'off'];
		$this->details[$trunkId] = $settings;
		return $trunkId;
	}

	public function seedManagedTrunk(string $host = 'sip.example.invalid', int $port = 5060, array $overrides = []): void {
		$trunkId = 'synthetic-trunk-seeded';
		$trunkName = 'DOMAINTAINS-OUT-' . substr(hash('sha256', json_encode(['outbound', $host, $port], JSON_UNESCAPED_SLASHES)), 0, 12);
		$this->trunks[] = ['trunkid' => $trunkId, 'name' => $trunkName, 'tech' => 'pjsip', 'disabled' => 'off'];
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
		$this->transactionSnapshot = [$this->routes, $this->patterns, $this->trunks];
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
		list($this->routes, $this->patterns, $this->trunks) = $this->transactionSnapshot;
		$this->transactionSnapshot = null;
		return true;
	}
}

class ActivationContractModule extends \FreePBX\modules\Domaintains {
	private $directory;
	private $responses;
	private $requests = [];
	private $core;
	private $routing;
	private $firewall;
	public $activationHostWasLocalBeforeRequest = false;
	public $reloadRequests = 0;

	public function __construct(string $directory, array $responses, $core = null, $routing = null, $firewall = null) {
		parent::__construct(new stdClass());
		$this->directory = $directory;
		$this->responses = $responses;
		$this->core = $core === null ? new ActivationContractCoreApi() : $core;
		$this->routing = $routing === null ? new ActivationContractRoutingApi() : $routing;
		$this->firewall = $firewall === null ? new ActivationContractFirewallApi() : $firewall;
		FreePBX::$module = $this;
	}

	protected function dialplanBuilder() { return new ActivationContractDialplan(); }

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
	}

	protected function supportsSodium(): bool {
		return true;
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
		return str_repeat('s', 64);
	}

	protected function signatureLength(): int {
		return 64;
	}

	protected function clearSigningSecret(string &$secretKey): void {
		$secretKey = '';
	}

	protected function sendActivationRequest(string $request): string {
		$this->requests[] = $request;
		$this->activationHostWasLocalBeforeRequest = isset($this->firewall->networkMaps['my-connect.freepbxhosting.uk']) && $this->firewall->networkMaps['my-connect.freepbxhosting.uk'] === 'internal';
		return (string)array_shift($this->responses);
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
foreach (['service', 'trunks', 'profile', 'domaintains_hostname', 'numbers'] as $field) {
	activation_assert(array_key_exists($field, $stored), 'persisted state should include required field ' . $field);
}
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
activation_assert($trunkSettings['authentication'] === 'off' && $trunkSettings['registration'] === 'none', 'managed trunk must render both FreePBX GUI None selections without authentication or registration');
activation_assert($trunkSettings['sip_server'] === 'sip.example.invalid' && $trunkSettings['sip_server_port'] === '5060', 'managed trunk should use the authorized SIP server and port');
activation_assert($trunkSettings['context'] === 'from-pstn' && $trunkSettings['sendrpid'] === 'no', 'managed trunk should apply the required context and sendrpid policy');
$routeTrunks = array_values($module->routingFixture()->trunks);
activation_assert(count($routeTrunks) === 1 && count($routeTrunks[0]) === 1 && $routeTrunks[0][0] === $trunkRecord['trunkid'], 'outbound route must use only the DOMAINTAINS trunk');
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
		['role' => 'outbound', 'sip_host' => 'outbound-b.example.invalid', 'sip_port' => 5062],
	],
]);
$multiTrunkModule = new ActivationContractModule($directory, [$multiTrunkResponse]);
$multiTrunkResult = $multiTrunkModule->activate('synthetic-test-token');
$multiTrunkRouteTrunks = array_values($multiTrunkModule->routingFixture()->trunks)[0];
activation_assert($multiTrunkResult['success'] === true, 'all authorized PBX-facing trunks should reconcile');
activation_assert(count($multiTrunkModule->coreFixture()->trunks) === 3, 'one managed PJSIP trunk should be created per authorized entry');
activation_assert(count($multiTrunkRouteTrunks) === 2, 'only outbound-role trunks should participate in the outbound route');
$allManagedTrunks = $multiTrunkModule->coreFixture()->trunks;
foreach ($allManagedTrunks as $managedTrunk) {
	$settings = $multiTrunkModule->coreFixture()->getTrunkDetails($managedTrunk['trunkid']);
	activation_assert($settings['authentication'] === 'off' && $settings['registration'] === 'none', 'every inbound and outbound managed trunk must select None / None in the FreePBX GUI');
	activation_assert($settings['auth_username'] === '' && $settings['username'] === '' && $settings['secret'] === '', 'every managed trunk must have blank authentication credentials');
	activation_assert($settings['context'] === 'from-pstn' && $settings['sendrpid'] === 'no', 'every managed trunk must preserve the required context and sendrpid policy');
}
activation_assert($multiTrunkRouteTrunks === [$allManagedTrunks[0]['trunkid'], $allManagedTrunks[2]['trunkid']], 'outbound route must include both outbound-role trunks in authorized order and exclude inbound trunks');
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
$conflictingCore->seedManagedTrunk('conflict.example.invalid');
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
activation_assert(count($localRetry->requests()) === 1, 'pending retry must resume locally without another activation request');
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
activation_assert($stateBeforeRetry === $stateAfterSameIdentity, 'same identity retry must not rewrite persisted state');
activation_assert($differentIdentityResult['success'] === false, 'different service identity must be rejected');
activation_assert($stateBeforeRetry === $stateAfterDifferentIdentity, 'different identity must not replace persisted state');
activation_assert(count($identityModule->requests()) === 1, 'provisioned idempotency and identity rejection must not call the remote endpoint again');
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
activation_assert(file_get_contents($directory . '/test-destination.json') === $markerBefore && count($inboundModule->requests()) === 1, 'test registration and remote activation should not repeat');
$localStatus = $inboundModule->getStatus();
activation_assert($localStatus['inbound_numbers'] === 2 && $localStatus['test_destination'] === 'ready', 'read-only status should expose local counts and readiness');
file_put_contents($directory . '/test-destination.json', '{"version":2}');
activation_assert(!$inboundModule->activate('synthetic-test-token')['success'], 'a corrupt test destination must fail closed even on a previously provisioned installation');
activation_assert(json_decode(file_get_contents($directory . '/state.json'), true)['provisioned'] === false, 'failed full re-verification must return persisted state to pending');
file_put_contents($directory . '/test-destination.json', '{"version":1}');
activation_assert($inboundModule->activate('synthetic-test-token')['success'] && count($inboundModule->requests()) === 1, 'repaired destination registration should resume locally without reactivation');
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
activation_assert(count($retryDIDModule->requests()) === 1 && file_get_contents($directory . '/signing.key') === $retryDIDKey, 'inbound retry must retain key and not recontact activation service');
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

echo "Activation contract passed.\n";
