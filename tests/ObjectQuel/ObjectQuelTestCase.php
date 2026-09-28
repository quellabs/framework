<?php
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	
	abstract class ObjectQuelTestCase extends TestCase {
		
		protected EntityManager $em;
		
		/**
		 * Tables to DELETE before each test, in FK-safe order (children first).
		 * Override in subclasses if your fixtures touch different tables.
		 */
		protected array $truncateTables = ['posts', 'users'];
		
		protected function setUp(): void {
			// fetch the EntityManager
			$this->em = $GLOBALS['test_em'];
			
			// DatabaseAdapter wraps a CakePHP Connection. Go through the inner
			// connection directly so the DELETE executes on the same session that
			// the EntityManager uses for queries, and exceptions are not swallowed.
			$conn = $this->em->getConnection()->getConnection();

			if ($this->em->getConnection()->getDatabaseType() === 'pgsql') {
				if ($this->truncateTables !== []) {
					$tables = array_map($conn->getDriver()->quoteIdentifier(...), $this->truncateTables);
					$conn->execute('TRUNCATE TABLE ' . implode(', ', $tables) . ' RESTART IDENTITY CASCADE');
				}
			} elseif ($this->em->getConnection()->getDatabaseType() === 'sqlite') {
				foreach ($this->truncateTables as $table) {
					$quoted = $conn->getDriver()->quoteIdentifier($table);
					$conn->execute("DELETE FROM {$quoted}");
					$conn->execute('DELETE FROM sqlite_sequence WHERE name = :table', ['table' => $table]);
				}
			} else {
				foreach ($this->truncateTables as $table) {
					$conn->execute("DELETE FROM `{$table}`");
					$conn->execute("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
				}
			}
			
			// Clear the identity map so stale entities from previous tests cannot
			// bleed through when the same primary keys are reused after truncation.
			$this->em->getUnitOfWork()->clear();
			
			// Seed the database
			$this->seedFixtures();

			if ($this->em->getConnection()->getDatabaseType() === 'pgsql') {
				foreach ($this->truncateTables as $table) {
					$quoted = $conn->getDriver()->quoteIdentifier($table);
					$conn->execute(
						"SELECT setval(pg_get_serial_sequence(:table, 'id'), COALESCE(MAX(id), 1), MAX(id) IS NOT NULL) FROM {$quoted}",
						['table' => $table]
					);
				}
			}
		}
		
		/**
		 * Override in each test class to insert the rows the test needs.
		 */
		protected function seedFixtures(): void {}
		
		/**
		 * Convenience wrapper — executes raw SQL via the EntityManager's connection.
		 */
		protected function exec(string $sql, array $params = []): void {
			$this->em->getConnection()->getConnection()->execute($sql, $params);
		}
	}
