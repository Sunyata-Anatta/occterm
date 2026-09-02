<?php

declare(strict_types=1);

/**
 * OCCTerm for Nextcloud.
 *
 * SPDX-FileCopyrightText: 2026 AllV01d
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Runs without PHPUnit and without a Nextcloud installation:
 *     composer install && composer test
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/stubs/nextcloud.php';
require __DIR__ . '/../lib/Console/BufferedConsoleOutput.php';
require __DIR__ . '/../lib/Console/ConsoleRequest.php';
require __DIR__ . '/../lib/Service/OccException.php';
require __DIR__ . '/../lib/Service/OccRunner.php';

use OCA\OCCTerm\Console\BufferedConsoleOutput;
use OCA\OCCTerm\Console\ConsoleRequest;
use OCA\OCCTerm\Service\OccRunner;
use OCP\App\IAppManager;
use OCP\Defaults;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ServerVersion;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

$passed = 0;
$failed = 0;

function check(string $what, callable $test): void {
	global $passed, $failed;
	try {
		$test();
		$passed++;
		echo "PASS  $what\n";
	} catch (Throwable $e) {
		$failed++;
		echo "FAIL  $what\n      " . $e->getMessage() . "\n";
	}
}

function that(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function makeRunner(): OccRunner {
	return new OccRunner(
		new ServerVersion(),
		new class implements IConfig {
			public function getSystemValueBool(string $key, bool $default = false): bool {
				return true;
			}
		},
		new class implements IEventDispatcher {
			public function dispatchTyped(object $event): void {
			}
		},
		new NullLogger(),
		new OC\MemoryInfo(),
		new class implements IAppManager {
			public function getEnabledApps(): array {
				return [];
			}
		},
		new Defaults(),
	);
}

check('BufferedConsoleOutput satisfies Symfony ConsoleOutputInterface', function (): void {
	$output = new BufferedConsoleOutput(OutputInterface::VERBOSITY_NORMAL, false);
	that($output instanceof ConsoleOutputInterface, 'not a ConsoleOutputInterface');
});

check('error output folds into the single transcript', function (): void {
	$output = new BufferedConsoleOutput(OutputInterface::VERBOSITY_NORMAL, false);
	$output->writeln('ordinary');
	$output->getErrorOutput()->writeln('problem');
	$text = $output->fetch();
	that(str_contains($text, 'ordinary'), 'lost the ordinary line');
	that(str_contains($text, 'problem'), 'lost the error line');
});

check('section() returns a usable ConsoleSectionOutput', function (): void {
	$output = new BufferedConsoleOutput(OutputInterface::VERBOSITY_NORMAL, false);
	$section = $output->section();
	$section->writeln('in a section');
	that($output->section() !== $section, 'each call should hand back a new section');
});

check('ConsoleRequest carries a console argv', function (): void {
	$request = new ConsoleRequest();
	that($request instanceof IRequest, 'must implement the public IRequest');
	that($request->server['argv'] === ['occ'], 'argv should default to ["occ"]');
	that((new ConsoleRequest(['occ', 'status']))->server['argv'] === ['occ', 'status'], 'argv should be settable');
});

check('an empty command line runs nothing and returns nothing', function (): void {
	that(makeRunner()->run('   ') === '', 'blank input should produce no output');
});

check('run() returns the command transcript', function (): void {
	$out = makeRunner()->run('list --format=txt');
	that(str_contains($out, 'help'), "expected the built-in commands in:\n$out");
});

check('listCommands() parses Symfony JSON into sorted names', function (): void {
	$names = makeRunner()->listCommands();
	that($names !== [], 'expected at least the built-in commands');
	that(in_array('list', $names, true), 'expected "list" among: ' . implode(',', $names));
	that(in_array('help', $names, true), 'expected "help" among: ' . implode(',', $names));
	$sorted = $names;
	sort($sorted);
	that($names === $sorted, 'names should come back sorted');
});

check('listCommands() survives output that is not JSON', function (): void {
	$runner = new class extends OccRunner {
		public function __construct() {
		}
		public function run(string $commandLine): string {
			return "Something went wrong, not JSON at all";
		}
	};
	that($runner->listCommands() === [], 'malformed output should yield an empty list, not a crash');
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
