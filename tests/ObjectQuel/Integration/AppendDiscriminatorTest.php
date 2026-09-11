<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * Integration coverage for `append`'s single-table-inheritance
	 * discriminator-column injection, for both the literal-values form and
	 * insert-from-select — see App\Entities\VehicleEntity/CarEntity and
	 * QuelToSQLAppend::compileValues()/compileFromSelect().
	 */
	class AppendDiscriminatorTest extends ObjectQuelTestCase {

		protected array $truncateTables = ['sti_vehicles', 'users'];

		/** @return array{name: string, vehicle_type: string}|false */
		private function readVehicleRow(string $name): array|false {
			return $this->em->getConnection()
				->execute('SELECT name, vehicle_type FROM sti_vehicles WHERE name = :name', ['name' => $name])
				->fetchAssoc();
		}

		public function testLiteralValuesAppendWritesTheDiscriminatorColumn(): void {
			$result = $this->em->executeQuery(
				'range of c is App\Entities\CarEntity
				append to c (name = :name)',
				['name' => 'Model T']
			);

			$this->assertSame(1, $result->getAffectedRows());

			$row = $this->readVehicleRow('Model T');

			$this->assertNotFalse($row);
			$this->assertSame('car', $row['vehicle_type']);
		}

		public function testInsertFromSelectAppendWritesTheDiscriminatorColumn(): void {
			$seeded = $this->em->executeQuery(
				'range of u is App\Entities\UserEntity
				append to u (username = :username, password = :password, banned = false)',
				['username' => 'Roadster', 'password' => 'pw']
			);

			$result = $this->em->executeQuery('
				range of dst is App\Entities\CarEntity
				range of src is App\Entities\UserEntity
				append to dst (name) retrieve (src.username) where src.id = :sourceId
			', ['sourceId' => $seeded->getGeneratedId()]);

			$this->assertSame(1, $result->getAffectedRows());

			$row = $this->readVehicleRow('Roadster');

			$this->assertNotFalse($row, 'insert-from-select must have written a row into sti_vehicles');
			$this->assertSame('car', $row['vehicle_type']);
		}
	}
