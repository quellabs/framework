<?php
	
	namespace Quellabs\SignalHub\Transport;
	
	/**
	 * Interface for signal transports
	 */
	interface TransportInterface {
		
		/**
		 * Send a signal message to the transport destination
		 * @param string $signalName The name of the signal being sent
		 * @param array $message The complete message data including signal, timestamp, source, and data
		 * @param array $config Transport-specific configuration for this message
		 * @return bool True if message was sent successfully, false otherwise
		 */
		public function send(string $signalName, array $message, array $config): bool;
		
		/**
		 * Get the name/identifier of this transport
		 * @return string Transport name (e.g., 'slack', 'rabbitmq', 'email')
		 */
		public function getName(): string;
	}