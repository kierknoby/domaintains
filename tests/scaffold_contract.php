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
scaffold_assert(strpos($module, "'state' => 'unprovisioned'") !== false, 'initial state should be unprovisioned');
scaffold_assert(strpos($module, "'provisioned' => false") !== false, 'initial scaffold should not claim provisioning');
scaffold_assert(strpos($page, '\\FreePBX::Domaintains()->showPage()') !== false, 'page controller should use the DOMAINTAINS BMO');
scaffold_assert(strpos($view, 'Development scaffold') !== false, 'GUI should clearly identify development scaffold');
scaffold_assert(strpos($console, "if (\$action !== 'status')") !== false, 'CLI should reject unimplemented actions');
scaffold_assert(strpos($readme, 'my-connect is') !== false, 'README should distinguish my-connect from DOMAINTAINS');

$forbidden = [
	'core_trunks_edit(',
	'addTrunk(',
	'createUpdateDID(',
	'fwconsole reload',
	'shell_exec(',
	'exec(',
];

foreach ($forbidden as $needle) {
	scaffold_assert(strpos($module, $needle) === false, 'initial BMO should not provision or execute shell commands: ' . $needle);
	if ($needle !== 'fwconsole reload') {
		scaffold_assert(strpos($console, $needle) === false, 'initial CLI should not provision or execute shell commands: ' . $needle);
	}
}

scaffold_assert(!file_exists($root . '/module.sig'), 'module.sig should not exist in the unsigned scaffold');

echo "Scaffold contract passed.\n";
