<?php
/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\FilesSharingRaw\Listener;

use OCA\FilesSharingRaw\Service\RawShareRegistry;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Share\Events\ShareDeletedEvent;

class ShareDeletedListener implements IEventListener {
	private RawShareRegistry $registry;

	public function __construct(RawShareRegistry $registry) {
		$this->registry = $registry;
	}

	public function handle(Event $event): void {
		if (!($event instanceof ShareDeletedEvent)) {
			return;
		}
		$share = $event->getShare();
		$shareId = $this->normalizeShareId((string)$share->getId());
		if ($shareId > 0) {
			// Share is really gone -> remove row.
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

