<?php

/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSharingRaw\Service;

use OCP\IURLGenerator;

class PublicUrlBuilder {
	/** @var IURLGenerator */
	private $url;

	public function __construct(IURLGenerator $url) {
		$this->url = $url;
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
		if ($path === '') {
			return $this->url->linkToRouteAbsolute('files_sharing_raw.pubPage.getByTokenRoot', ['token' => $token]);
		}

		return $this->url->linkToRouteAbsolute('files_sharing_raw.pubPage.getByTokenAndPathRoot', ['token' => $token, 'path' => $path]);
	}

	public function rssUrl(string $path = ''): string {
		if ($path === '') {
			return $this->url->linkToRoute('files_sharing_raw.pubPage.getRssRoot');
		}
		return $this->url->linkToRoute('files_sharing_raw.pubPage.getRssRootPath', ['path' => $path]);
	}
}
