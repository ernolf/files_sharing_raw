<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSharingRaw\Settings;

use OCA\FilesSharingRaw\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
	public function __construct(
		private readonly IInitialState $initialState,
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState(
			'csp_editor_group',
			$this->appConfig->getValueString(Application::APP_ID, Application::CONFIG_CSP_EDITOR_GROUP, Application::CSP_EDITOR_GROUP_DEFAULT),
		);

		$groups = array_map(
			static fn (IGroup $group): array => ['id' => $group->getGID(), 'label' => $group->getDisplayName()],
			$this->groupManager->search(''),
		);
		$this->initialState->provideInitialState('all_groups', $groups);

		return new TemplateResponse(Application::APP_ID, 'admin');
	}

	public function getSection(): string {
		return 'sharing';
	}

	// Core sharing settings use 0 to 40 (files_sharing, federatedfilesharing,
	// federation, sharebymail); 50 places this section after them.
	public function getPriority(): int {
		return 50;
	}
}
