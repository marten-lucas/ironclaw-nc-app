<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000002Date20260712000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('ic_talk_room_participants')) {
			return null;
		}

		$table = $schema->createTable('ic_talk_room_participants');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('room_token', Types::STRING, [
			'length' => 255,
			'notnull' => true,
		]);
		$table->addColumn('participant_count', Types::INTEGER, [
			'notnull' => true,
			'default' => 0,
			'unsigned' => true,
		]);
		$table->addColumn('participant_actors', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('refreshed_at', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['room_token'], 'ic_talk_room_participants_room_uq');
		$table->addIndex(['refreshed_at'], 'ic_talk_room_participants_refreshed_idx');

		return $schema;
	}
}