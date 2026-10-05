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
			->addArgument('action', InputArgument::OPTIONAL, 'Action: status, activate, deactivate or reactivate', 'status')
			->addOption('json', null, InputOption::VALUE_NONE, 'Return machine-readable JSON');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$action = strtolower(trim((string)$input->getArgument('action')));
		if ($action === '') {
			$action = 'status';
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
			$output->writeln('<error>Unknown action. Use status, activate, deactivate or reactivate.</error>');
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

	private function outputResult(array $result, InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('json')) {
			$output->writeln(json_encode($result, JSON_UNESCAPED_SLASHES));
		} else {
			$output->writeln(($result['success'] ? '<info>' : '<error>') . $result['message'] . ($result['success'] ? '</info>' : '</error>'));
		}
		return $result['success'] ? 0 : 1;
	}
}
