<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000004Date20260713000000 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $connection) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('ic_talk_outbox')) {
			$schema->dropTable('ic_talk_outbox');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$platform = $this->connection->getDatabasePlatform();
		if ($platform->getName() !== 'postgresql') {
			return;
		}

		// PostgreSQL may keep orphaned sequence objects after DROP TABLE.
		$this->connection->executeStatement('DROP SEQUENCE IF EXISTS oc_ic_talk_outbox_id_seq');
	}
}
