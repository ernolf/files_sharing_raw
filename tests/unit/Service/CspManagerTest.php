<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSharingRaw\Tests\Unit\Service;

use OCA\FilesSharingRaw\Service\CspManager;
use OCA\FilesSharingRaw\Service\RawShareRegistry;
use OCP\IConfig;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CspManagerTest extends TestCase {
	private IConfig&MockObject $config;
	private IManager&MockObject $shareManager;
	private RawShareRegistry&MockObject $registry;
	private CspManager $manager;
	/** @var string|null saved REQUEST_URI to restore in tearDown */
	private ?string $savedRequestUri = null;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->shareManager = $this->createMock(IManager::class);
		$this->registry = $this->createMock(RawShareRegistry::class);
		$this->manager = new CspManager($this->config, $this->shareManager, $this->registry);
		$this->savedRequestUri = $_SERVER['REQUEST_URI'] ?? null;
	}

	protected function tearDown(): void {
		if ($this->savedRequestUri === null) {
			unset($_SERVER['REQUEST_URI']);
		} else {
			$_SERVER['REQUEST_URI'] = $this->savedRequestUri;
		}
		parent::tearDown();
	}

	private function fileNode(string $name = 'file.txt', string $mime = 'text/plain'): object {
		return new class($name, $mime) {
			public function __construct(
				private string $name,
				private string $mime,
			) {
			}
			public function getName(): string {
				return $this->name;
			}
			public function getMimeType(): string {
				return $this->mime;
			}
		};
	}

	private function withRawCsp(array $rawCsp): void {
		$this->config->method('getSystemValue')
			->with('raw_csp', [])
			->willReturn($rawCsp);
	}

	// == Fallback ==

	public function testEmptyConfigReturnsHardFallback(): void {
		$_SERVER['REQUEST_URI'] = '/raw/aBc123/file.txt';
		$this->withRawCsp([]);

		self::assertSame(CspManager::HARD_FALLBACK, $this->manager->determineCspForRequest($this->fileNode()));
	}

	public function testNoMatchReturnsHardFallback(): void {
		$_SERVER['REQUEST_URI'] = '/raw/aBc123/file.txt';
		$this->withRawCsp(['token' => ['other' => "default-src 'self'"]]);

		self::assertSame(CspManager::HARD_FALLBACK, $this->manager->determineCspForRequest($this->fileNode()));
	}

	public function testSetHardFallbackOverridesTheDefault(): void {
		$_SERVER['REQUEST_URI'] = '/raw/aBc123/file.txt';
		$this->withRawCsp([]);
		$this->manager->setHardFallback("default-src 'none'");

		self::assertSame("default-src 'none'", $this->manager->determineCspForRequest($this->fileNode()));
	}

	// == Token matching ==

	public function testExactTokenMatchHasHighestPriority(): void {
		$_SERVER['REQUEST_URI'] = '/raw/aBc123/html/file.html';
		$this->withRawCsp([
			'token' => ['aBc123' => "default-src 'self'"],
			'extension' => ['html' => "img-src data:"],
		]);

		self::assertSame("default-src 'self'", $this->manager->determineCspForRequest($this->fileNode('file.html', 'text/html')));
	}

	public function testRootAliasAndLongUrlFormMatchTheSameTokenRule(): void {
		$this->withRawCsp(['token' => ['aBc123' => "default-src 'self'"]]);

		$_SERVER['REQUEST_URI'] = '/raw/aBc123/file.txt';
		self::assertSame("default-src 'self'", $this->manager->determineCspForRequest($this->fileNode()));

		$_SERVER['REQUEST_URI'] = '/apps/files_sharing_raw/aBc123/file.txt';
		self::assertSame("default-src 'self'", $this->manager->determineCspForRequest($this->fileNode()));
	}

	public function testRssRootUrlMatchesTheRssTokenRule(): void {
		$_SERVER['REQUEST_URI'] = '/rss/feed.xml';
		$this->withRawCsp(['token' => ['rss' => "default-src 'none'; style-src 'unsafe-inline'"]]);

		self::assertSame("default-src 'none'; style-src 'unsafe-inline'", $this->manager->determineCspForRequest($this->fileNode('feed.xml', 'application/xml')));
	}

	public function testDbCspIsUsedForRawEnabledShares(): void {
		$_SERVER['REQUEST_URI'] = '/raw/aBc123/file.txt';
		$this->withRawCsp(['extension' => ['txt' => "img-src data:"]]);

		$share = $this->createMock(IShare::class);
		$share->method('getShareType')->willReturn(IShare::TYPE_LINK);
		$share->method('getId')->willReturn('42');
		$this->shareManager->method('getShareByToken')->with('aBc123')->willReturn($share);
		$this->registry->method('isEnabled')->with(42)->willReturn(true);
		$this->registry->method('getCsp')->with(42)->willReturn("default-src  'self'");

		// DB CSP wins over the extension rule and is sanitized (double space collapsed)
		self::assertSame("default-src 'self'", $this->manager->determineCspForRequest($this->fileNode()));
	}

	public function testDbCspIsIgnoredForDisabledShares(): void {
		$_SERVER['REQUEST_URI'] = '/raw/aBc123/file.txt';
		$this->withRawCsp(['extension' => ['txt' => "img-src data:"]]);

		$share = $this->createMock(IShare::class);
		$share->method('getShareType')->willReturn(IShare::TYPE_LINK);
		$share->method('getId')->willReturn('42');
		$this->shareManager->method('getShareByToken')->willReturn($share);
		$this->registry->method('isEnabled')->with(42)->willReturn(false);

		self::assertSame('img-src data:', $this->manager->determineCspForRequest($this->fileNode()));
	}

	public function testShareLookupFailureNeverThrows(): void {
		$_SERVER['REQUEST_URI'] = '/raw/aBc123/file.txt';
		$this->withRawCsp(['extension' => ['txt' => "img-src data:"]]);
		$this->shareManager->method('getShareByToken')->willThrowException(new \Exception('gone'));

		self::assertSame('img-src data:', $this->manager->determineCspForRequest($this->fileNode()));
	}

	// == Path prefix matching ==

	public function testAbsolutePathPrefixMatches(): void {
		$_SERVER['REQUEST_URI'] = '/raw/aBc123/html/sub/page.html';
		$this->withRawCsp([
			'path_prefix' => ['/apps/files_sharing_raw/aBc123/html' => "default-src 'self'"],
		]);

		self::assertSame("default-src 'self'", $this->manager->determineCspForRequest($this->fileNode('page.html', 'text/html')));
	}

	public function testRelativePathPrefixMatchesAfterTheToken(): void {
		$_SERVER['REQUEST_URI'] = '/raw/aBc123/html/page.html';
		$this->withRawCsp([
			'path_prefix' => ['html/' => "default-src 'self'"],
		]);

		self::assertSame("default-src 'self'", $this->manager->determineCspForRequest($this->fileNode('page.html', 'text/html')));
	}

	public function testLongestPathPrefixWins(): void {
		$_SERVER['REQUEST_URI'] = '/raw/aBc123/html/sub/page.html';
		$this->withRawCsp([
			'path_prefix' => [
				'/html/' => "img-src data:",
				'/html/sub/' => "default-src 'self'",
			],
		]);

		self::assertSame("default-src 'self'", $this->manager->determineCspForRequest($this->fileNode('page.html', 'text/html')));
	}

	public function testPrivateUrlMatchesRelativePrefixAndSkipsTokenLogic(): void {
		$_SERVER['REQUEST_URI'] = '/raw/u/anansi/Documents/page.html';
		$this->withRawCsp([
			'path_prefix' => ['/Documents/' => "default-src 'self'"],
		]);
		$this->shareManager->expects(self::never())->method('getShareByToken');

		self::assertSame("default-src 'self'", $this->manager->determineCspForRequest($this->fileNode('page.html', 'text/html')));
	}

	// == path_contains matching ==

	public function testPathContainsVerbatimSlashPattern(): void {
		$_SERVER['REQUEST_URI'] = '/raw/aBc123/some/html/deep/page.html';
		$this->withRawCsp(['path_contains' => ['/html/' => "default-src 'self'"]]);

		self::assertSame("default-src 'self'", $this->manager->determineCspForRequest($this->fileNode('page.html', 'text/html')));
	}

	public function testPathContainsPlainSubstringPattern(): void {
		$_SERVER['REQUEST_URI'] = '/raw/aBc123/my_htmlfiles/page.txt';
		$this->withRawCsp(['path_contains' => ['html' => "default-src 'self'"]]);

		self::assertSame("default-src 'self'", $this->manager->determineCspForRequest($this->fileNode()));
	}

	// == Extension and mimetype matching ==

	public function testExtensionMatchIsCaseInsensitive(): void {
		$_SERVER['REQUEST_URI'] = '/raw/aBc123/PAGE.HTML';
		$this->withRawCsp(['extension' => ['html' => "default-src 'self'"]]);

		self::assertSame("default-src 'self'", $this->manager->determineCspForRequest($this->fileNode('PAGE.HTML', 'text/html')));
	}

	public function testMimetypeMatch(): void {
		$_SERVER['REQUEST_URI'] = '/raw/aBc123/image';
		$this->withRawCsp(['mimetype' => ['image/png' => 'img-src data:']]);

		self::assertSame('img-src data:', $this->manager->determineCspForRequest($this->fileNode('image', 'image/png')));
	}

	public function testExtensionBeatsMimetype(): void {
		$_SERVER['REQUEST_URI'] = '/raw/aBc123/page.html';
		$this->withRawCsp([
			'extension' => ['html' => "default-src 'self'"],
			'mimetype' => ['text/html' => 'img-src data:'],
		]);

		self::assertSame("default-src 'self'", $this->manager->determineCspForRequest($this->fileNode('page.html', 'text/html')));
	}

	// == buildCspFromPolicy ==

	public function testBuildPolicyFromString(): void {
		self::assertSame("default-src 'self'", $this->manager->buildCspFromPolicy("default-src  'self'"));
	}

	public function testBuildPolicyFromIndexedArray(): void {
		self::assertSame(
			"default-src 'self'; img-src data:",
			$this->manager->buildCspFromPolicy(["default-src 'self'", 'img-src data:'])
		);
	}

	public function testBuildPolicyFromAssociativeArrayUsesCanonicalOrder(): void {
		$policy = [
			'img-src' => 'data:',
			'default-src' => "'none'",
			'sandbox' => '',
		];

		self::assertSame("sandbox; default-src 'none'; img-src data:", $this->manager->buildCspFromPolicy($policy));
	}

	public function testBuildPolicySkipsUnknownDirectives(): void {
		$policy = [
			'default-src' => "'none'",
			'not-a-directive' => 'x',
		];

		self::assertSame("default-src 'none'", $this->manager->buildCspFromPolicy($policy));
	}

	public function testBuildPolicyDeduplicatesSources(): void {
		$policy = ['img-src' => ['data:', 'data:', 'blob:']];

		self::assertSame('img-src data: blob:', $this->manager->buildCspFromPolicy($policy));
	}

	// == sanitizeCspString ==

	public function testSanitizeRemovesControlCharsAndCollapsesWhitespace(): void {
		self::assertSame('a b c', $this->manager->sanitizeCspString("  a\x01b   c\x7F "));
	}
}
