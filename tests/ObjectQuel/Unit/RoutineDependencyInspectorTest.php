<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use Cake\Database\StatementInterface;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\BindingEvent;

	/**
	 * Scanning live triggers for a dependency on a routine (objectquel-equel-triggers-design.md,
	 * "Binding dependency discovery"), ahead of `destroy function`.
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
					throw new QuelException("Can't bind to '{$name}': no routine by that name exists.", 'routine_call_error');
				}

				return $metadataByRoutine[$name];
			});

			return $adapter;
		}

		/**
		 * @param string $returnType Metadata return type ('void' or a scalar type)
		 * @param bool $isTrigger Whether the routine is declared with `tfunction`
		 * @return array<string, mixed>
		 */
		private static function metadata(string $returnType, bool $isTrigger = false): array {
			return ['objectQuel' => 1, 'isTrigger' => $isTrigger, 'returnType' => $returnType, 'atomic' => false, 'parameters' => [], 'safety' => ['calls' => [], 'reads' => [], 'writes' => []]];
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
		 * A trigger on the table calling a tfunction is reported as a
		 * binding, regardless of which routine it calls.
		 * @return void
		 */
		public function testFindsBindingTriggerOnTable(): void {
			$adapter = $this->adapterReturning('mysql', null, [
				['trigger_name' => 'eq_users_replace_audit_user', 'body' => 'CALL `audit_user`(OLD.`id`, NEW.`id`)'],
			], ['audit_user' => self::metadata('void', isTrigger: true)]);

			self::assertSame(['eq_users_replace_audit_user'], $adapter->findBindingTriggersOnTable('users'));
		}

		/**
		 * A trigger calling an ordinary (non-trigger) routine isn't a binding.
		 * @return void
		 */
		public function testIgnoresATriggerCallingAnOrdinaryRoutine(): void {
			$adapter = $this->adapterReturning('mysql', null, [
				['trigger_name' => 'some_user_trigger', 'body' => 'CALL `log_change`(OLD.`id`)'],
			], ['log_change' => self::metadata('void')]);

			self::assertSame([], $adapter->findBindingTriggersOnTable('users'));
		}

		/**
		 * A trigger calling a routine with no recognizable ObjectQuel metadata isn't a binding.
		 * @return void
		 */
		public function testIgnoresATriggerCallingAnUnmanagedRoutine(): void {
			$adapter = $this->adapterReturning('mysql', null, [
				['trigger_name' => 'some_user_trigger', 'body' => 'CALL `legacy_proc`(OLD.`id`)'],
			], []);

			self::assertSame([], $adapter->findBindingTriggersOnTable('users'));
		}

		/**
		 * A trigger body with no recognizable CALL/EXEC pattern at all isn't a binding.
		 * @return void
		 */
		public function testIgnoresATriggerWithNoCallAtAll(): void {
			$adapter = $this->adapterReturning('mysql', null, [
				['trigger_name' => 'some_user_trigger', 'body' => 'SET NEW.updated_at = NOW()'],
			], []);

			self::assertSame([], $adapter->findBindingTriggersOnTable('users'));
		}

		/**
		 * PostgreSQL finds the binding through the generated helper function's source.
		 * @return void
		 */
		public function testPostgresFindsBindingThroughHelperSource(): void {
			$adapter = $this->adapterReturning('pgsql', null, [
				['trigger_name' => 'eq_users_replace_audit_user', 'body' => 'BEGIN CALL "audit_user"(OLD."id", NEW."id"); RETURN NEW; END;'],
			], ['audit_user' => self::metadata('void', isTrigger: true)]);

			self::assertSame(['eq_users_replace_audit_user'], $adapter->findBindingTriggersOnTable('users'));
		}

		/**
		 * SQL Server finds the binding whether the call is schema-qualified or not.
		 * @return void
		 */
		public function testSqlServerFindsBindingRegardlessOfSchemaQualification(): void {
			$adapter = $this->adapterReturning('sqlsrv', 'dbo', [
				['trigger_name' => 'eq_users_replace_audit_user', 'body' => 'EXEC [dbo].[audit_user] @_a0, @_a1;'],
			], ['audit_user' => self::metadata('void', isTrigger: true)]);

			self::assertSame(['eq_users_replace_audit_user'], $adapter->findBindingTriggersOnTable('users'));
		}

		/**
		 * @return void
		 */
		public function testListBindingsReturnsTableEventRoutineAndAlias(): void {
			$adapter = $this->adapterReturning('mysql', null, [
				['trigger_name' => 'eq_users_audit_trigger', 'table_name' => 'users', 'event' => 'INSERT', 'body' => 'CALL `audit_user`(NEW.`id`)'],
			], ['audit_user' => self::metadata('void', isTrigger: true)]);

			self::assertSame([
				['table' => 'users', 'event' => BindingEvent::Append, 'routine' => 'audit_user', 'alias' => 'audit_trigger'],
			], $adapter->listBindings());
		}

		/**
		 * UPDATE and DELETE map to Replace and Delete, same as Append for INSERT.
		 * @return void
		 */
		public function testListBindingsMapsUpdateAndDelete(): void {
			$adapter = $this->adapterReturning('mysql', null, [
				['trigger_name' => 'eq_users_t1', 'table_name' => 'users', 'event' => 'UPDATE', 'body' => 'CALL `f`(OLD.`id`)'],
				['trigger_name' => 'eq_users_t2', 'table_name' => 'users', 'event' => 'DELETE', 'body' => 'CALL `f`(OLD.`id`)'],
			], ['f' => self::metadata('void', isTrigger: true)]);

			$events = array_column($adapter->listBindings(), 'event');
			self::assertSame([BindingEvent::Replace, BindingEvent::Delete], $events);
		}

		/**
		 * A trigger calling a non-trigger or unmanaged routine is excluded, same as
		 * findBindingTriggersOnTable().
		 * @return void
		 */
		public function testListBindingsExcludesNonBindingTriggers(): void {
			$adapter = $this->adapterReturning('mysql', null, [
				['trigger_name' => 'some_user_trigger', 'table_name' => 'users', 'event' => 'UPDATE', 'body' => 'CALL `log_change`(OLD.`id`)'],
			], ['log_change' => self::metadata('void')]);

			self::assertSame([], $adapter->listBindings());
		}

		/**
		 * A trigger name that doesn't start with the expected `eq_<table>_` prefix (the
		 * truncation edge case — see EventBindingNaming::truncate()) is shown whole rather
		 * than guessed at.
		 * @return void
		 */
		public function testListBindingsFallsBackToWholeNameWhenPrefixIsMissing(): void {
			$adapter = $this->adapterReturning('mysql', null, [
				['trigger_name' => 'eq_somethingelse_ab12cd34', 'table_name' => 'users', 'event' => 'INSERT', 'body' => 'CALL `audit_user`(NEW.`id`)'],
			], ['audit_user' => self::metadata('void', isTrigger: true)]);

			self::assertSame('eq_somethingelse_ab12cd34', $adapter->listBindings()[0]['alias']);
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
