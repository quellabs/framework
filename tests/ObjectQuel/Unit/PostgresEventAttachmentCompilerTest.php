<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventAttachmentCompiler;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * PostgreSQL lowering of attachments (objectquel-equel-triggers-design.md, "Event
	 * lowering"). The expected SQL is not run against PostgreSQL here.
	 */
	class PostgresEventAttachmentCompilerTest extends TestCase {

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

			return (new EventAttachmentCompiler($GLOBALS['test_em'], new FakePlatformCapabilities('pgsql'), null, $adapter))->compile($source);
		}

		/**
		 * The helper function calls the routine with expanded OLD/NEW fields and returns NEW;
		 * the trigger then executes that helper.
		 * @return void
		 */
		public function testReplaceAttachmentGeneratesHelperAndTrigger(): void {
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$statements = $this->compile('
				range of u is UserEntity
				after replace u call audit_user as audit_trigger
			', ['audit_user' => self::triggerMetadata($entityClass, 2)]);

			self::assertCount(2, $statements);

			self::assertSame(
				"CREATE FUNCTION \"eq_users_audit_trigger_fn\"()\n"
				. "RETURNS trigger\n"
				. "LANGUAGE plpgsql\n"
				. "AS \$body\$\n"
				. "BEGIN\n"
				. "\tCALL \"audit_user\"(OLD.\"id\", OLD.\"username\", OLD.\"password\", OLD.\"banned\", NEW.\"id\", NEW.\"username\", NEW.\"password\", NEW.\"banned\");\n"
				. "\tRETURN NEW;\n"
				. "END;\n"
				. "\$body\$;",
				$statements[0]
			);

			self::assertSame(
				"CREATE TRIGGER \"eq_users_audit_trigger\"\n"
				. "AFTER UPDATE ON \"users\"\n"
				. "FOR EACH ROW EXECUTE FUNCTION \"eq_users_audit_trigger_fn\"();",
				$statements[1]
			);
		}

		/**
		 * A `replace` routine declaring only one entity-row parameter binds the leading row
		 * (old) by position; the helper's call carries only that row's columns.
		 * @return void
		 */
		public function testReplaceAttachmentWithOneParameterBindsOldOnly(): void {
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$statements = $this->compile('
				range of u is UserEntity
				after replace u call audit_old_only as audit_trigger
			', ['audit_old_only' => self::triggerMetadata($entityClass, 1)]);

			self::assertStringContainsString(
				"CALL \"audit_old_only\"(OLD.\"id\", OLD.\"username\", OLD.\"password\", OLD.\"banned\");",
				$statements[0]
			);
		}

		/**
		 * A DELETE attachment's helper returns OLD, since there is no resulting row.
		 * @return void
		 */
		public function testDeleteAttachmentHelperReturnsOld(): void {
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$statements = $this->compile('
				range of u is UserEntity
				after delete u call on_removed
			', ['on_removed' => self::triggerMetadata($entityClass, 1)]);

			self::assertStringContainsString('RETURN OLD;', $statements[0]);
			self::assertStringNotContainsString('RETURN NEW;', $statements[0]);
		}

		/**
		 * An INSERT attachment's helper returns NEW.
		 * @return void
		 */
		public function testAppendAttachmentHelperReturnsNew(): void {
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$statements = $this->compile('
				range of u is UserEntity
				after append to u call on_created
			', ['on_created' => self::triggerMetadata($entityClass, 1)]);

			self::assertStringContainsString('RETURN NEW;', $statements[0]);
			self::assertStringContainsString('AFTER INSERT ON "users"', $statements[1]);
		}
	}
