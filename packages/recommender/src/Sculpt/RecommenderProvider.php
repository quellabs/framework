<?php

namespace Quellabs\Recommender\Sculpt;

use Cake\Database\Connection;
use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Sculpt\Application;
use Quellabs\Sculpt\ServiceProvider;

/**
 * Registers quellabs/recommender commands with the Sculpt CLI.
 *
 * Sculpt discovers this provider through the "discover" section of composer.json.
 * Database credentials come from config/database.php, and recommender settings
 * come from config/recommender.php.
 */
class RecommenderProvider extends ServiceProvider {
	
	/** @var Connection|null Cached database connection */
	private ?Connection $connection = null;
	
	/** @var RecommendationConfig|null Cached recommendation settings */
	private ?RecommendationConfig $recommendationConfig = null;
	
	/**
	 * Return the default configuration values. Database defaults are read from database.php instead.
	 * @return array<string, mixed>
	 */
	public static function getDefaults(): array {
		return [
			'category'                    => 1,
			'threshold_nr_common_ratings' => 30,
			'threshold_mult'              => 2,
			'threshold_rating'            => 0.66,
			'cost'                        => 5.0,
			'not_interested'              => -1.0,
			'direct_links'                => false,
			'direct_slope'                => true,
			'max_candidate_depth'         => 2000,
			'max_backfill_rounds'         => 3,
			'max_eligibility_batch_size'  => 500,
		];
	}
	
	/**
	 * Register all recommender commands with the Sculpt application.
	 * @param Application $application The application the provider registers with
	 * @return void
	 */
	public function register(Application $application): void {
		$this->registerCommands($application, [
			PublishConfigCommand::class,
			InitCommand::class,
			RebuildLinksCommand::class,
			InitEvaluationCommand::class,
			PruneEvaluationCommand::class,
			TrainClickModelCommand::class,
			ActivateClickModelCommand::class,
		]);
	}
	
	/**
	 * Return the configured database connection, created once from config/database.php.
	 * @return Connection
	 */
	public function getConnection(): Connection {
		if ($this->connection !== null) {
			return $this->connection;
		}
		
		$this->connection = new Connection([
			'driver'   => $this->resolveDriver($this->getConfigValueAsString('driver', 'mysql')),
			'host'     => $this->getConfigValueAsString('host', 'localhost'),
			'username' => $this->getConfigValueAsString('username', ''),
			'password' => $this->getConfigValueAsString('password', ''),
			'database' => $this->getConfigValueAsString('database', ''),
			'port'     => $this->getConfigValueAsInt('port', 3306),
			'encoding' => $this->getConfigValueAsString('encoding', 'utf8mb4'),
		]);
		
		return $this->connection;
	}
	
	/**
	 * Return the recommendation settings built from config/recommender.php, created once.
	 * @return RecommendationConfig
	 */
	public function getRecommendationConfig(): RecommendationConfig {
		if ($this->recommendationConfig !== null) {
			return $this->recommendationConfig;
		}
		
		$this->recommendationConfig = new RecommendationConfig(
			category: $this->getConfigValueAsInt('category', 1),
			thresholdNrCommonRatings: $this->getConfigValueAsInt('threshold_nr_common_ratings', 30),
			thresholdMult: $this->getConfigValueAsInt('threshold_mult', 2),
			thresholdRating: $this->getConfigValueAsFloat('threshold_rating', 0.66),
			cost: $this->getConfigValueAsFloat('cost', 5.0),
			notInterested: $this->getConfigValueAsFloat('not_interested', -1.0),
			directLinks: (bool)$this->getConfigValue('direct_links', false),
			directSlope: (bool)$this->getConfigValue('direct_slope', true),
			maxCandidateDepth: $this->getConfigValueAsInt('max_candidate_depth', 2000),
			maxBackfillRounds: $this->getConfigValueAsInt('max_backfill_rounds', 3),
			maxEligibilityBatchSize: $this->getConfigValueAsInt('max_eligibility_batch_size', 500),
		);
		
		return $this->recommendationConfig;
	}
	
	/**
	 * Read a float config value, falling back to the default when the key is missing or non-numeric.
	 * @param string $key Config key
	 * @param float $default Fallback value
	 * @return float
	 */
	private function getConfigValueAsFloat(string $key, float $default): float {
		$value = $this->getConfigValue($key);
		return is_numeric($value) ? (float)$value : $default;
	}
	
	/**
	 * Resolve a short driver name to a fully qualified CakePHP driver class.
	 * @param string $driver The configured database driver name or alias
	 * @return string Driver class name, or the input when it is not a known alias
	 */
	private function resolveDriver(string $driver): string {
		$driverMap = [
			'mysql'     => \Cake\Database\Driver\Mysql::class,
			'postgres'  => \Cake\Database\Driver\Postgres::class,
			'sqlite'    => \Cake\Database\Driver\Sqlite::class,
			'sqlserver' => \Cake\Database\Driver\Sqlserver::class,
		];
		
		return $driverMap[$driver] ?? $driver;
	}
}
