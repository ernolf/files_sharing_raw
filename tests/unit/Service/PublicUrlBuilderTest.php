<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSharingRaw\Tests\Unit\Service;

use OCA\FilesSharingRaw\Service\PublicUrlBuilder;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PublicUrlBuilderTest extends TestCase {
	private IConfig&MockObject $config;
	private IURLGenerator&MockObject $url;
	private LoggerInterface&MockObject $logger;
	private PublicUrlBuilder $builder;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->url = $this->createMock(IURLGenerator::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->builder = new PublicUrlBuilder($this->config, $this->url, $this->logger);
	}

	// == hasRootAliases ==

	public function testRootAliasesDetectedFromProbeUrl(): void {
		$this->url->expects(self::once())
			->method('linkToRoute')
			->with('files_sharing_raw.pubPage.getByTokenRoot', ['token' => 'probe'])
			->willReturn('/raw/probe');

		self::assertTrue($this->builder->hasRootAliases());
		// second call is served from the cache: linkToRoute ran exactly once
		self::assertTrue($this->builder->hasRootAliases());
	}

	public function testRootAliasesAbsentWhenProbeFallsBackToAppRoute(): void {
		$this->url->method('linkToRoute')->willReturn('/apps/files_sharing_raw/probe');

		self::assertFalse($this->builder->hasRootAliases());
	}

	public function testProbeFailureIsLoggedAndTreatedAsAbsent(): void {
		$this->url->method('linkToRoute')->willThrowException(new \RuntimeException('no route'));
		$this->logger->expects(self::once())->method('warning');

		self::assertFalse($this->builder->hasRootAliases());
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

	public function testRssUrlUsesRootRouteWhenAliasesAreActive(): void {
		$this->url->method('linkToRoute')->willReturnMap([
			['files_sharing_raw.pubPage.getByTokenRoot', ['token' => 'probe'], '/raw/probe'],
			['files_sharing_raw.pubPage.getRssRoot', [], '/rss'],
		]);

		self::assertSame('/rss', $this->builder->rssUrl());
	}

	public function testRssUrlWithPathWhenAliasesAreActive(): void {
		$this->url->method('linkToRoute')->willReturnMap([
			['files_sharing_raw.pubPage.getByTokenRoot', ['token' => 'probe'], '/raw/probe'],
			['files_sharing_raw.pubPage.getRssRootPath', ['path' => 'feed.xml'], '/rss/feed.xml'],
		]);

		self::assertSame('/rss/feed.xml', $this->builder->rssUrl('feed.xml'));
	}

	public function testRssUrlFallsBackToTokenUrlWithoutAliases(): void {
		$this->url->method('linkToRoute')->willReturn('/apps/files_sharing_raw/probe');
		$this->url->expects(self::once())
			->method('linkToRouteAbsolute')
			->with('files_sharing_raw.pubPage.getByTokenAndPathRoot', ['token' => 'rss', 'path' => 'feed.xml'])
			->willReturn('https://cloud.example/apps/files_sharing_raw/rss/feed.xml');

		self::assertSame('https://cloud.example/apps/files_sharing_raw/rss/feed.xml', $this->builder->rssUrl('feed.xml'));
	}
}
