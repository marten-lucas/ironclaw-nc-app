<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000001Date20260709000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('ic_talk_outbox')) {
			return null;
		}

		$table = $schema->createTable('ic_talk_outbox');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('event_id', Types::STRING, [
			'length' => 191,
			'notnull' => true,
		]);
		$table->addColumn('room_token', Types::STRING, [
			'length' => 255,
			'notnull' => true,
		]);
		$table->addColumn('message_id', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('payload', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('status', Types::STRING, [
			'length' => 32,
			'notnull' => true,
			'default' => 'queued',
		]);
		$table->addColumn('attempts', Types::INTEGER, [
			'notnull' => true,
			'default' => 0,
			'unsigned' => true,
		]);
		$table->addColumn('next_attempt_at', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('last_error', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('updated_at', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('delivered_at', Types::BIGINT, [
			'notnull' => false,
			'unsigned' => true,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['event_id'], 'ic_talk_outbox_event_uq');
		$table->addIndex(['status', 'next_attempt_at'], 'ic_talk_outbox_status_next_idx');

		return $schema;
	}
}
