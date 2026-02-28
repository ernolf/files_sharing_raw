<?php
/**
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\FilesSharingRaw\Middleware;

use OCA\FilesSharingRaw\Db\RawShareMapper;
use OCP\AppFramework\Middleware;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Global middleware that blocks access to the standard Nextcloud share page (/s/{token})
 * when the share is flagged as raw-only — either via the DB registry or via config
 * (raw_only_tokens / raw_only_token_wildcards).
 *
 * Note: being listed in raw_only_tokens does NOT grant /raw/ access on its own.
 * That is still controlled separately by allowed_raw_tokens / allowed_raw_token_wildcards
 * or by the DB-enabled flag set through the UI.
 */
class ShareRawOnlyMiddleware extends Middleware {
	public function __construct(
		private IRequest $request,
		private RawShareMapper $mapper,
		private IConfig $config,
	) {}

	public function beforeController($controller, $methodName): void {
		$path = $this->request->getPathInfo();
		if (!is_string($path)) {
			return;
		}

		// Match /s/{token} or /s/{token}/download and similar NC public share paths.
		if (!preg_match('#^/s/([^/?]+)#', $path, $m)) {
			return;
		}

		$token = $m[1];

		if ($this->mapper->isRawOnlyByToken($token) || $this->isRawOnlyByConfig($token)) {
			throw new NotFoundException('Raw-only share');
		}
	}

	/**
	 * Check whether a token is marked as raw-only via system config arrays.
	 * Uses the same matching logic as PubPageController::isAllowedByConfig().
	 */
	private function isRawOnlyByConfig(string $token): bool {
		$exactTokens = $this->config->getSystemValue('raw_only_tokens', []);
		if (in_array($token, $exactTokens, true)) {
			return true;
		}

		$wildcards = $this->config->getSystemValue('raw_only_token_wildcards', []);
		foreach ($wildcards as $wildcard) {
			$pattern = '/^' . str_replace('\*', '.*', preg_quote($wildcard, '/')) . '$/';
			if (preg_match($pattern, $token)) {
				return true;
			}
		}

		return false;
	}
}
