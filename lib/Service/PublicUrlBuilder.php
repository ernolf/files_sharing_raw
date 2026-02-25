<?php
/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\FilesSharingRaw\Service;

use OCP\IConfig;
use OCP\IURLGenerator;

class PublicUrlBuilder {
	/** @var IConfig */
	private $config;
	/** @var IURLGenerator */
	private $url;

	public function __construct(IConfig $config, IURLGenerator $url) {
		$this->config = $config;
		$this->url = $url;
	}

	public function hasRootAliases(): bool {
		$rootUrlApps = $this->config->getSystemValue('rootUrlApps', []);
		return \is_array($rootUrlApps) && \in_array('files_sharing_raw', $rootUrlApps, true);
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
		$hasRoot = $this->hasRootAliases();

		if ($path === '') {
			$route = $hasRoot ? 'files_sharing_raw.pubPage.getByTokenRoot' : 'files_sharing_raw.pubPage.getByTokenWithoutS';
			return $this->url->linkToRoute($route, ['token' => $token]);
		}

		$route = $hasRoot ? 'files_sharing_raw.pubPage.getByTokenAndPathRoot' : 'files_sharing_raw.pubPage.getByTokenAndPathWithoutS';
		return $this->url->linkToRoute($route, ['token' => $token, 'path' => $path]);
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
