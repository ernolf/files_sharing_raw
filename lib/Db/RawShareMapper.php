<?php
/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\FilesSharingRaw\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class RawShareMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'raw_shares', RawShare::class);
	}

	public function findByShareIdOrNull(int $shareId): ?RawShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT))
			)
			->setMaxResults(1);

		try {
			/** @var RawShare $e */
			$e = $this->findEntity($qb);
			return $e;
		} catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
			return null;
		}
	}

	public function deleteByShareId(int $shareId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT))
			);
		$qb->executeStatement();
	}

	/**
	 * Upsert-like behavior:
	 * - if exists: update enabled/csp/updated_at
	 * - else: insert new row
	 */
	public function upsert(int $shareId, bool $enabled, ?string $csp, int $now): RawShare {
		// Do NOT rely on QBMapper::insert/update here (can result in INSERT () VALUES()).
		// Write explicitly to match DB columns (share_id, enabled, csp, created_at, updated_at).

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('enabled', $qb->createNamedParameter($enabled ? 1 : 0, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)));

		if ($csp === null) {
			$qb->set('csp', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
		} else {
			$qb->set('csp', $qb->createNamedParameter($csp, IQueryBuilder::PARAM_STR));
		}

		$affected = $qb->executeStatement();

		if ($affected === 0) {
			// 0 affected rows does not necessarily mean "row does not exist".
			// It can also mean "no-op update" depending on DB/driver.
			$existing = $this->findByShareIdOrNull($shareId);
			if ($existing !== null) {
				return $existing;
			}

			$qb = $this->db->getQueryBuilder();
			$values = [
				'share_id' => $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT),
				'enabled' => $qb->createNamedParameter($enabled ? 1 : 0, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			];
			$values['csp'] = ($csp === null)
				? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
				: $qb->createNamedParameter($csp, IQueryBuilder::PARAM_STR);

			$qb->insert($this->getTableName())
				->values($values);

			$qb->executeStatement();
		}

		$e = $this->findByShareIdOrNull($shareId);
		if ($e === null) {
			throw new \RuntimeException('raw_shares upsert failed for share_id=' . $shareId);
		}
		return $e;
	}
}

