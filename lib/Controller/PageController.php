<?php

declare(strict_types=1);

/**
 * OCC Terminal for Nextcloud.
 *
 * SPDX-FileCopyrightText: 2026 AllV01d
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OCCTerm\Controller;

use OCA\OCCTerm\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Util;

class PageController extends Controller {
	/**
	 * Serves the terminal page.
	 *
	 * Carries no NoAdminRequired attribute, so the framework admits
	 * administrators only.
	 */
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');

		return new TemplateResponse(Application::APP_ID, 'index');
	}
}
