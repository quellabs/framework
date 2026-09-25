<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\RoutineSignature;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Execution\Helpers\RoutineCallTyper;
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
	}
