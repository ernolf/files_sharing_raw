<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSharingRaw\Migration;

use Closure;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Disables raw access on password-protected shares. Raw delivery has no
 * password prompt, so such shares are no longer served raw.
 */
class Version000705Date20261009000000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('rs.share_id')
			->from('raw_shares', 'rs')
			->join('rs', 'share', 's', $qb->expr()->eq('rs.share_id', 's.id'))
			->where($qb->expr()->eq('rs.enabled', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('s.password'));

		$result = $qb->executeQuery();
		$shareIds = [];
		while (($row = $result->fetch()) !== false) {
			$shareIds[] = (int)$row['share_id'];
		}
		$result->closeCursor();

		// Oracle limits IN lists to 1000 entries.
		foreach (array_chunk($shareIds, 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('raw_shares')
				->set('enabled', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT))
				->where($qb->expr()->in('share_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}

		if ($shareIds !== []) {
			$output->info('Disabled raw access on ' . count($shareIds) . ' password-protected share(s).');
		}
	}
}
