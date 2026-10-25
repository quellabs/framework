<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\RoutineSignature;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Execution\Helpers\RoutineCallTyper;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCall;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineCall;

	class RoutineCallTyperTest extends TestCase {

		/**
		 * Verifies repeated calls share metadata only within one typing pass.
		 * @return void
		 */
		public function testRepeatedCallsShareLookupOnlyWithinOneQuery(): void {
			$adapter = $this->createMock(DatabaseAdapter::class);
			$adapter->expects(self::exactly(2))->method('getRoutineSignature')->with('f')
				->willReturnOnConsecutiveCalls(new RoutineSignature(false, 'integer'), new RoutineSignature(false, 'text'));
			$inner = new AstRoutineCall('f', []);
			$outer = new AstRoutineCall('f', [$inner]);
			$typer = new RoutineCallTyper($adapter);
			$typer->typeCalls($outer);
			self::assertSame('integer', $inner->getRoutineReturnType());
			self::assertSame('integer', $outer->getRoutineReturnType());
			$typer->typeCalls($outer);
			self::assertSame('text', $inner->getRoutineReturnType());
			self::assertSame('text', $outer->getRoutineReturnType());
		}

		/**
		 * Verifies procedures are rejected in expressions.
		 * @return void
		 */
		public function testProcedureCannotBeUsedAsAnExpression(): void {
			$adapter = $this->createMock(DatabaseAdapter::class);
			$adapter->method('getRoutineSignature')->with('p')->willReturn(new RoutineSignature(true, null));
			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("'p' is a procedure, which returns no value");
			(new RoutineCallTyper($adapter))->typeCalls(new AstRoutineCall('p', []));
		}

		/**
		 * Verifies unknown function return types remain unset.
		 * @return void
		 */
		public function testUnknownReturnTypeRemainsUnknown(): void {
			$adapter = $this->createMock(DatabaseAdapter::class);
			$adapter->method('getRoutineSignature')->willReturn(new RoutineSignature(false, null));
			$call = new AstRoutineCall('f', []);
			(new RoutineCallTyper($adapter))->typeCalls($call);
			self::assertNull($call->getRoutineReturnType());
		}

		/**
		 * Standalone calls may invoke procedures (still looked up, to reject a tfunction
		 * target), while expression arguments still need their types.
		 * @return void
		 */
		public function testRoutineBodySkipsStandaloneCallButTypesItsExpressionArguments(): void {
			$adapter = $this->createMock(DatabaseAdapter::class);
			$adapter->method('getRoutineSignature')->willReturnMap([
				['p', new RoutineSignature(true, null)],
				['f', new RoutineSignature(false, 'text')],
			]);
			$argument = new AstRoutineCall('f', []);
			$statement = new AstCall(new AstRoutineCall('p', [$argument]));

			(new RoutineCallTyper($adapter))->typeCalls($statement, true);

			self::assertSame('text', $argument->getRoutineReturnType());
		}

		/**
		 * A tfunction has no call path outside an event binding, not even as
		 * a standalone statement call.
		 * @return void
		 */
		public function testTriggerRoutineRejectedEvenAsStandaloneStatementCall(): void {
			$adapter = $this->createMock(DatabaseAdapter::class);
			$adapter->method('getRoutineSignature')->with('audit_user')
				->willReturn(new RoutineSignature(true, null, false, true));
			$statement = new AstCall(new AstRoutineCall('audit_user', []));

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("'audit_user' is declared with 'tfunction'");
			(new RoutineCallTyper($adapter))->typeCalls($statement, true);
		}

		/**
		 * A tfunction is rejected in an expression too, before the
		 * procedure-in-an-expression check even runs.
		 * @return void
		 */
		public function testTriggerRoutineRejectedAsExpression(): void {
			$adapter = $this->createMock(DatabaseAdapter::class);
			$adapter->method('getRoutineSignature')->with('audit_user')
				->willReturn(new RoutineSignature(true, null, false, true));

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("'audit_user' is declared with 'tfunction'");
			(new RoutineCallTyper($adapter))->typeCalls(new AstRoutineCall('audit_user', []));
		}
	}
