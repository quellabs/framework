<?php
	
	namespace Quellabs\Recommender\Integration;
	
	use Cake\Database\Connection;
use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\ServiceProviderInterface;
	use Quellabs\Discover\Provider\AbstractProvider;
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Recommender\Evaluation\EvaluationRecorder;
	use Quellabs\Recommender\Evaluation\EvaluationReport;
	use Quellabs\Recommender\Reconciliation\RecommendationReconciler;
use Quellabs\Recommender\Internal\UserSimilarity;
use Quellabs\Recommender\RecommendationEngine;
	
	/**
	 * Registers the recommender services with Canvas's DI container.
	 *
	 * Config values come from the file listed under extra.discover in composer.json
	 * and are injected through setConfig() before createInstance() is called.
	 */
	class ServiceProvider extends AbstractProvider implements ServiceProviderInterface {
	
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
			if ($className === RecommendationReconciler::class) {
				$connection = $this->dependency($dependencies, Connection::class);
				$config = $this->dependency($dependencies, RecommendationConfig::class);
				return new RecommendationReconciler($connection, $config,
					new UserSimilarity($connection, $config, new RecommendationEngine($connection, $config)));
			}

			if ($className !== RecommendationConfig::class) {
				return new $className(...$dependencies);
			}
	
			return RecommendationConfig::fromArray($this->getConfig());
		}

		/**
		 * Return the first dependency of the given type.
		 * @template T of object
		 * @param array<mixed> $dependencies Resolved constructor dependencies
		 * @param class-string<T> $type Required type
		 * @return T
		 * @throws \InvalidArgumentException When no dependency has the type
		 */
		private function dependency(array $dependencies, string $type): object {
			foreach ($dependencies as $dependency) {
				if ($dependency instanceof $type) {
					return $dependency;
				}
			}

			throw new \InvalidArgumentException("Missing dependency {$type}.");
		}
	}
