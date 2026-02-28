<?php
/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\FilesSharingRaw\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

class RawShare extends Entity {
	/** @var int */
	protected $shareId = 0;

	/** @var bool */
	protected $enabled = true;

	/** @var string|null */
	protected $csp = null;

	/** @var bool */
	protected $rawOnly = false;

	/** @var int */
	protected $createdAt = 0;

	/** @var int */
	protected $updatedAt = 0;

	public function __construct() {
		$this->addType('id', Types::BIGINT);
		$this->addType('shareId', Types::BIGINT);
		$this->addType('enabled', Types::BOOLEAN);
		$this->addType('csp', Types::TEXT);
		$this->addType('rawOnly', Types::BOOLEAN);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}

	public function getShareId(): int {
		return (int)$this->shareId;
	}

	public function setShareId(int $shareId): self {
		$this->setter('shareId', [$shareId]);
		return $this;
	}

	public function isEnabled(): bool {
		return (bool)$this->enabled;
	}

	public function setEnabled(bool $enabled): self {
		$this->setter('enabled', [$enabled]);
		return $this;
	}

	public function getCsp(): ?string {
		$csp = $this->csp;
		if ($csp === null) {
			return null;
		}
		$csp = trim((string)$csp);
		return $csp === '' ? null : $csp;
	}

	public function setCsp(?string $csp): self {
		if ($csp !== null) {
			$csp = trim($csp);
			if ($csp === '') {
				$csp = null;
			}
		}
		$this->setter('csp', [$csp]);
		return $this;
	}

	public function isRawOnly(): bool {
		return (bool)$this->rawOnly;
	}

	public function setRawOnly(bool $rawOnly): self {
		$this->setter('rawOnly', [$rawOnly]);
		return $this;
	}

	public function getCreatedAt(): int {
		return (int)$this->createdAt;
	}

	public function setCreatedAt(int $ts): self {
		$this->setter('createdAt', [$ts]);
		return $this;
	}

	public function getUpdatedAt(): int {
		return (int)$this->updatedAt;
	}

	public function setUpdatedAt(int $ts): self {
		$this->setter('updatedAt', [$ts]);
		return $this;
	}
}
