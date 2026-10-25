<?php

	namespace Quellabs\ObjectQuel\Tests\SQLServer;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;
	use Quellabs\ObjectQuel\Tests\Support\RetrieveSqlCompiler;

	/**
	 * T-SQL emission for sequence functions (rank, dense_rank, row_number, ntile,
	 * lag, lead) and their window-strategy rewrites. The expected SQL is not run
	 * against SQL Server here — see SqlServerRoutineCompilerTest's own docblock
	 * for why (no live SQL Server available to this suite).
	 */
	class SequenceFunctionSqlEmissionTest extends TestCase {

		private function compile(string $query): string {
			$platform = new class('sqlsrv') extends FakePlatformCapabilities {
				public function supportsWindowFunctions(): bool {
					return true;
				}
			};

			return (new RetrieveSqlCompiler($GLOBALS['test_em'], $platform))->compile($query);
		}

		public function testRowNumberEmitsBracketQuotedOverClause(): void {
			$sql = $this->compile('
				range of o is PostEntity
				retrieve (o.userId, o.id, rn = row_number(sort by o.id))
			');

			self::assertStringContainsString(
				'ROW_NUMBER() OVER (PARTITION BY [o].[user_id] ORDER BY [o].[id] ASC) as [rn]',
				$sql
			);
		}

		public function testExplicitPartitionByOverridesInference(): void {
			$sql = $this->compile('
				range of o is PostEntity
				retrieve (o.userId, o.title, r = rank(by o.userId sort by o.id desc))
			');

			self::assertStringContainsString(
				'RANK() OVER (PARTITION BY [o].[user_id] ORDER BY [o].[id] DESC) as [r]',
				$sql
			);
		}

		public function testDenseRankAndNtile(): void {
			$sql = $this->compile('
				range of o is PostEntity
				retrieve (o.userId, o.id, dr = dense_rank(by o.userId sort by o.id), b = ntile(4 by o.userId sort by o.id))
			');

			self::assertStringContainsString('DENSE_RANK() OVER (PARTITION BY [o].[user_id] ORDER BY [o].[id] ASC) as [dr]', $sql);
			self::assertStringContainsString('NTILE(4) OVER (PARTITION BY [o].[user_id] ORDER BY [o].[id] ASC) as [b]', $sql);
		}

		public function testLagAndLead(): void {
			$sql = $this->compile('
				range of o is PostEntity
				retrieve (
					o.userId,
					o.id,
					p = lag(o.id by o.userId sort by o.id),
					n = lead(o.id by o.userId sort by o.id)
				)
			');

			self::assertStringContainsString('LAG([o].[id]) OVER (PARTITION BY [o].[user_id] ORDER BY [o].[id] ASC) as [p]', $sql);
			self::assertStringContainsString('LEAD([o].[id]) OVER (PARTITION BY [o].[user_id] ORDER BY [o].[id] ASC) as [n]', $sql);
		}

		public function testRunningAggregateUsesWindowSyntax(): void {
			$sql = $this->compile('
				range of o is PostEntity
				retrieve (o.userId, o.id, running = sum(o.id by o.userId sort by o.id))
			');

			self::assertStringContainsString('COALESCE(SUM([o].[id]) OVER (PARTITION BY [o].[user_id] ORDER BY [o].[id] ASC), 0) as [running]', $sql);
		}

		public function testWhereFilterOnSequenceFunctionResultUsesDerivedTableJoin(): void {
			// SQL Server, like every other engine, can't reference a window
			// function's result in the same query block's WHERE — WhereWindowFilterRewriter's
			// derived-table rewrite must apply identically here.
			$sql = $this->compile('
				range of o is PostEntity
				retrieve (o.userId, o.id, rn = row_number(by o.userId sort by o.id))
				where rn <= 3
			');

			self::assertStringContainsString('ROW_NUMBER() OVER (PARTITION BY [o].[user_id] ORDER BY [o].[id] ASC) as [_where_seq_1._seq_1]', $sql);
			self::assertStringContainsString('INNER JOIN', $sql);
			self::assertStringContainsString('[_where_seq_1].[_where_seq_1._seq_1] <= 3', $sql);
			self::assertStringNotContainsString('WHERE [rn]', $sql);
		}
	}
