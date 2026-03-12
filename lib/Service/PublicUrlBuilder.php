<?php
/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\FilesSharingRaw\Service;

use OCP\IConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class PublicUrlBuilder {
	/** @var IConfig */
	private $config;
	/** @var IURLGenerator */
	private $url;
	/** @var LoggerInterface */
	private $logger;
	/** @var bool|null cached result of hasRootAliases() probe */
	private ?bool $rootAliasesCache = null;

	public function __construct(IConfig $config, IURLGenerator $url, LoggerInterface $logger) {
		$this->config = $config;
		$this->url = $url;
		$this->logger = $logger;
	}

	public function hasRootAliases(): bool {
		if ($this->rootAliasesCache !== null) {
			return $this->rootAliasesCache;
		}
		// Detect whether root aliases are active by probing route generation.
		// If RouteParser.php lists files_sharing_raw in its rootUrlApps constant,
		// linkToRoute returns a /raw/... URL; otherwise it falls back to /apps/files_sharing_raw/...
		try {
			$url = $this->url->linkToRoute(
				'files_sharing_raw.pubPage.getByTokenRoot',
				['token' => 'probe']
			);
			$this->rootAliasesCache = \str_contains($url, '/raw/probe');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[files_sharing_raw] hasRootAliases probe failed: {error}',
				['error' => $e->getMessage()]
			);
			$this->rootAliasesCache = false;
		}
		return $this->rootAliasesCache;
	}

	/**
	 * Build a canonical raw path for redirects (string, may be relative to instance).
	 */
	public function rawPath(string $token, string $path = ''): string {
		return $this->publicTokenUrl($token, $path);
	}

	/**
	 * Build a canonical rss path for redirects (string, may be relative to instance).
	 */
	public function rssPath(string $path = ''): string {
		return $this->rssUrl($path);
	}

	public function publicTokenUrl(string $token, string $path = ''): string {
		// When files_sharing_raw is listed in rootUrlApps (core patch applied), Nextcloud
		// registers the route at /raw/{token}. Without the patch, it falls back to
		// /apps/files_sharing_raw/{token}. In both cases linkToRouteAbsolute returns the
		// correct absolute URL — no guard needed here.
		// Once root aliases are active, redirectCanonicalIfNeeded() issues a 307 for any
		// request still arriving via the long /apps/files_sharing_raw/... path.
		if ($path === '') {
			return $this->url->linkToRouteAbsolute('files_sharing_raw.pubPage.getByTokenRoot', ['token' => $token]);
		}

		return $this->url->linkToRouteAbsolute('files_sharing_raw.pubPage.getByTokenAndPathRoot', ['token' => $token, 'path' => $path]);
	}

	public function rssUrl(string $path = ''): string {
		if ($this->hasRootAliases()) {
			if ($path === '') {
				return $this->url->linkToRoute('files_sharing_raw.pubPage.getRssRoot');
			}
			return $this->url->linkToRoute('files_sharing_raw.pubPage.getRssRootPath', ['path' => $path]);
		}
		// Fallback: /apps/raw/rss[/...]
		return $this->publicTokenUrl('rss', $path);
	}
}
