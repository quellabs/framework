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
	
	class ShipmentRouter implements ShipmentInterface {
		
		private array $moduleMap = [];
		private array $driverMap = [];
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
				if (empty($metadata['modules'])) {
					continue;
				}
				
				// Register each module name, guarding against duplicate registrations
				// across different provider packages
				foreach ($metadata['modules'] as $module) {
					if (isset($this->moduleMap[$module])) {
						throw new \RuntimeException("Duplicate shipping module '{$module}' registered by {$class} and {$this->moduleMap[$module]}");
					}
					
					$this->moduleMap[$module] = $class;
				}
				
				// Register the driver name if provided, allowing exchange() to be called
				// with a stable driver name instead of a module name.
				if (!empty($metadata['driver'])) {
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
		 * @return array
		 */
		public function getDeliveryOptions(string $shippingModule, ?ShipmentAddress $address = null): array {
			return $this->resolve($shippingModule)->getDeliveryOptions($shippingModule, $address);
		}
		
		/**
		 * Returns available pickup points for the given module.
		 * @param string $shippingModule
		 * @param ShipmentAddress|null $address
		 * @return array|Contracts\PickupOption[]
		 */
		public function getPickupOptions(string $shippingModule, ?ShipmentAddress $address = null): array {
			return $this->resolve($shippingModule)->getPickupOptions($shippingModule, $address);
		}
		
		/**
		 * Returns the URL where the label PDF for the given parcel can be downloaded.
		 * @param string $shippingModule
		 * @param string $parcelId
		 * @return string
		 * @throws ShipmentLabelException
		 */
		public function getLabelUrl(string $shippingModule, string $parcelId): string {
			return $this->resolve($shippingModule)->getLabelUrl($parcelId);
		}
		
		/**
		 * Returns all registered module names across all discovered providers.
		 * @return array
		 */
		public function getRegisteredModules(): array {
			return array_keys($this->moduleMap);
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
			$class = $this->moduleMap[$module] ?? throw new \RuntimeException("No shipping provider registered for module '{$module}'");
			return $this->discover->get($class);
		}
		
		/**
		 * Resolves a provider instance by driver name (e.g. 'sendcloud').
		 * @param string $driver
		 * @return ShipmentProviderInterface
		 * @throws \RuntimeException
		 */
		private function resolveDriver(string $driver): ShipmentProviderInterface {
			$class = $this->driverMap[$driver] ?? throw new \RuntimeException("No shipping provider registered for driver '{$driver}'");
			return $this->discover->get($class);
		}
		
		/**
		 * Loads and validates shipment_packing.php via the config provider, building the box catalog.
		 * Silently skips if the file is absent or has no boxes — pack() will throw on first use.
		 * Outer dimensions are set equal to inner dimensions since we do box-level packing only,
		 * not pallet-level stacking optimization.
		 */
		private function loadPackingConfig(ConfigProviderInterface $configProvider): void {
			$config = $configProvider->loadConfigFile('shipment_packing.php');
			
			$this->maxWeightPerBox = $config->getAs('max_weight_per_box', 'int', 0);
			
			$boxes = $config->getAs('boxes', 'array', []);
			
			foreach ($boxes as $index => $box) {
				$missing = array_diff(
					['reference', 'width', 'length', 'depth', 'empty_weight', 'max_weight'],
					array_keys($box)
				);
				
				if (!empty($missing)) {
					throw new \RuntimeException(
						"shipment_packing.php: box at index {$index} is missing required keys: " . implode(', ', $missing)
					);
				}
				
				$width  = (int) $box['width'];
				$length = (int) $box['length'];
				$depth  = (int) $box['depth'];

				$this->packingBoxCatalog[] = new PackingBox(
					reference: $box['reference'],
					outerWidth:  $width,
					outerLength: $length,
					outerDepth:  $depth,
					emptyWeight: (int)$box['empty_weight'],
					innerWidth:  $width,
					innerLength: $length,
					innerDepth:  $depth,
					maxWeight: (int)$box['max_weight'],
				);
			}
		}
	}