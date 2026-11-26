<?php

	namespace Quellabs\ObjectQuel\Tests\Universal;

	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * A computed projection (an aggregate or arithmetic over columns) is hydrated to the type its
	 * operands determine, not to whatever the driver hands back: MySQL returns SUM over an integer
	 * column as a DECIMAL string. Fixture: two users, five posts with ids 1-5 (three for user 1,
	 * two for user 2).
	 */
	class ComputedValueHydrationTest extends ObjectQuelTestCase {

		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', FALSE)");
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (2, 'bob', 'hash2', FALSE)");

			foreach ([[1, 1], [2, 1], [3, 1], [4, 2], [5, 2]] as $post) {
				$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
					VALUES ({$post[0]}, 'p{$post[0]}', 'content', TRUE, '2024-01-0{$post[0]} 00:00:00', 'pending', '{}', {$post[1]})");
			}
		}

		public function testSumOfAnIntegerColumnIsAnInteger(): void {
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (total = sum(o.id))
			"));

			$this->assertSame(15, $result[0]['total']);
		}

		public function testSumBesideAColumnIsAnIntegerOnEveryRow(): void {
			// Beside a plain column the aggregate is planned as a window subquery:
			// one row per post, each carrying its own user's total.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, total = sum(o.id))
			"));
			$totals = array_column($result, 'total');
			sort($totals);

			$this->assertSame([6, 6, 6, 9, 9], $totals);
		}

		public function testSumWithAnExplicitGroupingColumnIsAnInteger(): void {
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, total = sum(o.id by o.userId))
			"));

			foreach ($result as $row) {
				$this->assertContains($row['total'], [6, 9]);
				$this->assertIsInt($row['total']);
			}

			$this->assertNotEmpty($result);
		}

		public function testMinAndMaxOfAnIntegerColumnAreIntegers(): void {
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (lowest = min(o.id), highest = max(o.id))
			"));

			$this->assertSame(1, $result[0]['lowest']);
			$this->assertSame(5, $result[0]['highest']);
		}

		public function testCountIsAnIntegerWhateverTheColumnType(): void {
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (titles = count(o.title))
			"));

			$this->assertSame(5, $result[0]['titles']);
		}

		public function testArithmeticOverIntegerOperandsIsAnInteger(): void {
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (doubled = o.id * 2, shifted = o.id + 10)
				where o.id = 3
			"));

			$this->assertSame(6, $result[0]['doubled']);
			$this->assertSame(13, $result[0]['shifted']);
		}

		public function testMaxOverNoRowsStaysNull(): void {
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (highest = max(o.id))
				where o.id > 100
			"));

			$this->assertNull($result[0]['highest'] ?? null);
		}

		public function testAverageKeepsItsFraction(): void {
			// 1 and 2 average to 1.5: an integer cast would silently drop the fraction.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (mean = avg(o.id))
				where o.id <= 2
			"));

			$this->assertEqualsWithDelta(1.5, (float)$result[0]['mean'], 0.0001);
		}
	}
