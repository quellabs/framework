<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventAttachmentCompiler;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * MySQL/MariaDB lowering of attachments (objectquel-equel-triggers-design.md, "Event
	 * lowering"). The expected SQL is not run against MySQL or MariaDB here.
	 */
	class MysqlEventAttachmentCompilerTest extends TestCase {

		/**
		 * @param string $entityClass Fully qualified entity class the row parameters are typed with
		 * @param int $parameterCount Number of entity-row parameters, each typed $entityClass
		 * @return array<string, mixed> Decoded metadata
		 */
		private static function triggerMetadata(string $entityClass, int $parameterCount): array {
			return [
				'objectQuel' => 1,
				'returnType' => 'trigger',
				'atomic'     => false,
				'parameters' => array_fill(0, $parameterCount, ['kind' => 'entity', 'type' => $entityClass]),
				'safety'     => ['calls' => [], 'reads' => [], 'writes' => []],
			];
		}

		/**
		 * @param string $source Attachment source
		 * @param array<string, array<string, mixed>> $metadataByRoutine Decoded metadata, by routine name
		 * @return list<string> Generated statements
		 */
		private function compile(string $source, array $metadataByRoutine): array {
			$adapter = $this->createMock(DatabaseAdapter::class);
			$adapter->method('getRoutineMetadata')->willReturnCallback(fn(string $name) => $metadataByRoutine[$name]);

			return (new EventAttachmentCompiler($GLOBALS['test_em'], new FakePlatformCapabilities('mysql'), null, $adapter))->compile($source);
		}

		/**
		 * @return void
		 */
		public function testReplaceAttachmentCallsRoutineWithOldAndNew(): void {
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$statements = $this->compile('
				range of u is UserEntity
				after replace u call audit_user as audit_trigger
			', ['audit_user' => self::triggerMetadata($entityClass, 2)]);

			self::assertCount(1, $statements);
			self::assertSame(
				"CREATE TRIGGER `eq_users_audit_trigger`\n"
				. "AFTER UPDATE ON `users`\n"
				. "FOR EACH ROW\n"
				. "CALL `audit_user`(OLD.`id`, OLD.`username`, OLD.`password`, OLD.`banned`, NEW.`id`, NEW.`username`, NEW.`password`, NEW.`banned`);",
				$statements[0]
			);
		}

		/**
		 * @return void
		 */
		public function testAppendAttachmentOnlyBindsNew(): void {
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$statements = $this->compile('
				range of u is UserEntity
				after append to u call on_created as on_created_trigger
			', ['on_created' => self::triggerMetadata($entityClass, 1)]);

			self::assertSame(
				"CREATE TRIGGER `eq_users_on_created_trigger`\n"
				. "AFTER INSERT ON `users`\n"
				. "FOR EACH ROW\n"
				. "CALL `on_created`(NEW.`id`, NEW.`username`, NEW.`password`, NEW.`banned`);",
				$statements[0]
			);
		}

		/**
		 * @return void
		 */
		public function testDeleteAttachmentOnlyBindsOld(): void {
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$statements = $this->compile('
				range of u is UserEntity
				after delete u call on_removed as on_removed_trigger
			', ['on_removed' => self::triggerMetadata($entityClass, 1)]);

			self::assertSame(
				"CREATE TRIGGER `eq_users_on_removed_trigger`\n"
				. "AFTER DELETE ON `users`\n"
				. "FOR EACH ROW\n"
				. "CALL `on_removed`(OLD.`id`, OLD.`username`, OLD.`password`, OLD.`banned`);",
				$statements[0]
			);
		}

		/**
		 * Omitting `as <alias>` still compiles: the mocked connection reports no existing
		 * trigger, so the generated alias is accepted on the first attempt. The exact alias is
		 * opaque and random, so only the surrounding DDL shape is asserted here.
		 * @return void
		 */
		public function testOmittedAliasStillCompiles(): void {
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$statements = $this->compile('
				range of u is UserEntity
				after replace u call audit_user
			', ['audit_user' => self::triggerMetadata($entityClass, 2)]);

			self::assertCount(1, $statements);
			self::assertMatchesRegularExpression('/^CREATE TRIGGER `eq_users_[0-9a-f]+`\n/', $statements[0]);
		}
	}
