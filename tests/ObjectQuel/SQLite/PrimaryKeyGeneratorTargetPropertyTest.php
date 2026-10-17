<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use App\Entities\SequenceComponentEntity;

	/**
	 * Regression coverage for SequenceGenerator::generate() hardcoding
	 * $metadata->identifierKeys[0] for its MAX(col)+1 lookup instead of the
	 * property it was actually asked to generate a value for.
	 *
	 * App\Entities\SequenceComponentEntity declares 'token' (uuid strategy)
	 * before 'seq' (sequence strategy): the bug read MAX(token) — a uuid
	 * string — instead of MAX(seq) when computing the next value for 'seq'.
	 * Pre-seeding rows with disjoint token/seq ranges makes the two possible
	 * results unambiguously different.
	 *
	 * Uses the suite's shared $GLOBALS['test_em'] — see
	 * RelationshipCascadeForeignKeyTest for why a second EntityManager can't
	 * be constructed in this process.
	 */
	class PrimaryKeyGeneratorTargetPropertyTest extends TestCase {

		private static function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		protected function setUp(): void {
			$adapter = self::em()->getConnection();
			$adapter->execute('DELETE FROM sequence_component_entities');
			self::em()->getUnitOfWork()->clear();
		}

		public function testSequenceStrategyReadsItsOwnColumnRegardlessOfItsPositionAmongTheIdentifierKeys(): void {
			// MAX(token) ('row-b', lexicographically) and MAX(seq) (6) lead to
			// clearly different "next value" results: 1 (SQLite's numeric
			// coercion of a non-numeric string) if the bug reads the wrong
			// column, 7 if it correctly reads 'seq'.
			$adapter = self::em()->getConnection();
			$adapter->execute("INSERT INTO sequence_component_entities (token, seq, amount) VALUES ('row-a', 5, 10)");
			$adapter->execute("INSERT INTO sequence_component_entities (token, seq, amount) VALUES ('row-b', 6, 20)");

			$entity = new SequenceComponentEntity();
			$entity->setAmount(30);
			self::em()->persist($entity);
			self::em()->flush();

			$this->assertSame(7, $entity->getSeq());
			$this->assertIsString($entity->getToken());
			$this->assertNotSame('', $entity->getToken());

			$row = self::em()->getAll('
				range of s is App\\Entities\\SequenceComponentEntity
				retrieve (s.token, s.seq, s.amount)
				where s.seq = :seq
			', ['seq' => 7]);

			$this->assertCount(1, $row);
			$this->assertSame(30, $row[0]['s.amount']);
		}
	}
