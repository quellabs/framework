<?php
	
	namespace Quellabs\Shipments;
	
	use Quellabs\Contracts\Configuration\ConfigProviderInterface;
	use Quellabs\Discover\Discover;
	use Quellabs\Discover\Scanner\ComposerScanner;
	use Quellabs\Shipments\Contracts\CancelRequest;
	use Quellabs\Shipments\Contracts\CancelResult;
	use Quellabs\Shipments\Contracts\ShipmentAddress;
	use Quellabs\Shipments\Contracts\ShipmentInterface;
	use Quellabs\Shipments\Contracts\ShipmentLabelException;
	use Quellabs\Shipments\Contracts\ShipmentProviderInterface;
	use Quellabs\Shipments\Contracts\ShipmentRequest;
	use Quellabs\Shipments\Contracts\ShipmentResult;
	use Quellabs\Shipments\Contracts\ShipmentState;
	use Quellabs\Shipments\Packing\PackableItem;
	use Quellabs\Shipments\Packing\PackingBox;
	use Quellabs\Shipments\Packing\PackingResult;
	use Quellabs\Shipments\Packing\PackingService;
	
	/**
	 * @phpstan-type BoxConfig array{
	 *     reference: string,
	 *     width: int,
	 *     length: int,
	 *     depth: int,
	 *     empty_weight: int,
	 *     max_weight: int
	 * }
	 */
	class ShipmentRouter implements ShipmentInterface {
		
		/** @var array<string, class-string<ShipmentProviderInterface>> */
		private array $moduleMap = [];
		
		/** @var array<string, class-string<ShipmentProviderInterface>> */
		private array $driverMap = [];
		
		/** @var Discover Service discovery */
		private Discover $discover;
		
		/** @var PackingBox[] Built once from packing.php, reused per pack() call */
		private array $packingBoxCatalog = [];
		
		/** Global weight ceiling in grams, 0 = rely on per-box limits only */
		private int $maxWeightPerBox = 0;
		
		/**
		 * ShipmentRouter constructor.
		 * Discovers all shipping providers via Composer metadata and builds the module map.
		 * Optionally loads packing configuration from packing.php if a config provider is supplied.
		 */
		public function __construct(?ConfigProviderInterface $configProvider = null) {
			// Run discovery to populate provider definitions and collected metadata
			$this->discover = new Discover();
			$this->discover->addScanner(new ComposerScanner("shipments"));
			$this->discover->discover();
			
			// Iterate all discovered provider classes and build the module map
			foreach ($this->discover->getProviderClasses() as $class) {
				// Skip classes that don't implement ShipmentProviderInterface
				if (!is_subclass_of($class, ShipmentProviderInterface::class)) {
					continue;
				}
				
				// The metadata should include a list of modules
				$metadata = $class::getMetadata();
				
				// Skip providers that declare no modules — nothing to route to
				if (empty($metadata['modules']) || !is_array($metadata['modules'])) {
					continue;
				}
				
				// Register each module name, guarding against duplicate registrations
				// across different provider packages
				foreach ($metadata['modules'] as $module) {
					// Skip malformed modules. Never happens, but there to satisfy phpstan
					if (!is_string($module)) {
						continue;
					}
					
					// If the module already exists in the map, something went very wrong
					if (isset($this->moduleMap[$module])) {
						throw new \RuntimeException("Duplicate shipping module '{$module}' registered by {$class} and {$this->moduleMap[$module]}");
					}
					
					// Add class to map
					$this->moduleMap[$module] = $class;
				}
				
				// Register the driver name if provided, allowing exchange() to be called
				// with a stable driver name instead of a module name.
				if (!empty($metadata['driver']) && is_string($metadata['driver'])) {
					$this->driverMap[$metadata['driver']] = $class;
				}
			}
			
			// Load packing config if a provider was injected
			if ($configProvider !== null) {
				$this->loadPackingConfig($configProvider);
			}
		}
		
		/**
		 * Create a shipment using the provider registered for the request's shipping module.
		 * @param ShipmentRequest $request
		 * @return ShipmentResult
		 */
		public function create(ShipmentRequest $request): ShipmentResult {
			return $this->resolve($request->shippingModule)->create($request);
		}
		
		/**
		 * Cancel a shipment using the provider registered for the request's shipping module.
		 * @param CancelRequest $request
		 * @return CancelResult
		 */
		public function cancel(CancelRequest $request): CancelResult {
			return $this->resolve($request->shippingModule)->cancel($request);
		}
		
		/**
		 * Fetch the current state of a shipment for reconciliation of missed webhooks.
		 * @param string $driver Driver name as stored in ShipmentState::$provider (e.g. 'sendcloud')
		 * @param string $parcelId Provider-assigned parcel ID from ShipmentResult
		 * @return ShipmentState
		 */
		public function exchange(string $driver, string $parcelId): ShipmentState {
			return $this->resolveDriver($driver)->exchange($parcelId);
		}
		
		/**
		 * Returns available home delivery options for the given module.
		 * @param string $shippingModule
		 * @param ShipmentAddress|null $address
		 * @return array<int, mixed>
		 */
		public function getDeliveryOptions(string $shippingModule, ?ShipmentAddress $address = null): array {
			return $this->resolve($shippingModule)->getDeliveryOptions($shippingModule, $address);
		}
		
		/**
		 * Returns available pickup points for the given module.
		 * @param string $shippingModule
		 * @param ShipmentAddress|null $address
		 * @return array<int, mixed>
		 */
		public function getPickupOptions(string $shippingModule, ?ShipmentAddress $address = null): array {
			return $this->resolve($shippingModule)->getPickupOptions($shippingModule, $address);
		}
		
		/**
		 * Returns the URL where the label PDF for the given parcel can be downloaded.
		 * @param string $shippingModule
		 * @param string $parcelId
		 * @return string
		 */
		public function getLabelUrl(string $shippingModule, string $parcelId): string {
			return $this->resolve($shippingModule)->getLabelUrl($parcelId);
		}
		
		/**
		 * Calculate the optimal box assignment for a set of items.
		 * A fresh PackingService is created per call — no state bleeds between calls.
		 * Always check PackingResult::hasUnpackedItems() before proceeding.
		 *
		 * @param PackableItem[] $items
		 * @return PackingResult
		 * @throws \RuntimeException if no packing config was loaded (no ConfigProviderInterface injected
		 *                           or packing.php missing/empty boxes array)
		 */
		public function pack(array $items): PackingResult {
			if (empty($this->packingBoxCatalog)) {
				throw new \RuntimeException(
					"ShipmentRouter: no box catalog available. " .
					"Inject a ConfigProviderInterface and ensure packing.php defines at least one box."
				);
			}
			
			return (new PackingService($this->packingBoxCatalog, $this->maxWeightPerBox))
				->addItems($items)
				->pack();
		}
		
		/**
		 * Resolves a provider instance for the given module name.
		 * @param string $module
		 * @return ShipmentProviderInterface
		 * @throws \RuntimeException
		 */
		private function resolve(string $module): ShipmentProviderInterface {
			// If module does not exist, tell the user by throwing an exception
			if (!isset($this->moduleMap[$module])) {
				throw new \RuntimeException("No shipping provider registered for module '{$module}'");
			}
			
			// Try to get the provider
			$result = $this->discover->get($this->moduleMap[$module]);
			
			// If that failed, no provider implementation exists.
			// Should never happen, because of the previous isset check
			if (!$result instanceof ShipmentProviderInterface) {
				throw new \RuntimeException("Invalid shipping provider '{$module}'");
			}
			
			// Return the module
			return $result;
		}
		
		/**
		 * Resolves a provider instance by driver name (e.g. 'sendcloud').
		 * @param string $driver
		 * @return ShipmentProviderInterface
		 * @throws \RuntimeException
		 */
		private function resolveDriver(string $driver): ShipmentProviderInterface {
			// If driver does not exist, tell the user by throwing an exception
			if (!isset($this->driverMap[$driver])) {
				throw new \RuntimeException("No shipping driver registered for driver '{$driver}'");
			}
			
			// Try to get the driver
			$result = $this->discover->get($this->driverMap[$driver]);
			
			// If that failed, no provider implementation exists.
			// Should never happen, because of the previous isset check
			if (!$result instanceof ShipmentProviderInterface) {
				throw new \RuntimeException("Invalid shipping driver '{$driver}'");
			}
			
			// Return the driver
			return $result;
		}
		
		/**
		 * Loads and validates shipment_packing.php via the config provider, building the box catalog.
		 * Silently skips if the file is absent or has no boxes — pack() will throw on first use.
		 * Outer dimensions are set equal to inner dimensions since we do box-level packing only,
		 * not pallet-level stacking optimization.
		 */
		private function loadPackingConfig(ConfigProviderInterface $configProvider): void {
			// Load the packing configuration file via the config provider
			$config = $configProvider->loadConfigFile('shipment_packing.php');
			
			// Read the optional global weight ceiling; 0 means rely on per-box limits only.
			// get() is used instead of getAs() because getAs() returns mixed at the PHP level
			// regardless of its @phpstan-overload annotations, which PHPStan does not recognise.
			// Passing a typed default lets PHPStan infer the return type via the @template T on get().
			$maxWeight             = $config->get('max_weight_per_box', 0);
			$this->maxWeightPerBox = is_int($maxWeight) ? $maxWeight : 0;
			
			// Read the list of box definitions; default to empty so pack() throws a clear error
			/** @var array<mixed> $defaultBoxes */
			$defaultBoxes = [];
			$boxes        = $config->get('boxes', $defaultBoxes);
			
			if (!is_array($boxes)) {
				return;
			}
			
			foreach ($boxes as $index => $box) {
				if (!is_array($box)) {
					continue;
				}
				
				$b = $this->validateBoxConfig($box, $index);
				
				// Outer and inner dimensions are identical — we do box-level packing only,
				// not pallet-level stacking, so no wall thickness needs to be subtracted
				$this->packingBoxCatalog[] = new PackingBox(
					reference: $b['reference'],
					outerWidth: $b['width'],
					outerLength: $b['length'],
					outerDepth: $b['depth'],
					emptyWeight: $b['empty_weight'],
					innerWidth: $b['width'],
					innerLength: $b['length'],
					innerDepth: $b['depth'],
					maxWeight: $b['max_weight'],
				);
			}
		}
		
		/**
		 * Validates and extracts typed fields from a raw box config entry.
		 * Throws if any field is missing or has the wrong type.
		 * @param array<mixed> $box
		 * @param int|string $index
		 * @return BoxConfig
		 */
		private function validateBoxConfig(array $box, int|string $index): array {
			/** @var array<string, 'string'|'int'> $fields */
			$fields = [
				'reference'    => 'string',
				'width'        => 'int',
				'length'       => 'int',
				'depth'        => 'int',
				'empty_weight' => 'int',
				'max_weight'   => 'int',
			];
			
			$result = [];
			
			foreach ($fields as $key => $type) {
				if (!array_key_exists($key, $box)) {
					throw new \RuntimeException("shipment_packing.php: box at index {$index} is missing required key '{$key}'");
				}
				
				$value = $box[$key];
				
				if ($type === 'int' && !is_int($value)) {
					throw new \RuntimeException("shipment_packing.php: box at index {$index}: '{$key}' must be an integer");
				}
				
				if ($type === 'string' && !is_string($value)) {
					throw new \RuntimeException("shipment_packing.php: box at index {$index}: '{$key}' must be a string");
				}
				
				$result[$key] = $value;
			}
			
			/** @var BoxConfig $result */
			return $result;
		}
	}