<?php

declare(strict_types=1);

ob_start();
require __DIR__ . '/lifecycle_contract.php';
ob_end_clean();

class OwnershipContractModule extends ActivationContractModule {
	public function teardown(): void { $this->teardownOwnedConfiguration(); }
}

function ownership_ledger(string $directory): array {
	return json_decode(file_get_contents($directory . '/ownership.json'), true);
}

function ownership_expect_failure(callable $operation, string $message): void {
	$failed = false;
	try { $operation(); } catch (Throwable $error) { $failed = true; }
	activation_assert($failed, $message);
}

$directory = activation_temp_directory();
$module = new OwnershipContractModule($directory, [activation_fixture_response()], null, null, null, false);
$result = $module->activate('synthetic-test-token');
activation_assert(!$result['success'] && strpos($result['message'], 'provisioning endpoint') !== false, 'missing endpoint must fail clearly');
activation_assert($module->requests() === [] && !file_exists($directory . '/signing.key'), 'missing endpoint must not send a request or create identity');
foreach ([
	'http://control.example.invalid/activate', 'https://user:pass@control.example.invalid/activate',
	'https://control.example.invalid/activate#fragment', 'https:///activate', 'control.example.invalid',
	'https://bad_host.example.invalid/activate', 'https://control.example.invalid:0/activate',
	'https://control.example.invalid:65536/activate', 'https://control.example.invalid/act ivate',
	'https://control.example.invalid\\@other.example.invalid/activate', 'https://control.example.invalid/%zz',
	'https://@control.example.invalid/activate', 'https://[2001:db8::1]/activate',
] as $invalid) {
	activation_assert(!$module->configureProvisioningEndpoint($invalid)['success'], 'invalid endpoint must be rejected');
	activation_assert(!file_exists($directory . '/endpoint.json'), 'invalid endpoint must not be persisted');
}
$endpoint = 'https://control.example.invalid:8443/api/activate?version=1';
$module->firewallFixture()->networkMaps = ['existing.example.invalid' => 'internal', 'control.example.invalid' => 'other'];
$originalFirewall = $module->firewallFixture()->networkMaps;
activation_assert($module->configureProvisioningEndpoint($endpoint)['success'], 'complete HTTPS endpoint should persist');
activation_assert($module->getProvisioningEndpoint() === $endpoint, 'endpoint must not be rewritten');
activation_assert($module->activate('synthetic-test-token')['success'], 'configured activation should work');
activation_assert($module->firewallFixture()->networkMaps === $originalFirewall && $module->firewallFixture()->addCalls === 0 && $module->firewallFixture()->readCalls === 0, 'saving and using endpoint must not inspect or alter existing Firewall trust');
$ledger = ownership_ledger($directory);
activation_assert(count($ledger['objects']) === 5, 'fresh activation should record trunk, outbound, two inbound routes and test registration');
$types = array_column($ledger['objects'], 'type');
activation_assert(count(array_filter($types, function ($type) { return $type === 'inbound'; })) === 2, 'each inbound native key must be recorded');
foreach ($ledger['objects'] as $entry) {
	activation_assert($entry['origin'] === 'created', 'verified new objects must have created provenance');
	if ($entry['type'] === 'trunk') {
		activation_assert($entry['id'] === ['id' => $module->coreFixture()->trunks[0]['trunkid']], 'trunk ownership uses actual native trunkid');
	} elseif ($entry['type'] === 'outbound') {
		activation_assert($entry['id'] === ['id' => $module->routingFixture()->routes[0]['route_id']], 'outbound ownership uses actual native route_id');
	} elseif ($entry['type'] === 'inbound') {
		activation_assert($entry['id']['cidnum'] === '' && in_array($entry['id']['extension'], ['447700900123', '447700900124'], true), 'inbound ownership uses extension/cidnum primary-key tuple');
	}
}
$key = file_get_contents($directory . '/signing.key');
$state = file_get_contents($directory . '/state.json');
$configuration = [$module->coreFixture()->trunks, $module->coreFixture()->details, $module->coreFixture()->dids, $module->routingFixture()->routes];
$oldFirewall = $module->firewallFixture()->networkMaps;
activation_assert($module->configureProvisioningEndpoint('https://replacement.example.invalid/activate')['success'], 'endpoint change should be supported');
activation_assert(file_get_contents($directory . '/state.json') === $state && file_get_contents($directory . '/signing.key') === $key, 'endpoint change must preserve authorization and signing identity');
activation_assert([$module->coreFixture()->trunks, $module->coreFixture()->details, $module->coreFixture()->dids, $module->routingFixture()->routes] === $configuration, 'endpoint change must not alter PBX configuration');
activation_assert($module->firewallFixture()->networkMaps === $oldFirewall, 'saving endpoint must not mutate Firewall');
activation_assert($module->activate('synthetic-test-token')['success'], 'future activation should use new endpoint');
activation_assert(end($module->requestedEndpoints) === 'https://replacement.example.invalid/activate', 'provider request must use configured endpoint without requiring inbound trust');
activation_assert($module->reactivate('synthetic-replacement-token')['success'], 'reactivation should work without changing Firewall trust');
activation_assert($module->firewallFixture()->networkMaps === $oldFirewall && $module->firewallFixture()->addCalls === 0 && $module->firewallFixture()->readCalls === 0, 'Activate, Reactivate and endpoint change must leave all Firewall entries untouched');
$ledgerBeforeUnlink = file_get_contents($directory . '/ownership.json');
activation_assert($module->deactivate()['success'], 'local unlink should succeed');
activation_assert(file_get_contents($directory . '/ownership.json') === $ledgerBeforeUnlink && file_get_contents($directory . '/signing.key') === $key, 'Deactivate must preserve ownership ledger and key');
$reloaded = new OwnershipContractModule($directory, [activation_fixture_response()], $module->coreFixture(), $module->routingFixture(), $module->firewallFixture(), false);
activation_assert($reloaded->getProvisioningEndpoint() === 'https://replacement.example.invalid/activate', 'endpoint survives unlink and reinstantiation');
activation_assert(ownership_ledger($directory) === json_decode($ledgerBeforeUnlink, true), 'ownership survives reinstantiation');
$reloaded->teardown();
activation_assert($module->coreFixture()->trunks === [] && $module->coreFixture()->dids === [] && $module->routingFixture()->routes === [], 'teardown must delete all owned native objects after unlink');
activation_assert(ownership_ledger($directory)['objects'] === [] && !$reloaded->testDestinationRegistered(), 'verified absence removes ledger entries and owned test registration');
activation_assert(file_get_contents($directory . '/signing.key') === $key && $reloaded->getProvisioningEndpoint() !== '', 'teardown must preserve signing key and endpoint');
activation_assert($module->firewallFixture()->networkMaps === $oldFirewall && $module->firewallFixture()->addCalls === 0 && $module->firewallFixture()->readCalls === 0, 'teardown must neither inspect nor modify existing Firewall entries');
$reloaded->teardown();
activation_assert(ownership_ledger($directory)['objects'] === [], 'duplicate teardown must be harmless');
activation_cleanup($directory);

$directory = activation_temp_directory();
$core = new ActivationContractCoreApi();
$core->seedManagedTrunk();
$core->dids[] = ['extension' => '447700900123', 'cidnum' => '', 'destination' => 'domaintains-test,s,1', 'description' => 'DOMAINTAINS Inbound 447700900123'];
$module = new OwnershipContractModule($directory, [activation_fixture_response()], $core);
activation_assert($module->activate('synthetic-test-token')['success'], 'existing matching objects can continue to be reused');
foreach (ownership_ledger($directory)['objects'] as $entry) {
	activation_assert($entry['type'] !== 'trunk', 'pre-existing matching trunk must not become owned');
	activation_assert($entry['type'] !== 'inbound' || $entry['id']['extension'] !== '447700900123', 'pre-existing matching inbound route must not become owned');
}
$module->teardown();
activation_assert(count($core->trunks) === 1 && count($core->dids) === 1, 'teardown must preserve unowned matching objects');
activation_assert($module->testDestinationRegistered(), 'unowned inbound references must preserve test destination');
activation_cleanup($directory);

foreach (['max_channels', 'authentication'] as $change) {
	$directory = activation_temp_directory();
	$core = new ActivationContractCoreApi();
	$core->seedManagedTrunk();
	$module = new OwnershipContractModule($directory, [activation_fixture_response()], $core);
	activation_assert($module->activate('synthetic-test-token')['success'], 'exactly matching unowned trunk may be reused without adoption');
	$id = $core->trunks[0]['trunkid'];
	if ($change === 'max_channels') {
		$module->queueResponse(activation_fixture_response(['max_channels' => 10]));
	} else {
		$core->details[$id]['authentication'] = 'none';
		$core->details[$id]['secret'] = 'synthetic-legacy-credential';
		$module->queueResponse(activation_fixture_response());
	}
	$before = [$core->trunks, $core->details, $core->dids, $module->routingFixture()->routes];
	$reloads = $module->reloadRequests;
	$result = $module->activate('synthetic-test-token');
	activation_assert(!$result['success'] && strpos($result['message'], 'Explicit ownership adoption is required') === 0, 'unowned max_channels or SIP normalization must fail with an adoption-required diagnostic');
	activation_assert([$core->trunks, $core->details, $core->dids, $module->routingFixture()->routes] === $before && $core->editCalls === 0 && $module->reloadRequests === $reloads, 'unowned refresh must not mutate the trunk or other existing PBX configuration');
	foreach (ownership_ledger($directory)['objects'] as $entry) {
		activation_assert($entry['type'] !== 'trunk', 'failed unowned repair must not silently record trunk ownership');
	}
	$preview = $module->previewOwnershipAdoption();
	activation_assert($preview['success'], 'safe legacy trunk requiring normalization must be previewable');
	$trunkCandidates = array_values(array_filter($preview['objects'], function ($candidate) { return $candidate['type'] === 'trunk'; }));
	activation_assert(count($trunkCandidates) === 1 && $trunkCandidates[0]['id'] === ['id' => $id], 'legacy preview must capture the exact native trunk identifier');
	activation_assert($trunkCandidates[0]['current_state']['maxchans'] === '5' && $trunkCandidates[0]['current_state']['authentication'] === ($change === 'authentication' ? 'none' : 'off'), 'preview must show actual current settings rather than desired normalized state');
	activation_assert(strpos(json_encode($preview), 'synthetic-legacy-credential') === false, 'adoption preview must not disclose legacy credentials');
	activation_assert(!$module->adoptOwnership($preview['plan'], false)['success'], 'legacy normalization adoption must require explicit confirmation');
	activation_assert([$core->trunks, $core->details, $core->dids, $module->routingFixture()->routes] === $before, 'preview and declined adoption must not mutate candidates');
	activation_assert($module->adoptOwnership($preview['plan'], true)['success'], 'confirmed safe legacy candidate may become owned in its current state');
	activation_assert([$core->trunks, $core->details, $core->dids, $module->routingFixture()->routes] === $before && $core->editCalls === 0, 'recording ownership must not itself normalize the candidate');
	activation_assert($module->activate('synthetic-test-token')['success'] && $core->editCalls === 1, 'subsequent reconciliation may repair the explicitly adopted trunk');
	activation_assert($core->trunks[0]['trunkid'] === $id && $core->trunks[0]['maxchans'] === ($change === 'max_channels' ? '10' : '5') && $core->details[$id]['authentication'] === 'off' && $core->details[$id]['secret'] === '', 'owned repair must retain the native ID and apply authorized settings');
	activation_cleanup($directory);
}

$directory = activation_temp_directory();
$module = new OwnershipContractModule($directory, [activation_fixture_response()]);
activation_assert($module->activate('synthetic-test-token')['success'], 'legacy adoption fixture should provision');
unlink($directory . '/ownership.json');
activation_assert($module->activate('synthetic-test-token')['success'] && !file_exists($directory . '/ownership.json'), 'legacy refresh must not silently adopt any pre-existing matching object type');
$preview = $module->previewOwnershipAdoption();
activation_assert($preview['success'] && count($preview['objects']) === 5, 'adoption preview must enumerate exact matching objects');
activation_assert(!$module->adoptOwnership($preview['plan'], false)['success'] && !file_exists($directory . '/ownership.json'), 'unconfirmed adoption must not write ownership');
$core = $module->coreFixture();
$core->trunks[0]['maxchans'] = '9';
activation_assert(!$module->adoptOwnership($preview['plan'], true)['success'], 'changed preview must fail revalidation');
$core->trunks[0]['maxchans'] = '5';
activation_assert($module->adoptOwnership($preview['plan'], true)['success'], 'explicit unchanged confirmation should adopt');
foreach (ownership_ledger($directory)['objects'] as $entry) {
	activation_assert($entry['origin'] === 'adopted', 'legacy objects require explicit adopted provenance');
}
$preview = $module->previewOwnershipAdoption();
$before = file_get_contents($directory . '/ownership.json');
activation_assert($module->adoptOwnership($preview['plan'], true)['success'] && file_get_contents($directory . '/ownership.json') === $before, 'repeated adoption must be idempotent');
$module->teardown();
activation_assert($core->trunks === [] && $core->dids === [], 'explicitly adopted objects should be deletable');
activation_cleanup($directory);

foreach (['malformed', 'symlink', 'directory', 'bad-entry'] as $unsafe) {
	$directory = activation_temp_directory();
	$module = new OwnershipContractModule($directory, [activation_fixture_response()]);
	activation_assert($module->activate('synthetic-test-token')['success'], 'unsafe ownership fixture should provision');
	$key = file_get_contents($directory . '/signing.key');
	$state = file_get_contents($directory . '/state.json');
	unlink($directory . '/ownership.json');
	if ($unsafe === 'symlink') {
		symlink($directory . '/signing.key', $directory . '/ownership.json');
	} elseif ($unsafe === 'directory') {
		mkdir($directory . '/ownership.json');
	} else {
		file_put_contents($directory . '/ownership.json', $unsafe === 'malformed' ? '{bad' : '{"version":1,"objects":[{"type":"trunk"}]}');
	}
	activation_assert(!$module->activate('synthetic-test-token')['success'] && count($module->requests()) === 1, 'unsafe ownership must block activation before remote work');
	activation_assert(!$module->deactivate()['success'] && file_get_contents($directory . '/state.json') === $state, 'unsafe ledger must not be bypassed by unlink');
	ownership_expect_failure(function () use ($module) { $module->teardown(); }, 'unsafe ownership must block teardown');
	activation_assert($module->getStatus()['state'] === 'error' && file_get_contents($directory . '/signing.key') === $key, 'unsafe ownership must fail closed and preserve signing identity');
	if ($unsafe === 'directory') { rmdir($directory . '/ownership.json'); }
	activation_cleanup($directory);
}

$directory = activation_temp_directory();
$module = new OwnershipContractModule($directory, [activation_fixture_response()]);
activation_assert($module->activate('synthetic-test-token')['success'], 'reuse-identity fixture should provision');
$core = $module->coreFixture();
$id = $core->trunks[0]['trunkid'];
$core->details[$id]['sip_server'] = 'different.example.invalid';
$before = file_get_contents($directory . '/ownership.json');
ownership_expect_failure(function () use ($module) { $module->teardown(); }, 'changed/reused native identity must block teardown');
activation_assert(file_get_contents($directory . '/ownership.json') === $before && count($core->trunks) === 1, 'ambiguous identity must retain ledger and objects');
$core->details[$id]['sip_server'] = 'sip.example.invalid';
$routing = $module->routingFixture();
$routing->routes[] = ['route_id' => 'admin-route', 'name' => 'Administrator Route', 'seq' => 2];
$routing->trunks['admin-route'] = [$id];
ownership_expect_failure(function () use ($module) { $module->teardown(); }, 'unowned route reference must block trunk teardown');
activation_assert(count($core->trunks) === 1 && count($routing->routes) === 2, 'dependency refusal must precede all deletions');
$routing->deleteById('admin-route');
$core->deleteTrunk($id, 'pjsip', false);
$module->teardown();
activation_assert(ownership_ledger($directory)['objects'] === [], 'already-absent owned object reconciles safely');
activation_cleanup($directory);

class OwnershipFailDeleteRouting extends ActivationContractRoutingApi {
	public $failDelete = true;
	public function deleteById($id) {
		return $this->failDelete ? $this : parent::deleteById($id);
	}
}
$directory = activation_temp_directory();
$routing = new OwnershipFailDeleteRouting();
$module = new OwnershipContractModule($directory, [activation_fixture_response()], null, $routing);
activation_assert($module->activate('synthetic-test-token')['success'], 'failed-delete fixture should provision');
$before = file_get_contents($directory . '/ownership.json');
ownership_expect_failure(function () use ($module) { $module->teardown(); }, 'unsuccessful deletion must fail verification');
activation_assert(file_get_contents($directory . '/ownership.json') === $before && count($routing->routes) === 1, 'failed deletion must not discard ownership');
activation_assert(!$module->getStatus()['provisioned'], 'teardown cannot leave provisioned status after starting local removal');
$routing->failDelete = false;
$module->teardown();
activation_assert(ownership_ledger($directory)['objects'] === [], 'failed deletion must be retryable');
activation_cleanup($directory);

foreach (['symlink', 'directory', 'malformed'] as $unsafe) {
	$directory = activation_temp_directory();
	$module = new OwnershipContractModule($directory, [activation_fixture_response()]);
	activation_assert($module->activate('synthetic-test-token')['success'], 'unsafe endpoint fixture should provision');
	$key = file_get_contents($directory . '/signing.key');
	$state = file_get_contents($directory . '/state.json');
	unlink($directory . '/endpoint.json');
	if ($unsafe === 'symlink') {
		symlink($directory . '/signing.key', $directory . '/endpoint.json');
	} elseif ($unsafe === 'directory') {
		mkdir($directory . '/endpoint.json');
	} else {
		file_put_contents($directory . '/endpoint.json', '{broken');
	}
	activation_assert(!$module->configureProvisioningEndpoint('https://new.example.invalid/activate')['success'], 'unsafe endpoint state must not be overwritten');
	activation_assert(!$module->activate('synthetic-test-token')['success'] && count($module->requests()) === 1, 'unsafe endpoint must block provider operations');
	activation_assert(file_get_contents($directory . '/state.json') === $state && file_get_contents($directory . '/signing.key') === $key, 'unsafe endpoint must preserve authorization and key');
	if ($unsafe === 'directory') { rmdir($directory . '/endpoint.json'); }
	activation_cleanup($directory);
}

class OwnershipEndpointInput extends LifecycleInput {
	public $value = null;
	public function getArgument($name) { return $name === 'value' ? $this->value : $this->action; }
}
$directory = activation_temp_directory();
$module = new OwnershipContractModule($directory, [activation_fixture_response()], null, null, null, false);
$command = new \FreePBX\Console\Command\Domaintains();
$questions = new LifecycleQuestions();
$command->helper = $questions;
$input = new OwnershipEndpointInput();
$input->action = 'endpoint';
$input->value = 'https://control.example.invalid/activate';
$input->interactive = false;
$input->json = true;
$output = new LifecycleOutput();
activation_assert($command->run($input, $output) === 0 && $questions->calls === 0, 'CLI endpoint configuration must be scriptable');
activation_assert(json_decode(end($output->lines), true)['success'], 'scripted endpoint configuration should return JSON');
activation_assert($module->activate('synthetic-test-token')['success'], 'CLI adoption fixture should activate');
unlink($directory . '/ownership.json');
$input->action = 'adopt';
activation_assert($command->run($input, $output) === 1 && !file_exists($directory . '/ownership.json'), 'CLI adoption must refuse noninteractive use');
$input->interactive = true;
$questions->answers = [false];
activation_assert($command->run($input, $output) === 1 && !file_exists($directory . '/ownership.json'), 'CLI declined adoption must not write');
$questions->answers = [true];
activation_assert($command->run($input, $output) === 0 && count(ownership_ledger($directory)['objects']) === 5, 'CLI confirmed adoption must capture native identities');
activation_assert(strpos(implode("\n", $output->lines), 'synthetic-trunk-1') !== false, 'CLI must display exact native identifiers before confirmation');
activation_assert(strpos(implode("\n", $output->lines), '"current_state":{"name":') !== false && strpos(implode("\n", $output->lines), '"maxchans":"5"') !== false, 'CLI adoption must display current trunk state before confirmation');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['domaintains_csrf'] = 'endpoint-csrf';
$_POST = ['domaintains_action' => 'endpoint', 'domaintains_csrf' => 'wrong', 'provisioning_endpoint' => 'https://gui.example.invalid/activate'];
$module->doConfigPageInit('domaintains');
activation_assert($module->getProvisioningEndpoint() === 'https://control.example.invalid/activate', 'GUI endpoint change must reject invalid CSRF');
$_POST['domaintains_csrf'] = 'endpoint-csrf';
$module->doConfigPageInit('domaintains');
activation_assert($module->getProvisioningEndpoint() === 'https://gui.example.invalid/activate', 'GUI verified endpoint change should persist');
unlink($directory . '/endpoint.json');
activation_assert($module->getStatus()['state'] === 'provisioned', 'legacy provisioned status must not require endpoint configuration');
$state = file_get_contents($directory . '/state.json');
activation_assert(!$module->reactivate('replacement')['success'] && file_get_contents($directory . '/state.json') === $state, 'missing endpoint Reactivate must preserve known-good state');
activation_cleanup($directory);

class OwnershipRootContractModule extends RootOwnershipModule {
	public function teardown(): void { $this->teardownOwnedConfiguration(); }
}
class OwnershipWriteFailureRouting extends ActivationContractRoutingApi {
	public $failAfterDelete = false;
	public function deleteById($id) {
		$result = parent::deleteById($id);
		if ($this->failAfterDelete) { \FreePBX\modules\RootOwnershipFixture::$fail = true; }
		return $result;
	}
}
\FreePBX\modules\RootOwnershipFixture::$enabled = true;
$directory = activation_temp_directory();
$routing = new OwnershipWriteFailureRouting();
$rootModule = new OwnershipRootContractModule($directory, [activation_fixture_response()], null, $routing);
activation_assert($rootModule->activate('synthetic-test-token')['success'], 'root ownership fixture should activate');
foreach (['endpoint.json', 'ownership.json', 'state.json', 'signing.key', 'activation.lock', 'test-destination.json'] as $file) {
	$path = $directory . '/' . $file;
	activation_assert(\FreePBX\modules\fileowner($path) === 12001 && \FreePBX\modules\filegroup($path) === 12001 && (fileperms($path) & 0777) === 0600, 'root writes must normalize Asterisk ownership and private mode for ' . $file);
}
activation_assert((fileperms($directory) & 0777) === 0700, 'runtime directory must remain private');
$before = file_get_contents($directory . '/endpoint.json');
\FreePBX\modules\RootOwnershipFixture::$fail = true;
activation_assert(!$rootModule->configureProvisioningEndpoint('https://other.example.invalid/activate')['success'] && file_get_contents($directory . '/endpoint.json') === $before, 'ownership normalization failure must preserve endpoint configuration');
\FreePBX\modules\RootOwnershipFixture::$fail = false;
$before = file_get_contents($directory . '/ownership.json');
$routing->failAfterDelete = true;
ownership_expect_failure(function () use ($rootModule) { $rootModule->teardown(); }, 'post-delete ledger write failure must propagate');
activation_assert(file_get_contents($directory . '/ownership.json') === $before && $routing->routes === [], 'post-delete ledger write failure must retain original ownership until retry');
activation_assert(glob($directory . '/*.tmp') === [], 'failed ledger publication must clean atomic temporary files');
\FreePBX\modules\RootOwnershipFixture::$fail = false;
$routing->failAfterDelete = false;
$rootModule->teardown();
activation_assert(ownership_ledger($directory)['objects'] === [], 'retry must reconcile absent object after failed ledger publication');
\FreePBX\modules\RootOwnershipFixture::$enabled = false;
activation_cleanup($directory);

foreach (['maxchans', 'sip_server', 'unmanaged_setting'] as $changedField) {
	$directory = activation_temp_directory();
	$module = new OwnershipContractModule($directory, [activation_fixture_response()]);
	activation_assert($module->activate('synthetic-test-token')['success'], 'owned fingerprint fixture should provision');
	$core = $module->coreFixture();
	$id = $core->trunks[0]['trunkid'];
	if ($changedField === 'maxchans') {
		$core->trunks[0]['maxchans'] = '9';
	} else {
		$core->details[$id][$changedField] = 'externally-changed.example.invalid';
	}
	$configuration = [$core->trunks, $core->details];
	$ledger = file_get_contents($directory . '/ownership.json');
	$result = $module->activate('synthetic-test-token');
	activation_assert(!$result['success'], 'owned trunk with a changed fingerprint must fail closed before repair or silent recapture');
	activation_assert([$core->trunks, $core->details] === $configuration && $core->editCalls === 0, 'same native identifier must not authorize mutation of an externally changed object');
	activation_assert(file_get_contents($directory . '/ownership.json') === $ledger, 'rejected external changes must not update recorded ownership');
	activation_cleanup($directory);
}

$directory = activation_temp_directory();
$module = new OwnershipContractModule($directory, [activation_fixture_response()]);
activation_assert($module->activate('synthetic-test-token')['success'], 'same-ID replacement fixture should provision');
$core = $module->coreFixture();
$oldTrunk = $core->trunks[0];
$id = (string)$oldTrunk['trunkid'];
$replacement = $oldTrunk;
$replacement['maxchans'] = '9';
$core->trunks[0] = $replacement;
$core->details[$id] = array_merge($core->details[$id], ['sip_server' => 'replacement.example.invalid', 'secret' => 'synthetic-replacement-secret']);
$replacementConfiguration = [$core->trunks, $core->details];
$ledger = file_get_contents($directory . '/ownership.json');
$result = $module->activate('synthetic-test-token');
activation_assert(!$result['success'] && [$core->trunks, $core->details] === $replacementConfiguration, 'recreated object with the same native ID but a different configuration must fail closed without mutation');
activation_assert(file_get_contents($directory . '/ownership.json') === $ledger && $core->editCalls === 0, 'same-ID replacement must not refresh the ownership fingerprint or invoke Core edit');
activation_cleanup($directory);

foreach ([['trunk', 'not-a-fingerprint'], ['inbound', str_repeat('a', 64)]] as $invalidPending) {
	$directory = activation_temp_directory();
	$module = new OwnershipContractModule($directory, [activation_fixture_response()]);
	activation_assert($module->activate('synthetic-test-token')['success'], 'malformed pending fingerprint fixture should provision');
	$ledger = ownership_ledger($directory);
	$entryIndex = null;
	foreach ($ledger['objects'] as $index => $entry) {
		if ($entry['type'] === $invalidPending[0]) {
			$entryIndex = $index;
			break;
		}
	}
	activation_assert($entryIndex !== null, 'pending fingerprint fixture must contain the requested object type');
	$ledger['objects'][$entryIndex]['pending_fingerprint'] = $invalidPending[1];
	file_put_contents($directory . '/ownership.json', json_encode($ledger, JSON_UNESCAPED_SLASHES));
	$malformedLedger = file_get_contents($directory . '/ownership.json');
	$requests = count($module->requests());
	activation_assert(!$module->activate('synthetic-test-token')['success'] && count($module->requests()) === $requests, 'malformed or misplaced pending fingerprint must fail before provider communication');
	activation_assert(file_get_contents($directory . '/ownership.json') === $malformedLedger, 'malformed pending fingerprint must not be rewritten or repaired implicitly');
	activation_cleanup($directory);
}

class OwnershipFingerprintCore extends ActivationContractCoreApi {
	public $failPendingWrite = false;
	public $corruptReadBack = false;
	public function addTrunk(string $name, string $technology, array $settings, bool $edit = false): string {
		$id = parent::addTrunk($name, $technology, $settings, $edit);
		if ($edit && $this->failPendingWrite) { \FreePBX\modules\RootOwnershipFixture::$fail = true; }
		if ($edit && $this->corruptReadBack) { $this->details[$id]['registration'] = 'send'; }
		return $id;
	}
}
class OwnershipFingerprintRouting extends ActivationContractRoutingApi {
	public $failCommit = false;
	public $failFinalWrite = false;
	public function commit(): bool {
		if ($this->failCommit) { return false; }
		$result = parent::commit();
		if ($this->failFinalWrite) { \FreePBX\modules\RootOwnershipFixture::$fail = true; }
		return $result;
	}
}

foreach (['read-back', 'prepare', 'commit', 'finalize', 'finalize-new-desired', 'finalize-external-change'] as $failure) {
	\FreePBX\modules\RootOwnershipFixture::$enabled = true;
	$directory = activation_temp_directory();
	$core = new OwnershipFingerprintCore();
	$routing = new OwnershipFingerprintRouting();
	$module = new OwnershipRootContractModule($directory, [activation_fixture_response()], $core, $routing);
	activation_assert($module->activate('synthetic-test-token')['success'], 'fingerprint recovery fixture should provision');
	$beforeConfiguration = [$core->trunks, $core->details];
	$beforeLedger = file_get_contents($directory . '/ownership.json');
	$beforeTrunk = array_values(array_filter(ownership_ledger($directory)['objects'], function ($entry) { return $entry['type'] === 'trunk'; }))[0];
	$id = $beforeTrunk['id']['id'];
	$key = file_get_contents($directory . '/signing.key');
	$reloads = $module->reloadRequests;
	$module->queueResponse(activation_fixture_response(['max_channels' => 10]));
	$core->corruptReadBack = $failure === 'read-back';
	$core->failPendingWrite = $failure === 'prepare';
	$routing->failCommit = $failure === 'commit';
	$routing->failFinalWrite = strpos($failure, 'finalize') === 0;
	$failed = $module->activate('synthetic-test-token');
	activation_assert(!$failed['success'], 'injected ownership/transaction failure must not claim successful activation');
	activation_assert(!$routing->inTransaction() && glob($directory . '/*.tmp') === [], 'failed fingerprint update must close transactions and clean atomic temporary files');
	$entry = array_values(array_filter(ownership_ledger($directory)['objects'], function ($owned) { return $owned['type'] === 'trunk'; }))[0];
	activation_assert($entry['fingerprint'] === $beforeTrunk['fingerprint'], 'failed activation must not silently discard the old committed fingerprint');
	if (strpos($failure, 'finalize') === 0) {
		activation_assert($core->trunks[0]['maxchans'] === '10' && isset($entry['pending_fingerprint']) && $entry['pending_fingerprint'] !== $entry['fingerprint'], 'committed repair with failed final ledger write must retain a verified pending fingerprint');
		activation_assert($module->reloadRequests === $reloads + 1, 'committed changes must request reload even when final ownership persistence fails');
	} else {
		activation_assert([$core->trunks, $core->details] === $beforeConfiguration, 'verification, prepare-write or commit failure must roll back native changes');
		activation_assert(isset($entry['pending_fingerprint']) === ($failure === 'commit'), 'only a prepared but rolled-back commit may leave its pending fingerprint');
		if ($failure !== 'commit') {
			activation_assert(file_get_contents($directory . '/ownership.json') === $beforeLedger, 'failed verification or prepare must preserve the prior ledger byte-for-byte');
		}
	}
	\FreePBX\modules\RootOwnershipFixture::$fail = false;
	$core->failPendingWrite = false;
	$core->corruptReadBack = false;
	$routing->failCommit = false;
	$routing->failFinalWrite = false;
	if ($failure === 'finalize-external-change') {
		$core->details[$id]['sip_server'] = 'third-state.example.invalid';
	}
	$desiredChannels = $failure === 'finalize-new-desired' ? 15 : 10;
	$reloaded = new OwnershipRootContractModule($directory, [activation_fixture_response(['max_channels' => $desiredChannels])], $core, $routing, null, false);
	$edits = $core->editCalls;
	$ledgerBeforeRetry = file_get_contents($directory . '/ownership.json');
	$retry = $reloaded->activate('synthetic-test-token');
	if ($failure === 'finalize-external-change') {
		activation_assert(!$retry['success'] && $core->editCalls === $edits && file_get_contents($directory . '/ownership.json') === $ledgerBeforeRetry, 'recovery must reject a third state instead of trusting native ID or desired settings');
		activation_assert(!$reloaded->previewOwnershipAdoption()['success'], 'adoption must not bypass an unresolved fingerprint conflict');
	} else {
		activation_assert($retry['success'] && $core->trunks[0]['maxchans'] === (string)$desiredChannels, 'ordinary activation after reinstantiation must safely recover old or verified post-change configuration');
		$entry = array_values(array_filter(ownership_ledger($directory)['objects'], function ($owned) { return $owned['type'] === 'trunk'; }))[0];
		activation_assert(!isset($entry['pending_fingerprint']) && $entry['fingerprint'] !== $beforeTrunk['fingerprint'], 'successful recovery must finalize the verified new fingerprint');
		activation_assert($core->editCalls === $edits + ($failure === 'finalize' ? 0 : 1), 'recovery must not repeat an already committed desired modification');
		$settledLedger = file_get_contents($directory . '/ownership.json');
		$settledEdits = $core->editCalls;
		activation_assert($reloaded->activate('synthetic-test-token')['success'] && $core->editCalls === $settledEdits && file_get_contents($directory . '/ownership.json') === $settledLedger, 'settled reconciliation must remain idempotent without stale-fingerprint rejection or ledger rewrites');
		$preview = $reloaded->previewOwnershipAdoption();
		activation_assert($preview['success'] && $reloaded->adoptOwnership($preview['plan'], true)['success'], 'normal explicit adoption must remain functional after recovery');
	}
	activation_assert(file_get_contents($directory . '/signing.key') === $key, 'fingerprint recovery must not replace the signing identity');
	\FreePBX\modules\RootOwnershipFixture::$enabled = false;
	activation_cleanup($directory);
}
class OwnershipNativeCore extends OwnershipFingerprintCore {
	public $runtimeDialopts = 'Ttr';
	public $deleteHooks = 0;
	public $conversionMetadata = 'preserved';
	public $corruptRuntime = false;
	public function listTrunks(): array {
		$trunks = parent::listTrunks();
		foreach ($trunks as &$trunk) { $trunk['dialopts'] = $this->runtimeDialopts; }
		return $trunks;
	}
	public function deleteTrunk($id, $technology, $edit): bool {
		$this->runtimeDialopts = false;
		$this->deleteHooks++;
		$this->conversionMetadata = null;
		return parent::deleteTrunk($id, $technology, $edit);
	}
	public function addTrunk(string $name, string $technology, array $settings, bool $edit = false): string {
		$id = parent::addTrunk($name, $technology, $settings, $edit);
		$this->runtimeDialopts = $settings['dialopts'] ?? false;
		if ($edit && $this->corruptRuntime) { $this->runtimeDialopts = 'unexpected-native-value'; }
		return $id;
	}
}
class OwnershipNativeRouting extends OwnershipFingerprintRouting {
	public $failRollback = false;
	public function rollBack(): bool {
		if ($this->failRollback) { parent::commit(); return false; }
		return parent::rollBack();
	}
}
foreach (['edit', 'read-back', 'prepare', 'commit', 'rollback', 'runtime'] as $failure) {
	\FreePBX\modules\RootOwnershipFixture::$enabled = true;
	$directory = activation_temp_directory();
	$core = new OwnershipNativeCore();
	$core->seedManagedTrunk('sip.example.invalid', 5060, ['authentication' => 'none']);
	$routing = new OwnershipNativeRouting();
	$module = new OwnershipRootContractModule($directory, [activation_fixture_response()], $core, $routing);
	activation_assert(!$module->activate('synthetic-test-token')['success'], 'native fixture requires explicit adoption');
	activation_adopt_current($module);
	$before = [$core->trunks, $core->details];
	$ledger = file_get_contents($directory . '/ownership.json');
	$core->failEdit = $failure === 'edit' || $failure === 'rollback';
	$core->corruptReadBack = $failure === 'read-back';
	$core->failPendingWrite = $failure === 'prepare';
	$core->corruptRuntime = $failure === 'runtime';
	$routing->failCommit = $failure === 'commit';
	$routing->failRollback = $failure === 'rollback';
	$reloads = $module->reloadRequests;
	$result = $module->activate('synthetic-test-token');
	activation_assert(!$result['success'], 'native reconciliation failure must not claim success');
	activation_assert(($failure === 'runtime' || $core->runtimeDialopts === 'Ttr') && $core->deleteHooks === 0 && $core->conversionMetadata === 'preserved', 'normalization must avoid AstDB deletion, conversion metadata removal and deletion hooks');
	\FreePBX\modules\RootOwnershipFixture::$fail = false;
	if (in_array($failure, ['rollback', 'runtime'], true)) {
		activation_assert(strpos($result['message'], 'prior trunk configuration could not be restored') !== false, 'failed rollback must report unverified restoration explicitly');
		activation_assert(file_get_contents($directory . '/ownership.json') === $ledger, 'failed restoration must not recapture ownership');
		activation_assert(!$module->getStatus()['provisioned'] && $module->getStatus()['last_error'] === $result['message'] && $module->reloadRequests === $reloads + 1, 'unverified restoration must persist an accurate pending diagnostic and request reload');
		activation_assert(!$module->activate('synthetic-test-token')['success'], 'unverified restoration must remain fail closed');
	} else {
		activation_assert([$core->trunks, $core->details] === $before, 'rollback must restore the prior native rows');
		$core->failEdit = false;
		$core->corruptReadBack = false;
		$core->failPendingWrite = false;
		$routing->failCommit = false;
		activation_assert($module->activate('synthetic-test-token')['success'], 'restored native configuration must remain retryable');
		activation_assert($core->runtimeDialopts === 'Ttr' && $core->trunks[0]['trunkid'] === $before[0][0]['trunkid'], 'successful retry must preserve dialopts and native identity');
	}
	\FreePBX\modules\RootOwnershipFixture::$enabled = false;
	activation_cleanup($directory);
}
foreach (['teardown', 'adoption'] as $operation) {
	foreach (['original', 'pending', 'third-state', 'write-failure'] as $recovery) {
		\FreePBX\modules\RootOwnershipFixture::$enabled = true;
		$directory = activation_temp_directory();
		$core = new OwnershipFingerprintCore();
		$routing = new OwnershipFingerprintRouting();
		$module = new OwnershipRootContractModule($directory, [activation_fixture_response()], $core, $routing);
		activation_assert($module->activate('synthetic-test-token')['success'], 'local pending recovery fixture should provision');
		$module->queueResponse(activation_fixture_response(['max_channels' => 10]));
		$routing->failCommit = $recovery === 'original';
		$routing->failFinalWrite = $recovery !== 'original';
		activation_assert(!$module->activate('synthetic-test-token')['success'], 'local recovery requires an interrupted ownership update');
		\FreePBX\modules\RootOwnershipFixture::$fail = false;
		$routing->failCommit = false;
		$routing->failFinalWrite = false;
		$module = new OwnershipRootContractModule($directory, [], $core, $routing, null, false);
		$module->remoteException = new RuntimeException('Local recovery must not contact the provider.');
		if ($operation === 'teardown' && $recovery === 'pending') {
			activation_assert($module->deactivate()['success'], 'pending teardown must also work after local Deactivate');
		}
		if ($recovery === 'third-state') { $core->details[$core->trunks[0]['trunkid']]['sip_server'] = 'third-state.example.invalid'; }
		$configuration = [$core->trunks, $core->details, $core->dids, $routing->routes];
		$ledger = file_get_contents($directory . '/ownership.json');
		if ($operation === 'adoption') {
			$preview = $module->previewOwnershipAdoption();
			activation_assert($preview['success'] === ($recovery !== 'third-state'), 'adoption preview must accept only recorded original or pending configuration');
			activation_assert(file_get_contents($directory . '/ownership.json') === $ledger, 'preview must not finalize ownership before confirmation');
		}
		if ($recovery === 'write-failure') { \FreePBX\modules\RootOwnershipFixture::$fail = true; }
		$shouldFail = in_array($recovery, ['third-state', 'write-failure'], true);
		if ($operation === 'teardown') {
			if ($shouldFail) {
				ownership_expect_failure(function () use ($module) { $module->teardown(); }, 'unverified or unpersisted pending recovery must stop teardown');
			} else { $module->teardown(); }
		} elseif ($preview['success']) {
			activation_assert($module->adoptOwnership($preview['plan'], true)['success'] === !$shouldFail, 'confirmed adoption must finalize only verified, persistable pending state');
		}
		\FreePBX\modules\RootOwnershipFixture::$fail = false;
		if ($shouldFail) {
			activation_assert([$core->trunks, $core->details, $core->dids, $routing->routes] === $configuration && file_get_contents($directory . '/ownership.json') === $ledger, 'failed pending recovery must preserve native configuration and ownership evidence');
		}
		if ($recovery === 'write-failure') {
			if ($operation === 'teardown') { $module->teardown(); }
			else { activation_assert($module->adoptOwnership($preview['plan'], true)['success'], 'failed pending publication must be retryable with the unchanged preview'); }
		}
		if ($recovery !== 'third-state') {
			foreach (ownership_ledger($directory)['objects'] as $entry) {
				activation_assert(!isset($entry['pending_fingerprint']), 'successful local recovery must settle pending ownership');
			}
			if ($operation === 'teardown') {
				activation_assert($core->trunks === [] && $core->dids === [] && $routing->routes === [] && ownership_ledger($directory)['objects'] === [], 'verified pending ownership must permit complete protected teardown');
				$module->teardown();
			} else {
				activation_assert([$core->trunks, $core->details, $core->dids, $routing->routes] === $configuration, 'pending adoption recovery must never normalize native objects');
				$settled = file_get_contents($directory . '/ownership.json');
				$preview = $module->previewOwnershipAdoption();
				activation_assert($preview['success'] && $module->adoptOwnership($preview['plan'], true)['success'] && file_get_contents($directory . '/ownership.json') === $settled, 'settled adoption must remain idempotent');
			}
		}
		activation_assert($module->requests() === [], 'local ownership recovery must not require provider communication');
		\FreePBX\modules\RootOwnershipFixture::$enabled = false;
		activation_cleanup($directory);
	}
}
echo "Owned-trunk fingerprint and recovery regressions passed.\n";

$source = file_get_contents(dirname(__DIR__) . '/Domaintains.class.php');
activation_assert(strpos($source, 'control.example.invalid') === false && strpos($source, 'ACTIVATION_ENDPOINT') === false, 'production code must not embed a default provisioning endpoint');
activation_assert(strpos($source, 'ensureActivationHostInLocalZone') === false && strpos($source, 'addNetworkToZone') === false && strpos($source, '::Firewall(') === false, 'provisioning must have no automatic Firewall trust integration');
echo "Ownership and endpoint contract passed.\n";