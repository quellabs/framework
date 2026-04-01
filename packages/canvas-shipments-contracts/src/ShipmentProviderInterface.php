<?php
	
	namespace Quellabs\Shipments\Contracts;
	
	use Quellabs\Contracts\Discovery\ProviderInterface;
	
	/**
	 * Contract that every shipping provider driver must implement.
	 * Extends ShipmentInterface with driver-specific concerns: discovery metadata,
	 * configuration lifecycle, and the provider-level exchange() call.
	 * Discovered via Composer metadata and registered in ShipmentRouter.
	 */
	interface ShipmentProviderInterface extends ShipmentInterface, ProviderInterface {
		
		/**
		 * Returns static discovery metadata for this driver.
		 * Called without instantiation during the Discover scan.
		 *
		 * Expected keys:
		 *   'driver'  => string  — stable identifier (e.g. 'sendcloud')
		 *   'modules' => array   — list of module names this driver handles (e.g. ['sendcloud_postnl', 'sendcloud_dhl'])
		 *
		 * @return array<string, mixed>
		 */
		public static function getMetadata(): array;
		
		/**
		 * Returns the active configuration for this driver instance.
		 * @return array
		 */
		public function getConfig(): array;
		
		/**
		 * Applies configuration to this driver instance.
		 * Called by the discovery system after instantiation, before any other method.
		 * @param array $config
		 * @return void
		 */
		public function setConfig(array $config): void;
		
		/**
		 * Returns default configuration values for this driver.
		 * Merged with loaded config at boot — config file values take precedence.
		 * @return array
		 */
		public function getDefaults(): array;
		
		/**
		 * Fetches the current state of a shipment.
		 * Used to reconcile missed webhooks or poll for status on demand.
		 * The router-level exchange() wraps this, adding driver dispatch by name.
		 * @param string $parcelId Provider-assigned parcel ID from ShipmentResult
		 * @return ShipmentState
		 * @throws ShipmentExchangeException
		 */
		public function exchange(string $parcelId): ShipmentState;
	}
