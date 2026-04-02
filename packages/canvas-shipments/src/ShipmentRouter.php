<?php
	
	namespace Quellabs\Shipments;
	
	use Quellabs\Discover\Discover;
	use Quellabs\Discover\Scanner\ComposerScanner;
	use Quellabs\Shipments\Contracts\CancelRequest;
	use Quellabs\Shipments\Contracts\CancelResult;
	use Quellabs\Shipments\Contracts\ShipmentAddress;
	use Quellabs\Shipments\Contracts\ShipmentInterface;
	use Quellabs\Shipments\Contracts\ShipmentProviderInterface;
	use Quellabs\Shipments\Contracts\ShipmentRequest;
	use Quellabs\Shipments\Contracts\ShipmentResult;
	use Quellabs\Shipments\Contracts\ShipmentState;
	
	class ShipmentRouter implements ShipmentInterface {
		
		private array $moduleMap = [];
		private array $driverMap = [];
		private Discover $discover;
		
		/**
		 * ShipmentRouter constructor.
		 * Discovers all shipping providers via Composer metadata and builds the module map.
		 */
		public function __construct() {
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
		 * Returns all registered module names across all discovered providers.
		 * @return array
		 */
		public function getRegisteredModules(): array {
			return array_keys($this->moduleMap);
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
	}
