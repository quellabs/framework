<?php

namespace Quellabs\Recommender\Integration;

use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Recommender\EvaluationRecorder;
use Quellabs\Recommender\EvaluationReport;
use Quellabs\Recommender\RecommendationReconciler;
use Quellabs\Contracts\Context\MethodContextInterface;
use Quellabs\Contracts\DependencyInjection\ServiceProviderInterface;

/**
 * Registers the recommender services with Canvas's DI container.
 *
 * Config values come from the file listed under extra.discover in composer.json
 * and are injected through setConfig() before createInstance() is called.
 */
class ServiceProvider implements ServiceProviderInterface {
	
	/** @var array<string, mixed> Raw configuration values from config/recommender.php */
	private array $config = [];
	
	/**
	 * Return the provider metadata, which is empty for this provider.
	 * @return array<string, mixed>
	 */
	public static function getMetadata(): array {
		return [];
	}
	
	/**
	 * Return the raw configuration array currently held by the provider.
	 * @return array<string, mixed>
	 */
	public function getConfig(): array {
		return $this->config;
	}
	
	/**
	 * Store the raw configuration array injected by the DI container.
	 * @param array<string, mixed> $config Raw configuration values
	 * @return void
	 */
	public function setConfig(array $config): void {
		$this->config = $config;
	}
	
	/**
	 * Check whether this provider handles the requested class.
	 * @param string $className Fully-qualified class name being resolved
	 * @param array<string, mixed> $metadata Provider metadata
	 * @return bool True if this provider handles the class
	 */
	public function supports(string $className, array $metadata): bool {
		$supportedClasses = [
			RecommendationConfig::class,
			RecommendationReconciler::class,
			EvaluationRecorder::class,
			EvaluationReport::class,
		];
		
		return in_array($className, $supportedClasses, true);
	}
	
	/**
	 * Build the requested service, constructing RecommendationConfig from the loaded config values.
	 * @param string $className Fully-qualified class name being resolved
	 * @param array<string, mixed> $dependencies Resolved constructor dependencies
	 * @param array<string, mixed> $metadata Provider metadata
	 * @param MethodContextInterface|null $methodContext Optional method-call context
	 * @return object The configured instance or requested recommender service
	 */
	public function createInstance(
		string                  $className,
		array                   $dependencies,
		array                   $metadata,
		?MethodContextInterface $methodContext = null
	): object {
		if ($className !== RecommendationConfig::class) {
			return new $className(...$dependencies);
		}
		
		return new RecommendationConfig(
			category: $this->getInt('category', 1),
			thresholdNrCommonRatings: $this->getInt('threshold_nr_common_ratings', 30),
			thresholdMult: $this->getInt('threshold_mult', 2),
			thresholdRating: $this->getFloat('threshold_rating', 0.66),
			cost: $this->getFloat('cost', 5.0),
			notInterested: $this->getFloat('not_interested', -1.0),
			directLinks: $this->getBool('direct_links', false),
			directSlope: $this->getBool('direct_slope', true),
			maxCandidateDepth: $this->getInt('max_candidate_depth', 2000),
			maxBackfillRounds: $this->getInt('max_backfill_rounds', 3),
			maxEligibilityBatchSize: $this->getInt('max_eligibility_batch_size', 500),
		);
	}
	
	/**
	 * Read an integer config value, falling back to a default when missing or non-numeric.
	 * @param string $key Config key
	 * @param int $default Fallback value
	 * @return int
	 */
	private function getInt(string $key, int $default): int {
		$value = $this->config[$key] ?? $default;
		return is_numeric($value) ? (int)$value : $default;
	}
	
	/**
	 * Read a float config value, falling back to a default when missing or non-numeric.
	 * @param string $key Config key
	 * @param float $default Fallback value
	 * @return float
	 */
	private function getFloat(string $key, float $default): float {
		$value = $this->config[$key] ?? $default;
		return is_numeric($value) ? (float)$value : $default;
	}
	
	/**
	 * Read a boolean config value, falling back to a default when missing.
	 * @param string $key Config key
	 * @param bool $default Fallback value
	 * @return bool
	 */
	private function getBool(string $key, bool $default): bool {
		$value = $this->config[$key] ?? $default;

		// (bool)"false" is true, so string values are parsed explicitly; unrecognized strings fall back to the default
		if (is_string($value)) {
			return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
		}

		return (bool)$value;
	}
}
