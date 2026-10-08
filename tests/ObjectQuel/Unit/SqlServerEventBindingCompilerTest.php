<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventBindingCompiler;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * SQL Server lowering of bindings (objectquel-equel-triggers-design.md, "Event
	 * lowering"): a statement-level trigger that loops over inserted/deleted itself, pairing
	 * them on the target's mapped key for UPDATE. The expected SQL is not run against SQL
	 * Server here.
	 */
	class SqlServerEventBindingCompilerTest extends TestCase {

		/**
		 * @param string $entityClass Fully qualified entity class the row parameters are typed with
		 * @param int $parameterCount Number of entity-row parameters, each typed $entityClass
		 * @return array<string, mixed> Decoded metadata
		 */
		private static function triggerMetadata(string $entityClass, int $parameterCount): array {
			return [
				'objectQuel' => 1,
				'isTrigger'  => true,
				'returnType' => 'void',
				'atomic'     => false,
				'parameters' => array_fill(0, $parameterCount, ['kind' => 'entity', 'type' => $entityClass]),
				'safety'     => ['calls' => [], 'reads' => [], 'writes' => []],
			];
		}

		/**
		 * @param string $source Binding source
		 * @param array<string, array<string, mixed>> $metadataByRoutine Decoded metadata, by routine name
		 * @return list<string> Generated statements
		 */
		private function compile(string $source, array $metadataByRoutine): array {
			$adapter = $this->createMock(DatabaseAdapter::class);
			$adapter->method('getRoutineMetadata')->willReturnCallback(fn(string $name) => $metadataByRoutine[$name]);

			return (new EventBindingCompiler($GLOBALS['test_em'], new FakePlatformCapabilities('sqlsrv'), 'dbo', $adapter))->compile($source);
		}

		/**
		 * UPDATE pairs deleted/inserted on the mapped key, guarded by a zero-row check and a
		 * key-column-targeted check, then EXECs the routine once per fetched pair.
		 * @return void
		 */
		public function testReplaceBindingPairsOldAndNewByKey(): void {
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$statements = $this->compile('
				range of u is UserEntity
				after replace u call audit_user as audit_trigger
			', ['audit_user' => self::triggerMetadata($entityClass, 2)]);

			self::assertCount(1, $statements);
			$sql = $statements[0];

			self::assertStringContainsString("CREATE TRIGGER [eq_7dfb4cf67742_audit_trigger]\nON [dbo].[users]\nAFTER UPDATE", $sql);
			self::assertStringContainsString('IF NOT EXISTS (SELECT 1 FROM inserted)', $sql);
			self::assertStringContainsString('IF UPDATE([id])', $sql);
			self::assertStringContainsString('FROM deleted AS d', $sql);
			self::assertStringContainsString('JOIN inserted AS i ON d.[id] = i.[id]', $sql);
			self::assertStringContainsString('d.[id] AS [c0]', $sql);
			self::assertStringContainsString('i.[id] AS [c4]', $sql);
			self::assertStringContainsString('EXEC [dbo].[audit_user] @_a0, @_a1, @_a2, @_a3, @_a4, @_a5, @_a6, @_a7;', $sql);
			self::assertStringContainsString('CLOSE _eq_pairs;', $sql);
			self::assertStringContainsString('DEALLOCATE _eq_pairs;', $sql);
		}

		/**
		 * A `replace` routine declaring only one entity-row parameter binds the leading row
		 * (old) by position: the pairing join/guards still run (they don't depend on the
		 * routine's arity), but only `d`-aliased columns are selected and EXECed.
		 * @return void
		 */
		public function testReplaceBindingWithOneParameterBindsOldOnly(): void {
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$statements = $this->compile('
				range of u is UserEntity
				after replace u call audit_old_only as audit_trigger
			', ['audit_old_only' => self::triggerMetadata($entityClass, 1)]);

			$sql = $statements[0];
			self::assertStringContainsString('JOIN inserted AS i ON d.[id] = i.[id]', $sql);
			self::assertStringContainsString('d.[id] AS [c0]', $sql);
			self::assertStringNotContainsString('i.[id] AS', $sql);
			self::assertStringContainsString('EXEC [dbo].[audit_old_only] @_a0, @_a1, @_a2, @_a3;', $sql);
		}

		/**
		 * INSERT iterates `inserted` alone, with no key pairing or guards at all.
		 * @return void
		 */
		public function testAppendBindingIteratesInsertedAlone(): void {
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$statements = $this->compile('
				range of u is UserEntity
				after append to u call on_created
			', ['on_created' => self::triggerMetadata($entityClass, 1)]);

			$sql = $statements[0];
			self::assertStringContainsString('AFTER INSERT', $sql);
			self::assertStringContainsString('FROM inserted AS i;', $sql);
			self::assertStringNotContainsString('deleted', $sql);
			self::assertStringNotContainsString('IF UPDATE(', $sql);
		}

		/**
		 * DELETE iterates `deleted` alone, with no key pairing or guards at all.
		 * @return void
		 */
		public function testDeleteBindingIteratesDeletedAlone(): void {
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$statements = $this->compile('
				range of u is UserEntity
				after delete u call on_removed
			', ['on_removed' => self::triggerMetadata($entityClass, 1)]);

			$sql = $statements[0];
			self::assertStringContainsString('AFTER DELETE', $sql);
			self::assertStringContainsString('FROM deleted AS d;', $sql);
			self::assertStringNotContainsString('inserted', $sql);
		}

		/**
		 * A target entity with no mapped primary key can't receive an UPDATE binding on
		 * SQL Server: there is nothing reliable to pair deleted/inserted on.
		 * @return void
		 */
		public function testRejectsUpdateBindingWithoutAUsableKey(): void {
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('NoKeyEntity')->className;
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage('no mapped primary key');
			$this->compile('
				range of n is NoKeyEntity
				after replace n call f
			', ['f' => self::triggerMetadata($entityClass, 2)]);
		}
	}
