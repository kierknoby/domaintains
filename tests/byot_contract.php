<?php

declare(strict_types=1);

ob_start();
require __DIR__ . '/lifecycle_contract.php';
ob_end_clean();

class ByotLifecycleInput extends LifecycleInput {
	public $value = null;
	public function getArgument($name) { return $name === 'value' ? $this->value : $this->action; }
}

$directory = activation_temp_directory();
$module = new ActivationContractModule($directory, []);
$module->localHostnameValue = 'neutral.example';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['domaintains_csrf'] = 'synthetic-csrf';
$_POST = ['domaintains_action' => 'identity', 'domaintains_csrf' => 'wrong'];
$module->doConfigPageInit('domaintains');
activation_assert(!file_exists($directory . '/identity.json'), 'identity discovery requires GUI CSRF');
$_POST['domaintains_csrf'] = 'synthetic-csrf';
$module->doConfigPageInit('domaintains');
$identity = $module->getServiceIdentity();
activation_assert((bool)preg_match('/^pbx-[a-f0-9]{32}$/D', $identity), 'new neutral PBX identity must be unpredictable and locally generated');
$command = new \FreePBX\Console\Command\Domaintains();
$input = new ByotLifecycleInput();
$input->action = 'identity';
$input->json = true;
$output = new LifecycleOutput();
activation_assert($command->run($input, $output) === 0 && json_decode($output->lines[0], true)['service'] === $identity, 'CLI identity discovery uses shared protected identity');
$module->localHostnameValue = 'renamed.example';
activation_assert($module->getServiceIdentity() === $identity, 'PBX identity must not follow hostname changes');
$module->queueResponse(activation_fixture_response(['service' => $identity]));
$missingPolicy = $module->activate('synthetic-test-token');
activation_assert(!$missingPolicy['success'] && strpos($missingPolicy['message'], 'routing policy') !== false && !file_exists($directory . '/state.json') && $module->coreFixture()->addCalls === 0, 'missing neutral policy fails with actionable error before local mutations');
$patterns = [['prepend_digits' => '', 'match_pattern_prefix' => '', 'match_pattern_pass' => '12025550XXX', 'match_cid' => '']];
$input->action = 'routing';
$input->value = json_encode($patterns);
activation_assert($command->run($input, new LifecycleOutput()) === 0, 'neutral policy must be administrator supplied through CLI');
$module->queueResponse(activation_fixture_response(['service' => $identity]));
activation_assert($module->activate('synthetic-test-token')['success'], 'neutral identity and explicit policy activate');
activation_assert($module->routePatternPolicy($identity) === $patterns, 'no invented neutral dial plan');
$policyBefore = file_get_contents($directory . '/routing.json');
activation_assert(!$module->configureRoutingPolicy([['prepend_digits' => '', 'match_pattern_prefix' => '', 'match_pattern_pass' => "X.\ncontext=bad", 'match_cid' => '']])['success'], 'dialplan injection rejected');
activation_assert(file_get_contents($directory . '/routing.json') === $policyBefore, 'invalid policy leaves accepted policy unchanged');
activation_assert($module->deactivate()['success'] && $module->getServiceIdentity() === $identity, 'local unlink must preserve neutral identity');
unlink($directory . '/identity.json');
try { $module->getServiceIdentity(); throw new RuntimeException('Expected missing identity rejection.'); }
catch (RuntimeException $error) { activation_assert($error->getMessage() === 'Invalid local service identity.' && !file_exists($directory . '/identity.json'), 'missing neutral identity with retained signing key cannot silently rebind'); }
file_put_contents($directory . '/identity.json', '{"version":1,"service":"bad;context"}');
try { $module->getServiceIdentity(); throw new RuntimeException('Expected damaged identity rejection.'); }
catch (RuntimeException $error) { activation_assert($error->getMessage() === 'Invalid local service identity.', 'damaged identity cannot regenerate'); }
activation_cleanup($directory);

$directory = activation_temp_directory();
$module = new ActivationContractModule($directory, [activation_fixture_response()]);
activation_assert($module->activate('synthetic-test-token')['success'], 'legacy activation must remain compatible');
unlink($directory . '/identity.json');
$before = [];
foreach (['state.json', 'signing.key', 'ownership.json', 'endpoint.json'] as $file) { $before[$file] = file_get_contents($directory . '/' . $file); }
$module->localHostnameValue = 'renamed.example';
activation_assert($module->getServiceIdentity() === 'my-12345678', 'retained authorization takes precedence over hostname');
foreach ($before as $file => $contents) { activation_assert(file_get_contents($directory . '/' . $file) === $contents, 'identity retention must not rewrite ' . $file); }
activation_cleanup($directory);

ob_start();
require __DIR__ . '/../examples/byot-provider/tests/orchestration_contract.php';
ob_end_clean();
require_once __DIR__ . '/../examples/byot-provider/src/bootstrap.php';

class ByotIntegratedModule extends ActivationContractModule {
	public $provider;
	public $signedRequests = [];

	protected function loadOrCreateSigningKeypair(bool $allowCreate = true): array {
		$loader = new ReflectionMethod(\FreePBX\modules\Domaintains::class, 'loadOrCreateSigningKeypair');
		$loader->setAccessible(true);
		return $loader->invoke($this, $allowCreate);
	}

	protected function signActivationClaim(string $claim, string $secretKey): string {
		return sodium_crypto_sign_detached($claim, $secretKey);
	}

	protected function clearSigningSecret(string &$secretKey): void {
		sodium_memzero($secretKey);
	}

	protected function sendActivationRequest(string $request): string {
		$decoded = json_decode($request, true);
		$this->signedRequests[] = $decoded;
		list($status, $response) = $this->provider->process($decoded, $decoded['claim']['timestamp']);
		if ($status !== 200) { throw new RuntimeException('Synthetic provider rejected activation.'); }
		return json_encode($response, JSON_UNESCAPED_SLASHES);
	}
}

$directory = activation_temp_directory();
$providerDirectory = sys_get_temp_dir() . '/domaintains-integrated-' . bin2hex(random_bytes(8));
try {
	$module = new ByotIntegratedModule($directory, []);
	$module->clock = 1000;
	$module->localHostnameValue = 'neutral.example';
	$service = $module->getServiceIdentity();
	activation_assert($module->configureRoutingPolicy($patterns)['success'], 'integrated neutral PBX policy');
	$configuration = ['private_state_dir' => $providerDirectory, 'max_request_age' => 300, 'simulate_dns' => true,
		'billing' => byot_fixture($service), 'plans' => [$service => byot_plan()], 'policies' => [$service => byot_policies()],
		'destinations' => [$service => ['account' => 'account-01', 'pbx' => 'pbx-01', 'sip_host' => 'pbx.example', 'sip_port' => 5060, 'transport' => 'udp']]];
	$runtime = new \Domaintains\Byot\Runtime($configuration);
	$credential = $runtime->order('account-01', 'pbx-01', $service, ['voice-01', 'voice-02'], 1000);
	activation_assert($runtime->store->read('order-' . hash('sha256', $service))['status'] === 'complete', 'order prepares and verifies carrier delivery before issuing credentials');
	$module->provider = $runtime->provider;
	activation_assert($module->activate($credential['token'])['success'], 'actual module Ed25519 claim must traverse default runtime and actual response validation');
	activation_assert($module->getStatus()['provisioned'] && $module->getStatus()['inbound_numbers'] === 2, 'real provider response reconciles and verifies local configuration');
	activation_assert(array_keys($module->signedRequests[0]['claim']) === ['token', 'public_key', 'timestamp', 'nonce', 'service'], 'canonical claim order unchanged');
	$nativeIds = array_column($module->coreFixture()->trunks, 'trunkid');
	$secret = file_get_contents($directory . '/signing.key');
	activation_assert($module->activate($credential['token'])['success'], 'actual signing key reloaded for idempotent refresh');
	activation_assert(array_column($module->coreFixture()->trunks, 'trunkid') === $nativeIds && file_get_contents($directory . '/signing.key') === $secret, 'native identities and signing key preserved');
	$snapshot = [];
	foreach (['state.json', 'signing.key', 'ownership.json', 'endpoint.json', 'identity.json', 'routing.json'] as $file) { $snapshot[$file] = file_get_contents($directory . '/' . $file); }
	$runtime->registry->setStatus($service, 'revoked');
	activation_assert(!$module->activate($credential['token'])['success'], 'revoked provider credential is rejected');
	foreach ($snapshot as $file => $contents) { activation_assert(file_get_contents($directory . '/' . $file) === $contents, 'rejected refresh preserves ' . $file); }
	activation_assert($module->firewallFixture()->addCalls === 0, 'integrated BYOT never manages Firewall');
} finally {
	activation_cleanup($directory);
	if (is_dir($providerDirectory)) { byot_clean($providerDirectory); }
}

echo "BYOT module and real-signature provider integration contracts passed.\n";