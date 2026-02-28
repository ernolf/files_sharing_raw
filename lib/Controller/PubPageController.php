<?php
/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-FileCopyrightText: 2018-2019 Gerben
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\FilesSharingRaw\Controller;

use OCA\FilesSharingRaw\Service\CspManager;
use OCA\FilesSharingRaw\Service\PublicUrlBuilder;
use OCA\FilesSharingRaw\Service\RawShareRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\Share\IManager;
use OCP\Share\IShare;

class PubPageController extends Controller {
	use RawResponse;

	/** @var IManager */
	private $shareManager;
	/** @var IConfig */
	private $config;
	/** @var PublicUrlBuilder */
	private $publicUrlBuilder;
	/** @var CspManager */
	protected $cspManager;
	/** @var RawShareRegistry */
	private $rawRegistry;

	private function plainNotFound() {
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
			ini_set('session.use_cookies', 0);
		}
		header_remove('Set-Cookie');
		header('Content-Type: text/plain; charset=utf-8');
		header('Cache-Control: no-store, max-age=0');
		header('Content-Length: 9');
		http_response_code(404);
		echo 'Not found';
		exit;
	}

	public function __construct(
		$appName,
		IRequest $request,
		IManager $shareManager,
		IConfig $config,
		CspManager $cspManager,
		PublicUrlBuilder $publicUrlBuilder,
		RawShareRegistry $rawRegistry
	) {
		parent::__construct($appName, $request);
		$this->shareManager = $shareManager;
		$this->config = $config;
		$this->cspManager = $cspManager;
		$this->publicUrlBuilder = $publicUrlBuilder;
		$this->rawRegistry = $rawRegistry;
	}

	private function redirectCanonicalIfNeeded(string $token, ?string $path): void {
		if (!$this->publicUrlBuilder->hasRootAliases()) {
			return;
		}

		$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
		if ($method !== 'GET' && $method !== 'HEAD') {
			return;
		}

		$uri = $_SERVER['REQUEST_URI'] ?? '';
		$reqPath = parse_url($uri, PHP_URL_PATH);
		if ($reqPath === null || $reqPath === false) {
			$reqPath = $uri;
		}
		if (strpos((string)$reqPath, '/apps/files_sharing_raw') !== 0) {
			return;
		}

		if ($token === 'rss') {
			$target = $this->publicUrlBuilder->rssPath((string)($path ?? ''));
		} else {
			$target = $this->publicUrlBuilder->rawPath($token, (string)($path ?? ''));
		}
		$qs = parse_url($uri, PHP_URL_QUERY);
		if (is_string($qs) && $qs !== '') {
			$target .= '?' . $qs;
		}
		header('Location: ' . $target, true, 301);
		exit;
	}

	private function isAllowedByConfig(string $token): bool {
		// Load allowed tokens and wildcards from config
		$allowedTokens = $this->config->getSystemValue('allowed_raw_tokens', []);
		$allowedWildcards = $this->config->getSystemValue('allowed_raw_token_wildcards', []);

		// Direct match check
		if (in_array($token, $allowedTokens, true)) {
			return true;
		}

		// Wildcard match check
		foreach ($allowedWildcards as $wildcard) {
			// Replace '*' with a regex pattern to match any number of any characters
			$pattern = '/^' . str_replace('\*', '.*', preg_quote($wildcard, '/')) . '$/';

			if (preg_match($pattern, $token)) {
				return true;
			}
		}

		return false;
	}

	private function isAllowedShare(\OCP\Share\IShare $share, string $token): bool {
		// Config ALWAYS has top priority (tokens + wildcards).
		if ($this->isAllowedByConfig($token)) {
			return true;
		}
		// DB is additive only (UI checkbox creates the DB entry).
		return $this->rawRegistry->isEnabled((int)$share->getId());
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getByToken($token) {
		try {
			$share = $this->shareManager->getShareByToken($token);
			if ($share->getShareType() !== IShare::TYPE_LINK) {
				$this->plainNotFound();
			}
			if (!$this->isAllowedShare($share, (string)$token)) {
				$this->plainNotFound();
			}
			$this->redirectCanonicalIfNeeded((string)$token, null);
			$node = $share->getNode();
		} catch (\Throwable $e) {
			$this->plainNotFound();
		}
		$this->returnRawResponse($node);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getByTokenWithoutS($token) {
		return $this->getByToken($token);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getByTokenRoot($token) {
		// Wrapper for root alias /raw/{token}, keeps legacy /apps/raw/{token} intact
		return $this->getByTokenWithoutS($token);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getByTokenRootLegacyS($token) {
		// Wrapper for legacy root alias /raw/s/{token}
		return $this->getByTokenWithoutS($token);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getByTokenAndPath($token, $path) {
		try {
			$share = $this->shareManager->getShareByToken($token);
			if ($share->getShareType() !== IShare::TYPE_LINK) {
				$this->plainNotFound();
			}
			if (!$this->isAllowedShare($share, (string)$token)) {
				$this->plainNotFound();
			}
			$this->redirectCanonicalIfNeeded((string)$token, (string)$path);
			$dirNode = $share->getNode();
		} catch (\Throwable $e) {
			$this->plainNotFound();
		}
		if ($dirNode->getType() !== 'dir') {
			// Do not leak details; behave like a plain raw miss.
			$this->plainNotFound();
		}
		try {
			$fileNode = $dirNode->get($path);
		} catch (NotFoundException $e) {
			$this->plainNotFound();
		}
		$this->returnRawResponse($fileNode);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getByTokenAndPathWithoutS($token, $path) {
		return $this->getByTokenAndPath($token, $path);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getByTokenAndPathRoot($token, $path) {
		// Wrapper for root alias /raw/{token}/{path}
		return $this->getByTokenAndPathWithoutS($token, $path);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getByTokenAndPathRootLegacyS($token, $path) {
		// Wrapper for legacy root alias /raw/s/{token}/{path}
		return $this->getByTokenAndPathWithoutS($token, $path);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getRssRoot() {
		// Root namespace alias: /rss -> behaves like /raw/rss
		return $this->getByTokenRoot('rss');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function getRssRootPath($path = '') {
		// Root namespace alias: /rss/{path} -> behaves like /raw/rss/{path}
		if ($path === '' || $path === null) {
			return $this->getByTokenRoot('rss');
		}
		return $this->getByTokenAndPathRoot('rss', (string)$path);
	}
}
