<?php
	
	namespace Quellabs\Recommender\Sculpt;
	
	use Quellabs\Sculpt\ConfigurationManager;
	use Cake\Database\Connection;
	
	/**
	 * Creates the vogoo_ratings and vogoo_links tables required by the recommender engine.
	 *
	 * Usage:
	 *   sculpt recommender:init-db
	 *   sculpt recommender:init-db --force   (drop and recreate existing tables)
	 */
	class InitCommand extends RecommenderCommand {
		
		/**
		 * Return the command signature.
		 * @return string The command signature
		 */
		public function getSignature(): string {
			return 'recommender:init-db';
		}
		
		/**
		 * Return the one-line command description shown in command listings.
		 * @return string One-line description of the command
		 */
		public function getDescription(): string {
			return 'Create the vogoo_ratings and vogoo_links database tables';
		}
		
		/**
		 * Return the detailed help text shown for this command.
		 * @return string Detailed help text
		 */
		public function getHelp(): string {
			return <<<HELP
	<bold>Usage:</bold>
	  sculpt recommender:init-db [--force]
	
	<bold>Options:</bold>
	  --force   Drop existing tables before creating them. All data will be lost.
	
	<bold>Tables created:</bold>
	  vogoo_ratings   Stores member/product ratings (float 0.0-1.0, -1.0 = not interested)
	  vogoo_links     Stores independent liked and slope counts and differential sums
	
	<bold>Notes:</bold>
	  Existing installations must migrate and rebuild from ratings; --force deletes ratings.
	HELP;
		}
		
		/**
		 * Create the recommender database tables (vogoo_ratings, vogoo_links).
		 * @param ConfigurationManager $config The Sculpt configuration manager (flags and arguments)
		 * @return int Exit code: 0 on success, 1 when a table exists and --force was not given
		 */
		public function execute(ConfigurationManager $config): int {
			$connection = $this->getRecommenderProvider()->getConnection();
			$force = $config->hasFlag('force');
			$tables = ['vogoo_ratings', 'vogoo_links'];
			$existing = $this->existingTables($connection, $tables);
	
			// Bail out if tables exist and --force was not given
			if ($existing !== [] && !$force) {
				foreach ($existing as $table) {
					$this->output->warning("Table '{$table}' already exists. Use --force to drop and recreate.");
				}
	
				return 1;
			}
	
			if ($force && $existing !== []) {
				$this->dropTables($connection, $tables);
			}
	
			$this->createRatingsTable($connection);
			$this->createLinksTable($connection);
	
			return 0;
		}
	
		/**
		 * Return the subset of tables that already exist in the current database.
		 * @param Connection $connection The recommender database connection
		 * @param array<int, string> $tables Table names to check
		 * @return array<int, string> Names of the existing tables
		 */
		private function existingTables(Connection $connection, array $tables): array {
			$existing = [];
	
			foreach ($tables as $table) {
				$rows = $connection->execute(
					'
						SELECT
							COUNT(*) AS cnt
						FROM information_schema.tables
						WHERE table_schema = DATABASE() AND
							table_name = :table',
					['table' => $table],
				)->fetchAssoc();
	
				if ((int)$rows['cnt'] > 0) {
					$existing[] = $table;
				}
			}
	
			return $existing;
		}
	
		/**
		 * Drop the given tables, in reverse order to avoid foreign key issues.
		 * @param Connection $connection The recommender database connection
		 * @param array<int, string> $tables Table names in creation order
		 * @return void
		 */
		private function dropTables(Connection $connection, array $tables): void {
			foreach (array_reverse($tables) as $table) {
				$connection->execute("DROP TABLE IF EXISTS `{$table}`");
				$this->output->writeLn("<dim>Dropped table '{$table}'.</dim>");
			}
		}
		
		/**
		 * Create the vogoo_ratings table.
		 * @param Connection $connection The CakePHP database connection
		 * @return void
		 */
		private function createRatingsTable(Connection $connection): void {
			$connection->execute(
				'CREATE TABLE `vogoo_ratings` (
			    `member_id`  INT UNSIGNED  NOT NULL,
			    `product_id` INT UNSIGNED  NOT NULL,
			    `category`   INT UNSIGNED  NOT NULL DEFAULT 1,
			    `rating`     FLOAT         NOT NULL,
			    `ts`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			    PRIMARY KEY (`member_id`, `product_id`, `category`),
			    INDEX `idx_product`  (`product_id`, `category`),
			    INDEX `idx_member`   (`member_id`,  `category`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
			);
			
			$this->output->success("Created table 'vogoo_ratings'.");
		}
		
		/**
		 * Create the vogoo_links table.
		 * @param Connection $connection The CakePHP database connection
		 * @return void
		 */
		private function createLinksTable(Connection $connection): void {
			$connection->execute(
				'CREATE TABLE `vogoo_links` (
			    `item_id1`   INT UNSIGNED  NOT NULL,
			    `item_id2`   INT UNSIGNED  NOT NULL,
			    `category`   INT UNSIGNED  NOT NULL DEFAULT 1,
			    `liked_count` INT UNSIGNED NOT NULL DEFAULT 0,
			    `slope_count` INT UNSIGNED NOT NULL DEFAULT 0,
			    `diff_slope` FLOAT         NOT NULL DEFAULT 0.0,
			    PRIMARY KEY (`item_id1`, `item_id2`, `category`),
			    INDEX `idx_item2` (`item_id2`, `category`),
			    INDEX `idx_category` (`category`, `item_id1`, `item_id2`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
			);
			
			$this->output->success("Created table 'vogoo_links'.");
		}
	}