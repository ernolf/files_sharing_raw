<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSharingRaw\Tests\Unit\Db;

use OCA\FilesSharingRaw\Db\RawShare;
use PHPUnit\Framework\TestCase;

class RawShareTest extends TestCase {
	public function testDefaults(): void {
		$e = new RawShare();

		self::assertSame(0, $e->getShareId());
		self::assertTrue($e->isEnabled());
		self::assertFalse($e->isRawOnly());
		self::assertNull($e->getCsp());
		self::assertSame(0, $e->getCreatedAt());
		self::assertSame(0, $e->getUpdatedAt());
	}

	public function testSettersReturnSelfAndStoreValues(): void {
		$e = new RawShare();
		$r = $e->setShareId(7)
			->setEnabled(false)
			->setRawOnly(true)
			->setCreatedAt(100)
			->setUpdatedAt(200);

		self::assertSame($e, $r);
		self::assertSame(7, $e->getShareId());
		self::assertFalse($e->isEnabled());
		self::assertTrue($e->isRawOnly());
		self::assertSame(100, $e->getCreatedAt());
		self::assertSame(200, $e->getUpdatedAt());
	}

	public function testCspIsTrimmed(): void {
		$e = new RawShare();
		$e->setCsp("  default-src 'self'  ");

		self::assertSame("default-src 'self'", $e->getCsp());
	}

	public function testBlankCspBecomesNull(): void {
		$e = new RawShare();
		$e->setCsp('   ');

		self::assertNull($e->getCsp());

		$e->setCsp(null);
		self::assertNull($e->getCsp());
	}
}
