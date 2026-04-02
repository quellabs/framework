<?php
	
	namespace Quellabs\Shipments\Contracts;
	
	/**
	 * Classifies a shipping option as home delivery or pickup point collection.
	 */
	enum ShippingOptionType {
		/** Parcel will be delivered to the recipient's address. */
		case HomeDelivery;
		
		/** Recipient will collect the parcel from a service point or post office. */
		case Pickup;
	}
