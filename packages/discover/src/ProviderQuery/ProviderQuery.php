<?php
	
	namespace Quellabs\Discover\ProviderQuery;
	
	/**
	 * Fluent query builder for filtering and retrieving service providers.
	 *
	 * Allows chaining multiple filter criteria (capabilities, priority, family, custom filters)
	 * before instantiating the matching providers. Filters are applied in order: family filter
	 * first (operates on definition), then all metadata filters.
	 *
	 * @example
	 * $providers = $query
	 *     ->withFamily('cache')
	 *     ->withCapability('distributed')
	 *     ->withMinPriority(50)
	 *     ->get();
	 */
	class ProviderQuery {
		/**
		 * @var \Closure Callback that instantiates provider instances from definitions
		 */
		private \Closure $instantiator;
		
		/**
		 * @var array Provider definitions to query against
		 */
		private array $definitions;
		
		/**
		 * @var array<callable> Stack of metadata filter functions to apply
		 */
		private array $filters = [];
		
		/**
		 * @var string|null Family name to filter by (applied to definition.family, not metadata)
		 */
		private ?string $familyFilter = null;
		
		/**
		 * Initialize a new provider query.
		 *
		 * @param \Closure $instantiator Callback that converts a definition to a provider instance
		 * @param array $definitions Array of provider definitions to filter
		 */
		public function __construct(\Closure $instantiator, array $definitions) {
			$this->instantiator = $instantiator;
			$this->definitions = $definitions;
		}
		
		/**
		 * Filter providers that have a specific capability.
		 *
		 * Checks if the provider's metadata contains a 'capabilities' array
		 * that includes the specified capability string.
		 *
		 * @param string $capability The capability name to require
		 * @return self Returns $this for method chaining
		 */
		public function withCapability(string $capability): self {
			$this->filters[] = function (array $metadata) use ($capability): bool {
				return isset($metadata['capabilities']) &&
					is_array($metadata['capabilities']) &&
					in_array($capability, $metadata['capabilities'], true);
			};
			return $this;
		}
		
		/**
		 * Filter providers with a minimum priority value.
		 *
		 * Checks if the provider's metadata contains a numeric 'priority' value
		 * that is greater than or equal to the specified threshold.
		 *
		 * @param int $priority The minimum priority value required
		 * @return self Returns $this for method chaining
		 */
		public function withMinPriority(int $priority): self {
			$this->filters[] = function (array $metadata) use ($priority): bool {
				return isset($metadata['priority']) &&
					is_numeric($metadata['priority']) &&
					$metadata['priority'] >= $priority;
			};
			return $this;
		}
		
		/**
		 * Filter providers by family name.
		 *
		 * Unlike other filters, this operates on the definition's family property
		 * rather than metadata. Only one family filter can be active at a time;
		 * calling this multiple times will replace the previous family filter.
		 *
		 * @param string $family The family name to filter by
		 * @return self Returns $this for method chaining
		 */
		public function withFamily(string $family): self {
			$this->familyFilter = $family;
			return $this;
		}
		
		/**
		 * Add a custom filter function.
		 *
		 * The callable receives the provider's metadata array and must return
		 * a boolean indicating whether the provider passes the filter.
		 *
		 * @param callable $filter Filter function with signature: fn(array $metadata): bool
		 * @return self Returns $this for method chaining
		 */
		public function where(callable $filter): self {
			$this->filters[] = $filter;
			return $this;
		}
		
		/**
		 * Execute the query and return instantiated providers.
		 *
		 * Applies all filters in order:
		 * 1. Family filter (if set) - operates on definition.family
		 * 2. All metadata filters - operate on definition.metadata
		 *
		 * Then instantiates each matching definition using the instantiator callback.
		 *
		 * @return array Array of instantiated provider instances that match all filters
		 */
		public function get(): array {
			$filtered = $this->definitions;
			
			// Apply family filter first (it checks definition, not metadata)
			if ($this->familyFilter !== null) {
				$filtered = array_filter($filtered, fn($def) => $def->family === $this->familyFilter);
			}
			
			// Apply metadata filters
			foreach ($this->filters as $filter) {
				$filtered = array_filter($filtered, fn($def) => $filter($def->metadata));
			}
			
			// Instantiate and return providers
			return array_map($this->instantiator, array_values($filtered));
		}
		
		/**
		 * Execute the query and return instantiated providers as a generator
		 * More memory efficient than get() for large result sets as providers
		 * are instantiated one at a time instead of all at once.
		 * @return \Generator Generator yielding instantiated provider instances
		 */
		public function lazy(): \Generator {
			$filtered = $this->definitions;
			
			// Apply family filter first (it checks definition, not metadata)
			if ($this->familyFilter !== null) {
				$filtered = array_filter($filtered, fn($def) => $def->family === $this->familyFilter);
			}
			
			// Apply metadata filters
			foreach ($this->filters as $filter) {
				$filtered = array_filter($filtered, fn($def) => $filter($def->metadata));
			}
			
			// Instantiate and yield providers one at a time
			foreach ($filtered as $definition) {
				yield ($this->instantiator)($definition);
			}
		}
	}