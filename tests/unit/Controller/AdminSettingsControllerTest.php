<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSharingRaw\Tests\Unit\Controller;

use OCA\FilesSharingRaw\Controller\AdminSettingsController;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminSettingsControllerTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private IGroupManager&MockObject $groupManager;
	private AdminSettingsController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->controller = new AdminSettingsController(
			'files_sharing_raw',
			$this->createMock(IRequest::class),
			$this->appConfig,
			$this->groupManager,
		);
	}

	public function testSetCspEditorGroupStoresAnExistingGroup(): void {
		$this->groupManager->method('groupExists')->with('csp')->willReturn(true);
		$this->appConfig->expects(self::once())
			->method('setValueString')
			->with('files_sharing_raw', 'csp_editor_group', 'csp');

		$response = $this->controller->setCspEditorGroup('csp');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['group' => 'csp'], $response->getData());
	}

	public function testSetCspEditorGroupRejectsAnUnknownGroup(): void {
		$this->groupManager->method('groupExists')->with('nope')->willReturn(false);
		$this->appConfig->expects(self::never())->method('setValueString');

		$response = $this->controller->setCspEditorGroup('nope');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
