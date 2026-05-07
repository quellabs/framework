<?php
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Cake\Database\Connection;
	
	abstract class ObjectQuelTestCase extends TestCase {
		
		protected EntityManager $em;
		protected Connection $connection;
		
		/**
		 * Tables to truncate before each test, in order (respecting FK constraints).
		 * Override in subclasses if you need a different set.
		 */
		protected array $truncateTables = ['posts', 'users'];
		
		protected function setUp(): void {
			$this->em = $GLOBALS['test_em'];
			$this->connection = $GLOBALS['test_connection'];
			
			// Truncate in reverse FK order (posts before users)
			foreach ($this->truncateTables as $table) {
				$this->connection->execute("DELETE FROM `{$table}`");
			}
			
			// Reset auto-increment so IDs are predictable
			foreach ($this->truncateTables as $table) {
				$this->connection->execute("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
			}
			
			$this->seedFixtures();
		}
		
		protected function seedFixtures(): void {}
		
		protected function exec(string $sql, array $params = []): void {
			$this->connection->execute($sql, $params);
		}
	}