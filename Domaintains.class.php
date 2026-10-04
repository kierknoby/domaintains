<?php
/**
 * DOMAINTAINS for FreePBX 16 and 17.
 *
 * PBX-side integration with the DOMAINTAINS remote orchestration service.
 *
 * @copyright 2026 20 Telecom Ltd (trading as 20tele.com)
 * @license   GPLv3+
 */

namespace FreePBX\modules;

class Domaintains implements \BMO {

	/** Fallback only. Authoritative version lives in module.xml. */
	const VERSION = '0.3.0';
	const ACTIVATION_ENDPOINT = 'https://my-connect.freepbxhosting.uk/activate';
	const TRUNK_NAME = 'DOMAINTAINS';
	const ROUTE_NAME = 'DOMAINTAINS-Outbound';
	const TEST_DESTINATION = 'domaintains-test,s,1';

	/** @var \FreePBX */
	private $FreePBX;

	public function __construct($freepbx = null) {
		if ($freepbx === null) {
			throw new \Exception('Not given a FreePBX Object');
		}
		$this->FreePBX = $freepbx;
	}

	public function install(): void {}
	public function uninstall(): void {}
	public function backup(): array { return []; }
	public function restore($backup): void {}
	public function doConfigPageInit($page): void {
		if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST' || !isset($_POST['domaintains_action']) || $_POST['domaintains_action'] !== 'activate') {
			return;
		}

		$csrfToken = isset($_POST['domaintains_csrf']) ? (string)$_POST['domaintains_csrf'] : '';
		$sessionToken = $this->getSessionCsrfToken();
		if ($sessionToken === null || $csrfToken === '' || !hash_equals($sessionToken, $csrfToken)) {
			$this->setGuiMessage(false, _('Activation request could not be verified. Reload the page and try again.'));
			return;
		}

		unset($_SESSION['domaintains_csrf']);
		$key = isset($_POST['activation_key']) ? trim((string)$_POST['activation_key']) : '';
		$result = $this->activate($key);
		$this->setGuiMessage($result['success'], $result['message']);
	}

	public function getVersion(): string {
		try {
			$info = \FreePBX::Modules()->getInfo('domaintains');
			if (isset($info['domaintains']['version'])) {
				return (string)$info['domaintains']['version'];
			}
		} catch (\Exception $e) {
			// Module metadata may be unavailable during early install.
		}

		return self::VERSION;
	}

	/**
	 * Return the current DOMAINTAINS module state.
	 *
	 * The status comes from the local activation record, not a remote status
	 * request. A damaged record is reported as an error and cannot be overwritten
	 * by a new activation attempt.
	 */
	public function getStatus(): array {
		$state = 'unprovisioned';
		$provisioned = false;
		$activatedAt = null;
		try {
			$record = $this->readActivationState();
			if ($record !== null) {
				$provisioned = $record->provisioned;
				$state = $provisioned ? 'provisioned' : 'pending';
				$activatedAt = $record->activated_at;
			}
		} catch (\Throwable $e) {
			$state = 'error';
		}

		$status = [
			'module' => 'DOMAINTAINS',
			'rawname' => 'domaintains',
			'version' => $this->getVersion(),
			'state' => $state,
			'provisioned' => $provisioned,
			'freepbx_support' => ['16', '17'],
		];
		if ($activatedAt !== null) {
			$status['activated_at'] = $activatedAt;
		}
		if (isset($record) && $record !== null && $state !== 'error') {
			$status['inbound_numbers'] = count($record->numbers);
			$status['inbound_trunks'] = count(array_filter($record->trunks, function ($trunk) { return $trunk->role === 'inbound'; }));
			$status['outbound_trunks'] = count(array_filter($record->trunks, function ($trunk) { return $trunk->role === 'outbound'; }));
			$status['test_destination'] = $provisioned ? 'ready' : 'pending';
		}
		return $status;
	}

	/** Activate this installation using the provider-issued key. */
	public function activate(string $activationKey): array {
		if (trim($activationKey) === '') {
			return ['success' => false, 'message' => _('Enter an activation key.')];
		}
		if (!$this->supportsSodium()) {
			return ['success' => false, 'message' => _('Activation is unavailable because sodium support is missing.')];
		}
		$lock = null;
		$configurationChanged = false;
		$reloadRequested = false;
		try {
			$this->ensureStorageDirectory();
			$lockPath = $this->storageDirectory() . '/activation.lock';
			if (is_link($lockPath)) {
				throw new \RuntimeException('Invalid activation lock.');
			}
			$lock = fopen($lockPath, 'c');
			if ($lock === false) {
				throw new \RuntimeException('Unable to open activation lock.');
			}
			if (!chmod($lockPath, 0600)) {
				throw new \RuntimeException('Unable to secure activation lock.');
			}
			if (!flock($lock, LOCK_EX)) {
				throw new \RuntimeException('Unable to lock activation state.');
			}

			$existingState = $this->readActivationState();
			$this->ensureActivationHostInLocalZone();
			if ($existingState === null && !$this->supportsHttpsTransport()) {
				return ['success' => false, 'message' => _('Activation is unavailable because HTTPS support is missing.')];
			}
			list($publicKey, $secretKey) = $this->loadOrCreateSigningKeypair($existingState === null);
			$publicKeyFingerprint = hash('sha256', $publicKey);
			$activationKeyFingerprint = $this->activationKeyFingerprint($activationKey, $secretKey);
			if ($existingState !== null) {
				if (!hash_equals($existingState->public_key_fingerprint, $publicKeyFingerprint)) {
					return ['success' => false, 'message' => _('The local signing identity does not match the pending activation state.')];
				}
				if (!hash_equals($existingState->activation_key_fingerprint, $activationKeyFingerprint)) {
					return ['success' => false, 'message' => _('This installation is already linked to a different activation identity.')];
				}
				$this->clearSigningSecret($secretKey);
				$responseValues = $this->authorizedValues($existingState);
				$state = array_merge($this->authorizedValues($existingState), [
					'provisioned' => $existingState->provisioned,
					'activated_at' => $existingState->activated_at,
					'public_key_fingerprint' => $existingState->public_key_fingerprint,
					'activation_key_fingerprint' => $existingState->activation_key_fingerprint,
				]);
			} else {
				$claim = [
					'token' => $activationKey,
					'public_key' => base64_encode($publicKey),
					'timestamp' => time(),
					'nonce' => base64_encode(random_bytes(24)),
				];
				$canonicalClaim = json_encode($claim, JSON_UNESCAPED_SLASHES);
				if ($canonicalClaim === false) {
					throw new \RuntimeException('Unable to encode activation claim.');
				}

				try {
					$signature = $this->signActivationClaim($canonicalClaim, $secretKey);
				} catch (\Throwable $e) {
					$this->clearSigningSecret($secretKey);
					throw new \RuntimeException('Unable to sign activation claim.');
				}
				$this->clearSigningSecret($secretKey);
				if (!is_string($signature) || strlen($signature) !== $this->signatureLength()) {
					throw new \RuntimeException('Unable to sign activation claim.');
				}
				$request = json_encode([
					'action' => 'activate',
					'claim' => $claim,
					'signature' => base64_encode($signature),
				], JSON_UNESCAPED_SLASHES);
				if ($request === false) {
					throw new \RuntimeException('Unable to encode activation request.');
				}

				$responseValues = $this->validateActivationResponse($this->sendActivationRequest($request));
				$state = array_merge($responseValues, [
					'provisioned' => false,
					'activated_at' => time(),
					'public_key_fingerprint' => $publicKeyFingerprint,
					'activation_key_fingerprint' => $activationKeyFingerprint,
				]);
				$this->writeActivationState($state);
			}

			try {
				if ($state['provisioned']) {
					$state['provisioned'] = false;
					$this->writeActivationState($state);
				}
				$this->reconcileLocalConfiguration($state, $configurationChanged);
				if ($configurationChanged) {
					$this->requestConfigurationReload();
					$reloadRequested = true;
				}
				$this->verifyLocalConfiguration($state);
				$state['provisioned'] = true;
				$this->writeActivationState($state);
			} catch (\Throwable $e) {
				if ($configurationChanged && !$reloadRequested) {
					$this->requestConfigurationReload();
				}
				throw $e;
			}
			return ['success' => true, 'message' => _('DOMAINTAINS activation and local configuration completed successfully.')];
		} catch (\Throwable $e) {
			return ['success' => false, 'message' => _('Activation failed. Check the service connection and try again.')];
		} finally {
			if (isset($secretKey) && is_string($secretKey)) {
				$this->clearSigningSecret($secretKey);
			}
			if (is_resource($lock)) {
				flock($lock, LOCK_UN);
				fclose($lock);
			}
		}
	}

	public function showPage(): string {
		return load_view(__DIR__ . '/views/main.php', [
			'moduleVersion' => $this->getVersion(),
			'status' => $this->getStatus(),
			'activationCsrfToken' => $this->createSessionCsrfToken(),
			'activationMessage' => $this->takeGuiMessage(),
		]);
	}

	protected function storageDirectory(): string {
		return '/var/lib/asterisk/domaintains';
	}

	private function ensureStorageDirectory(): void {
		$directory = $this->storageDirectory();
		if (is_link($directory)) {
			throw new \RuntimeException('Activation storage must not be a symbolic link.');
		}
		if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
			throw new \RuntimeException('Unable to create activation storage.');
		}
		if (!chmod($directory, 0700)) {
			throw new \RuntimeException('Unable to secure activation storage.');
		}
	}

	protected function loadOrCreateSigningKeypair(bool $allowCreate = true): array {
		$path = $this->storageDirectory() . '/signing.key';
		if (file_exists($path) || is_link($path)) {
			if (is_link($path) || !is_file($path)) {
				throw new \RuntimeException('Invalid signing key file.');
			}
			if (!chmod($path, 0600)) {
				throw new \RuntimeException('Unable to secure signing key file.');
			}
			$secretKey = @file_get_contents($path);
			if (!is_string($secretKey) || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
				throw new \RuntimeException('Invalid signing key file.');
			}
			$publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);
			return [$publicKey, $secretKey];
		}
		if (!$allowCreate) {
			throw new \RuntimeException('Signing key is missing for retained activation state.');
		}

		$keypair = sodium_crypto_sign_keypair();
		$publicKey = sodium_crypto_sign_publickey($keypair);
		$secretKey = sodium_crypto_sign_secretkey($keypair);
		sodium_memzero($keypair);
		$this->writeSecureFile($path, $secretKey);
		return [$publicKey, $secretKey];
	}

	private function readActivationState() {
		$path = $this->storageDirectory() . '/state.json';
		if (!file_exists($path) && !is_link($path)) {
			return null;
		}
		if (is_link($path) || !is_file($path)) {
			throw new \RuntimeException('Invalid activation state file.');
		}
		if (!chmod($path, 0600)) {
			throw new \RuntimeException('Unable to secure activation state file.');
		}
		$contents = @file_get_contents($path);
		$record = is_string($contents) ? json_decode($contents) : null;
		if (!is_object($record) || !isset($record->provisioned, $record->activated_at, $record->public_key_fingerprint, $record->activation_key_fingerprint) || !is_bool($record->provisioned) || !is_int($record->activated_at) || !preg_match('/^[a-f0-9]{64}$/', (string)$record->public_key_fingerprint) || !preg_match('/^[a-f0-9]{64}$/', (string)$record->activation_key_fingerprint)) {
			throw new \RuntimeException('Invalid activation state file.');
		}
		$this->validateProvisioningFields($record);
		return $record;
	}

	protected function supportsSodium(): bool {
		return function_exists('sodium_crypto_sign_detached') && function_exists('sodium_crypto_sign_keypair') && function_exists('sodium_crypto_sign_publickey_from_secretkey');
	}

	protected function supportsHttpsTransport(): bool {
		return function_exists('curl_init');
	}

	protected function signActivationClaim(string $claim, string $secretKey): string {
		return sodium_crypto_sign_detached($claim, $secretKey);
	}

	protected function signatureLength(): int {
		return SODIUM_CRYPTO_SIGN_BYTES;
	}

	protected function clearSigningSecret(string &$secretKey): void {
		if (function_exists('sodium_memzero')) {
			sodium_memzero($secretKey);
		} else {
			$secretKey = '';
		}
	}

	private function activationKeyFingerprint(string $activationKey, string $secretKey): string {
		return hash_hmac('sha256', "domaintains-activation-key\0" . $activationKey, $secretKey);
	}

	private function validateActivationResponse(string $response): array {
		$decoded = json_decode($response);
		if (json_last_error() !== JSON_ERROR_NONE || !is_object($decoded) || !isset($decoded->ok) || $decoded->ok !== true) {
			throw new \RuntimeException('Invalid activation response.');
		}
		return $this->validateProvisioningFields($decoded);
	}

	private function validateProvisioningFields($data): array {
		if (is_object($data)) {
			$data = (array)$data;
		}
		$fields = ['service', 'profile', 'domaintains_hostname', 'trunks', 'numbers'];
		$values = [];
		foreach ($fields as $field) {
			if (!array_key_exists($field, $data)) {
				throw new \RuntimeException('Activation state is missing a required local field.');
			}
			$values[$field] = $data[$field];
		}
		foreach (['service', 'profile', 'domaintains_hostname'] as $field) {
			if (!is_string($values[$field]) || trim($values[$field]) === '') {
				throw new \RuntimeException('Activation state has an invalid local field.');
			}
		}
		if (!preg_match('/^my-[0-9]{8}$/D', $values['service']) || !is_array($values['trunks']) || count($values['trunks']) === 0) {
			throw new \RuntimeException('Activation state has an invalid service or trunk list.');
		}
		$values['trunks'] = $this->validateAuthorizedTrunks($values['trunks']);
		if (!is_array($values['numbers']) || array_values($values['numbers']) !== $values['numbers']) {
			throw new \RuntimeException('Authorized numbers must be a list.');
		}
		$seenNumbers = [];
		foreach ($values['numbers'] as $number) {
			if (!is_string($number) || !preg_match('/^[0-9]+$/D', $number) || isset($seenNumbers[$number])) {
				throw new \RuntimeException('Invalid or duplicate authorized number.');
			}
			$seenNumbers[$number] = true;
		}
		return $values;
	}

	private function validateAuthorizedTrunks(array $trunks): array {
		$validated = [];
		$seen = [];
		foreach ($trunks as $trunk) {
			if (is_object($trunk)) {
				$trunk = (array)$trunk;
			}
			if (!is_array($trunk) || !isset($trunk['role'], $trunk['sip_host'], $trunk['sip_port']) || !is_string($trunk['role']) || !in_array($trunk['role'], ['inbound', 'outbound'], true) || !is_string($trunk['sip_host']) || trim($trunk['sip_host']) === '' || !is_int($trunk['sip_port']) || $trunk['sip_port'] < 1 || $trunk['sip_port'] > 65535) {
				throw new \RuntimeException('Activation response contains an invalid authorized trunk.');
			}
			$identity = json_encode([$trunk['role'], $trunk['sip_host'], $trunk['sip_port']], JSON_UNESCAPED_SLASHES);
			if ($identity === false || isset($seen[$identity])) {
				throw new \RuntimeException('Activation response contains a duplicate authorized trunk.');
			}
			$seen[$identity] = true;
			$validated[] = [
				'role' => $trunk['role'],
				'sip_host' => $trunk['sip_host'],
				'sip_port' => $trunk['sip_port'],
			];
		}
		return $validated;
	}

	private function authorizedValues($data): array {
		return $this->validateProvisioningFields($data);
	}

	private function writeActivationState(array $state): void {
		$encoded = json_encode($state, JSON_UNESCAPED_SLASHES);
		if ($encoded === false) {
			throw new \RuntimeException('Unable to encode activation state.');
		}
		$this->writeSecureFile($this->storageDirectory() . '/state.json', $encoded . "\n");
	}

	protected function coreApi() {
		return $this->FreePBX->Core;
	}

	protected function routingApi() {
		return new \FreePBX\modules\Core\Components\Outboundrouting();
	}

	protected function databaseApi() {
		return \FreePBX::Database();
	}

	protected function firewallApi() {
		return \FreePBX::Firewall();
	}

	private function ensureActivationHostInLocalZone(): void {
		$host = parse_url(self::ACTIVATION_ENDPOINT, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			throw new \RuntimeException('Activation service hostname is invalid.');
		}
		$firewall = $this->firewallApi();
		if (!method_exists($firewall, 'get_networkmaps') || !method_exists($firewall, 'addNetworkToZone')) {
			throw new \RuntimeException('FreePBX Firewall zone API is unavailable.');
		}
		$networkMaps = $this->firewallNetworkMaps($firewall);
		if (array_key_exists($host, $networkMaps)) {
			if ($networkMaps[$host] !== 'internal') {
				throw new \RuntimeException('Activation service hostname is assigned to a conflicting Firewall zone.');
			}
			return;
		}
		$firewall->addNetworkToZone($host, 'internal');
		$networkMaps = $this->firewallNetworkMaps($firewall);
		if (!isset($networkMaps[$host]) || $networkMaps[$host] !== 'internal') {
			throw new \RuntimeException('Activation service hostname did not verify in the Firewall local zone.');
		}
	}

	private function firewallNetworkMaps($firewall): array {
		$networkMaps = $firewall->get_networkmaps();
		if ($networkMaps === false || $networkMaps === null || $networkMaps === '') {
			return [];
		}
		if (!is_array($networkMaps)) {
			throw new \RuntimeException('Unable to read FreePBX Firewall zones.');
		}
		return $networkMaps;
	}

	private function reconcileLocalConfiguration(array $state, &$configurationChanged): void {
		$this->reconcileTestDestination($configurationChanged);
		$this->assertNoUnexpectedManagedTrunks($state['trunks']);
		$outboundTrunkIds = [];
		foreach ($state['trunks'] as $authorizedTrunk) {
			$trunk = $this->reconcileTrunk($authorizedTrunk, $configurationChanged);
			if ($trunk['changed']) {
				$configurationChanged = true;
			}
			if ($authorizedTrunk['role'] === 'outbound') {
				$outboundTrunkIds[] = $trunk['id'];
			}
		}
		$routeChanged = $this->reconcileOutboundRoute($state['service'], $outboundTrunkIds, $configurationChanged);
		if ($routeChanged) {
			$configurationChanged = true;
		}
		$this->reconcileInboundRoutes($state['numbers'], $configurationChanged);
	}

	private function assertNoUnexpectedManagedTrunks(array $authorizedTrunks): void {
		$expectedNames = [];
		foreach ($authorizedTrunks as $authorizedTrunk) {
			$expectedNames[$this->managedTrunkName($authorizedTrunk)] = true;
		}
		$trunks = $this->coreApi()->listTrunks();
		if (!is_array($trunks)) {
			throw new \RuntimeException('Unable to list FreePBX trunks.');
		}
		foreach ($trunks as $trunk) {
			if (isset($trunk['name']) && strpos($trunk['name'], self::TRUNK_NAME) === 0 && !isset($expectedNames[$trunk['name']])) {
				throw new \RuntimeException('An unexpected module-owned trunk conflicts with the authorized trunk set.');
			}
		}
	}

	public function testDestinationRegistered(): bool {
		$path = $this->storageDirectory() . '/test-destination.json';
		if (!file_exists($path) && !is_link($path)) {
			return false;
		}
		if (is_link($path) || !is_file($path) || !is_readable($path)) {
			throw new \RuntimeException('Invalid test destination registration.');
		}
		$registration = json_decode((string)file_get_contents($path), true);
		if ($registration !== ['version' => 1]) {
			throw new \RuntimeException('Invalid test destination registration.');
		}
		return true;
	}

	private function reconcileTestDestination(&$configurationChanged): void {
		require_once __DIR__ . '/functions.inc.php';
		if (!$this->testDestinationRegistered()) {
			$this->writeSecureFile($this->storageDirectory() . '/test-destination.json', "{\"version\":1}\n");
			$configurationChanged = true;
		}
		$this->verifyTestDestination();
	}

	protected function dialplanBuilder() {
		if (!class_exists('extensions')) {
			$webRoot = $this->FreePBX->Config->get('AMPWEBROOT');
			require_once $webRoot . '/admin/libraries/extensions.class.php';
		}
		return new \extensions();
	}

	public function contributeTestDialplan($builder): void {
		if (!$this->testDestinationRegistered()) {
			return;
		}
		$builder->add('domaintains-test', 's', '', new \ext_answer(''));
		$builder->add('domaintains-test', 's', '', new \ext_wait('1'));
		$builder->add('domaintains-test', 's', '', new \ext_playtones('1000/200,0/200,1000/200,0/200,1000/400'));
		$builder->add('domaintains-test', 's', '', new \ext_wait('2'));
		$builder->add('domaintains-test', 's', '', new \ext_stopplaytones(''));
		$builder->add('domaintains-test', 's', '', new \ext_hangup(''));
	}

	private function verifyTestDestination(): void {
		if (!$this->testDestinationRegistered() || !function_exists('domaintains_get_config') || !function_exists('domaintains_destinations')) {
			throw new \RuntimeException('Test destination hooks are unavailable.');
		}
		$builder = $this->dialplanBuilder();
		$this->contributeTestDialplan($builder);
		$steps = isset($builder->_exts['domaintains-test'][' s ']) ? $builder->_exts['domaintains-test'][' s '] : [];
		$output = [];
		foreach ($steps as $step) {
			$output[] = $step['cmd']->output();
		}
		if ($output !== ['Answer', 'Wait(1)', 'Playtones(1000/200,0/200,1000/200,0/200,1000/400)', 'Wait(2)', 'StopPlaytones', 'Hangup()']) {
			throw new \RuntimeException('Test answering dialplan failed verification.');
		}
	}

	private function inboundRouteMatches(array $route, string $number): bool {
		return isset($route['extension'], $route['cidnum'], $route['destination'], $route['description'])
			&& (string)$route['extension'] === $number && (string)$route['cidnum'] === ''
			&& $route['destination'] === self::TEST_DESTINATION
			&& $route['description'] === 'DOMAINTAINS Inbound ' . $number;
	}

	private function matchingInboundRoutes(string $number): array {
		$routes = $this->coreApi()->getAllDIDs();
		if (!is_array($routes)) {
			throw new \RuntimeException('Unable to inspect inbound routes.');
		}
		return array_values(array_filter($routes, function ($route) use ($number) {
			return isset($route['extension']) && (string)$route['extension'] === $number;
		}));
	}

	private function reconcileInboundRoutes(array $numbers, &$configurationChanged): void {
		foreach ($numbers as $number) {
			$matches = $this->matchingInboundRoutes($number);
			if (count($matches) > 0) {
				if (count($matches) !== 1 || !$this->inboundRouteMatches($matches[0], $number)) {
					throw new \RuntimeException('An existing DID route conflicts with the authorized inbound route.');
				}
				continue;
			}
			if (!$this->coreApi()->addDID([
				'extension' => $number,
				'cidnum' => '',
				'description' => 'DOMAINTAINS Inbound ' . $number,
				'destination' => self::TEST_DESTINATION,
			])) {
				throw new \RuntimeException('Unable to create an authorized inbound route.');
			}
			$configurationChanged = true;
			$this->verifyInboundRoutes([$number]);
		}
	}

	private function verifyInboundRoutes(array $numbers): void {
		foreach ($numbers as $number) {
			$matches = $this->matchingInboundRoutes($number);
			if (count($matches) !== 1 || !$this->inboundRouteMatches($matches[0], $number)) {
				throw new \RuntimeException('An authorized inbound route failed verification.');
			}
		}
	}

	private function reconcileTrunk(array $authorizedTrunk, &$configurationChanged): array {
		$core = $this->coreApi();
		$trunkName = $this->managedTrunkName($authorizedTrunk);
		$trunks = $core->listTrunks();
		if (!is_array($trunks)) {
			throw new \RuntimeException('Unable to list FreePBX trunks.');
		}
		$matches = array_values(array_filter($trunks, function ($trunk) use ($trunkName) {
			return isset($trunk['name']) && $trunk['name'] === $trunkName;
		}));
		if (count($matches) > 1) {
			throw new \RuntimeException('Multiple module-owned trunks were found.');
		}
		if (count($matches) === 1) {
			$trunk = $matches[0];
			$id = isset($trunk['trunkid']) ? $trunk['trunkid'] : null;
			if ($id !== null && $this->trunkMatchesAuthorizedState($id, $trunk, $authorizedTrunk, true)) {
				$details = $core->getTrunkDetails($id);
				if ($details['authentication'] === 'none') {
					$this->normalizeManagedTrunkAuthentication($id, $trunk, $details, $authorizedTrunk);
					$configurationChanged = true;
					$trunk = $this->findManagedTrunk($core, $authorizedTrunk);
				}
			}
			if ($id === null || !$this->trunkMatchesAuthorizedState($id, $trunk, $authorizedTrunk)) {
				throw new \RuntimeException('The module-owned trunk conflicts with the authorized configuration.');
			}
			return ['id' => $id, 'changed' => false];
		}

		$settings = $this->desiredTrunkSettings($authorizedTrunk);
		if (!method_exists($core, 'checkPJSIPsettings')) {
			throw new \RuntimeException('FreePBX PJSIP trunk API is unavailable.');
		}
		$settings = $core->checkPJSIPsettings($settings, ['imports' => $settings]);
		$originalPost = $_POST;
		$_POST = [];
		try {
			$trunkId = $core->addTrunk($trunkName, 'pjsip', $settings);
		} finally {
			$_POST = $originalPost;
		}
		if ($trunkId === false || $trunkId === null || $trunkId === '') {
			throw new \RuntimeException('Unable to create the module-owned PJSIP trunk.');
		}
		$configurationChanged = true;
		$created = $this->findManagedTrunk($core, $authorizedTrunk);
		if ($created === null || !$this->trunkMatchesAuthorizedState($created['trunkid'], $created, $authorizedTrunk)) {
			throw new \RuntimeException('The created PJSIP trunk did not verify.');
		}
		return ['id' => $created['trunkid'], 'changed' => true];
	}

	private function findManagedTrunk($core, array $authorizedTrunk) {
		$trunkName = $this->managedTrunkName($authorizedTrunk);
		$trunks = $core->listTrunks();
		if (!is_array($trunks)) {
			throw new \RuntimeException('Unable to list FreePBX trunks.');
		}
		$matches = array_values(array_filter($trunks, function ($trunk) use ($trunkName) {
			return isset($trunk['name']) && $trunk['name'] === $trunkName;
		}));
		if (count($matches) > 1) {
			throw new \RuntimeException('Multiple module-owned trunks were found.');
		}
		return count($matches) === 1 ? $matches[0] : null;
	}

	private function normalizeManagedTrunkAuthentication($id, array $trunk, array $details, array $authorizedTrunk): void {
		$core = $this->coreApi();
		$settings = array_merge($this->desiredTrunkSettings($authorizedTrunk), $trunk, $details, [
			'authentication' => 'off',
			'trunknum' => $id,
			'disabletrunk' => $trunk['disabled'],
			'failtrunk' => isset($trunk['failscript']) ? $trunk['failscript'] : '',
		]);
		$database = $this->databaseApi();
		if (!$database->beginTransaction()) {
			throw new \RuntimeException('Unable to start trunk normalization transaction.');
		}
		$originalPost = $_POST;
		$_POST = [];
		try {
			if ($core->deleteTrunk($id, 'pjsip', true) !== true || (string)$core->addTrunk($trunk['name'], 'pjsip', $settings, true) !== (string)$id) {
				throw new \RuntimeException('Unable to normalize managed trunk authentication.');
			}
			$updated = $this->findManagedTrunk($core, $authorizedTrunk);
			if ($updated === null || !$this->trunkMatchesAuthorizedState($id, $updated, $authorizedTrunk) || !$database->commit()) {
				throw new \RuntimeException('Normalized managed trunk failed verification.');
			}
		} catch (\Throwable $e) {
			if ($database->inTransaction()) {
				$database->rollBack();
			}
			throw $e;
		} finally {
			$_POST = $originalPost;
		}
	}

	private function managedTrunkName(array $authorizedTrunk): string {
		$identity = json_encode([$authorizedTrunk['role'], $authorizedTrunk['sip_host'], $authorizedTrunk['sip_port']], JSON_UNESCAPED_SLASHES);
		if ($identity === false) {
			throw new \RuntimeException('Unable to derive a managed trunk name.');
		}
		$roleMarker = $authorizedTrunk['role'] === 'outbound' ? 'OUT' : 'IN';
		return self::TRUNK_NAME . '-' . $roleMarker . '-' . substr(hash('sha256', $identity), 0, 12);
	}

	private function desiredTrunkSettings(array $authorizedTrunk): array {
		$trunkName = $this->managedTrunkName($authorizedTrunk);
		return [
			'channelid' => $trunkName,
			'trunk_name' => $trunkName,
			'outcid' => '',
			'keepcid' => 'off',
			'maxchans' => '',
			'failtrunk' => '',
			'dialoutprefix' => '',
			'peerdetails' => '',
			'usercontext' => '',
			'userconfig' => '',
			'register' => '',
			'disabletrunk' => 'off',
			'provider' => '',
			'continue' => 'off',
			'dialopts' => false,
			'sip_server' => $authorizedTrunk['sip_host'],
			'sip_server_port' => (string)$authorizedTrunk['sip_port'],
			'context' => 'from-pstn',
			'sendrpid' => 'no',
			'authentication' => 'off',
			'registration' => 'none',
			'auth_username' => '',
			'username' => '',
			'secret' => '',
		];
	}

	private function trunkMatchesAuthorizedState($trunkId, array $trunk, array $authorizedTrunk, bool $allowLegacyAuthentication = false): bool {
		if (!isset($trunk['tech'], $trunk['disabled']) || strtolower((string)$trunk['tech']) !== 'pjsip' || strtolower((string)$trunk['disabled']) !== 'off') {
			return false;
		}
		$core = $this->coreApi();
		$details = $core->getTrunkDetails($trunkId);
		if (!is_array($details)) {
			return false;
		}
		$expected = $this->desiredTrunkSettings($authorizedTrunk);
		foreach (['trunk_name', 'sip_server', 'context', 'sendrpid', 'authentication', 'registration'] as $field) {
			if ($field === 'authentication' && $allowLegacyAuthentication && isset($details[$field]) && $details[$field] === 'none') {
				continue;
			}
			if (!isset($details[$field]) || (string)$details[$field] !== (string)$expected[$field]) {
				return false;
			}
		}
		if (!isset($details['sip_server_port']) || (int)$details['sip_server_port'] !== (int)$expected['sip_server_port']) {
			return false;
		}
		foreach (['username', 'auth_username', 'secret', 'md5_cred', 'outbound_auth', 'auth'] as $credentialField) {
			if (isset($details[$credentialField]) && trim((string)$details[$credentialField]) !== '') {
				return false;
			}
		}
		return true;
	}

	private function reconcileOutboundRoute(string $service, array $trunkIds, &$configurationChanged): bool {
		$routing = $this->routingApi();
		$routes = $routing->listAll();
		if (!is_array($routes)) {
			throw new \RuntimeException('Unable to list FreePBX outbound routes.');
		}
		$matches = array_values(array_filter($routes, function ($route) {
			return isset($route['name']) && $route['name'] === self::ROUTE_NAME;
		}));
		if (count($matches) > 1) {
			throw new \RuntimeException('Multiple module-owned outbound routes were found.');
		}
		if (count($trunkIds) === 0) {
			if (count($matches) !== 0) {
				throw new \RuntimeException('A module-owned outbound route exists without an authorized outbound trunk.');
			}
			return false;
		}
		$patterns = $this->outboundRoutePatterns($service);
		if (count($matches) === 1) {
			if (!$this->routeMatchesPolicy($routing, $matches[0], $patterns, $trunkIds)) {
				throw new \RuntimeException('The module-owned outbound route conflicts with the required policy.');
			}
			return false;
		}

		$database = $this->databaseApi();
		if (!method_exists($database, 'beginTransaction') || !method_exists($database, 'inTransaction') || !method_exists($database, 'commit') || !method_exists($database, 'rollBack')) {
			throw new \RuntimeException('FreePBX route transaction API is unavailable.');
		}
		if (!$database->beginTransaction()) {
			throw new \RuntimeException('Unable to start outbound route transaction.');
		}
		try {
			$routeId = $routing->add(self::ROUTE_NAME, '', '', '', 'no', 'no', '', 0, $patterns, $trunkIds, 'bottom');
			if ($routeId === false || $routeId === null || $routeId === '') {
				throw new \RuntimeException('Unable to create the module-owned outbound route.');
			}
			$route = $this->findManagedRoute($routing);
			if ($route === null || !$this->routeMatchesPolicy($routing, $route, $patterns, $trunkIds)) {
				throw new \RuntimeException('The created outbound route did not verify.');
			}
			if (!$database->commit()) {
				throw new \RuntimeException('Unable to commit outbound route transaction.');
			}
		} catch (\Throwable $e) {
			if ($database->inTransaction()) {
				$database->rollBack();
			}
			throw $e;
		}
		$configurationChanged = true;
		return true;
	}

	private function findManagedRoute($routing) {
		$routes = $routing->listAll();
		if (!is_array($routes)) {
			throw new \RuntimeException('Unable to list FreePBX outbound routes.');
		}
		$matches = array_values(array_filter($routes, function ($route) {
			return isset($route['name']) && $route['name'] === self::ROUTE_NAME;
		}));
		if (count($matches) > 1) {
			throw new \RuntimeException('Multiple module-owned outbound routes were found.');
		}
		return count($matches) === 1 ? $matches[0] : null;
	}

	private function routeMatchesPolicy($routing, array $route, array $expectedPatterns, array $trunkIds): bool {
		if (!isset($route['route_id'])) {
			return false;
		}
		$actualPatterns = $routing->getRoutePatternsById($route['route_id']);
		$actualTrunks = $routing->getRouteTrunksById($route['route_id']);
		if (!is_array($actualPatterns) || !is_array($actualTrunks)) {
			return false;
		}
		$normalizePattern = function ($pattern) {
			return [
				'prepend_digits' => isset($pattern['prepend_digits']) ? (string)$pattern['prepend_digits'] : '',
				'match_pattern_prefix' => isset($pattern['match_pattern_prefix']) ? (string)$pattern['match_pattern_prefix'] : '',
				'match_pattern_pass' => isset($pattern['match_pattern_pass']) ? (string)$pattern['match_pattern_pass'] : '',
				'match_cid' => isset($pattern['match_cid']) ? (string)$pattern['match_cid'] : '',
			];
		};
		$actual = array_map($normalizePattern, $actualPatterns);
		$expected = array_map($normalizePattern, $expectedPatterns);
		$sortPatterns = function (&$items) {
			usort($items, function ($left, $right) {
				return strcmp(implode("\0", $left), implode("\0", $right));
			});
		};
		$sortPatterns($actual);
		$sortPatterns($expected);
		$actualTrunks = array_map('strval', array_values($actualTrunks));
		$expectedTrunks = array_map('strval', array_values($trunkIds));
		return $actual === $expected && $actualTrunks === $expectedTrunks;
	}

	protected function outboundRoutePatterns(string $service): array {
		if (!preg_match('/^my-([0-9]{8})$/D', $service, $matches)) {
			throw new \RuntimeException('The authorized service identity is invalid.');
		}
		$pbxTag = $matches[1];
		$tagPrefix = $pbxTag . '*';
		$patterns = [
			['prepend_digits' => $pbxTag . '*44', 'match_pattern_prefix' => '0', 'match_pattern_pass' => 'Z.', 'match_cid' => ''],
			['prepend_digits' => $tagPrefix, 'match_pattern_prefix' => '+', 'match_pattern_pass' => '44Z.', 'match_cid' => ''],
			['prepend_digits' => $tagPrefix, 'match_pattern_prefix' => '', 'match_pattern_pass' => '44Z.', 'match_cid' => ''],
			['prepend_digits' => $tagPrefix, 'match_pattern_prefix' => '', 'match_pattern_pass' => '00Z.', 'match_cid' => ''],
		];
		foreach (['101', '105', '111', '112', '116XXX', '119', '159', '195', '999'] as $serviceNumberPattern) {
			$patterns[] = [
				'prepend_digits' => $tagPrefix,
				'match_pattern_prefix' => '',
				'match_pattern_pass' => $serviceNumberPattern,
				'match_cid' => '',
			];
		}
		return $patterns;
	}

	private function verifyLocalConfiguration(array $state): void {
		$core = $this->coreApi();
		$outboundTrunkIds = [];
		foreach ($state['trunks'] as $authorizedTrunk) {
			$trunk = $this->findManagedTrunk($core, $authorizedTrunk);
			if ($trunk === null || !$this->trunkMatchesAuthorizedState($trunk['trunkid'], $trunk, $authorizedTrunk)) {
				throw new \RuntimeException('A local PJSIP trunk failed verification.');
			}
			if ($authorizedTrunk['role'] === 'outbound') {
				$outboundTrunkIds[] = $trunk['trunkid'];
			}
		}
		$routing = $this->routingApi();
		$route = $this->findManagedRoute($routing);
		if (count($outboundTrunkIds) === 0) {
			if ($route !== null) {
				throw new \RuntimeException('Unexpected module-owned outbound route without authorized outbound trunks.');
			}
		} elseif ($route === null || !$this->routeMatchesPolicy($routing, $route, $this->outboundRoutePatterns($state['service']), $outboundTrunkIds)) {
			throw new \RuntimeException('The local outbound route failed verification.');
		}
		$this->verifyInboundRoutes($state['numbers']);
		$this->verifyTestDestination();
	}

	protected function requestConfigurationReload(): void {
		if (!function_exists('needreload')) {
			throw new \RuntimeException('FreePBX reload notification API is unavailable.');
		}
		needreload();
	}

	private function writeSecureFile(string $path, string $contents): void {
		$temporaryPath = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
		$handle = @fopen($temporaryPath, 'x');
		if ($handle === false) {
			throw new \RuntimeException('Unable to create secure file.');
		}
		try {
			if (!chmod($temporaryPath, 0600) || fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
				throw new \RuntimeException('Unable to write secure file.');
			}
		} catch (\Throwable $e) {
			fclose($handle);
			@unlink($temporaryPath);
			throw $e;
		}
		fclose($handle);
		if (!rename($temporaryPath, $path)) {
			@unlink($temporaryPath);
			throw new \RuntimeException('Unable to save secure file.');
		}
	}

	protected function sendActivationRequest(string $request): string {
		$handle = curl_init(self::ACTIVATION_ENDPOINT);
		if ($handle === false) {
			throw new \RuntimeException('Unable to initialize HTTPS request.');
		}
		$optionsSet = curl_setopt_array($handle, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $request,
			CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		]);
		if (!$optionsSet) {
			curl_close($handle);
			throw new \RuntimeException('Unable to configure HTTPS request.');
		}
		$response = curl_exec($handle);
		$statusCode = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
		$curlError = curl_errno($handle);
		curl_close($handle);
		if ($curlError !== 0 || !is_string($response) || $statusCode < 200 || $statusCode >= 300) {
			throw new \RuntimeException('Invalid HTTPS response.');
		}
		return $response;
	}

	private function createSessionCsrfToken() {
		if (!$this->startSession()) {
			return '';
		}
		if (empty($_SESSION['domaintains_csrf'])) {
			$_SESSION['domaintains_csrf'] = bin2hex(random_bytes(32));
		}
		return $_SESSION['domaintains_csrf'];
	}

	private function getSessionCsrfToken() {
		if (!$this->startSession() || empty($_SESSION['domaintains_csrf'])) {
			return null;
		}
		return (string)$_SESSION['domaintains_csrf'];
	}

	private function startSession(): bool {
		if (session_status() === PHP_SESSION_ACTIVE) {
			return true;
		}
		return !headers_sent() && session_start();
	}

	private function setGuiMessage(bool $success, string $message): void {
		if ($this->startSession()) {
			$_SESSION['domaintains_activation_message'] = ['success' => $success, 'message' => $message];
		}
	}

	private function takeGuiMessage(): array {
		if (!$this->startSession() || !isset($_SESSION['domaintains_activation_message']) || !is_array($_SESSION['domaintains_activation_message'])) {
			return [];
		}
		$message = $_SESSION['domaintains_activation_message'];
		unset($_SESSION['domaintains_activation_message']);
		return $message;
	}
}
