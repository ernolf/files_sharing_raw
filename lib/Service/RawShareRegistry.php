<?php
/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\FilesSharingRaw\Service;

use OCA\FilesSharingRaw\Db\RawShare;
use OCA\FilesSharingRaw\Db\RawShareMapper;
use OCP\AppFramework\Utility\ITimeFactory;

class RawShareRegistry {
	private RawShareMapper $mapper;
	private ITimeFactory $time;

	public function __construct(RawShareMapper $mapper, ITimeFactory $time) {
		$this->mapper = $mapper;
		$this->time = $time;
	}

	public function getMeta(int $shareId): ?RawShare {
		$e = $this->mapper->findByShareIdOrNull($shareId);
		if ($e === null) {
			return null;
		}
		return $e->isEnabled() ? $e : null;
	}

	public function isEnabled(int $shareId): bool {
		return $this->getMeta($shareId) !== null;
	}

	public function getCsp(int $shareId): ?string {
		$e = $this->getMeta($shareId);
		if ($e === null) {
			return null;
		}
		return $e->getCsp();
	}

	/**
	 * Return the stored CSP regardless of enabled state.
	 * Used to preserve an existing CSP when a user without CSP-editor
	 * privileges calls the set() API endpoint.
	 */
	public function getStoredCsp(int $shareId): ?string {
		$e = $this->mapper->findByShareIdOrNull($shareId);
		return $e !== null ? $e->getCsp() : null;
	}

	public function isRawOnly(int $shareId): bool {
		$e = $this->mapper->findByShareIdOrNull($shareId);
		if ($e === null) {
			return false;
		}
		return $e->isEnabled() && $e->isRawOnly();
	}

	public function enable(int $shareId, ?string $csp, bool $rawOnly = false): RawShare {
		$now = (int)$this->time->getTime();
		$csp = $this->normalizeCsp($csp);
		return $this->mapper->upsert($shareId, true, $csp, $now, $rawOnly);
	}

	public function disable(int $shareId): void {
		// Do not delete the row on disable. Keep it and just mark disabled so
		// re-enabling does not create a fresh row — CSP and rawOnly are preserved.
		$existing = $this->mapper->findByShareIdOrNull($shareId);
		if ($existing === null) {
			return;
		}

		$now = (int)$this->time->getTime();
		$this->mapper->upsert($shareId, false, $existing->getCsp(), $now, $existing->isRawOnly());
	}

	public function purge(int $shareId): void {
		// Hard delete: use only when the underlying share is really gone.
		$this->mapper->deleteByShareId($shareId);
	}

	private function normalizeCsp(?string $csp): ?string {
		if ($csp === null) {
			return null;
		}
		$csp = trim($csp);
		if ($csp === '') {
			return null;
		}

		// Hard cap to prevent oversized payloads / DB issues.
		$max = 8192;
		if (strlen($csp) > $max) {
			$csp = substr($csp, 0, $max);
		}

		// Remove control chars.
		$csp = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $csp);
		$csp = preg_replace('/\s{2,}/', ' ', trim((string)$csp));

		return $csp === '' ? null : $csp;
	}
}
