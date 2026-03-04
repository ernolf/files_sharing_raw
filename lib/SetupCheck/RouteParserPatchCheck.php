<?php
/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\FilesSharingRaw\SetupCheck;

use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

class RouteParserPatchCheck implements ISetupCheck {

	private IL10N $l10n;

	public function __construct(IFactory $l10nFactory) {
		$this->l10n = $l10nFactory->get('files_sharing_raw');
	}

	public function getName(): string {
		return $this->l10n->t('Raw file server: /raw/ URL alias');
	}

	public function getCategory(): string {
		return 'files_sharing_raw';
	}

	public function run(): SetupResult {
		$path = \OC::$SERVERROOT . '/lib/private/AppFramework/Routing/RouteParser.php';

		if (!is_file($path)) {
			return SetupResult::warning(
				$this->l10n->t('RouteParser.php not found at the expected location.')
			);
		}

		$content = @file_get_contents($path);
		if ($content === false) {
			return SetupResult::warning(
				$this->l10n->t('RouteParser.php could not be read.')
			);
		}

		if (str_contains($content, "'files_sharing_raw'")) {
			return SetupResult::success();
		}

		return SetupResult::warning(
			$this->l10n->t(
				'\'files_sharing_raw\' is not registered in rootUrlApps. '
				. 'Run patch-route-parser.sh from the app directory to enable '
				. 'clean /raw/<token> URLs. Without this patch, longer '
				. '/apps/files_sharing_raw/<token> URLs are used as fallback. {link}'
			),
			null,
			[
				'link' => [
					'type' => 'highlight',
					'id' => 'patch-instructions',
					'name' => $this->l10n->t('Read more…'),
					'link' => 'https://github.com/ernolf/files_sharing_raw/blob/main/Readme.md#activating-root-alias-urls-raw',
				],
			]
		);
	}
}
