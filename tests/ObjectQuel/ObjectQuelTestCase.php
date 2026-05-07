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
			$this->em = $GLOBALS['test_em'];
			
			// DatabaseAdapter wraps a CakePHP Connection. Go through the inner
			// connection directly so the DELETE executes on the same session that
			// the EntityManager uses for queries, and exceptions are not swallowed.
			$conn = $this->em->getConnection()->getConnection();
			
			foreach ($this->truncateTables as $table) {
				$conn->execute("DELETE FROM `{$table}`");
				$conn->execute("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
			}
			
			$this->seedFixtures();
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