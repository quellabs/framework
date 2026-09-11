<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use App\Enums\TestEnum;
	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * Regression coverage for AppendExecutor::normalizeParameterValues():
	 * a literal-values `append to <entity-range> (...)` statement must
	 * normalize bound-parameter values through the same
	 * Serializer::denormalizeValue() logic InsertPersister::persist() uses,
	 * so a \DateTime object, a PHP array, and a backed enum bound as
	 * `:param`s land in the database in the exact same storage
	 * representation persist() would produce — not as raw, unconverted PHP
	 * values handed straight to the driver.
	 *
	 * Uses App\Entities\PostEntity ("posts"), the only fixture entity with
	 * datetime/json/enum-typed columns.
	 */
	class AppendParameterNormalizationTest extends ObjectQuelTestCase {

		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', 0)");
		}

		public function testAppendNormalizesDateTimeJsonAndEnumParametersLikePersist(): void {
			$createdAt = new \DateTime('2024-06-01 12:34:56');
			$json = ['id' => 1, 'test' => 'hi'];

			$result = $this->em->executeQuery('
				range of p is App\Entities\PostEntity
				append to p (
					title = :title,
					content = :content,
					published = false,
					createdAt = :createdAt,
					TestEnum = :testEnum,
					testJSON = :testJson,
					userId = :userId
				)
			', [
				'title' => 'Normalized Post',
				'content' => 'Body',
				'createdAt' => $createdAt,
				'testEnum' => TestEnum::PENDING,
				'testJson' => $json,
				'userId' => 1,
			]);

			$this->assertSame(1, $result->getAffectedRows());
			$id = $result->getGeneratedId();
			$this->assertIsInt($id);

			// Assert the raw stored representation matches what persist() would
			// have written — proves normalization happened before the INSERT,
			// not just that retrieve() can coerce it back afterward.
			$row = $this->em->getConnection()
				->execute('SELECT created_at, test_enum, test_json FROM posts WHERE id = :id', ['id' => $id])
				->fetchAll('assoc')[0];

			$this->assertSame('2024-06-01 12:34:56', $row['created_at']);
			$this->assertSame('pending', $row['test_enum']);
			$this->assertSame($json, json_decode($row['test_json'], true));

			// And the normal ObjectQuel retrieve/hydration path reads it back
			// into the correct PHP types, same as a persist()ed entity would.
			$hydrated = $this->em->executeQuery('
				range of p is App\Entities\PostEntity
				retrieve (p)
				where p.id = :id
			', ['id' => $id]);

			$post = $hydrated[0]['p'];
			$this->assertEquals($createdAt, $post->getCreatedAt());
			$this->assertSame(TestEnum::PENDING, $post->getTestEnum());
			$this->assertSame($json, $post->getTestJSON());
		}
	}
