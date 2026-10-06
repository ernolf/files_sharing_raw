<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSharingRaw\Tests\Unit\Settings;

use OCA\FilesSharingRaw\Settings\AdminSettings;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminSettingsTest extends TestCase {
	private IInitialState&MockObject $initialState;
	private IAppConfig&MockObject $appConfig;
	private IGroupManager&MockObject $groupManager;
	private AdminSettings $settings;

	protected function setUp(): void {
		parent::setUp();
		$this->initialState = $this->createMock(IInitialState::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->settings = new AdminSettings($this->initialState, $this->appConfig, $this->groupManager);
	}

	private function group(string $gid, string $displayName): IGroup&MockObject {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$group->method('getDisplayName')->willReturn($displayName);
		return $group;
	}

	public function testGetFormProvidesTheConfiguredGroupAndAllGroups(): void {
		$this->appConfig->method('getValueString')
			->with('files_sharing_raw', 'csp_editor_group', 'admin')
			->willReturn('csp');
		$this->groupManager->method('search')->with('')->willReturn([
			$this->group('admin', 'Admins'),
			$this->group('csp', 'CSP editors'),
		]);

		$provided = [];
		$this->initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, mixed $value) use (&$provided): void {
				$provided[$key] = $value;
			});

		$response = $this->settings->getForm();

		self::assertSame('admin', $response->getTemplateName());
		self::assertSame('csp', $provided['csp_editor_group']);
		self::assertSame([
			['id' => 'admin', 'label' => 'Admins'],
			['id' => 'csp', 'label' => 'CSP editors'],
		], $provided['all_groups']);
	}

	public function testSectionIsSharing(): void {
		self::assertSame('sharing', $this->settings->getSection());
		self::assertSame(50, $this->settings->getPriority());
	}
}
