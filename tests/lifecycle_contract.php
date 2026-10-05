<?php

declare(strict_types=1);

namespace Symfony\Component\Console\Input {
	interface InputInterface {}
	class InputArgument { const OPTIONAL = 1; }
	class InputOption { const VALUE_NONE = 1; }
}

namespace Symfony\Component\Console\Output {
	interface OutputInterface { const VERBOSITY_VERBOSE = 64; }
}

namespace Symfony\Component\Console\Question {
	class Question {
		public $hidden = false;
		public $fallback = true;
		public function __construct($question, $default = null) {}
		public function setHidden($hidden) { $this->hidden = $hidden; }
		public function setHiddenFallback($fallback) { $this->fallback = $fallback; }
	}
	class ConfirmationQuestion extends Question {
		public $default;
		public function __construct($question, $default) { $this->default = $default; }
	}
}

namespace Symfony\Component\Console\Command {
	class Command {
		public $helper;
		public function setName($name) { return $this; }
		public function setDescription($description) { return $this; }
		public function addArgument($name, $mode, $description, $default) { return $this; }
		public function addOption($name, $shortcut, $mode, $description) { return $this; }
		public function getHelper($name) { return $this->helper; }
		public function run($input, $output) { return $this->execute($input, $output); }
	}
}

namespace FreePBX\modules {
	class RootOwnershipFixture {
		public static $enabled = false;
		public static $owners = [];
		public static $groups = [];
		public static $fail = false;
		public static $published = [];
	}
	function fileowner($path) {
		return RootOwnershipFixture::$enabled ? (RootOwnershipFixture::$owners[$path] ?? 0) : \fileowner($path);
	}
	function filegroup($path) {
		return RootOwnershipFixture::$enabled ? (RootOwnershipFixture::$groups[$path] ?? 0) : \filegroup($path);
	}
	function chown($path, $owner) {
		if (!RootOwnershipFixture::$enabled) { return \chown($path, $owner); }
		if (RootOwnershipFixture::$fail) { return false; }
		RootOwnershipFixture::$owners[$path] = $owner;
		return true;
	}
	function chgrp($path, $group) {
		if (!RootOwnershipFixture::$enabled) { return \chgrp($path, $group); }
		if (RootOwnershipFixture::$fail) { return false; }
		RootOwnershipFixture::$groups[$path] = $group;
		return true;
	}
	function rename($source, $target) {
		if (RootOwnershipFixture::$enabled) {
			if (fileowner($source) !== 12001 || filegroup($source) !== 12001 || (\fileperms($source) & 0777) !== 0600) {
				throw new \RuntimeException('Atomic replacement attempted without runtime ownership.');
			}
		}
		$result = \rename($source, $target);
		if ($result && RootOwnershipFixture::$enabled) {
			RootOwnershipFixture::$owners[$target] = fileowner($source);
			RootOwnershipFixture::$groups[$target] = filegroup($source);
			RootOwnershipFixture::$published[] = $target;
		}
		return $result;
	}
}

namespace {
	session_start();
	ob_start();
	require __DIR__ . '/activation_contract.php';
	ob_end_clean();
	require dirname(__DIR__) . '/Console/Domaintains.class.php';

	class LifecycleInput implements \Symfony\Component\Console\Input\InputInterface {
		public $action;
		public $json = false;
		public $interactive = true;
		public function getArgument($name) { return $this->action; }
		public function getOption($name) { return $this->json; }
		public function isInteractive() { return $this->interactive; }
	}
	class LifecycleOutput implements \Symfony\Component\Console\Output\OutputInterface {
		public $lines = [];
		public function writeln($line) { $this->lines[] = $line; }
		public function getVerbosity() { return 32; }
	}
	class LifecycleQuestions {
		public $answers = [];
		public $calls = 0;
		public function ask($input, $output, $question) {
			$this->calls++;
			if ($question instanceof \Symfony\Component\Console\Question\ConfirmationQuestion) {
				activation_assert($question->default === false, 'CLI confirmation must default to no');
			} else {
				activation_assert($question->hidden && !$question->fallback, 'reactivation key input must be hidden without fallback');
			}
			return array_shift($this->answers);
		}
	}
	$directory = activation_temp_directory();
	$module = new ActivationContractModule($directory, [activation_fixture_response()]);
	activation_assert($module->activate('synthetic-test-token')['success'], 'CLI lifecycle fixture should provision');
	$command = new \FreePBX\Console\Command\Domaintains();
	$questions = new LifecycleQuestions();
	$command->helper = $questions;
	$input = new LifecycleInput();
	$input->action = 'deactivate';
	$input->interactive = false;
	$output = new LifecycleOutput();
	activation_assert($command->run($input, $output) === 1 && is_file($directory . '/state.json') && $questions->calls === 0, 'non-interactive deactivation must refuse without deleting state');
	$input->interactive = true;
	$questions->answers = [false];
	activation_assert($command->run($input, $output) === 1 && is_file($directory . '/state.json'), 'declined CLI confirmation must preserve state');
	$input->action = 'reactivate';
	$questions->answers = [true, 'synthetic-replacement-token'];
	activation_assert($command->run($input, $output) === 0, 'confirmed CLI reactivation should use shared activation path');
	activation_assert(strpos(implode("\n", $output->lines), 'synthetic-replacement-token') === false, 'CLI output must not contain entered key');
	$input->action = 'deactivate';
	$questions->answers = [true];
	activation_assert($command->run($input, $output) === 0, 'confirmed CLI deactivation should succeed');
	$input->action = 'status';
	$input->json = true;
	$output->lines = [];
	activation_assert($command->run($input, $output) === 0 && json_decode($output->lines[0], true)['state'] === 'unprovisioned', 'CLI JSON status should report unprovisioned after deactivation');
	activation_assert($module->activate('synthetic-test-token')['success'], 'GUI lifecycle fixture should provision');
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SESSION['domaintains_csrf'] = 'synthetic-csrf';
	$_POST = ['domaintains_action' => 'reactivate', 'domaintains_csrf' => 'synthetic-csrf', 'activation_key' => 'synthetic-gui-replacement-token'];
	$requestsBeforeConfirmation = count($module->requests());
	$module->doConfigPageInit('domaintains');
	activation_assert(count($module->requests()) === $requestsBeforeConfirmation, 'unconfirmed GUI replacement must not contact the provider');
	$_SESSION['domaintains_csrf'] = 'synthetic-csrf';
	$_POST['domaintains_confirm'] = 'yes';
	$module->doConfigPageInit('domaintains');
	activation_assert(count($module->requests()) === $requestsBeforeConfirmation + 1 && $module->getStatus()['state'] === 'provisioned', 'confirmed GUI replacement should use shared activation and provision');
	$_SESSION['domaintains_csrf'] = 'synthetic-csrf';
	$_POST = ['domaintains_action' => 'deactivate', 'domaintains_csrf' => 'wrong', 'domaintains_confirm' => 'yes'];
	$module->doConfigPageInit('domaintains');
	activation_assert(is_file($directory . '/state.json'), 'invalid GUI CSRF must preserve activation');
	$_POST['domaintains_csrf'] = 'synthetic-csrf';
	unset($_POST['domaintains_confirm']);
	$module->doConfigPageInit('domaintains');
	activation_assert(is_file($directory . '/state.json'), 'GUI lifecycle operation requires explicit confirmation');
	$_SESSION['domaintains_csrf'] = 'synthetic-csrf';
	$_POST['domaintains_confirm'] = 'yes';
	$module->doConfigPageInit('domaintains');
	activation_assert(!is_file($directory . '/state.json') && is_file($directory . '/signing.key'), 'confirmed GUI deactivation preserves signing key');
	define('FREEPBX_IS_AUTH', true);
	$moduleVersion = $module->getVersion();
	$activationCsrfToken = 'synthetic-csrf';
	$activationMessage = [];
	foreach (['unprovisioned', 'provisioned', 'pending', 'error'] as $stateName) {
		$status = ['state' => $stateName, 'provisioned' => $stateName === 'provisioned', 'inbound_numbers' => 0, 'inbound_trunks' => 0, 'outbound_trunks' => 0];
		ob_start();
		include dirname(__DIR__) . '/views/main.php';
		$html = ob_get_clean();
		activation_assert((strpos($html, 'value="reactivate"') !== false) === in_array($stateName, ['provisioned', 'pending'], true), 'GUI lifecycle controls must be limited to retained valid state');
		activation_assert((strpos($html, 'value="deactivate"') !== false) === in_array($stateName, ['provisioned', 'pending'], true), 'GUI deactivation controls must be limited to retained valid state');
	}
	activation_cleanup($directory);

	class RootOwnershipModule extends ActivationContractModule {
		protected function storageOwner(): array { return [12001, 12001]; }
		protected function loadOrCreateSigningKeypair(bool $allowCreate = true): array {
			if (!is_file($this->storageDirectory() . '/signing.key') && $allowCreate) {
				$write = new ReflectionMethod(\FreePBX\modules\Domaintains::class, 'writeSecureFile');
				$write->setAccessible(true);
				$write->invoke($this, $this->storageDirectory() . '/signing.key', 'synthetic-test-signing-key');
			}
			return parent::loadOrCreateSigningKeypair($allowCreate);
		}
	}
	\FreePBX\modules\RootOwnershipFixture::$enabled = true;
	$directory = activation_temp_directory();
	$rootModule = new RootOwnershipModule($directory, [activation_fixture_response()]);
	activation_assert($rootModule->activate('synthetic-test-token')['success'], 'simulated root activation should publish runtime-owned state');
	activation_assert(\FreePBX\modules\fileowner($directory . '/state.json') === 12001 && \FreePBX\modules\filegroup($directory . '/state.json') === 12001 && (fileperms($directory . '/state.json') & 0777) === 0600, 'root activation state must have runtime ownership and mode 0600');
	activation_assert(\FreePBX\modules\fileowner($directory . '/signing.key') === 12001 && (fileperms($directory . '/signing.key') & 0777) === 0600, 'root activation must keep the signing key runtime-owned and private');
	activation_assert($rootModule->activate('synthetic-test-token')['success'], 'root-context state rewrite must retain runtime ownership');
	$stateBeforeFailure = file_get_contents($directory . '/state.json');
	\FreePBX\modules\RootOwnershipFixture::$fail = true;
	activation_assert(!$rootModule->activate('synthetic-test-token')['success'] && file_get_contents($directory . '/state.json') === $stateBeforeFailure, 'ownership failure must not replace valid state');
	activation_assert(glob($directory . '/*.tmp') === [], 'failed ownership normalization must clean temporary files');
	\FreePBX\modules\RootOwnershipFixture::$fail = false;
	\FreePBX\modules\RootOwnershipFixture::$enabled = false;
	activation_cleanup($directory);
	echo "Lifecycle and ownership contract passed.\n";
}
