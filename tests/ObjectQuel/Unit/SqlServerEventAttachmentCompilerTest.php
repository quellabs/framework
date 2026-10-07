<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventAttachmentCompiler;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * SQL Server lowering of attachments (objectquel-equel-triggers-design.md, "Event
	 * lowering"): a statement-level trigger that loops over inserted/deleted itself, pairing
	 * them on the target's mapped key for UPDATE. The expected SQL is not run against SQL
	 * Server here.
	 */
	class SqlServerEventAttachmentCompilerTest extends TestCase {

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
				'safety'     => ['calls' => [], 'reads' => [], 'writes' => [], 'features' => []],
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

			return (new EventAttachmentCompiler($GLOBALS['test_em'], new FakePlatformCapabilities('sqlsrv'), 'dbo', $adapter))->compile($source);
		}

		/**
		 * UPDATE pairs deleted/inserted on the mapped key, guarded by a zero-row check and a
		 * key-column-targeted check, then EXECs the routine once per fetched pair.
		 * @return void
		 */
		public function testReplaceAttachmentPairsOldAndNewByKey(): void {
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$statements = $this->compile('
				range of u is UserEntity
				after replace u call audit_user(old, new)
			', ['audit_user' => self::triggerMetadata($entityClass, 2)]);

			self::assertCount(1, $statements);
			$sql = $statements[0];

			self::assertStringContainsString("CREATE TRIGGER [eq_users_replace_audit_user]\nON [dbo].[users]\nAFTER UPDATE", $sql);
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
		 * INSERT iterates `inserted` alone, with no key pairing or guards at all.
		 * @return void
		 */
		public function testAppendAttachmentIteratesInsertedAlone(): void {
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$statements = $this->compile('
				range of u is UserEntity
				after append to u call on_created(new)
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
		public function testDeleteAttachmentIteratesDeletedAlone(): void {
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$statements = $this->compile('
				range of u is UserEntity
				after delete u call on_removed(old)
			', ['on_removed' => self::triggerMetadata($entityClass, 1)]);

			$sql = $statements[0];
			self::assertStringContainsString('AFTER DELETE', $sql);
			self::assertStringContainsString('FROM deleted AS d;', $sql);
			self::assertStringNotContainsString('inserted', $sql);
		}

		/**
		 * A target entity with no mapped primary key can't receive an UPDATE attachment on
		 * SQL Server: there is nothing reliable to pair deleted/inserted on.
		 * @return void
		 */
		public function testRejectsUpdateAttachmentWithoutAUsableKey(): void {
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('NoKeyEntity')->className;
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage('no mapped primary key');
			$this->compile('
				range of n is NoKeyEntity
				after replace n call f(old, new)
			', ['f' => self::triggerMetadata($entityClass, 2)]);
		}
	}
