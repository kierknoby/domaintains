<?php
/**
 * fwconsole domaintains command.
 *
 *   fwconsole domaintains status [--json]
 *   fwconsole domaintains activate
 */

namespace FreePBX\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class Domaintains extends Command {

	protected function configure() {
		$this->setName('domaintains')
			->setDescription('Activate DOMAINTAINS and inspect local activation status')
			->addArgument('action', InputArgument::OPTIONAL, 'Action: status, identity, routing, activate, deactivate, reactivate, endpoint or adopt', 'status')
			->addArgument('value', InputArgument::OPTIONAL, 'Complete HTTPS endpoint or explicit routing-pattern JSON', null)
			->addOption('json', null, InputOption::VALUE_NONE, 'Return machine-readable JSON');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		try {
			$this->dropRootPrivileges();
		} catch (\Throwable $e) {
			return $this->outputResult(['success' => false, 'message' => 'Unable to switch securely to the Asterisk runtime account. No module state was accessed.'], $input, $output);
		}
		$action = strtolower(trim((string)$input->getArgument('action')));
		if ($action === '') {
			$action = 'status';
		}
		if ($action === 'identity') {
			try {
				$service = \FreePBX::Domaintains()->getServiceIdentity();
				$output->writeln($input->getOption('json') ? json_encode(['service' => $service], JSON_UNESCAPED_SLASHES) : $service);
				return 0;
			} catch (\Throwable $e) {
				return $this->outputResult(['success' => false, 'message' => 'Unable to read PBX identity safely.'], $input, $output);
			}
		}
		if ($action === 'routing') {
			$value = $input->getArgument('value');
			$patterns = is_string($value) && strlen($value) <= 65536 ? json_decode($value, true, 8) : null;
			if (!is_array($patterns)) {
				return $this->outputResult(['success' => false, 'message' => 'Supply an explicit JSON array of outbound route patterns.'], $input, $output);
			}
			return $this->outputResult(\FreePBX::Domaintains()->configureRoutingPolicy($patterns), $input, $output);
		}
		if ($action === 'endpoint') {
			$value = $input->getArgument('value');
			if ($value !== null) {
				return $this->outputResult(\FreePBX::Domaintains()->configureProvisioningEndpoint((string)$value), $input, $output);
			}
			try {
				$endpoint = \FreePBX::Domaintains()->getProvisioningEndpoint();
				$output->writeln($input->getOption('json') ? json_encode(['endpoint' => $endpoint], JSON_UNESCAPED_SLASHES) : ($endpoint === '' ? 'No provisioning endpoint configured.' : $endpoint));
				return 0;
			} catch (\Throwable $e) {
				return $this->outputResult(['success' => false, 'message' => 'Unable to read provisioning endpoint safely.'], $input, $output);
			}
		}
		if ($action === 'adopt') {
			if (!$input->isInteractive()) {
				return $this->outputResult(['success' => false, 'message' => 'Ownership adoption requires interactive review and confirmation.'], $input, $output);
			}
			$preview = \FreePBX::Domaintains()->previewOwnershipAdoption();
			if (!$preview['success']) {
				return $this->outputResult($preview, $input, $output);
			}
			$promptOutput = $input->getOption('json') && method_exists($output, 'getErrorOutput') ? $output->getErrorOutput() : $output;
			$promptOutput->writeln('Exact matching objects proposed for ownership adoption:');
			foreach ($preview['objects'] as $object) {
				$promptOutput->writeln(json_encode(['type' => $object['type'], 'id' => $object['id'], 'label' => $object['label'], 'current_state' => isset($object['current_state']) ? $object['current_state'] : null, 'already_owned' => $object['owned']], JSON_UNESCAPED_SLASHES));
			}
			try {
				$confirmed = $this->getHelper('question')->ask($input, $promptOutput, new ConfirmationQuestion('Adopt these exact objects as DOMAINTAINS-owned? [y/N] ', false));
			} catch (\RuntimeException $e) {
				$confirmed = false;
			}
			return $this->outputResult(\FreePBX::Domaintains()->adoptOwnership($preview['plan'], $confirmed === true), $input, $output);
		}

		if (in_array($action, ['deactivate', 'reactivate'], true)) {
			if (!$input->isInteractive()) {
				return $this->outputResult(['success' => false, 'message' => 'This operation requires interactive confirmation.'], $input, $output);
			}
			$promptOutput = $input->getOption('json') && method_exists($output, 'getErrorOutput') ? $output->getErrorOutput() : $output;
			$confirmation = new ConfirmationQuestion($action === 'deactivate'
				? 'Unlink activation while preserving signing identity and PBX configuration? [y/N] '
				: 'Replace activation authorization while preserving signing identity? [y/N] ', false);
			try {
				$confirmed = $this->getHelper('question')->ask($input, $promptOutput, $confirmation);
			} catch (\RuntimeException $e) {
				return $this->outputResult(['success' => false, 'message' => 'Unable to confirm this operation securely.'], $input, $output);
			}
			if (!$confirmed) {
				return $this->outputResult(['success' => false, 'message' => 'Operation cancelled.'], $input, $output);
			}
			if ($action === 'deactivate') {
				return $this->outputResult(\FreePBX::Domaintains()->deactivate(), $input, $output);
			}
		}

		if ($action === 'activate' || $action === 'reactivate') {
			if (!$input->isInteractive()) {
				return $this->outputResult(['success' => false, 'message' => 'Activation requires a secure interactive key prompt.'], $input, $output);
			}
			$question = new Question('Activation key: ');
			$question->setHidden(true);
			$question->setHiddenFallback(false);
			try {
				$promptOutput = $input->getOption('json') && method_exists($output, 'getErrorOutput') ? $output->getErrorOutput() : $output;
				$key = (string)$this->getHelper('question')->ask($input, $promptOutput, $question);
			} catch (\RuntimeException $e) {
				return $this->outputResult(['success' => false, 'message' => 'Unable to read the activation key securely.'], $input, $output);
			}
			if (trim($key) === '') {
				return $this->outputResult(['success' => false, 'message' => 'An activation key is required.'], $input, $output);
			}
			$result = $action === 'reactivate' ? \FreePBX::Domaintains()->reactivate($key) : \FreePBX::Domaintains()->activate($key);
			$key = '';
			if ($input->getOption('json')) {
				return $this->outputResult($result, $input, $output);
			}
			$output->writeln(($result['success'] ? '<info>' : '<error>') . $result['message'] . ($result['success'] ? '</info>' : '</error>'));
			if (!$result['success'] && isset($result['stage']) && $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
				$output->writeln($result['stage'] === 'local' ? 'Stage: local reconciliation' : 'Stage: remote activation');
			}
			return $result['success'] ? 0 : 1;
		}

		if ($action !== 'status') {
			$output->writeln('<error>Unknown action. Use status, identity, routing, activate, deactivate, reactivate, endpoint or adopt.</error>');
			return 1;
		}

		$status = \FreePBX::Domaintains()->getStatus();
		if ($input->getOption('json')) {
			$json = json_encode($status, JSON_UNESCAPED_SLASHES);
			if ($json === false) {
				$output->writeln('<error>Unable to encode DOMAINTAINS status.</error>');
				return 1;
			}
			$output->writeln($json);
			return 0;
		}

		$output->writeln('DOMAINTAINS');
		$output->writeln('===========');
		$output->writeln('Version: ' . $status['version']);
		$output->writeln('State: ' . $status['state']);
		$output->writeln('Provisioned: ' . ($status['provisioned'] ? 'yes' : 'no'));
		if (isset($status['last_error'], $status['last_error_stage'])) {
			$output->writeln('Last error: ' . $status['last_error']);
			$output->writeln('Last error stage: ' . $status['last_error_stage']);
		}
		$output->writeln('FreePBX support: ' . implode(' and ', $status['freepbx_support']));

		return 0;
	}

	private function dropRootPrivileges(): void {
		if (!function_exists('posix_geteuid')) {
			throw new \RuntimeException('POSIX support is required.');
		}
		if (posix_geteuid() !== 0) {
			return;
		}
		$account = posix_getpwnam('asterisk');
		if (!is_array($account) || !isset($account['uid'], $account['gid']) || (int)$account['uid'] <= 0 || (int)$account['gid'] <= 0
			|| !function_exists('posix_initgroups') || !function_exists('posix_setgid') || !function_exists('posix_setuid')
			|| !posix_initgroups('asterisk', (int)$account['gid'])
			|| !posix_setgid((int)$account['gid']) || !posix_setuid((int)$account['uid'])
			|| posix_getuid() !== (int)$account['uid'] || posix_geteuid() !== (int)$account['uid']
			|| posix_getgid() !== (int)$account['gid'] || posix_getegid() !== (int)$account['gid']) {
			throw new \RuntimeException('Unable to drop root privileges.');
		}
	}

	private function outputResult(array $result, InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('json')) {
			$output->writeln(json_encode($result, JSON_UNESCAPED_SLASHES));
		} else {
			$output->writeln(($result['success'] ? '<info>' : '<error>') . $result['message'] . ($result['success'] ? '</info>' : '</error>'));
		}
		return $result['success'] ? 0 : 1;
	}
}
