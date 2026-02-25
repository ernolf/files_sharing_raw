<?php
/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\FilesSharingRaw\Controller;

use OCA\FilesSharingRaw\Service\PublicUrlBuilder;
use OCA\FilesSharingRaw\Service\RawShareRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\Share;

class RawShareApiController extends Controller {
	private IManager $shareManager;
	private IUserSession $userSession;
	private RawShareRegistry $registry;
	private PublicUrlBuilder $urlBuilder;
	private IRootFolder $rootFolder;

	public function __construct(
		string $appName,
		IRequest $request,
		IManager $shareManager,
		IUserSession $userSession,
		RawShareRegistry $registry,
		PublicUrlBuilder $urlBuilder,
		IRootFolder $rootFolder
	) {
		parent::__construct($appName, $request);
		$this->shareManager = $shareManager;
		$this->userSession = $userSession;
		$this->registry = $registry;
		$this->urlBuilder = $urlBuilder;
		$this->rootFolder = $rootFolder;
	}

	#[NoAdminRequired]
	public function get(int $shareId): DataResponse {
		$share = $this->getEditableLinkShareOrNull($shareId);
		if ($share === null) {
			return new DataResponse(['error' => 'not_found'], 404);
		}

		$token = (string)$share->getToken();
		$enabled = $this->registry->isEnabled($shareId);
		$csp = $this->registry->getCsp($shareId);

		return new DataResponse([
			'shareId' => $shareId,
			'enabled' => $enabled,
			'csp' => $csp,
			'token' => $token,
			'rawUrl' => $this->urlBuilder->publicTokenUrl($token),
		]);
	}

	#[NoAdminRequired]
	public function set(int $shareId): DataResponse {
		$share = $this->getEditableLinkShareOrNull($shareId);
		if ($share === null) {
			return new DataResponse(['error' => 'not_found'], 404);
		}

		$enabled = $this->toBool($this->request->getParam('enabled', false));
		$csp = $this->request->getParam('csp', null);
		if ($csp !== null && !is_string($csp)) {
			$csp = null;
		}

		if ($enabled) {
			$this->registry->enable($shareId, $csp);
		} else {
			$this->registry->disable($shareId);
		}

		$token = (string)$share->getToken();

		return new DataResponse([
			'shareId' => $shareId,
			'enabled' => $enabled,
			'csp' => $enabled ? $this->registry->getCsp($shareId) : null,
			'token' => $token,
			'rawUrl' => $this->urlBuilder->publicTokenUrl($token),
		]);
	}

	#[NoAdminRequired]
	public function listByFileId(int $fileId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'not_authenticated'], 401);
		}
		$uid = (string)$user->getUID();

		// Resolve the node within the current user's view (mounts included).
		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
			$nodes = $userFolder->getById($fileId);
		} catch (\Throwable $e) {
			return new DataResponse(['error' => 'not_found'], 404);
		}

		if (!is_array($nodes) || count($nodes) === 0) {
			return new DataResponse(['fileId' => $fileId, 'shares' => []]);
		}

		$node = $nodes[0];

		// Get public link shares for this node (best-effort, never throw).
		try {
			$shares = $this->shareManager->getSharesBy($uid, Share::SHARE_TYPE_LINK, $node, true, 200, 0);
		} catch (\Throwable $e) {
			$shares = [];
		}

		$out = [];
		foreach ($shares as $share) {
			try {
				if ($share->getShareType() !== Share::SHARE_TYPE_LINK) {
					continue;
				}
				$shareId = $this->normalizeShareId((string)$share->getId());
				if ($shareId <= 0) {
					continue;
				}

				// Only allow share initiator or share owner to see/manage raw exposure here.
				$sharedBy = (string)$share->getSharedBy();
				$owner = (string)$share->getShareOwner();
				if ($sharedBy !== $uid && $owner !== $uid) {
					continue;
				}

				$token = (string)$share->getToken();
				$out[] = [
					'shareId' => $shareId,
					'token' => $token,
					'enabled' => $this->registry->isEnabled($shareId),
					'rawUrl' => $this->urlBuilder->publicTokenUrl($token),
				];
			} catch (\Throwable $e) {
				continue;
			}
		}

		return new DataResponse([
			'fileId' => $fileId,
			'shares' => $out,
		]);
	}

	private function getEditableLinkShareOrNull(int $shareId): ?IShare {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}
		$uid = (string)$user->getUID();

		try {
			// Nextcloud internal shares are addressed as "<provider>:<id>" (usually "ocinternal:<id>")
			$share = $this->shareManager->getShareById('ocinternal:' . (string)$shareId);
		} catch (\Throwable $e) {
			// Fallback for setups/providers that still accept plain numeric IDs
			try {
				$share = $this->shareManager->getShareById((string)$shareId);
			} catch (\Throwable $e2) {
				return null;
			}
		}

		if ($share->getShareType() !== Share::SHARE_TYPE_LINK) {
			return null;
		}

		// Only allow share initiator or share owner to manage raw exposure.
		$sharedBy = (string)$share->getSharedBy();
		$owner = (string)$share->getShareOwner();
		if ($sharedBy !== $uid && $owner !== $uid) {
			return null;
		}

		return $share;
	}

	private function toBool($v): bool {
		if (is_bool($v)) {
			return $v;
		}
		if (is_int($v)) {
			return $v !== 0;
		}
		if (is_string($v)) {
			$s = strtolower(trim($v));
			if ($s === '1' || $s === 'true' || $s === 'yes' || $s === 'on') {
				return true;
			}
			if ($s === '0' || $s === 'false' || $s === 'no' || $s === 'off' || $s === '') {
				return false;
			}
		}
		return (bool)$v;
	}

	private function normalizeShareId(string $rawId): int {
		// Nextcloud can return "<provider>:<id>" (e.g. "ocinternal:1473").
		if (str_contains($rawId, ':')) {
			$rawId = substr($rawId, strrpos($rawId, ':') + 1);
		}
		$n = (int)$rawId;
		return $n > 0 ? $n : 0;
	}
}

