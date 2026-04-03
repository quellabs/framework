<?php
	
	namespace Quellabs\Shipments\Contracts;
	
	/**
	 * Normalised shipment status values.
	 *
	 * Providers map their own internal status codes to these values in exchange()
	 * and webhook handling, keeping application code decoupled from provider specifics.
	 *
	 * The progression is not strictly linear — a parcel may jump from Created to Delivered
	 * if intermediate events arrive out of order or are never emitted.
	 */
	enum ShipmentStatus {
		
		/** Parcel record created at provider; label not yet requested or printed. */
		case Created;
		
		/** Label generated and parcel announced to carrier; awaiting pickup or drop-off. */
		case ReadyToSend;
		
		/** Carrier has scanned and accepted the parcel. */
		case InTransit;
		
		/** Parcel is out for final delivery. */
		case OutForDelivery;
		
		/** Parcel successfully delivered to the recipient. */
		case Delivered;
		
		/** Delivery failed (recipient absent, address issues, etc.). Carrier will retry. */
		case DeliveryFailed;
		
		/** Parcel is held at a post office or service point for recipient pickup. */
		case AwaitingPickup;
		
		/** Parcel returned to sender (after failed delivery attempts or explicit return). */
		case ReturnedToSender;
		
		/** Parcel cancelled before handover to the carrier. */
		case Cancelled;
		
		/** Parcel confirmed lost by the carrier. A claim process may be applicable. */
		case Lost;
		
		/** Parcel destroyed by the carrier or customs (e.g. damaged beyond delivery, prohibited contents). */
		case Destroyed;
		
		/** Status received but cannot be mapped to any of the above; see ShipmentState::$internalState. */
		case Unknown;
	}