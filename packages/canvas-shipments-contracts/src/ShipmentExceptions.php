<?php
	
	namespace Quellabs\Shipments\Contracts;
	
	/**
	 * Base exception for all shipment provider errors.
	 * Carries the driver name and provider error ID alongside the message
	 * to allow precise logging without needing to inspect message strings.
	 */
	class ShipmentException extends \RuntimeException {
		
		public function __construct(
			private readonly string     $driver,
			private readonly int|string $errorId,
			string                      $message,
			\Throwable                  $previous = null,
		) {
			parent::__construct($message, 0, $previous);
		}
		
		public function getDriver(): string {
			return $this->driver;
		}
		
		public function getErrorId(): int|string {
			return $this->errorId;
		}
	}
	
	/**
	 * Thrown when ShipmentProviderInterface::create() fails.
	 */
	class ShipmentCreationException extends ShipmentException {
	}
	
	/**
	 * Thrown when ShipmentProviderInterface::cancel() fails.
	 */
	class ShipmentCancellationException extends ShipmentException {
	}
	
	/**
	 * Thrown when ShipmentProviderInterface::exchange() fails.
	 */
	class ShipmentExchangeException extends ShipmentException {
	}

/**
 * Thrown when ShipmentProviderInterface::getLabelUrl() fails.
 */
class ShipmentLabelException extends ShipmentException {
}
