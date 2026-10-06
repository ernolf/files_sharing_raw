<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSharingRaw\Controller;

use OCA\FilesSharingRaw\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;

/**
 * Admin-only endpoints (no #[NoAdminRequired]: a plain Controller method
 * requires an administrator by default).
 */
class AdminSettingsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct($appName, $request);
	}

	public function setCspEditorGroup(string $group): DataResponse {
		if (!$this->groupManager->groupExists($group)) {
			return new DataResponse(['error' => 'group_not_found'], Http::STATUS_BAD_REQUEST);
		}

		$this->appConfig->setValueString(Application::APP_ID, Application::CONFIG_CSP_EDITOR_GROUP, $group);

		return new DataResponse(['group' => $group]);
	}
}
