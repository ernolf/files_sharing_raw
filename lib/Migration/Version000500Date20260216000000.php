<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSharingRaw\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000500Date20260216000000 extends SimpleMigrationStep {

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('raw_shares')) {
			return $schema;
		}

		$table = $schema->createTable('raw_shares');

		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'unsigned' => true,
			'notnull' => true,
		]);
		$table->setPrimaryKey(['id']);

		$table->addColumn('share_id', Types::BIGINT, [
			'unsigned' => true,
			'notnull' => true,
		]);

		// Must be nullable: Nextcloud does not support non-null boolean columns (Oracle).
		$table->addColumn('enabled', Types::BOOLEAN, [
			'notnull' => false,
			'default' => true,
		]);

		// Keep as TEXT (CLOB-like) for cross-DB safety; validate max length in app code if desired.
		$table->addColumn('csp', Types::TEXT, [
			'notnull' => false,
		]);

		$table->addColumn('created_at', Types::BIGINT, [
			'unsigned' => true,
			'notnull' => true,
			'default' => 0,
		]);

		$table->addColumn('updated_at', Types::BIGINT, [
			'unsigned' => true,
			'notnull' => true,
			'default' => 0,
		]);

		$table->addUniqueIndex(['share_id'], 'raw_shares_share_id_uq');
		$table->addIndex(['enabled'], 'raw_shares_enabled_idx');

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}
}
