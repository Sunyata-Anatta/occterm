<?php

declare(strict_types=1);

/**
 * OCC Terminal for Nextcloud.
 *
 * SPDX-FileCopyrightText: 2026 AllV01d
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OCCTerm\Console;

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Captures console output in memory.
 *
 * OC\\Console\\Application::loadCommands() requires a ConsoleOutputInterface,
 * which Symfony's BufferedOutput does not implement because it has no error
 * stream and no sections. This adds both: errors fold into the same buffer, so
 * the caller sees one transcript, and sections write to an in-memory stream.
 */
class BufferedConsoleOutput extends BufferedOutput implements ConsoleOutputInterface {
	/** @var ConsoleSectionOutput[] */
	private array $sections = [];

	/** @var resource|null */
	private $sectionStream = null;

	public function getErrorOutput(): OutputInterface {
		return $this;
	}

	public function setErrorOutput(OutputInterface $error): void {
		// Errors are folded into the single buffer on purpose; a web client
		// reads one transcript, the way a terminal shows one stream.
	}

	public function section(): ConsoleSectionOutput {
		if ($this->sectionStream === null) {
			$stream = fopen('php://temp', 'w+b');
			if ($stream === false) {
				throw new \RuntimeException('Could not open an in-memory stream for console sections');
			}
			$this->sectionStream = $stream;
		}

		return new ConsoleSectionOutput(
			$this->sectionStream,
			$this->sections,
			$this->getVerbosity(),
			$this->isDecorated(),
			$this->getFormatter(),
		);
	}
}
