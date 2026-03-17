<?php
	
	namespace Quellabs\Contracts\Discovery;
	
	use InvalidArgumentException;
	
	/**
	 * Simple immutable value object for provider definitions
	 * Replaces arrays with type-safe objects
	 */
	readonly class ProviderDefinition {
		public string $className;
		public string $family;
		public array $configFiles;
		public array $metadata;
		public array $defaults;
		
		/**
		 * ProviderDefinition constructor
		 * @param string $className
		 * @param string $family
		 * @param array $configFiles
		 * @param array $metadata
		 */
		public function __construct(
			string  $className,
			string  $family,
			array   $configFiles = [],
			array   $metadata = []
		) {
			$this->metadata = $metadata;
			$this->configFiles = $configFiles;
			$this->family = $family;
			$this->className = $className;
			
			if (empty($this->className)) {
				throw new InvalidArgumentException('Provider class name cannot be empty');
			}
			
			if (empty($this->family)) {
				throw new InvalidArgumentException('Provider family cannot be empty');
			}
		}
		
		/**
		 * Create from an array (backward compatibility)
		 * @param array $data
		 * @return self
		 */
		public static function fromArray(array $data): self {
			if (!isset($data['class']) || !isset($data['family'])) {
				throw new InvalidArgumentException('Missing required class or family');
			}
			
			if (!isset($data['config'])) {
				$configFiles = [];
			} elseif (is_array($data['config'])) {
				$configFiles = $data['config'];
			} else {
				$configFiles = [$data['config']];
			}
			
			return new self(
				className: $data['class'],
				family: $data['family'],
				configFiles: $configFiles,
				metadata: $data['metadata'] ?? []
			);
		}
		
		/**
		 * Convert to array (for caching/serialization)
		 * @return array
		 */
		public function toArray(): array {
			return [
				'class'    => $this->className,
				'family'   => $this->family,
				'config'   => $this->configFiles,
				'metadata' => $this->metadata
			];
		}
		
		/**
		 * Generate unique key
		 * @return string
		 */
		public function getKey(): string {
			return $this->family . '::' . $this->className;
		}

		/**
		 * Check if the definition has a config file
		 * @return bool
		 */
		public function hasConfigFile(): bool {
			return !empty($this->configFiles);
		}
	}