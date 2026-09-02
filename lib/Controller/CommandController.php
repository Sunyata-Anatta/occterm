<?php

declare(strict_types=1);

/**
 * OCC Terminal for Nextcloud.
 *
 * SPDX-FileCopyrightText: 2026 AllV01d
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OCCTerm\Controller;

use OCA\OCCTerm\Service\OccException;
use OCA\OCCTerm\Service\OccRunner;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Every route here is administrator-only: none carries NoAdminRequired, so the
 * security middleware rejects everyone else before the controller runs.
 */
class CommandController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private OccRunner $runner,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Run one occ command and return its transcript.
	 *
	 * @param string $command The command line, as it would be typed after "occ"
	 * @return DataResponse<Http::STATUS_OK, array{output: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{message: string}, array{}>
	 */
	#[ApiRoute(verb: 'POST', url: '/api/v1/command')]
	public function run(string $command = ''): DataResponse {
		try {
			return new DataResponse(['output' => $this->runner->run($command)]);
		} catch (OccException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Names of every available occ command, for completion.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{commands: string[]}, array{}>
	 */
	#[ApiRoute(verb: 'GET', url: '/api/v1/commands')]
	public function commands(): DataResponse {
		return new DataResponse(['commands' => $this->runner->listCommands()]);
	}
}
