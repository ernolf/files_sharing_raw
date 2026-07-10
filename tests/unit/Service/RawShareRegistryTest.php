<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSharingRaw\Tests\Unit\Service;

use OCA\FilesSharingRaw\Db\RawShare;
use OCA\FilesSharingRaw\Db\RawShareMapper;
use OCA\FilesSharingRaw\Service\RawShareRegistry;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RawShareRegistryTest extends TestCase {
	private RawShareMapper&MockObject $mapper;
	private ITimeFactory&MockObject $time;
	private RawShareRegistry $registry;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(RawShareMapper::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->registry = new RawShareRegistry($this->mapper, $this->time);
	}

	private function entity(bool $enabled, ?string $csp = null, bool $rawOnly = false): RawShare {
		$e = new RawShare();
		$e->setShareId(5);
		$e->setEnabled($enabled);
		$e->setCsp($csp);
		$e->setRawOnly($rawOnly);
		return $e;
	}

	// == getMeta / isEnabled ==

	public function testGetMetaReturnsNullForUnknownShare(): void {
		$this->mapper->method('findByShareIdOrNull')->with(5)->willReturn(null);

		self::assertNull($this->registry->getMeta(5));
		self::assertFalse($this->registry->isEnabled(5));
	}

	public function testGetMetaReturnsNullForDisabledShare(): void {
		$this->mapper->method('findByShareIdOrNull')->willReturn($this->entity(false));

		self::assertNull($this->registry->getMeta(5));
		self::assertFalse($this->registry->isEnabled(5));
	}

	public function testGetMetaReturnsTheEnabledEntity(): void {
		$entity = $this->entity(true);
		$this->mapper->method('findByShareIdOrNull')->willReturn($entity);

		self::assertSame($entity, $this->registry->getMeta(5));
		self::assertTrue($this->registry->isEnabled(5));
	}

	// == getCsp / getStoredCsp ==

	public function testGetCspOnlyForEnabledShares(): void {
		$this->mapper->method('findByShareIdOrNull')->willReturn($this->entity(false, "default-src 'self'"));

		self::assertNull($this->registry->getCsp(5));
		// the stored CSP is still readable regardless of enabled state
		self::assertSame("default-src 'self'", $this->registry->getStoredCsp(5));
	}

	public function testGetCspForEnabledShare(): void {
		$this->mapper->method('findByShareIdOrNull')->willReturn($this->entity(true, "default-src 'self'"));

		self::assertSame("default-src 'self'", $this->registry->getCsp(5));
	}

	// == isRawOnly ==

	public function testIsRawOnlyRequiresEnabled(): void {
		$this->mapper->method('findByShareIdOrNull')->willReturn($this->entity(false, null, true));

		self::assertFalse($this->registry->isRawOnly(5));
	}

	public function testIsRawOnlyForEnabledRawOnlyShare(): void {
		$this->mapper->method('findByShareIdOrNull')->willReturn($this->entity(true, null, true));

		self::assertTrue($this->registry->isRawOnly(5));
	}

	// == enable ==

	public function testEnableUpsertsWithNormalizedCsp(): void {
		$this->time->method('getTime')->willReturn(1000);
		$this->mapper->expects(self::once())
			->method('upsert')
			->with(5, true, "default-src 'self'", 1000, false)
			->willReturn($this->entity(true, "default-src 'self'"));

		$this->registry->enable(5, "  default-src \x01 'self'  ");
	}

	public function testEnableTreatsBlankCspAsNull(): void {
		$this->time->method('getTime')->willReturn(1000);
		$this->mapper->expects(self::once())
			->method('upsert')
			->with(5, true, null, 1000, true)
			->willReturn($this->entity(true, null, true));

		$this->registry->enable(5, '   ', true);
	}

	public function testEnableCapsOversizedCsp(): void {
		$this->time->method('getTime')->willReturn(1000);
		$this->mapper->expects(self::once())
			->method('upsert')
			->with(5, true, self::callback(static fn (string $csp): bool => strlen($csp) === 8192), 1000, false)
			->willReturn($this->entity(true));

		$this->registry->enable(5, str_repeat('a', 10000));
	}

	// == disable ==

	public function testDisableIsANoOpForUnknownShares(): void {
		$this->mapper->method('findByShareIdOrNull')->willReturn(null);
		$this->mapper->expects(self::never())->method('upsert');

		$this->registry->disable(5);
	}

	public function testDisablePreservesCspAndRawOnly(): void {
		$this->time->method('getTime')->willReturn(2000);
		$this->mapper->method('findByShareIdOrNull')->willReturn($this->entity(true, "default-src 'self'", true));
		$this->mapper->expects(self::once())
			->method('upsert')
			->with(5, false, "default-src 'self'", 2000, true)
			->willReturn($this->entity(false, "default-src 'self'", true));

		$this->registry->disable(5);
	}

	// == purge ==

	public function testPurgeDeletesTheRow(): void {
		$this->mapper->expects(self::once())->method('deleteByShareId')->with(5);

		$this->registry->purge(5);
	}
}
