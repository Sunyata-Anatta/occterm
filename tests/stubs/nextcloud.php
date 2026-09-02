<?php

declare(strict_types=1);

/**
 * OCCTerm for Nextcloud.
 *
 * SPDX-FileCopyrightText: 2026 AllV01d
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Stand-ins for the Nextcloud API surface OCCTerm touches, so the unit tests
 * run without a Nextcloud installation. Signatures are copied from
 * nextcloud/server @ stable34; if a signature here stops matching the server,
 * the tests are lying and must be regenerated. Checked 2026-09-02.
 */

namespace OCP {
	class ServerVersion {
		public function getVersionString(): string {
			return '34.0.0';
		}
	}
	class Defaults {
		public function getName(): string {
			return 'Nextcloud';
		}
	}
	interface IConfig {
		public function getSystemValueBool(string $key, bool $default = false): bool;
	}
	interface IRequest {
		public function getHeader(string $name): string;
		public function getParam(string $key, $default = null);
		public function getParams(): array;
		public function getMethod(): string;
		public function getUploadedFile(string $key);
		public function getEnv(string $key);
		public function getCookie(string $key);
		public function passesCSRFCheck(): bool;
		public function passesStrictCookieCheck(): bool;
		public function passesLaxCookieCheck(): bool;
		public function getId(): string;
		public function getRemoteAddress(): string;
		public function getServerProtocol(): string;
		public function getHttpProtocol(): string;
		public function getRequestUri(): string;
		public function getRawPathInfo(): string;
		public function getPathInfo();
		public function getScriptName(): string;
		public function isUserAgent(array $agent): bool;
		public function getInsecureServerHost(): string;
		public function getServerHost(): string;
		public function throwDecodingExceptionIfAny(): void;
		public function getFormat(): ?string;
	}
}

namespace OCP\App {
	interface IAppManager {
		public function getEnabledApps(): array;
	}
}

namespace OCP\EventDispatcher {
	interface IEventDispatcher {
		public function dispatchTyped(object $event): void;
	}
}

namespace OC {
	class MemoryInfo {
		public function isMemoryLimitSufficient(): bool {
			return true;
		}
	}
}

namespace OC\Console {

	use OC\MemoryInfo;
	use OCP\App\IAppManager;
	use OCP\Defaults;
	use OCP\EventDispatcher\IEventDispatcher;
	use OCP\IConfig;
	use OCP\IRequest;
	use OCP\ServerVersion;
	use Psr\Log\LoggerInterface;
	use Symfony\Component\Console\Application as SymfonyApplication;
	use Symfony\Component\Console\Input\InputInterface;
	use Symfony\Component\Console\Output\ConsoleOutputInterface;
	use Symfony\Component\Console\Output\OutputInterface;

	/** Constructor copied verbatim from stable34 lib/private/Console/Application.php */
	class Application {
		private SymfonyApplication $application;

		public function __construct(
			ServerVersion $serverVersion,
			private IConfig $config,
			private IEventDispatcher $dispatcher,
			private IRequest $request,
			private LoggerInterface $logger,
			private MemoryInfo $memoryInfo,
			private IAppManager $appManager,
			private Defaults $defaults,
		) {
			$this->application = new SymfonyApplication($defaults->getName(), $serverVersion->getVersionString());
		}

		public function loadCommands(InputInterface $input, ConsoleOutputInterface $output): void {
			$this->memoryInfo->isMemoryLimitSufficient();
			$this->config->getSystemValueBool('installed', false);
		}

		public function setAutoExit(bool $boolean): void {
			$this->application->setAutoExit($boolean);
		}

		public function run(InputInterface $input, OutputInterface $output): int {
			// stable34 run() reads argv off the injected request for ConsoleEvent
			$argv = $this->request->server['argv'];
			if (!is_array($argv) || ($argv[0] ?? null) !== 'occ') {
				throw new \RuntimeException('the injected request supplied no console argv');
			}

			return $this->application->run($input, $output);
		}
	}
}
