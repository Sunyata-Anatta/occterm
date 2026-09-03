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
	 * What the client needs to colour and complete a command line.
	 *
	 * Uses Symfony's own machine-readable listing rather than reaching into the
	 * application object, so this needs no reflection.
	 *
	 * Each command reports its own options and its usage line. The usage line
	 * doubles as the example shown to the user, so no separate help text is sent.
	 * Options carried by every command are reported once under "global" rather
	 * than repeated per command, which is most of the payload otherwise.
	 *
	 * @return array{commands: array<string, array{o: string[], u: string}>, global: string[]}
	 */
	public function commandIndex(): array {
		$parsed = json_decode($this->run('list --format=json'), true);
		if (!is_array($parsed) || !isset($parsed['commands']) || !is_array($parsed['commands'])) {
			return ['commands' => [], 'global' => []];
		}

		$options = [];
		$usage = [];
		foreach ($parsed['commands'] as $command) {
			$name = $command['name'] ?? null;
			if (!is_string($name) || $name === '') {
				continue;
			}

			$names = [];
			foreach ($command['definition']['options'] ?? [] as $option) {
				if (isset($option['name']) && is_string($option['name'])) {
					$names[] = $option['name'];
				}
			}
			sort($names);
			$options[$name] = $names;
			$usage[$name] = (string)(($command['usage'] ?? [])[0] ?? $name);
		}

		if ($options === []) {
			return ['commands' => [], 'global' => []];
		}

		// An option that every single command carries is a global one.
		$sets = array_values($options);
		$global = array_shift($sets);
		foreach ($sets as $set) {
			$global = array_intersect($global, $set);
		}
		$global = array_values($global);
		sort($global);

		$commands = [];
		foreach ($options as $name => $names) {
			$commands[$name] = [
				'o' => array_values(array_diff($names, $global)),
				'u' => $usage[$name],
			];
		}
		ksort($commands);

		return ['commands' => $commands, 'global' => $global];
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
