<?php
	
	namespace Quellabs\Shipments\Contracts;
	
	/**
	 * Base contract for shipment operations.
	 * Satisfied by both ShipmentRouter (dispatch layer) and individual provider drivers.
	 * Injected into application code via the DI container.
	 */
	interface ShipmentInterface {
		
		/**
		 * Create a shipment (parcel).
		 * @param ShipmentRequest $request
		 * @return ShipmentResult
		 */
		public function create(ShipmentRequest $request): ShipmentResult;
		
		/**
		 * Cancel a previously created shipment.
		 * @param CancelRequest $request
		 * @return CancelResult
		 */
		public function cancel(CancelRequest $request): CancelResult;
		
		/**
		 * Returns available shipping options (service points, delivery windows, etc.)
		 * for the given module.
		 * @param string $shippingModule
		 * @return array
		 */
		public function getShippingOptions(string $shippingModule): array;
	}
