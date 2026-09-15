<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use App\Enums\TestEnum;
	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * Regression coverage for QuelToSQLReplace's use of the shared
	 * WriteVerbParameterNormalizer (see AppendParameterNormalizationTest,
	 * its `append` counterpart, and WriteVerbParameterNormalizer's own
	 * docblock): a `replace <range> (...) where ...` statement's SET-clause
	 * bound-parameter values must be normalized through the same
	 * Serializer::denormalizeValue() logic InsertPersister/UpdatePersister
	 * use, exactly like `append`'s literal-values form.
	 *
	 * Uses App\Entities\PostEntity ("posts"), the only fixture entity with
	 * datetime/json/enum-typed columns.
	 */
	class ReplaceParameterNormalizationTest extends ObjectQuelTestCase {

		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', 0)");
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
                VALUES (1, 'Original', 'Body', 0, '2024-01-01 00:00:00', 'pending', '{\"id\": 1}', 1)");
		}

		public function testReplaceNormalizesDateTimeAndEnumParametersLikePersist(): void {
			$updatedAt = new \DateTime('2024-07-15 09:30:00');

			$result = $this->em->executeQuery('
				range of p is App\Entities\PostEntity
				replace p (createdAt = :createdAt, TestEnum = :testEnum) where p.id = :id
			', [
				'createdAt' => $updatedAt,
				'testEnum' => TestEnum::SHIPPED,
				'id' => 1,
			]);

			$this->assertSame(1, $result->getAffectedRows());

			// Raw stored representation — proves normalization happened
			// before the UPDATE, not just that retrieve() can coerce it back.
			$row = $this->em->getConnection()
				->execute('SELECT created_at, test_enum FROM posts WHERE id = 1')
				->fetchAll('assoc')[0];

			$this->assertSame('2024-07-15 09:30:00', $row['created_at']);
			$this->assertSame('shipped', $row['test_enum']);
		}
	}
