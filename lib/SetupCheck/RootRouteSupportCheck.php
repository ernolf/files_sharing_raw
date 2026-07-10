<?php

/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSharingRaw\SetupCheck;

use OCA\FilesSharingRaw\Service\PublicUrlBuilder;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Feature detection for the root URL aliases (/raw, /rss).
 *
 * The core grants files_sharing_raw its root routes since Nextcloud 32.0.8 and
 * 33.0.2 (rootUrlApps in RouteParser.php). A version check cannot express that
 * requirement (33.0.0 and 33.0.1 satisfy any min-version but lack the backport),
 * so the support is probed at runtime through the route generator instead.
 */
class RootRouteSupportCheck implements ISetupCheck {

	private IL10N $l10n;
	private PublicUrlBuilder $urlBuilder;

	public function __construct(IFactory $l10nFactory, PublicUrlBuilder $urlBuilder) {
		$this->l10n = $l10nFactory->get('files_sharing_raw');
		$this->urlBuilder = $urlBuilder;
	}

	public function getName(): string {
		return $this->l10n->t('Raw file server: /raw/ URL alias');
	}

	public function getCategory(): string {
		return 'files_sharing_raw';
	}

	public function run(): SetupResult {
		if ($this->urlBuilder->hasRootAliases()) {
			return SetupResult::success();
		}

		return SetupResult::warning(
			$this->l10n->t(
				'This Nextcloud does not grant files_sharing_raw its root routes yet, '
				. 'so links use the long /apps/files_sharing_raw/… form as fallback. '
				. 'Upgrade to Nextcloud 32.0.8 / 33.0.2 or later for the clean /raw/… URLs.'
			)
		);
	}
}
