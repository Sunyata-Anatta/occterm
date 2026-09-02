<?php

declare(strict_types=1);

/**
 * OCC Terminal for Nextcloud.
 *
 * SPDX-FileCopyrightText: 2026 AllV01d
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OCCTerm\Service;

use OC\Console\Application as ConsoleApplication;
use OC\MemoryInfo;
use OCA\OCCTerm\Console\BufferedConsoleOutput;
use OCA\OCCTerm\Console\ConsoleRequest;
use OCP\App\IAppManager;
use OCP\Defaults;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\ServerVersion;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Runs occ commands in-process and returns their output as text.
 *
 * Nextcloud publishes no public API for executing console commands: OCP\\Console
 * contains only ConsoleEvent. Every private-namespace dependency this app has is
 * therefore confined to this one class, so that a server-side change breaks one
 * file rather than the whole app. See docs/architecture.md.
 */
class OccRunner {
	public function __construct(
		private ServerVersion $serverVersion,
		private IConfig $config,
		private IEventDispatcher $dispatcher,
		private LoggerInterface $logger,
		private MemoryInfo $memoryInfo,
		private IAppManager $appManager,
		private Defaults $defaults,
	) {
	}

	/**
	 * @throws OccException when the command could not be executed at all
	 */
	public function run(string $commandLine): string {
		$commandLine = trim($commandLine);
		if ($commandLine === '') {
			return '';
		}

		$output = new BufferedConsoleOutput(OutputInterface::VERBOSITY_NORMAL, false);
		$application = $this->buildApplication();

		try {
			$application->loadCommands(new StringInput(''), $output);
			$application->run(new StringInput($commandLine), $output);
		} catch (Throwable $e) {
			$this->logger->error('occ command failed: ' . $e->getMessage(), ['exception' => $e]);
			throw new OccException($e->getMessage(), 0, $e);
		}

		return $output->fetch();
	}

	/**
	 * Names of every available occ command.
	 *
	 * Uses Symfony's own machine-readable listing rather than reaching into the
	 * application object, so this needs no reflection.
	 *
	 * @return string[]
	 */
	public function listCommands(): array {
		$json = $this->run('list --format=json');
		$parsed = json_decode($json, true);
		if (!is_array($parsed) || !isset($parsed['commands']) || !is_array($parsed['commands'])) {
			return [];
		}

		$names = [];
		foreach ($parsed['commands'] as $command) {
			if (isset($command['name']) && is_string($command['name'])) {
				$names[] = $command['name'];
			}
		}
		sort($names);

		return $names;
	}

	private function buildApplication(): ConsoleApplication {
		$application = new ConsoleApplication(
			$this->serverVersion,
			$this->config,
			$this->dispatcher,
			new ConsoleRequest(),
			$this->logger,
			$this->memoryInfo,
			$this->appManager,
			$this->defaults,
		);
		$application->setAutoExit(false);

		return $application;
	}
}
