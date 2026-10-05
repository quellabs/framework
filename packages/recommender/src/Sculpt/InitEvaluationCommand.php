<?php
	
	namespace Quellabs\Recommender\Sculpt;
	
	use Cake\Database\Connection;
	use Quellabs\Recommender\Internal\Persistence\EvaluationSchema;
	use Quellabs\Sculpt\ConfigurationManager;
	
	/** Installs only the optional evaluation and model tables. */
	class InitEvaluationCommand extends RecommenderCommand {
		
		/**
		 * Return the command signature.
		 * @return string Command signature
		 */
		public function getSignature(): string {
			return 'recommender:init-evaluation-db';
		}
		
		/**
		 * Return the short command description.
		 * @return string Short command description
		 */
		public function getDescription(): string {
			return 'Create or verify optional recommender evaluation tables';
		}
		
		/**
		 * Return the usage help.
		 * @return string Usage help
		 */
		public function getHelp(): string {
			return 'Usage: sculpt recommender:init-evaluation-db';
		}
		
		/**
		 * Apply the evaluation migration, then verify each evaluation table against the expected schema.
		 * @param ConfigurationManager $config CLI configuration
		 * @return int Exit status
		 * @throws \RuntimeException When the migration file is missing or a table does not match the schema
		 */
		public function execute(ConfigurationManager $config): int {
			$connection = $this->getRecommenderProvider()->getConnection();
	
			$this->applyMigration($connection);
			
			EvaluationSchema::verify($connection);
	
			$this->output->success('Evaluation tables are ready.');
			return 0;
		}
		
		/**
		 * Run the evaluation migration statements, skipping SQL comment lines.
		 * @param Connection $connection Evaluation database connection
		 * @return void
		 * @throws \RuntimeException When the migration file is missing or cannot be parsed
		 */
		private function applyMigration(Connection $connection): void {
			$path = dirname(__DIR__, 2) . '/migrations/2026-10-evaluation-tables.sql';
			$sql = file_get_contents($path);
			
			if ($sql === false) {
				throw new \RuntimeException('Evaluation migration file is missing.');
			}
			
			$withoutComments = preg_replace('/^--.*$/m', '', $sql);
			
			if ($withoutComments === null) {
				throw new \RuntimeException('Could not parse evaluation migration.');
			}
			
			$statements = array_filter(array_map('trim', explode(';', $withoutComments)));
			
			foreach ($statements as $statement) {
				$connection->execute($statement);
			}
		}
		
	}