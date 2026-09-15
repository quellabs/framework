<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * Regression coverage for QuelToSQLDelete's use of the shared
	 * WriteVerbParameterNormalizer (see AppendParameterNormalizationTest /
	 * ReplaceParameterNormalizationTest, its `append`/`replace` counterparts,
	 * and WriteVerbParameterNormalizer's own docblock): a
	 * `delete <range> where ...` statement's bound-parameter values must be
	 * normalized through the same Serializer::denormalizeValue() logic
	 * InsertPersister/UpdatePersister use whenever they're compared against
	 * a real entity column — `delete` has no SET clause, so its WHERE clause
	 * is the only place this can matter.
	 *
	 * Uses App\Entities\PostEntity ("posts"), the only fixture entity with a
	 * datetime-typed column.
	 */
	class DeleteParameterNormalizationTest extends ObjectQuelTestCase {

		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', 0)");
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
                VALUES (1, 'Old post', 'Body', 0, '2020-01-01 00:00:00', 'pending', '{\"id\": 1}', 1)");
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
                VALUES (2, 'New post', 'Body', 0, '2030-01-01 00:00:00', 'pending', '{\"id\": 2}', 1)");
		}

		public function testDeleteNormalizesADateTimeParameterComparedAgainstADatetimeColumn(): void {
			$result = $this->em->executeQuery('
				range of p is App\Entities\PostEntity
				delete p where p.createdAt < :cutoff
			', ['cutoff' => new \DateTime('2025-01-01 00:00:00')]);

			$this->assertSame(1, $result->getAffectedRows());

			$remainingTitles = $this->em->getCol('
				range of p is App\Entities\PostEntity retrieve (p.title)
			');

			$this->assertSame(['New post'], $remainingTitles);
		}
	}
