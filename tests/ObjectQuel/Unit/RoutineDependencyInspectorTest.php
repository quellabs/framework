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
		 * @return DatabaseAdapter&\PHPUnit\Framework\MockObject\MockObject
		 */
		private function adapterReturning(string $databaseType, ?string $schema, array $rows): DatabaseAdapter {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'getRoutineSchema', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn($databaseType);
			$adapter->method('getRoutineSchema')->willReturn($schema);
			$statement = $this->createStub(StatementInterface::class);
			$statement->method('fetchAll')->willReturn($rows);
			$adapter->method('execute')->willReturn($statement);

			return $adapter;
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
