<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use Cake\Database\StatementInterface;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Scanning live triggers for a dependency on a routine (objectquel-equel-triggers-design.md,
	 * "Attachment dependency discovery"), ahead of `destroy function`.
	 */
	class RoutineDependencyInspectorTest extends TestCase {

		/**
		 * @param string $databaseType Engine
		 * @param ?string $schema Routine schema, or null
		 * @param list<array{trigger_name: string, body: string}> $rows Catalog rows
		 * @param array<string, array<string, mixed>> $metadataByRoutine Decoded metadata returned by getRoutineMetadata(), by routine name
		 * @return DatabaseAdapter&\PHPUnit\Framework\MockObject\MockObject
		 */
		private function adapterReturning(string $databaseType, ?string $schema, array $rows, array $metadataByRoutine = []): DatabaseAdapter {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'getRoutineSchema', 'execute', 'getRoutineMetadata'])->getMock();
			$adapter->method('getDatabaseType')->willReturn($databaseType);
			$adapter->method('getRoutineSchema')->willReturn($schema);
			$statement = $this->createStub(StatementInterface::class);
			$statement->method('fetchAll')->willReturn($rows);
			$adapter->method('execute')->willReturn($statement);
			$adapter->method('getRoutineMetadata')->willReturnCallback(function (string $name) use ($metadataByRoutine) {
				if (!isset($metadataByRoutine[$name])) {
					throw new QuelException("Can't attach to '{$name}': no routine by that name exists.", 'routine_call_error');
				}

				return $metadataByRoutine[$name];
			});

			return $adapter;
		}

		/**
		 * @param string $returnType 'trigger' or 'void'
		 * @return array<string, mixed>
		 */
		private static function metadata(string $returnType): array {
			return ['objectQuel' => 1, 'returnType' => $returnType, 'atomic' => false, 'parameters' => [], 'safety' => ['calls' => [], 'reads' => [], 'writes' => [], 'features' => []]];
		}

		/**
		 * @return void
		 */
		public function testMysqlFindsATriggerThatCallsTheRoutine(): void {
			$adapter = $this->adapterReturning('mysql', null, [
				['trigger_name' => 'eq_users_replace_audit_user', 'body' => 'CALL `audit_user`(OLD.`id`, NEW.`id`)'],
			]);

			self::assertSame(['eq_users_replace_audit_user'], $adapter->findDependentTriggers('audit_user'));
		}

		/**
		 * A routine name that is a substring of another trigger's called routine must not match.
		 * @return void
		 */
		public function testMysqlDoesNotMatchALongerRoutineName(): void {
			$adapter = $this->adapterReturning('mysql', null, [
				['trigger_name' => 'eq_users_replace_foo', 'body' => 'CALL `foo`(OLD.`id`)'],
			]);

			self::assertSame([], $adapter->findDependentTriggers('f'));
		}

		/**
		 * @return void
		 */
		public function testMysqlIgnoresUnrelatedTriggers(): void {
			$adapter = $this->adapterReturning('mysql', null, [
				['trigger_name' => 'eq_users_replace_other', 'body' => 'CALL `other_routine`(OLD.`id`)'],
			]);

			self::assertSame([], $adapter->findDependentTriggers('audit_user'));
		}

		/**
		 * PostgreSQL triggers call the generated helper, not the routine directly, so the scan
		 * reads the helper function's own source.
		 * @return void
		 */
		public function testPostgresFindsAHelperThatCallsTheRoutine(): void {
			$adapter = $this->adapterReturning('pgsql', null, [
				['trigger_name' => 'eq_users_replace_audit_user', 'body' => 'BEGIN CALL "audit_user"(OLD."id", NEW."id"); RETURN NEW; END;'],
			]);

			self::assertSame(['eq_users_replace_audit_user'], $adapter->findDependentTriggers('audit_user'));
		}

		/**
		 * @return void
		 */
		public function testSqlServerFindsATriggerThatCallsTheRoutineUnqualified(): void {
			$adapter = $this->adapterReturning('sqlsrv', 'dbo', [
				['trigger_name' => 'eq_users_replace_audit_user', 'body' => 'EXEC [audit_user] @_a0, @_a1;'],
			]);

			self::assertSame(['eq_users_replace_audit_user'], $adapter->findDependentTriggers('audit_user'));
		}

		/**
		 * @return void
		 */
		public function testSqlServerFindsATriggerThatCallsTheRoutineSchemaQualified(): void {
			$adapter = $this->adapterReturning('sqlsrv', 'dbo', [
				['trigger_name' => 'eq_users_replace_audit_user', 'body' => 'EXEC [dbo].[audit_user] @_a0, @_a1;'],
			]);

			self::assertSame(['eq_users_replace_audit_user'], $adapter->findDependentTriggers('audit_user'));
		}

		/**
		 * @return void
		 */
		public function testNoDependentsReturnsEmptyList(): void {
			$adapter = $this->adapterReturning('mysql', null, []);
			self::assertSame([], $adapter->findDependentTriggers('audit_user'));
		}

		/**
		 * A trigger on the table calling a `trigger`-declared routine is reported as an
		 * attachment, regardless of which routine it calls.
		 * @return void
		 */
		public function testFindsAttachmentTriggerOnTable(): void {
			$adapter = $this->adapterReturning('mysql', null, [
				['trigger_name' => 'eq_users_replace_audit_user', 'body' => 'CALL `audit_user`(OLD.`id`, NEW.`id`)'],
			], ['audit_user' => self::metadata('trigger')]);

			self::assertSame(['eq_users_replace_audit_user'], $adapter->findAttachmentTriggersOnTable('users'));
		}

		/**
		 * A trigger calling an ordinary (non-trigger) routine isn't an attachment.
		 * @return void
		 */
		public function testIgnoresATriggerCallingAnOrdinaryRoutine(): void {
			$adapter = $this->adapterReturning('mysql', null, [
				['trigger_name' => 'some_user_trigger', 'body' => 'CALL `log_change`(OLD.`id`)'],
			], ['log_change' => self::metadata('void')]);

			self::assertSame([], $adapter->findAttachmentTriggersOnTable('users'));
		}

		/**
		 * A trigger calling a routine with no recognizable ObjectQuel metadata isn't an attachment.
		 * @return void
		 */
		public function testIgnoresATriggerCallingAnUnmanagedRoutine(): void {
			$adapter = $this->adapterReturning('mysql', null, [
				['trigger_name' => 'some_user_trigger', 'body' => 'CALL `legacy_proc`(OLD.`id`)'],
			], []);

			self::assertSame([], $adapter->findAttachmentTriggersOnTable('users'));
		}

		/**
		 * A trigger body with no recognizable CALL/EXEC pattern at all isn't an attachment.
		 * @return void
		 */
		public function testIgnoresATriggerWithNoCallAtAll(): void {
			$adapter = $this->adapterReturning('mysql', null, [
				['trigger_name' => 'some_user_trigger', 'body' => 'SET NEW.updated_at = NOW()'],
			], []);

			self::assertSame([], $adapter->findAttachmentTriggersOnTable('users'));
		}

		/**
		 * PostgreSQL finds the attachment through the generated helper function's source.
		 * @return void
		 */
		public function testPostgresFindsAttachmentThroughHelperSource(): void {
			$adapter = $this->adapterReturning('pgsql', null, [
				['trigger_name' => 'eq_users_replace_audit_user', 'body' => 'BEGIN CALL "audit_user"(OLD."id", NEW."id"); RETURN NEW; END;'],
			], ['audit_user' => self::metadata('trigger')]);

			self::assertSame(['eq_users_replace_audit_user'], $adapter->findAttachmentTriggersOnTable('users'));
		}

		/**
		 * SQL Server finds the attachment whether the call is schema-qualified or not.
		 * @return void
		 */
		public function testSqlServerFindsAttachmentRegardlessOfSchemaQualification(): void {
			$adapter = $this->adapterReturning('sqlsrv', 'dbo', [
				['trigger_name' => 'eq_users_replace_audit_user', 'body' => 'EXEC [dbo].[audit_user] @_a0, @_a1;'],
			], ['audit_user' => self::metadata('trigger')]);

			self::assertSame(['eq_users_replace_audit_user'], $adapter->findAttachmentTriggersOnTable('users'));
		}

		/**
		 * @return void
		 */
		public function testLookupFailureSurfacesAsQuelException(): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'getRoutineSchema', 'execute', 'getLastErrorMessage'])->getMock();
			$adapter->method('getDatabaseType')->willReturn('mysql');
			$adapter->method('getRoutineSchema')->willReturn(null);
			$adapter->method('execute')->willReturn(null);
			$adapter->method('getLastErrorMessage')->willReturn('catalog unavailable');

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('catalog unavailable');
			$adapter->findDependentTriggers('audit_user');
		}
	}
