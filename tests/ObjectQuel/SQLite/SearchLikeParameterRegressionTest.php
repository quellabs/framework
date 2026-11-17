<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * Regression coverage for a search() bug: when the search term is passed as a
	 * runtime :parameter rather than a string literal, SearchStrategyResolver
	 * rewrites the search() call into a fresh LIKE chain with its own inject_*
	 * bound parameters, but the original :parameter stayed bound with no matching
	 * placeholder left anywhere in the rendered SQL. PDO rejects that mismatch
	 * with "SQLSTATE[HY093]: Invalid parameter number: number of bound variables
	 * does not match number of tokens" — so search() never worked at all with a
	 * bound parameter, only with a literal string.
	 *
	 * SearchTermEntity::$label carries no FullTextIndex, so search() resolves to
	 * the LIKE fallback being tested here.
	 *
	 * Uses the suite's shared $GLOBALS['test_em'] — see
	 * RelationshipCascadeForeignKeyTest for why a second EntityManager can't
	 * be constructed in this process.
	 */
	class SearchLikeParameterRegressionTest extends TestCase {

		private static function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		protected function setUp(): void {
			$adapter = self::em()->getConnection();
			$adapter->execute('DELETE FROM search_term_entities');
			self::em()->getUnitOfWork()->clear();
		}

		public function testSearchWithBoundParameterMatchesLikeFallback(): void {
			$adapter = self::em()->getConnection();
			$adapter->execute("INSERT INTO search_term_entities (label) VALUES ('Onbekende afschrijving')");
			$adapter->execute("INSERT INTO search_term_entities (label) VALUES ('Bekende afschrijving')");
			$adapter->execute("INSERT INTO search_term_entities (label) VALUES ('Factuur 123')");

			$rows = self::em()->getAll('
				range of s is App\\Entities\\SearchTermEntity
				retrieve (s.label)
				where search(s.label, :term)
				sort by s.label
			', ['term' => 'onbekende']);

			$this->assertSame([['s.label' => 'Onbekende afschrijving']], $rows);
		}

		public function testSearchWithBoundParameterCombinesWithWindowAndCount(): void {
			$adapter = self::em()->getConnection();
			$adapter->execute("INSERT INTO search_term_entities (label) VALUES ('Afschrijving een')");
			$adapter->execute("INSERT INTO search_term_entities (label) VALUES ('Afschrijving twee')");
			$adapter->execute("INSERT INTO search_term_entities (label) VALUES ('Afschrijving drie')");
			$adapter->execute("INSERT INTO search_term_entities (label) VALUES ('Niet gevonden')");

			$countRows = self::em()->getAll('
				range of s is App\\Entities\\SearchTermEntity
				retrieve (total = count(s.id))
				where search(s.label, :term)
			', ['term' => 'afschrijving']);

			$this->assertSame(3, $countRows[0]['total']);

			$pageRows = self::em()->getAll('
				range of s is App\\Entities\\SearchTermEntity
				retrieve (s.label)
				where search(s.label, :term)
				sort by s.label asc
				window 1, 2
			', ['term' => 'afschrijving']);

			$this->assertSame([['s.label' => 'Afschrijving twee']], $pageRows);
		}

		/**
		 * Guards the fix's safety check: the same :parameter name used by search()
		 * and, separately, by a plain comparison elsewhere in the WHERE clause must
		 * stay bound for that other comparison — search() may only drop it when
		 * nothing else in the query still needs it.
		 */
		public function testSharedParameterNameStaysBoundForTheOtherUsage(): void {
			$adapter = self::em()->getConnection();
			// Both labels match search(s.label, :term); only the first's note
			// also equals :term exactly, so only the AND condition distinguishes them.
			$adapter->execute("INSERT INTO search_term_entities (label, note) VALUES ('Afschrijving bank', 'afschrijving')");
			$adapter->execute("INSERT INTO search_term_entities (label, note) VALUES ('Afschrijving giro', 'anders')");

			$rows = self::em()->getAll('
				range of s is App\\Entities\\SearchTermEntity
				retrieve (s.label, s.note)
				where search(s.label, :term) and s.note = :term
			', ['term' => 'afschrijving']);

			$this->assertSame([['s.label' => 'Afschrijving bank', 's.note' => 'afschrijving']], $rows);
		}
	}
