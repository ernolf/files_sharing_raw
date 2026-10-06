<?php

/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-FileCopyrightText: 2018-2019 Gerben
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSharingRaw\Controller;

use OCA\FilesSharingRaw\Service\CspManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class PrivatePageController extends Controller {
	use RawResponse;

	/** @var string|null */
	private $loggedInUserId;

	/** @var IRootFolder */
	private $rootFolder;

	/** @var IConfig */
	private $config;

	/** @var CspManager */
	protected $cspManager;

	/** @var IURLGenerator */
	private $url;

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param IRootFolder $rootFolder
	 * @param CspManager $cspManager
	 * @param IConfig $config
	 * @param IUserSession $userSession
	 * @param IURLGenerator $url
	 */
	public function __construct(
		$appName,
		IRequest $request,
		IRootFolder $rootFolder,
		CspManager $cspManager,
		IConfig $config,
		IUserSession $userSession,
		IURLGenerator $url,
	) {
		parent::__construct($appName, $request);

		$this->rootFolder = $rootFolder;
		$this->cspManager = $cspManager;
		$this->config = $config;
		$this->url = $url;

		// Set loggedInUserId from the user session if available (null if anonymous)
		// This is safer and more idiomatic than passing the UID into the constructor.
		$this->loggedInUserId = null;
		if ($userSession->isLoggedIn() && $userSession->getUser() !== null) {
			$this->loggedInUserId = $userSession->getUser()->getUID();
		}
		// Note: for public/anonymous requests loggedInUserId remains null
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getByPath($userId, $path) {
		if ($userId !== $this->loggedInUserId) {
			// TODO Currently, we only allow access to one's own files. I suppose we could implement
			// authorisation checks and give the user access to files that have been shared with them.
			return new NotFoundResponse(); // would 403 Forbidden be better?
		}

		$userFolder = $this->rootFolder->getUserFolder($userId);

		try {
			$node = $userFolder->get($path);
		} catch (NotFoundException $e) {
			return new NotFoundResponse();
		}
		$this->returnRawResponse($node);
	}

	// Legacy route: /apps/files_sharing_raw/u/{userId}/{path} is kept so that links in that
	// form keep working; it 307-redirects to the canonical /raw/u/{userId}/{path} URL.

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function legacyByPath($userId, $path) {
		$canonical = $this->url->linkToRoute(
			'files_sharing_raw.privatePage.getByPath',
			['userId' => $userId, 'path' => $path]
		);
		$qs = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
		if (is_string($qs) && $qs !== '') {
			$canonical .= '?' . $qs;
		}
		header('Location: ' . $canonical, true, 307);
		exit;
	}
}
