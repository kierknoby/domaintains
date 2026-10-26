<?php

declare(strict_types=1);

namespace FreePBX\modules {
	function curl_init($endpoint) { return new \stdClass(); }
	function curl_setopt_array($handle, $options) { \SecurityCurlTransport::$options = $options; return true; }
	function curl_exec($handle) { return \SecurityCurlTransport::transfer($handle); }
	function curl_getinfo($handle, $option) { return 200; }
	function curl_errno($handle) { return \SecurityCurlTransport::$aborted ? 23 : 0; }
	function curl_close($handle) {}
	function posix_geteuid() { return \SecurityPrivileges::$rootStorage ? 0 : \posix_geteuid(); }
}

namespace FreePBX\Console\Command {
	function posix_geteuid() { return \SecurityPrivileges::$enabled ? \SecurityPrivileges::$uid : \posix_geteuid(); }
	function posix_getuid() { return \SecurityPrivileges::$uid; }
	function posix_getgid() { return \SecurityPrivileges::$gid; }
	function posix_getegid() { return \SecurityPrivileges::$gid; }
	function posix_getpwnam($name) { return ['uid' => 12001, 'gid' => 12001]; }
	function posix_initgroups($name, $group) { \SecurityPrivileges::$calls[] = 'groups'; return \SecurityPrivileges::$failure !== 'groups'; }
	function posix_setgid($group) {
		\SecurityPrivileges::$calls[] = 'gid';
		if (\SecurityPrivileges::$failure === 'gid') { return false; }
		\SecurityPrivileges::$gid = $group;
		return true;
	}
	function posix_setuid($owner) {
		\SecurityPrivileges::$calls[] = 'uid';
		if (\SecurityPrivileges::$failure === 'uid') { return false; }
		\SecurityPrivileges::$uid = $owner;
		return true;
	}
}

namespace {
class SecurityPrivileges {
	public static $rootStorage = false;
	public static $enabled = false;
	public static $uid = 0;
	public static $gid = 0;
	public static $calls = [];
	public static $failure = null;
}

ob_start();
require __DIR__ . '/lifecycle_contract.php';
ob_end_clean();

class SecurityCurlTransport {
	public static $options = [];
	public static $chunks = [];
	public static $aborted = false;
	public static $delivered = 0;
	public static function transfer($handle): bool {
		self::$aborted = false;
		self::$delivered = 0;
		foreach (self::$chunks as $chunk) {
			self::$delivered++;
			if ((self::$options[CURLOPT_WRITEFUNCTION])($handle, $chunk) !== strlen($chunk)) {
				self::$aborted = true;
				return false;
			}
		}
		return true;
	}
}

class SecurityTransportModule extends \FreePBX\modules\Domaintains {
	public function getProvisioningEndpoint(): string { return 'https://control.example.invalid/activate'; }
	public function request(): string { return $this->sendActivationRequest('{}'); }
}

class SecurityConsoleInput extends LifecycleInput {
	public $value = null;
	public function getArgument($name) { return $name === 'value' ? $this->value : $this->action; }
}

function security_rejected(callable $operation, string $message): void {
	$failed = false;
	try { $operation(); } catch (Throwable $error) { $failed = true; }
	activation_assert($failed, $message);
}

$module = new \FreePBX\modules\Domaintains(new stdClass());
$validateTrunks = new ReflectionMethod($module, 'validateAuthorizedTrunks');
$validateTrunks->setAccessible(true);
$desiredSettings = new ReflectionMethod($module, 'desiredTrunkSettings');
$desiredSettings->setAccessible(true);
$validHosts = [
	'sip.example.invalid', 'SIP.Example.Invalid.', 'sip-edge', 'xn--bcher-kva.example',
	'192.0.2.1', '2001:db8::1', '[2001:db8::1]', '::ffff:192.0.2.1', '[::1]',
	str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 61),
];
foreach ($validHosts as $host) {
	$trunk = ['role' => 'outbound', 'sip_host' => $host, 'sip_port' => 5060];
	activation_assert($validateTrunks->invoke($module, [$trunk]) === [$trunk], 'valid SIP hosts must retain their exact identity: ' . $host);
	$expectedServer = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $host . ']' : $host;
	activation_assert($desiredSettings->invoke($module, $trunk, 5)['sip_server'] === $expectedServer, 'native SIP URI generation requires bracketed IPv6 without rewriting other hosts');
}
foreach ([
	'', ' ', "sip.example.invalid\ncontext=from-internal", "sip.example.invalid\r\n[general]",
	"sip.example.invalid\0suffix", "sip.example.invalid\t", "sip.example.invalid\x7f",
	'sip.example.invalid;directive', 'sip.example.invalid#directive', 'sip.example.invalid/path',
	'sip:example.invalid', 'user@sip.example.invalid', 'sip.example.invalid:5060', 'bad_host.example',
	'-bad.example', 'bad-.example', 'bad..example', '.example', '.', '..', 'sip.example.invalid..', '256.0.0.1', '192.0.2.01',
	'[2001:db8::invalid]', '[192.0.2.1]', '2001:db8::1%eth0', '[::1]:5060',
	str_repeat('a', 64) . '.example', str_repeat('a', 255),
] as $host) {
	$failed = false;
	try {
		$validateTrunks->invoke($module, [['role' => 'outbound', 'sip_host' => $host, 'sip_port' => 5060]]);
	} catch (Throwable $error) {
		$failed = true;
	}
	activation_assert($failed, 'unsafe SIP host must be rejected: ' . json_encode($host));
}

$validateResponse = new ReflectionMethod($module, 'validateActivationResponse');
$validateResponse->setAccessible(true);
$limits = \FreePBX\modules\Domaintains::class;
$numbers = [];
for ($index = 0; $index < $limits::MAX_AUTHORIZED_NUMBERS; $index++) {
	$numbers[] = '44' . str_pad((string)$index, 10, '0', STR_PAD_LEFT);
}
$trunks = [];
for ($index = 0; $index < $limits::MAX_AUTHORIZED_TRUNKS; $index++) {
	$trunks[] = ['role' => 'outbound', 'sip_host' => 'sip-' . $index . '.example.invalid', 'sip_port' => 5060];
}
$boundaries = [
	'numbers' => $numbers, 'trunks' => $trunks,
	'profile' => str_repeat('p', $limits::MAX_PROVISIONING_TEXT_BYTES),
	'domaintains_hostname' => str_repeat('h', $limits::MAX_PROVISIONING_TEXT_BYTES),
	'max_channels' => 500,
];
activation_assert($validateResponse->invoke($module, activation_fixture_response($boundaries), 'my-12345678')['numbers'] === $numbers, 'maximum entitlement counts and text lengths must be accepted');
activation_assert($validateResponse->invoke($module, activation_fixture_response(['numbers' => [str_repeat('1', $limits::MAX_NUMBER_DIGITS)]]), 'my-12345678')['numbers'][0] === str_repeat('1', $limits::MAX_NUMBER_DIGITS), 'maximum number length must be accepted');
activation_assert($validateResponse->invoke($module, activation_fixture_response(['numbers' => [], 'max_channels' => 1]), 'my-12345678')['numbers'] === [], 'empty number entitlement and minimum channels remain valid');
foreach ([
	['numbers' => array_merge($numbers, ['449999999999'])],
	['trunks' => array_merge($trunks, [['role' => 'outbound', 'sip_host' => 'extra.example.invalid', 'sip_port' => 5060]])],
	['numbers' => [str_repeat('1', $limits::MAX_NUMBER_DIGITS + 1)]],
	['profile' => str_repeat('p', $limits::MAX_PROVISIONING_TEXT_BYTES + 1)],
	['domaintains_hostname' => str_repeat('h', $limits::MAX_PROVISIONING_TEXT_BYTES + 1)],
] as $overrides) {
	security_rejected(function () use ($validateResponse, $module, $overrides) {
		$validateResponse->invoke($module, activation_fixture_response($overrides), 'my-12345678');
	}, 'excessive entitlements or strings must fail validation');
}

$transport = new SecurityTransportModule(new stdClass());
$maximumResponse = str_pad(activation_fixture_response(), $limits::MAX_RESPONSE_BYTES, ' ');
SecurityCurlTransport::$chunks = str_split($maximumResponse, 8192);
activation_assert($transport->request() === $maximumResponse, 'HTTP transfer must accept exactly the response byte limit');
activation_assert(SecurityCurlTransport::$options[CURLOPT_RETURNTRANSFER] === false && SecurityCurlTransport::$options[CURLOPT_SSL_VERIFYPEER] === true && SecurityCurlTransport::$options[CURLOPT_SSL_VERIFYHOST] === 2, 'streamed transport must preserve TLS verification and avoid cURL body buffering');
activation_assert($validateResponse->invoke($module, $maximumResponse, 'my-12345678')['service'] === 'my-12345678', 'complete boundary-sized JSON response must remain valid');
SecurityCurlTransport::$chunks = [$maximumResponse, ' ', 'must-not-be-read'];
security_rejected(function () use ($transport) { $transport->request(); }, 'transfer exceeding the limit must fail even after a valid JSON prefix');
activation_assert(SecurityCurlTransport::$aborted && SecurityCurlTransport::$delivered === 2, 'oversized transfer must terminate at the first excessive chunk');
SecurityCurlTransport::$chunks = [str_repeat('x', $limits::MAX_RESPONSE_BYTES + 1), 'must-not-be-read'];
security_rejected(function () use ($transport) { $transport->request(); }, 'a single oversized chunk must fail');
activation_assert(SecurityCurlTransport::$delivered === 1, 'oversized first chunk must not be buffered or followed');
security_rejected(function () use ($validateResponse, $module, $maximumResponse) { $validateResponse->invoke($module, $maximumResponse . ' ', 'my-12345678'); }, 'final response validation must reject oversized JSON too');
security_rejected(function () use ($validateResponse, $module) { $validateResponse->invoke($module, substr(activation_fixture_response(), 0, -1), 'my-12345678'); }, 'truncated JSON must never be accepted');

$command = new \FreePBX\Console\Command\Domaintains();
$dropPrivileges = new ReflectionMethod($command, 'dropRootPrivileges');
$dropPrivileges->setAccessible(true);
SecurityPrivileges::$enabled = true;
$dropPrivileges->invoke($command);
activation_assert(SecurityPrivileges::$calls === ['groups', 'gid', 'uid'] && SecurityPrivileges::$uid === 12001 && SecurityPrivileges::$gid === 12001, 'root CLI must permanently drop supplementary groups, group and user before module access');
SecurityPrivileges::$calls = [];
$dropPrivileges->invoke($command);
activation_assert(SecurityPrivileges::$calls === [], 'unprivileged invocation must not change its identity');
foreach (['groups', 'gid', 'uid'] as $failure) {
	SecurityPrivileges::$uid = 0;
	SecurityPrivileges::$gid = 0;
	SecurityPrivileges::$failure = $failure;
	security_rejected(function () use ($dropPrivileges, $command) { $dropPrivileges->invoke($command); }, 'failed privilege transition must stop the command');
}
SecurityPrivileges::$enabled = false;
SecurityPrivileges::$failure = null;

$directory = activation_temp_directory();
$storageModule = new ActivationContractModule($directory, [activation_fixture_response()]);
activation_assert($storageModule->activate('synthetic-test-token')['success'], 'storage fixture must activate normally');
$before = [];
foreach (scandir($directory) as $file) {
	if ($file !== '.' && $file !== '..') { $before[$file] = [file_get_contents($directory . '/' . $file), fileperms($directory . '/' . $file) & 0777]; }
}
SecurityPrivileges::$rootStorage = true;
activation_assert(!$storageModule->activate('synthetic-test-token')['success'] && !$storageModule->deactivate()['success'] && !$storageModule->configureProvisioningEndpoint('https://other.example.invalid/activate')['success'], 'direct effective-root storage operations must fail closed');
$writeFile = new ReflectionMethod($storageModule, 'writeSecureFile');
$writeFile->setAccessible(true);
security_rejected(function () use ($writeFile, $storageModule, $directory) { $writeFile->invoke($storageModule, $directory . '/signing.key', 'must-not-replace'); }, 'root file writes must be refused before temporary file creation');
SecurityPrivileges::$rootStorage = false;
$after = [];
foreach (scandir($directory) as $file) {
	if ($file !== '.' && $file !== '..') { $after[$file] = [file_get_contents($directory . '/' . $file), fileperms($directory . '/' . $file) & 0777]; }
}
activation_assert($before === $after, 'privileged refusal must preserve all state bytes and file permissions, including the signing identity');
activation_assert($storageModule->activate('synthetic-test-token')['success'], 'unprivileged access must remain compatible with existing stores');
$nativeBefore = [$storageModule->coreFixture()->trunks, $storageModule->coreFixture()->details, $storageModule->coreFixture()->dids, $storageModule->routingFixture()->routes];
$retainedBefore = [];
foreach (['state.json', 'ownership.json', 'signing.key', 'endpoint.json'] as $file) {
	$retainedBefore[$file] = file_get_contents($directory . '/' . $file);
}
foreach ([
	activation_fixture_response(['trunks' => [['role' => 'outbound', 'sip_host' => "sip.example.invalid\ncontext=from-internal", 'sip_port' => 5060]]]),
	activation_fixture_response(['numbers' => array_merge($numbers, ['449999999999'])]),
	activation_fixture_response(['trunks' => array_merge($trunks, [['role' => 'outbound', 'sip_host' => 'extra.example.invalid', 'sip_port' => 5060]])]),
	activation_fixture_response(['profile' => str_repeat('p', $limits::MAX_PROVISIONING_TEXT_BYTES + 1)]),
	$maximumResponse . ' ',
] as $invalidResponse) {
	$storageModule->queueResponse($invalidResponse);
	$result = $storageModule->activate('synthetic-test-token');
	activation_assert(!$result['success'] && $result['stage'] === 'remote', 'unsafe refresh must be rejected before local reconciliation');
	foreach ($retainedBefore as $file => $contents) {
		activation_assert(file_get_contents($directory . '/' . $file) === $contents, 'unsafe refresh must preserve retained ' . $file);
	}
	activation_assert([$storageModule->coreFixture()->trunks, $storageModule->coreFixture()->details, $storageModule->coreFixture()->dids, $storageModule->routingFixture()->routes] === $nativeBefore, 'unsafe refresh must not change any existing PBX object');
}
activation_cleanup($directory);

$directory = activation_temp_directory();
$sixObjectResponse = activation_fixture_response(['trunks' => [
	['role' => 'inbound', 'sip_host' => 'sip-in.example.invalid', 'sip_port' => 5060],
	['role' => 'outbound', 'sip_host' => 'sip-out.example.invalid', 'sip_port' => 5060],
]]);
$upgradeModule = new ActivationContractModule($directory, [$sixObjectResponse]);
activation_assert($upgradeModule->activate('synthetic-test-token')['success'], 'six-object upgrade fixture must provision');
unlink($directory . '/ownership.json');
$preview = $upgradeModule->previewOwnershipAdoption();
activation_assert($preview['success'] && count($preview['objects']) === 6 && $upgradeModule->adoptOwnership($preview['plan'], true)['success'], 'upgrade baseline must contain six explicitly adopted objects');
$baseline = [];
foreach (['state.json', 'ownership.json', 'signing.key', 'endpoint.json', 'test-destination.json'] as $file) {
	$baseline[$file] = file_get_contents($directory . '/' . $file);
}
$nativeBaseline = [$upgradeModule->coreFixture()->trunks, $upgradeModule->coreFixture()->details, $upgradeModule->coreFixture()->dids, $upgradeModule->routingFixture()->routes];
SecurityPrivileges::$enabled = true;
SecurityPrivileges::$uid = 0;
SecurityPrivileges::$gid = 0;
SecurityPrivileges::$calls = [];
SecurityPrivileges::$rootStorage = true;
$registration = new \FreePBX\Console\Command\Domaintains();
$configureCommand = new ReflectionMethod($registration, 'configure');
$configureCommand->setAccessible(true);
$configureCommand->invoke($registration);
$installer = new \FreePBX\modules\Domaintains(new stdClass());
$installer->install();
require dirname(__DIR__) . '/install.php';
activation_assert(SecurityPrivileges::$calls === [] && SecurityPrivileges::$uid === 0, 'command registration and root install hooks must not drop installer privileges');
SecurityPrivileges::$rootStorage = false;
SecurityPrivileges::$enabled = false;
$upgraded = new ActivationContractModule($directory, [$sixObjectResponse], $upgradeModule->coreFixture(), $upgradeModule->routingFixture(), $upgradeModule->firewallFixture(), false);
activation_assert($upgraded->getStatus()['state'] === 'provisioned', 'existing activation must remain readable without migration');
foreach ($baseline as $file => $contents) {
	activation_assert(file_get_contents($directory . '/' . $file) === $contents, 'install, registration and status must preserve existing ' . $file . ' byte for byte');
}
$command = new \FreePBX\Console\Command\Domaintains();
$questions = new LifecycleQuestions();
$command->helper = $questions;
foreach ([
	['status', null, []], ['endpoint', null, []],
	['endpoint', 'https://replacement.example.invalid/activate', []],
	['activate', null, ['synthetic-test-token']],
	['reactivate', null, [true, 'synthetic-replacement-token']],
	['adopt', null, [true]],
] as $scenario) {
	SecurityPrivileges::$enabled = true;
	SecurityPrivileges::$uid = 0;
	SecurityPrivileges::$gid = 0;
	SecurityPrivileges::$calls = [];
	$input = new SecurityConsoleInput();
	$input->action = $scenario[0];
	$input->value = $scenario[1];
	$input->json = true;
	$questions->answers = $scenario[2];
	activation_assert($command->run($input, new LifecycleOutput()) === 0, 'root CLI action must remain usable after dropping privileges: ' . $scenario[0]);
	activation_assert(SecurityPrivileges::$calls === ['groups', 'gid', 'uid'], 'CLI must finish privilege transition before each action: ' . $scenario[0]);
}
activation_assert(file_get_contents($directory . '/ownership.json') === $baseline['ownership.json'] && file_get_contents($directory . '/signing.key') === $baseline['signing.key'], 'all CLI actions must preserve adopted ownership and signing identity');
activation_assert([$upgraded->coreFixture()->trunks, $upgraded->coreFixture()->details, $upgraded->coreFixture()->dids, $upgraded->routingFixture()->routes] === $nativeBaseline, 'upgrade and CLI refreshes must preserve native trunk and route identities and settings');
activation_assert($upgraded->firewallFixture()->readCalls === 0 && $upgraded->firewallFixture()->addCalls === 0, 'upgrades and all CLI actions must not inspect or modify Firewall');
foreach (['groups', 'gid', 'uid'] as $failure) {
	SecurityPrivileges::$uid = 0;
	SecurityPrivileges::$gid = 0;
	SecurityPrivileges::$failure = $failure;
	FreePBX::$module = new stdClass();
	activation_assert($command->run($input, new LifecycleOutput()) === 1, 'partial privilege transition must return failure before touching the module');
}
SecurityPrivileges::$failure = null;
SecurityPrivileges::$enabled = false;
FreePBX::$module = $upgraded;
activation_assert($upgraded->deactivate()['success'] && !file_exists($directory . '/state.json'), 'local Deactivate must still remove only activation authorization');
activation_assert(file_get_contents($directory . '/ownership.json') === $baseline['ownership.json'] && file_get_contents($directory . '/signing.key') === $baseline['signing.key'] && $upgraded->getProvisioningEndpoint() === 'https://replacement.example.invalid/activate', 'Deactivate must preserve key, adopted ownership and configured endpoint after upgrade');
activation_cleanup($directory);

echo "Security contract checks passed.\n";
}