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

class Domaintains extends Command {

	protected function configure() {
		$this->setName('domaintains')
			->setDescription('Activate DOMAINTAINS and inspect local activation status')
			->addArgument('action', InputArgument::OPTIONAL, 'Action: status or activate', 'status')
			->addOption('json', null, InputOption::VALUE_NONE, 'Return machine-readable JSON');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$action = strtolower(trim((string)$input->getArgument('action')));
		if ($action === '') {
			$action = 'status';
		}

		if ($action === 'activate') {
			$question = new Question('Activation key: ');
			$question->setHidden(true);
			$question->setHiddenFallback(false);
			try {
				$key = (string)$this->getHelper('question')->ask($input, $output, $question);
			} catch (\RuntimeException $e) {
				$output->writeln('<error>Unable to read the activation key securely.</error>');
				return 1;
			}
			if (trim($key) === '') {
				$output->writeln('<error>An activation key is required.</error>');
				return 1;
			}
			$result = \FreePBX::Domaintains()->activate($key);
			$key = '';
			$output->writeln(($result['success'] ? '<info>' : '<error>') . $result['message'] . ($result['success'] ? '</info>' : '</error>'));
			if (!$result['success'] && isset($result['stage']) && $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
				$output->writeln($result['stage'] === 'local' ? 'Stage: local reconciliation' : 'Stage: remote activation');
			}
			return $result['success'] ? 0 : 1;
		}

		if ($action !== 'status') {
			$output->writeln('<error>Unknown action. Use status or activate.</error>');
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
}
