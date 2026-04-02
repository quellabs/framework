<?php
	
	namespace Quellabs\Shipments\Contracts;
	
	/**
	 * Encapsulates everything a provider needs to create a shipment.
	 * All address fields follow Dutch conventions where applicable but are intentionally
	 * generic enough for international use.
	 */
	class ShipmentRequest {
		
		public function __construct(
			/**
			 * The shipping module that determines which driver handles this request.
			 * Must match a module name registered in ShipmentRouter (e.g. 'sendcloud_postnl').
			 */
			public readonly string          $shippingModule,
			
			/**
			 * Caller's own reference for this shipment — used to correlate webhook events back
			 * to the originating order. This is NOT the provider-assigned parcel ID.
			 * Store it alongside the parcel ID returned in ShipmentResult.
			 */
			public readonly string          $reference,
			
			/**
			 * Recipient address.
			 */
			public readonly ShipmentAddress $deliveryAddress,
			
			/**
			 * Total weight of the parcel in grams.
			 */
			public readonly int             $weightGrams,
			
			/**
			 * The provider-specific shipping method ID (e.g. SendCloud shipping_method_id).
			 * Obtain this from getDeliveryOptions() and persist it on the order.
			 */
			public readonly int|string|null $methodId = null,
			
			/**
			 * Declared value of the parcel contents in minor units (e.g. 1250 = €12.50).
			 * Required by some carriers for customs and insurance.
			 */
			public readonly int             $declaredValueCents = 0,
			
			/**
			 * ISO 4217 currency code for declaredValueCents.
			 */
			public readonly string          $currency = 'EUR',
			
			/**
			 * Optional human-readable description of the parcel contents.
			 * Shown on the label or used for customs declarations.
			 */
			public readonly ?string         $description = null,
			
			/**
			 * Optional service point ID when the recipient chose click-and-collect.
			 * Pass null for home delivery.
			 */
			public readonly ?string         $servicePointId = null,
			
			/**
			 * Optional package type hint for the carrier.
			 * Accepted values vary per driver:
			 *   MyParcel: 'parcel', 'mailbox', 'letter', 'digital_stamp' — defaults to 'parcel' if omitted.
			 *   DHL:      'SMALL', 'MEDIUM', 'LARGE', 'XL'               — defaults to weight-based auto-selection if omitted.
			 * Pass null (or omit) to let the driver decide.
			 */
			public readonly ?string         $packageType = null,
			
			/**
			 * Whether to request the label immediately upon parcel creation.
			 * Set to false if you want to batch-request labels separately.
			 */
			public readonly bool            $requestLabel = true,
			
			/**
			 * Arbitrary extra data passed through to the provider without validation.
			 * Use for provider-specific fields not covered by this contract.
			 */
			public readonly array           $extraData = [],
		) {
		}
	}
