<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use Cake\Database\StatementInterface;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\Execution\Executors\DestroyRoutineExecutor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyRoutine;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Verifies routine destruction checks existence through DatabaseAdapter before running MySQL DROP statements.
	 */
	class DestroyRoutineExecutorTest extends TestCase {

		/**
		 * A present function or procedure allows both kind-specific DROP statements to run.
		 * @return void
		 */
		public function testPresentRoutineRunsBothDrops(): void {
			$connection = $this->createMock(DatabaseAdapter::class);
			$connection->expects(self::once())->method('routineExists')->with('f')->willReturn(true);
			$connection->expects(self::exactly(2))->method('execute')->willReturn($this->createMock(StatementInterface::class));

			(new DestroyRoutineExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute(new AstDestroyRoutine('f', false), new ExecutionContext([]));
		}

		/**
		 * A missing routine without `if exists` raises the established destruction error before issuing DROP statements.
		 * @return void
		 */
		public function testMissingRoutineFailsBeforeDrops(): void {
			$connection = $this->createMock(DatabaseAdapter::class);
			$connection->expects(self::once())->method('routineExists')->with('f')->willReturn(false);
			$connection->expects(self::never())->method('execute');

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("Failed to destroy routine 'f': it doesn't exist");
			(new DestroyRoutineExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute(new AstDestroyRoutine('f', false), new ExecutionContext([]));
		}

		/**
		 * `if exists` relies on the compiled DROP statements and does not perform an existence lookup.
		 * @return void
		 */
		public function testIfExistsSkipsLookup(): void {
			$connection = $this->createMock(DatabaseAdapter::class);
			$connection->expects(self::never())->method('routineExists');
			$connection->expects(self::exactly(2))->method('execute')->willReturn($this->createMock(StatementInterface::class));

			(new DestroyRoutineExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute(new AstDestroyRoutine('f', true), new ExecutionContext([]));
		}

		/**
		 * Existence lookup failures retain their original catalog error.
		 * @return void
		 */
		public function testLookupFailurePropagates(): void {
			$connection = $this->createMock(DatabaseAdapter::class);
			$connection->expects(self::once())->method('routineExists')->with('f')
				->willThrowException(new QuelException("Failed to look up routine 'f': catalog unavailable", 'routine_destruction_error'));
			$connection->expects(self::never())->method('execute');

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('catalog unavailable');
			(new DestroyRoutineExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute(new AstDestroyRoutine('f', false), new ExecutionContext([]));
		}
	}
