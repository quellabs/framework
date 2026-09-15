<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use Cake\Database\StatementInterface;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Execution\Executors\DdlRunner;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Coverage for runTransactionally()'s rollback-on-failure behavior —
	 * in particular that it rolls back on ANY exception raised while
	 * running the statement sequence, not just QuelException. execute()
	 * itself normally never throws (it swallows failures and returns null,
	 * which run() turns into a QuelException — see run()'s own comment),
	 * but a connection can still raise something else entirely (a type
	 * error, an unwrapped driver exception); that must not leave the
	 * transaction open either.
	 */
	class DdlRunnerTest extends TestCase {

		public function testRollsBackOnAQuelExceptionFromAFailingStatement(): void {
			$connection = $this->createMock(DatabaseAdapter::class);
			$connection->method('execute')->willReturn(null);
			$connection->method('getLastErrorMessage')->willReturn('boom');
			$connection->expects(self::once())->method('beginTrans');
			$connection->expects(self::once())->method('rollbackTrans');
			$connection->expects(self::never())->method('commitTrans');

			$this->expectException(QuelException::class);

			(new DdlRunner($connection))->runTransactionally(
				['SELECT 1'],
				new FakePlatformCapabilities('pgsql'),
				'failed',
				'error_code'
			);
		}

		/**
		 * A non-QuelException raised mid-sequence (something other than an
		 * ordinary failing statement) must still trigger a rollback and
		 * propagate — same as the QuelException case above.
		 */
		public function testRollsBackOnAnUnexpectedExceptionTypeFromAFailingStatement(): void {
			$connection = $this->createMock(DatabaseAdapter::class);
			$connection->method('execute')->willThrowException(new \RuntimeException('driver exploded'));
			$connection->expects(self::once())->method('beginTrans');
			$connection->expects(self::once())->method('rollbackTrans');
			$connection->expects(self::never())->method('commitTrans');

			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('driver exploded');

			(new DdlRunner($connection))->runTransactionally(
				['SELECT 1'],
				new FakePlatformCapabilities('pgsql'),
				'failed',
				'error_code'
			);
		}

		public function testCommitsWhenEveryStatementSucceeds(): void {
			$connection = $this->createMock(DatabaseAdapter::class);
			$connection->method('execute')->willReturn($this->createMock(StatementInterface::class));
			$connection->expects(self::once())->method('beginTrans');
			$connection->expects(self::once())->method('commitTrans');
			$connection->expects(self::never())->method('rollbackTrans');

			(new DdlRunner($connection))->runTransactionally(
				['SELECT 1'],
				new FakePlatformCapabilities('pgsql'),
				'failed',
				'error_code'
			);
		}
	}
