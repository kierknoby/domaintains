<?php
/**
 * fwconsole domaintains command.
 *
 * Initial scaffold:
 *   fwconsole domaintains
 *   fwconsole domaintains status
 *   fwconsole domaintains status --json
 */

namespace FreePBX\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Domaintains extends Command {

	protected function configure() {
		$this->setName('domaintains')
			->setDescription('Inspect and manage DOMAINTAINS PBX-side connectivity')
			->addArgument('action', InputArgument::OPTIONAL, 'Action: status', 'status')
			->addOption('json', null, InputOption::VALUE_NONE, 'Return machine-readable JSON');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$action = strtolower(trim((string)$input->getArgument('action')));
		if ($action === '') {
			$action = 'status';
		}

		if ($action !== 'status') {
			$output->writeln('<error>Unknown action. The initial DOMAINTAINS scaffold supports status only.</error>');
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
		$output->writeln('Remote bridge: ' . $status['bridge']);
		$output->writeln('FreePBX support: ' . implode(' and ', $status['freepbx_support']));

		return 0;
	}
}
