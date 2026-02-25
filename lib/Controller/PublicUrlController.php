<?php
/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\FilesSharingRaw\Controller;

use OCA\FilesSharingRaw\Service\PublicUrlBuilder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;

class PublicUrlController extends Controller {
	private PublicUrlBuilder $builder;

	public function __construct(string $appName, IRequest $request, PublicUrlBuilder $builder) {
		parent::__construct($appName, $request);
		$this->builder = $builder;
	}

	#[NoAdminRequired]
	public function getTokenUrl(string $token = '', string $path = ''): DataResponse {
		$token = trim((string)$token);
		$path = (string)$path;

		if ($token === '') {
			return new DataResponse(['ok' => false, 'error' => 'missing token'], 400);
		}

		// Keep token constraints strict to avoid weird edge cases.
		if (!preg_match('/^[A-Za-z0-9-]+$/', $token)) {
			return new DataResponse(['ok' => false, 'error' => 'invalid token'], 400);
		}

		$url = $this->builder->publicTokenUrl($token, $path);
		return new DataResponse([
			'ok' => true,
			'url' => $url,
			'hasRoot' => $this->builder->hasRootAliases(),
		]);
	}
}
