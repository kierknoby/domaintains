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
	const VERSION = '0.3.5-dev';
	const ACTIVATION_ENDPOINT = 'https://my-connect.freepbxhosting.uk/activate';
	const TRUNK_NAME = 'DOMAINTAINS';
	const ROUTE_NAME = 'DOMAINTAINS-Outbound';
	const TEST_DESTINATION = 'domaintains-test,s,1';

	/** @var \FreePBX */
	private $FreePBX;
	private $managedTrunkNames = [];

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
		if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST' || !isset($_POST['domaintains_action']) || !in_array($_POST['domaintains_action'], ['activate', 'deactivate', 'reactivate'], true)) {
			return;
		}

		$csrfToken = isset($_POST['domaintains_csrf']) ? (string)$_POST['domaintains_csrf'] : '';
		$sessionToken = $this->getSessionCsrfToken();
		if ($sessionToken === null || $csrfToken === '' || !hash_equals($sessionToken, $csrfToken)) {
			$this->setGuiMessage(false, _('Activation request could not be verified. Reload the page and try again.'));
			return;
		}

		unset($_SESSION['domaintains_csrf']);
		$action = $_POST['domaintains_action'];
		if ($action !== 'activate' && (!isset($_POST['domaintains_confirm']) || $_POST['domaintains_confirm'] !== 'yes')) {
			$this->setGuiMessage(false, _('Confirm the requested lifecycle operation.'));
			return;
		}
		$key = isset($_POST['activation_key']) ? trim((string)$_POST['activation_key']) : '';
		$result = $action === 'deactivate' ? $this->deactivate() : ($action === 'reactivate' ? $this->reactivate($key) : $this->activate($key));
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
			if (isset($record->last_error, $record->last_error_stage) && $record->last_error_stage === 'local') {
				$status['last_error'] = $this->safeLocalErrorMessage(is_string($record->last_error) ? $record->last_error : '');
				$status['last_error_stage'] = 'local';
			}
		}
		return $status;
	}

	/** Activate this installation using the provider-issued key. */
	public function activate(string $activationKey): array {
		return $this->performActivation($activationKey, false);
	}

	public function reactivate(string $activationKey): array {
		return $this->performActivation($activationKey, true);
	}

	public function deactivate(): array {
		$lock = null;
		try {
			$lock = $this->lockActivationState();
			if ($this->readActivationState() !== null && !@unlink($this->storageDirectory() . '/state.json')) {
				throw new \RuntimeException('Unable to remove activation state.');
			}
			return ['success' => true, 'message' => _('DOMAINTAINS deactivated. Signing identity and PBX configuration preserved.')];
		} catch (\Throwable $e) {
			return ['success' => false, 'message' => _('Unable to deactivate DOMAINTAINS safely. Retained state requires attention.'), 'stage' => 'local-state'];
		} finally {
			if (is_resource($lock)) {
				flock($lock, LOCK_UN);
				fclose($lock);
			}
		}
	}

	private function lockActivationState() {
		$this->ensureStorageDirectory();
		$lockPath = $this->storageDirectory() . '/activation.lock';
		if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
			throw new \RuntimeException('Invalid activation lock.');
		}
		$lock = fopen($lockPath, 'c');
		if ($lock === false) {
			throw new \RuntimeException('Unable to open activation lock.');
		}
		try {
			$this->secureStoragePath($lockPath, 0600);
		} catch (\Throwable $e) {
			fclose($lock);
			throw $e;
		}
		if (!flock($lock, LOCK_EX)) {
			fclose($lock);
			throw new \RuntimeException('Unable to lock activation state.');
		}
		return $lock;
	}

	private function performActivation(string $activationKey, bool $replaceActivationIdentity): array {
		if (trim($activationKey) === '') {
			return ['success' => false, 'message' => _('Enter an activation key.'), 'stage' => 'remote'];
		}
		if (!$this->supportsSodium()) {
			return ['success' => false, 'message' => _('Activation is unavailable because sodium support is missing.'), 'stage' => 'remote'];
		}
		$lock = null;
		$stage = 'local';
		$state = null;
		$configurationChanged = false;
		$reloadRequested = false;
		try {
			$lock = $this->lockActivationState();

			$stage = 'local-state';
			$existingState = $this->readActivationState();
			if ($replaceActivationIdentity && $existingState === null) {
				return ['success' => false, 'message' => _('No retained activation authorization exists. Use Activate instead.'), 'stage' => 'local'];
			}
			if ($existingState !== null) {
				$stage = 'local';
				$state = array_merge($this->authorizedValues($existingState), [
					'provisioned' => $existingState->provisioned,
					'activated_at' => $existingState->activated_at,
					'public_key_fingerprint' => $existingState->public_key_fingerprint,
					'activation_key_fingerprint' => $existingState->activation_key_fingerprint,
				]);
			}
			$stage = 'local';
			$localService = $this->validatedLocalServiceIdentity();
			$this->ensureActivationHostInLocalZone();
			if (!$this->supportsHttpsTransport()) {
				return ['success' => false, 'message' => _('Activation is unavailable because HTTPS support is missing.'), 'stage' => 'remote'];
			}
			list($publicKey, $secretKey) = $this->loadOrCreateSigningKeypair($existingState === null && !$replaceActivationIdentity);
			$publicKeyFingerprint = hash('sha256', $publicKey);
			$activationKeyFingerprint = $this->activationKeyFingerprint($activationKey, $secretKey);
			if ($existingState !== null) {
				if (!hash_equals($existingState->public_key_fingerprint, $publicKeyFingerprint)) {
					return ['success' => false, 'message' => _('The local signing identity does not match the pending activation state.'), 'stage' => 'local'];
				}
				if (!$replaceActivationIdentity && !hash_equals($existingState->activation_key_fingerprint, $activationKeyFingerprint)) {
					return ['success' => false, 'message' => _('This installation is already linked to a different activation identity.'), 'stage' => 'local'];
				}
			}

			// Retained state stays the last known-good record until a validated provider refresh is persisted.
			$stage = 'remote';
			$request = $this->signedActivationRequest($activationKey, $publicKey, $secretKey, $localService);
			$responseValues = $this->validateActivationResponse($this->sendActivationRequest($request), $localService);
			$state = array_merge($responseValues, [
				'provisioned' => false,
				'activated_at' => $this->currentTime(),
				'public_key_fingerprint' => $publicKeyFingerprint,
				'activation_key_fingerprint' => $activationKeyFingerprint,
			]);
			$this->writeActivationState($state);
			$stage = 'local';

			try {
				$this->reconcileLocalConfiguration($state, $configurationChanged);
				if ($configurationChanged) {
					$this->requestConfigurationReload();
					$reloadRequested = true;
				}
				$this->verifyLocalConfiguration($state);
				unset($state['last_error'], $state['last_error_stage']);
				$state['provisioned'] = true;
				$this->writeActivationState($state);
			} catch (\Throwable $e) {
				if ($configurationChanged && !$reloadRequested) {
					try {
						$this->requestConfigurationReload();
					} catch (\Throwable $reloadError) {
					}
				}
				throw $e;
			}
			return ['success' => true, 'message' => _('DOMAINTAINS activation and local configuration completed successfully.')];
		} catch (\Throwable $e) {
			$message = $stage === 'local-state'
				? _('Local activation state is incomplete or incompatible. Contact support.')
				: ($stage === 'local'
					? $this->safeLocalErrorMessage($e instanceof \RuntimeException ? $e->getMessage() : '')
					: _('Unable to complete activation with the DOMAINTAINS service.'));
			if ($stage === 'local' && is_array($state)) {
				$state['provisioned'] = false;
				$state['last_error'] = $message;
				$state['last_error_stage'] = 'local';
				try {
					$this->writeActivationState($state);
				} catch (\Throwable $storageError) {
					$message .= ' ' . _('The failure status could not be saved.');
				}
			}
			return ['success' => false, 'message' => $message, 'stage' => $stage];
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

	private function safeLocalErrorMessage(string $message): string {
		$safeMessages = [
			'Local DOMAINTAINS reconciliation failed.',
			'An unexpected module-owned trunk conflicts with the authorized trunk set.',
			'The module-owned trunk conflicts with the authorized configuration.',
			'Multiple module-owned trunks were found.',
			'Unable to list FreePBX trunks.',
			'FreePBX PJSIP trunk API is unavailable.',
			'Unable to create the module-owned PJSIP trunk.',
			'The created PJSIP trunk did not verify.',
			'Unable to start trunk normalization transaction.',
			'Unable to normalize managed trunk authentication.',
			'Normalized managed trunk failed verification.',
			'Unable to derive a managed trunk name.',
			'Invalid test destination registration.',
			'Test destination hooks are unavailable.',
			'Test answering dialplan failed verification.',
			'Unable to inspect inbound routes.',
			'An existing DID route conflicts with the authorized inbound route.',
			'Unable to create an authorized inbound route.',
			'An authorized inbound route failed verification.',
			'Unable to list FreePBX outbound routes.',
			'Multiple module-owned outbound routes were found.',
			'A module-owned outbound route exists without an authorized outbound trunk.',
			'The module-owned outbound route conflicts with the required policy.',
			'FreePBX route transaction API is unavailable.',
			'Unable to start outbound route transaction.',
			'Unable to create the module-owned outbound route.',
			'The created outbound route did not verify.',
			'Unable to commit outbound route transaction.',
			'The authorized service identity is invalid.',
			'A local PJSIP trunk failed verification.',
			'Unexpected module-owned outbound route without authorized outbound trunks.',
			'The local outbound route failed verification.',
			'FreePBX reload notification API is unavailable.',
			'FreePBX Firewall zone API is unavailable.',
			'Activation service hostname is assigned to a conflicting Firewall zone.',
			'Activation service hostname did not verify in the Firewall local zone.',
			'Unable to read FreePBX Firewall zones.',
			'Unable to create secure file.',
			'Unable to write secure file.',
			'Unable to save secure file.',
			'Unable to encode activation state.',
			'Signing key is missing for retained activation state.',
			'Invalid signing key file.',
			'Unable to secure signing key file.',
			'Ambiguous managed trunk upgrade mapping.',
			'Unexpected managed trunk role or identity.',
			'Unable to reconcile managed trunk in place.',
			'Reconciled managed trunk failed verification.',
		];
		return in_array($message, $safeMessages, true) ? $message : 'Local DOMAINTAINS reconciliation failed.';
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
		$this->secureStoragePath($directory, 0700, true);
	}

	protected function storageOwner(): array {
		$account = function_exists('posix_getpwnam') ? posix_getpwnam('asterisk') : false;
		if (!is_array($account) || !isset($account['uid'], $account['gid'])) {
			throw new \RuntimeException('Unable to determine secure storage ownership.');
		}
		return [(int)$account['uid'], (int)$account['gid']];
	}

	private function secureStoragePath(string $path, int $mode, bool $directory = false): void {
		if (is_link($path) || ($directory ? !is_dir($path) : !is_file($path))) {
			throw new \RuntimeException('Invalid secure storage path.');
		}
		list($owner, $group) = $this->storageOwner();
		clearstatcache(true, $path);
		if (!@chmod($path, $mode)
			|| (fileowner($path) !== $owner && !@chown($path, $owner))
			|| (filegroup($path) !== $group && !@chgrp($path, $group))) {
			throw new \RuntimeException('Unable to secure storage ownership.');
		}
		clearstatcache(true, $path);
		if (fileowner($path) !== $owner || filegroup($path) !== $group || (fileperms($path) & 0777) !== $mode) {
			throw new \RuntimeException('Secure storage ownership failed verification.');
		}
	}

	protected function loadOrCreateSigningKeypair(bool $allowCreate = true): array {
		$path = $this->storageDirectory() . '/signing.key';
		if (file_exists($path) || is_link($path)) {
			if (is_link($path) || !is_file($path)) {
				throw new \RuntimeException('Invalid signing key file.');
			}
			$this->secureStoragePath($path, 0600);
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
		$this->secureStoragePath($path, 0600);
		$contents = @file_get_contents($path);
		$record = is_string($contents) ? json_decode($contents) : null;
		if (!is_object($record) || !isset($record->provisioned, $record->activated_at, $record->public_key_fingerprint, $record->activation_key_fingerprint) || !is_bool($record->provisioned) || !is_int($record->activated_at) || !preg_match('/^[a-f0-9]{64}$/', (string)$record->public_key_fingerprint) || !preg_match('/^[a-f0-9]{64}$/', (string)$record->activation_key_fingerprint)) {
			throw new \RuntimeException('Invalid activation state file.');
		}
		$this->validateProvisioningFields($record);
		return $record;
	}

	protected function localHostname(): string {
		$hostname = gethostname();
		return is_string($hostname) ? $hostname : '';
	}

	private function validatedLocalServiceIdentity(): string {
		$hostname = strtolower(trim($this->localHostname()));
		$labelSeparator = strpos($hostname, '.');
		$service = $labelSeparator === false ? $hostname : substr($hostname, 0, $labelSeparator);
		if (!preg_match('/^my-[0-9]{8}$/D', $service)) {
			throw new \RuntimeException('Invalid local service identity.');
		}
		return $service;
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

	protected function currentTime(): int {
		return time();
	}

	private function signedActivationRequest(string $activationKey, string $publicKey, string &$secretKey, string $service): string {
		$claim = [
			'token' => $activationKey,
			'public_key' => base64_encode($publicKey),
			'timestamp' => $this->currentTime(),
			'nonce' => base64_encode(random_bytes(24)),
			'service' => $service,
		];
		$canonicalClaim = json_encode($claim, JSON_UNESCAPED_SLASHES);
		if ($canonicalClaim === false) {
			throw new \RuntimeException('Unable to encode activation claim.');
		}
		try {
			$signature = $this->signActivationClaim($canonicalClaim, $secretKey);
		} catch (\Throwable $e) {
			throw new \RuntimeException('Unable to sign activation claim.');
		} finally {
			$this->clearSigningSecret($secretKey);
		}
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
		return $request;
	}

	private function activationKeyFingerprint(string $activationKey, string $secretKey): string {
		return hash_hmac('sha256', "domaintains-activation-key\0" . $activationKey, $secretKey);
	}

	private function validateActivationResponse(string $response, string $localService): array {
		$decoded = json_decode($response);
		if (json_last_error() !== JSON_ERROR_NONE || !is_object($decoded) || !isset($decoded->ok) || $decoded->ok !== true) {
			throw new \RuntimeException('Invalid activation response.');
		}
		$values = $this->validateProvisioningFields($decoded);
		if ($values['service'] !== $localService) {
			throw new \RuntimeException('Activation response service identity does not match the local service.');
		}
		return $values;
	}

	private function validateProvisioningFields($data): array {
		if (is_object($data)) {
			$data = (array)$data;
		}
		$fields = ['service', 'profile', 'domaintains_hostname', 'trunks', 'numbers', 'max_channels'];
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
		if (!is_int($values['max_channels']) || $values['max_channels'] < 1 || $values['max_channels'] > 500) {
			throw new \RuntimeException('Activation state has an invalid channel entitlement.');
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
			$trunk = $this->reconcileTrunk($authorizedTrunk, $state['max_channels'], $configurationChanged);
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
		$this->managedTrunkNames = [];
		$targets = [];
		$roles = [];
		foreach ($authorizedTrunks as $authorizedTrunk) {
			$key = $this->managedTrunkName($authorizedTrunk);
			if (isset($targets[$key])) {
				throw new \RuntimeException('Ambiguous managed trunk upgrade mapping.');
			}
			$targets[$key] = $authorizedTrunk;
			$roles[$authorizedTrunk['role']][] = $key;
		}
		$trunks = $this->coreApi()->listTrunks();
		if (!is_array($trunks)) {
			throw new \RuntimeException('Unable to list FreePBX trunks.');
		}
		foreach ($trunks as $trunk) {
			if (!isset($trunk['name']) || !preg_match('/^DOMAINTAINS-(IN|OUT)-[a-f0-9]{12}$/D', $trunk['name'], $matches)) {
				continue;
			}
			$name = $trunk['name'];
			$role = $matches[1] === 'OUT' ? 'outbound' : 'inbound';
			if (isset($targets[$name])) {
				$key = $name;
			} elseif (isset($roles[$role])) {
				if (count($roles[$role]) !== 1) {
					throw new \RuntimeException('Ambiguous managed trunk upgrade mapping.');
				}
				$key = $roles[$role][0];
			} else {
				throw new \RuntimeException('Unexpected managed trunk role or identity.');
			}
			if (isset($this->managedTrunkNames[$key])) {
				throw new \RuntimeException('Ambiguous managed trunk upgrade mapping.');
			}
			$this->managedTrunkNames[$key] = $name;
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

	private function reconcileTrunk(array $authorizedTrunk, int $maxChannels, &$configurationChanged): array {
		$core = $this->coreApi();
		$trunkName = $this->resolvedManagedTrunkName($authorizedTrunk);
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
			if ($id !== null && !$this->trunkMatchesAuthorizedState($id, $trunk, $authorizedTrunk, $maxChannels)) {
				$details = $core->getTrunkDetails($id);
				if (!$this->safeManagedTrunkUpgrade($trunk, $details)) {
					throw new \RuntimeException('The module-owned trunk conflicts with the authorized configuration.');
				}
				$this->reconcileManagedTrunkInPlace($id, $trunk, $details, $authorizedTrunk, $maxChannels);
				$configurationChanged = true;
				$trunk = $this->findManagedTrunk($core, $authorizedTrunk);
			}
			if ($id === null || !$this->trunkMatchesAuthorizedState($id, $trunk, $authorizedTrunk, $maxChannels)) {
				throw new \RuntimeException('The module-owned trunk conflicts with the authorized configuration.');
			}
			return ['id' => $id, 'changed' => false];
		}

		$settings = $this->desiredTrunkSettings($authorizedTrunk, $maxChannels);
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
		if ($created === null || !$this->trunkMatchesAuthorizedState($created['trunkid'], $created, $authorizedTrunk, $maxChannels)) {
			throw new \RuntimeException('The created PJSIP trunk did not verify.');
		}
		return ['id' => $created['trunkid'], 'changed' => true];
	}

	private function findManagedTrunk($core, array $authorizedTrunk) {
		$trunkName = $this->resolvedManagedTrunkName($authorizedTrunk);
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

	private function safeManagedTrunkUpgrade(array $trunk, $details): bool {
		return isset($trunk['tech'], $trunk['disabled']) && $trunk['tech'] === 'pjsip' && $trunk['disabled'] === 'off'
			&& is_array($details) && isset($details['trunk_name'], $details['registration'], $details['context'], $details['sendrpid'])
			&& $details['trunk_name'] === $trunk['name'] && $details['registration'] === 'none'
			&& $details['context'] === 'from-pstn' && $details['sendrpid'] === 'no'
			&& in_array(isset($details['authentication']) ? $details['authentication'] : '', ['', 'none', 'off'], true);
	}

	private function reconcileManagedTrunkInPlace($id, array $trunk, array $details, array $authorizedTrunk, int $maxChannels): void {
		$core = $this->coreApi();
		$settings = array_merge($trunk, $details, $this->desiredTrunkSettings($authorizedTrunk, $maxChannels), [
			'trunknum' => $id,
			'disabletrunk' => $trunk['disabled'],
			'failtrunk' => isset($trunk['failscript']) ? $trunk['failscript'] : '',
			'outcid' => isset($trunk['outcid']) ? $trunk['outcid'] : '',
			'keepcid' => isset($trunk['keepcid']) ? $trunk['keepcid'] : 'off',
			'maxchans' => (string)$maxChannels,
			'continue' => isset($trunk['continue']) ? $trunk['continue'] : 'off',
			'dialopts' => isset($trunk['dialopts']) ? $trunk['dialopts'] : false,
			'md5_cred' => '',
			'auth' => '',
			'outbound_auth' => '',
		]);
		if (isset($details['codecs']) && is_string($details['codecs']) && $details['codecs'] !== '') {
			$settings['codec'] = array_fill_keys(explode(',', $details['codecs']), true);
		}
		$database = $this->databaseApi();
		if (!$database->beginTransaction()) {
			throw new \RuntimeException('Unable to start trunk normalization transaction.');
		}
		$originalPost = $_POST;
		$_POST = [];
		try {
			if ($core->deleteTrunk($id, 'pjsip', true) !== true || (string)$core->addTrunk($trunk['name'], 'pjsip', $settings, true) !== (string)$id) {
				throw new \RuntimeException('Unable to reconcile managed trunk in place.');
			}
			$updated = $this->findManagedTrunk($core, $authorizedTrunk);
			if ($updated === null || !$this->trunkMatchesAuthorizedState($id, $updated, $authorizedTrunk, $maxChannels) || !$database->commit()) {
				throw new \RuntimeException('Reconciled managed trunk failed verification.');
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
		$roleMarker = $authorizedTrunk['role'] === 'outbound' ? 'OUT' : 'IN';
		return self::TRUNK_NAME . '-' . $roleMarker . '-' . substr(hash('sha256', $this->managedTrunkIdentity($authorizedTrunk)), 0, 12);
	}

	/** Host-derived compatibility identity until the contract supplies a stable provider-issued trunk ID. */
	private function managedTrunkIdentity(array $authorizedTrunk): string {
		$identity = json_encode([$authorizedTrunk['role'], $authorizedTrunk['sip_host'], $authorizedTrunk['sip_port']], JSON_UNESCAPED_SLASHES);
		if ($identity === false) {
			throw new \RuntimeException('Unable to derive a managed trunk name.');
		}
		return $identity;
	}

	private function resolvedManagedTrunkName(array $authorizedTrunk): string {
		$key = $this->managedTrunkName($authorizedTrunk);
		return isset($this->managedTrunkNames[$key]) ? $this->managedTrunkNames[$key] : $key;
	}

	private function desiredTrunkSettings(array $authorizedTrunk, int $maxChannels): array {
		$trunkName = $this->resolvedManagedTrunkName($authorizedTrunk);
		return [
			'channelid' => $trunkName,
			'trunk_name' => $trunkName,
			'outcid' => '',
			'keepcid' => 'off',
			'maxchans' => (string)$maxChannels,
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

	private function trunkMatchesAuthorizedState($trunkId, array $trunk, array $authorizedTrunk, int $maxChannels): bool {
		if (!isset($trunk['tech'], $trunk['disabled']) || strtolower((string)$trunk['tech']) !== 'pjsip' || strtolower((string)$trunk['disabled']) !== 'off') {
			return false;
		}
		$core = $this->coreApi();
		$details = $core->getTrunkDetails($trunkId);
		if (!is_array($details)) {
			return false;
		}
		$expected = $this->desiredTrunkSettings($authorizedTrunk, $maxChannels);
		foreach (['trunk_name', 'sip_server', 'context', 'sendrpid', 'authentication', 'registration'] as $field) {
			if (!isset($details[$field]) || (string)$details[$field] !== (string)$expected[$field]) {
				return false;
			}
		}
		if (!isset($trunk['maxchans']) || (string)$trunk['maxchans'] !== (string)$maxChannels) {
			return false;
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
			if ($trunk === null || !$this->trunkMatchesAuthorizedState($trunk['trunkid'], $trunk, $authorizedTrunk, $state['max_channels'])) {
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
		if (is_link($path) || (file_exists($path) && !is_file($path))) {
			throw new \RuntimeException('Invalid secure storage path.');
		}
		$temporaryPath = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
		$handle = @fopen($temporaryPath, 'x');
		if ($handle === false) {
			throw new \RuntimeException('Unable to create secure file.');
		}
		try {
			$this->secureStoragePath($temporaryPath, 0600);
			if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
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
