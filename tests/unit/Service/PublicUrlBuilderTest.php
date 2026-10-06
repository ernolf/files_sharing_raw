<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSharingRaw\Tests\Unit\Service;

use OCA\FilesSharingRaw\Service\PublicUrlBuilder;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PublicUrlBuilderTest extends TestCase {
	private IURLGenerator&MockObject $url;
	private PublicUrlBuilder $builder;

	protected function setUp(): void {
		parent::setUp();
		$this->url = $this->createMock(IURLGenerator::class);
		$this->builder = new PublicUrlBuilder($this->url);
	}

	// == publicTokenUrl ==

	public function testPublicTokenUrlWithoutPath(): void {
		$this->url->expects(self::once())
			->method('linkToRouteAbsolute')
			->with('files_sharing_raw.pubPage.getByTokenRoot', ['token' => 'aBc123'])
			->willReturn('https://cloud.example/raw/aBc123');

		self::assertSame('https://cloud.example/raw/aBc123', $this->builder->publicTokenUrl('aBc123'));
	}

	public function testPublicTokenUrlWithPath(): void {
		$this->url->expects(self::once())
			->method('linkToRouteAbsolute')
			->with('files_sharing_raw.pubPage.getByTokenAndPathRoot', ['token' => 'aBc123', 'path' => 'sub/file.txt'])
			->willReturn('https://cloud.example/raw/aBc123/sub/file.txt');

		self::assertSame('https://cloud.example/raw/aBc123/sub/file.txt', $this->builder->publicTokenUrl('aBc123', 'sub/file.txt'));
	}

	public function testRawPathDelegatesToPublicTokenUrl(): void {
		$this->url->method('linkToRouteAbsolute')->willReturn('https://cloud.example/raw/aBc123');

		self::assertSame('https://cloud.example/raw/aBc123', $this->builder->rawPath('aBc123'));
	}

	// == rssUrl ==

	public function testRssUrlWithoutPath(): void {
		$this->url->expects(self::once())
			->method('linkToRoute')
			->with('files_sharing_raw.pubPage.getRssRoot')
			->willReturn('/rss');

		self::assertSame('/rss', $this->builder->rssUrl());
	}

	public function testRssUrlWithPath(): void {
		$this->url->expects(self::once())
			->method('linkToRoute')
			->with('files_sharing_raw.pubPage.getRssRootPath', ['path' => 'feed.xml'])
			->willReturn('/rss/feed.xml');

		self::assertSame('/rss/feed.xml', $this->builder->rssUrl('feed.xml'));
	}
}
