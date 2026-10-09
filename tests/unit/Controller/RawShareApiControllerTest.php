<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSharingRaw\Tests\Unit\Controller;

use OCA\FilesSharingRaw\Controller\RawShareApiController;
use OCA\FilesSharingRaw\Service\PublicUrlBuilder;
use OCA\FilesSharingRaw\Service\RawShareRegistry;
use OCP\AppFramework\Http;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RawShareApiControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IManager&MockObject $shareManager;
	private RawShareRegistry&MockObject $registry;
	private RawShareApiController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->shareManager = $this->createMock(IManager::class);
		$this->registry = $this->createMock(RawShareRegistry::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		// isInGroup() has no native return type, so an unconfigured mock returns null
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn(false);

		$this->controller = new RawShareApiController(
			'files_sharing_raw',
			$this->request,
			$this->shareManager,
			$userSession,
			$this->registry,
			$this->createMock(PublicUrlBuilder::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(IConfig::class),
			$groupManager,
		);
	}

	private function linkShare(?string $password): void {
		$share = $this->createMock(IShare::class);
		$share->method('getShareType')->willReturn(IShare::TYPE_LINK);
		$share->method('getSharedBy')->willReturn('alice');
		$share->method('getShareOwner')->willReturn('alice');
		$share->method('getToken')->willReturn('aBc123');
		$share->method('getPassword')->willReturn($password);
		$this->shareManager->method('getShareById')->with('ocinternal:7')->willReturn($share);
	}

	private function requestParams(array $params): void {
		$this->request->method('getParam')
			->willReturnCallback(fn (string $key, $default = null) => $params[$key] ?? $default);
	}

	public function testSetRefusesToEnableAPasswordProtectedShare(): void {
		$this->linkShare('hashed');
		$this->requestParams(['enabled' => '1']);
		$this->registry->expects(self::never())->method('enable');

		$response = $this->controller->set(7);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'password_protected'], $response->getData());
	}

	public function testSetDisablesAPasswordProtectedShare(): void {
		$this->linkShare('hashed');
		$this->requestParams(['enabled' => '0']);
		$this->registry->expects(self::once())->method('disable')->with(7);

		$response = $this->controller->set(7);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSetEnablesAShareWithoutPassword(): void {
		$this->linkShare(null);
		$this->requestParams(['enabled' => '1']);
		$this->registry->expects(self::once())->method('enable')->with(7, null, false);

		$response = $this->controller->set(7);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testGetReportsAPasswordProtectedShareAsDisabled(): void {
		$this->linkShare('hashed');
		$this->registry->method('isEnabled')->with(7)->willReturn(true);

		$data = $this->controller->get(7)->getData();

		self::assertFalse($data['enabled']);
		self::assertTrue($data['passwordProtected']);
	}
}
