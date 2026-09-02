<?php

declare(strict_types=1);

/**
 * OCC Terminal for Nextcloud.
 *
 * SPDX-FileCopyrightText: 2026 AllV01d
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OCCTerm\Console;

use OCP\IRequest;

/**
 * A request carrying a console argv, for code paths that expect one.
 *
 * OC\\Console\\Application dispatches ConsoleEvent with
 * $request->server['argv']. A web request has no argv, so this supplies a
 * minimal one. It implements the public OCP\\IRequest interface rather than
 * extending the server's private Request class, so it depends on published API
 * only. Nothing here answers questions about a real HTTP request; the methods
 * return inert values because no console code path consults them.
 *
 * @property-read string[] $server
 */
class ConsoleRequest implements IRequest {
	/** @var string[] */
	public array $server;

	/** @var array<string,string> */
	public array $urlParams = [];

	/**
	 * @param string[] $argv
	 */
	public function __construct(array $argv = ['occ']) {
		$this->server = ['argv' => $argv];
	}

	public function getHeader(string $name): string {
		return '';
	}

	public function getParam(string $key, $default = null) {
		return $default;
	}

	public function getParams(): array {
		return [];
	}

	public function getMethod(): string {
		return 'CLI';
	}

	public function getUploadedFile(string $key) {
		return null;
	}

	public function getEnv(string $key) {
		return null;
	}

	public function getCookie(string $key) {
		return null;
	}

	public function passesCSRFCheck(): bool {
		return false;
	}

	public function passesStrictCookieCheck(): bool {
		return false;
	}

	public function passesLaxCookieCheck(): bool {
		return false;
	}

	public function getId(): string {
		return '';
	}

	public function getRemoteAddress(): string {
		return '';
	}

	public function getServerProtocol(): string {
		return '';
	}

	public function getHttpProtocol(): string {
		return '';
	}

	public function getRequestUri(): string {
		return '';
	}

	public function getRawPathInfo(): string {
		return '';
	}

	public function getPathInfo() {
		return '';
	}

	public function getScriptName(): string {
		return 'occ';
	}

	public function isUserAgent(array $agent): bool {
		return false;
	}

	public function getInsecureServerHost(): string {
		return '';
	}

	public function getServerHost(): string {
		return '';
	}

	public function throwDecodingExceptionIfAny(): void {
	}

	public function getFormat(): ?string {
		return null;
	}
}
