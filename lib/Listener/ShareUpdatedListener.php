<?php
/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\FilesSharingRaw\Listener;

use OCA\FilesSharingRaw\Service\RawShareRegistry;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Share\Events\ShareUpdatedEvent;
use OCP\Share\IShare;

class ShareUpdatedListener implements IEventListener {
	private RawShareRegistry $registry;

	public function __construct(RawShareRegistry $registry) {
		$this->registry = $registry;
	}

	public function handle(Event $event): void {
		if (!($event instanceof ShareUpdatedEvent)) {
			return;
		}
		$share = $event->getShare();
		$shareId = $this->normalizeShareId((string)$share->getId());
		if ($shareId <= 0) {
			return;
		}

		// If it stops being a public link, ensure the raw entry is gone.
		if ($share->getShareType() !== IShare::TYPE_LINK) {
			// Not a link share anymore -> remove row.
			$this->registry->purge($shareId);
		}
	}

	private function normalizeShareId(string $rawId): int {
		// Nextcloud can return "<provider>:<id>" (e.g. "ocinternal:1473").
		if (strpos($rawId, ':') !== false) {
			$rawId = substr($rawId, strrpos($rawId, ':') + 1);
		}
		$n = (int)$rawId;
		return $n > 0 ? $n : 0;
	}
}

