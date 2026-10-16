<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Execution\Transformers\PaginationTransformer;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Regression coverage for PaginationTransformer bugs found auditing it for
	 * composite-key readiness, both pre-existing for a single-column key too:
	 *
	 * - addInConditionForPagination() wrapped every page-filter value in
	 *   AstNumber, so a non-numeric primary key (e.g. a natural string key)
	 *   compiled to an unquoted, invalid SQL literal — see
	 *   testWindowedPaginationOverAStringPrimaryKeyDoesNotCompileAnInvalidLiteral().
	 * - fetchAllPrimaryKeysForPagination() had no deterministic ORDER BY when
	 *   the query declared no sort of its own, so which rows land on which
	 *   page was formally undefined — see
	 *   testEnsureDeterministicSortAddsAPrimaryKeyOrderOnlyWhenNoneWasDeclared().
	 *
	 * Uses the suite's shared $GLOBALS['test_em'] — see
	 * RelationshipCascadeForeignKeyTest for why a second EntityManager can't
	 * be constructed in this process.
	 */
	class PaginationTransformerRegressionTest extends TestCase {

		private static function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		protected function setUp(): void {
			$adapter = self::em()->getConnection();
			$adapter->execute('DELETE FROM string_pk_entities');
			$adapter->execute('DELETE FROM accounts');
			self::em()->getUnitOfWork()->clear();
		}

		public function testWindowedPaginationOverAStringPrimaryKeyDoesNotCompileAnInvalidLiteral(): void {
			$adapter = self::em()->getConnection();
			$adapter->execute("INSERT INTO string_pk_entities (code, position) VALUES ('B', 20)");
			$adapter->execute("INSERT INTO string_pk_entities (code, position) VALUES ('A', 10)");
			$adapter->execute("INSERT INTO string_pk_entities (code, position) VALUES ('C', 30)");

			$rows = self::em()->getAll('
				range of s is App\\Entities\\StringPkEntity
				retrieve (s.code, s.position)
				sort by s.position
				window 0, 2
			');

			$this->assertSame(
				[['s.code' => 'A', 's.position' => 10], ['s.code' => 'B', 's.position' => 20]],
				$rows
			);
		}

		public function testEnsureDeterministicSortAddsAPrimaryKeyOrderOnlyWhenNoneWasDeclared(): void {
			$em = self::em();
			$adapter = $em->getConnection();
			$adapter->execute("INSERT INTO accounts (id, label) VALUES (1, 'alpha')");
			$adapter->execute("INSERT INTO accounts (id, label) VALUES (2, 'beta')");

			$transformer = new PaginationTransformer($em, new FakePlatformCapabilities('sqlite'));

			// No sort declared — the transformer must impose one over the primary key.
			$ast = $this->parseAccountWindowRetrieve();
			$parameters = [];
			$transformer->transform($ast, $parameters);

			$sort = $ast->getSort();
			$this->assertCount(1, $sort);
			$this->assertInstanceOf(AstIdentifier::class, $sort[0]['ast']);
			$this->assertSame('id', $sort[0]['ast']->getNext()->getName());
			$this->assertSame('asc', $sort[0]['order']);

			// An explicit sort is left exactly as the caller declared it.
			$astWithSort = $this->parseAccountWindowRetrieve();
			$ownSort = [['ast' => new AstIdentifier('a'), 'order' => 'desc']];
			$astWithSort->setSort($ownSort);
			$parameters = [];
			$transformer->transform($astWithSort, $parameters);

			$this->assertSame($ownSort, $astWithSort->getSort());
		}

		/**
		 * Parses `range of a is App\Entities\AccountEntity retrieve (a.id,
		 * a.label) window 0, 1` for driving PaginationTransformer::transform()
		 * directly, without running it through the full execution pipeline.
		 * @return AstRetrieve
		 */
		private function parseAccountWindowRetrieve(): AstRetrieve {
			$query = '
				range of a is App\\Entities\\AccountEntity
				retrieve (a.id, a.label)
				window 0, 1
			';

			$ast = (new Parser(new Lexer($query), self::em()->getEntityStore()))->parse();
			$this->assertInstanceOf(AstRetrieve::class, $ast);
			return $ast;
		}
	}
