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

	public function __construct(IConfig $config, IURLGenerator $url, LoggerInterface $logger) {
		$this->config = $config;
		$this->url = $url;
		$this->logger = $logger;
	}

	public function hasRootAliases(): bool {
		// Detect whether root aliases are active by probing route generation.
		// If RouteParser.php lists files_sharing_raw in its rootUrlApps constant,
		// linkToRoute returns a /raw/... URL; otherwise it falls back to /apps/files_sharing_raw/...
		try {
			$url = $this->url->linkToRoute(
				'files_sharing_raw.pubPage.getByTokenRoot',
				['token' => 'probe']
			);
			$result = \str_contains($url, '/raw/probe');
			$this->logger->warning(
				'[files_sharing_raw] hasRootAliases probe: url={url} result={result}',
				['url' => $url, 'result' => $result ? 'true' : 'false']
			);
			return $result;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[files_sharing_raw] hasRootAliases probe failed: {error}',
				['error' => $e->getMessage()]
			);
			return false;
		}
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
		// The app requires the /raw/ root alias (files_sharing_raw in rootUrlApps).
		// Non-root routes were removed in 0.5.0; without the root alias no valid URL exists.
		if (!$this->hasRootAliases()) {
			$this->logger->warning(
				'[files_sharing_raw] publicTokenUrl: no root aliases active, returning empty URL for token={token}',
				['token' => $token]
			);
			return '';
		}

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
