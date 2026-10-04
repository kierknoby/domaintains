<?php

declare(strict_types=1);

function scaffold_assert(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$root = dirname(__DIR__);
$module = file_get_contents($root . '/Domaintains.class.php');
$console = file_get_contents($root . '/Console/Domaintains.class.php');
$page = file_get_contents($root . '/page.domaintains.php');
$view = file_get_contents($root . '/views/main.php');
$readme = file_get_contents($root . '/README.md');

scaffold_assert(strpos($module, 'class Domaintains implements \\BMO') !== false, 'BMO class should exist');
scaffold_assert(strpos($module, 'readActivationState()') !== false, 'status should read persisted activation state');
scaffold_assert(strpos($module, 'public function activate(string $activationKey)') !== false, 'module should expose shared activation method');
scaffold_assert(strpos($module, 'reconcileLocalConfiguration(') !== false, 'activation should reconcile local FreePBX configuration');
scaffold_assert(strpos($page, '\\FreePBX::Domaintains()->showPage()') !== false, 'page controller should use the DOMAINTAINS BMO');
scaffold_assert(strpos($view, 'name="activation_key"') !== false, 'GUI should provide an activation key field');
scaffold_assert(strpos($view, 'name="domaintains_csrf"') !== false, 'GUI activation should include CSRF protection');
scaffold_assert(strpos($console, "->activate(\$key)") !== false, 'CLI should use the shared module activation method');
scaffold_assert(strpos($console, "addArgument('activation-key'") === false, 'CLI must not accept an activation key as an argument');
scaffold_assert(strpos($console, 'setHidden(true)') !== false && strpos($console, 'setHiddenFallback(false)') !== false, 'CLI must prompt with hidden input and fail if hidden input is unsupported');
scaffold_assert(strpos($readme, "fwconsole domaintains activate\n") !== false, 'README should document activation without a key argument');
scaffold_assert(strpos($readme, '<activation-key>') === false, 'README must not show an activation key on a command line');
scaffold_assert(strpos($module, "'token' => \$activationKey") !== false, 'activation claim should carry the supplied key');
scaffold_assert(strpos($module, 'sodium_crypto_sign_detached') !== false, 'activation request should use detached Ed25519 signing');
scaffold_assert(strpos($module, 'random_bytes(24)') !== false, 'activation nonce should use secure random bytes');
scaffold_assert(strpos($module, "'/signing.key'") !== false, 'local signing key should be persisted');
scaffold_assert(strpos($module, "'/state.json'") !== false, 'activation state should be persisted');

$forbidden = [
	'core_trunks_edit(',
	'createUpdateDID(',
	'fwconsole reload',
	'shell_exec(',
	'passthru(',
	'system(',
];

foreach ($forbidden as $needle) {
	scaffold_assert(strpos($module, $needle) === false, 'initial BMO should not provision or execute shell commands: ' . $needle);
	if ($needle !== 'fwconsole reload') {
		scaffold_assert(strpos($console, $needle) === false, 'initial CLI should not provision or execute shell commands: ' . $needle);
	}
}

scaffold_assert(!file_exists($root . '/module.sig'), 'module.sig should not exist in this repository');

echo "Scaffold contract passed.\n";
