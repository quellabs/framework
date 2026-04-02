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
	 * Returns available home delivery options for the given module.
	 *
	 * $address is optional but some providers (e.g. MyParcel) require it to compute
	 * available delivery windows per recipient location. Providers that do not need
	 * an address (e.g. SendCloud) silently ignore it.
	 *
	 * @param string               $shippingModule
	 * @param ShipmentAddress|null $address
	 * @return DeliveryOption[]
	 */
	public function getDeliveryOptions(string $shippingModule, ?ShipmentAddress $address = null): array;

	/**
	 * Returns available pickup points for the given module.
	 *
	 * $address is used as the search origin — providers return points near this location.
	 * Returns an empty array when no address is provided or no points are found.
	 *
	 * @param string               $shippingModule
	 * @param ShipmentAddress|null $address
	 * @return PickupOption[]
	 */
	public function getPickupOptions(string $shippingModule, ?ShipmentAddress $address = null): array;
}
