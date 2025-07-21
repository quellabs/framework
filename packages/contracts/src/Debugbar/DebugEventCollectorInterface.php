<?php
	
	namespace Quellabs\Contracts\Debugbar;
	
	use Quellabs\SignalHub\Signal;
	use Quellabs\SignalHub\SignalHub;
	
	/**
	 * Interface for debug event collectors that listen for and store debug-related signals.
	 *
	 * This interface defines the contract for classes that automatically collect
	 * debug events from a SignalHub and provide methods to retrieve them.
	 */
	interface DebugEventCollectorInterface {
		/**
		 * Constructor - sets up signal listeners for debug events
		 *
		 * @param SignalHub $signalHub The signal hub instance to monitor
		 * @throws \Exception If signal connection fails
		 */
		public function __construct(SignalHub $signalHub);
		
		/**
		 * Handles newly registered signals - automatically connects to debug signals
		 *
		 * @param Signal $signal The newly registered signal
		 * @throws \Exception If signal connection fails
		 */
		public function handleNewSignal(Signal $signal): void;
		
		/**
		 * Returns all collected debug events
		 *
		 * @return array Array of events, each containing:
		 *               - 'signal': string - The signal name
		 *               - 'data': array - The event data
		 *               - 'timestamp': float - High precision timestamp
		 */
		public function getEvents(): array;
		
		/**
		 * Filters and returns events that match any of the provided signal patterns
		 *
		 * Supports both exact matches and wildcard patterns:
		 * - Exact: 'debug.cache.hit'
		 * - Multi-char wildcard: 'debug.cache.*' (matches zero or more chars)
		 * - Single-char wildcard: 'debug.cache.h?t' (matches exactly one char)
		 * - Combined: 'debug.*.h?t' (combines both wildcard types)
		 *
		 * @param array $signalPatterns Array of signal names or patterns to match against
		 * @return array Array of matching events with same structure as getEvents()
		 */
		public function getEventsBySignals(array $signalPatterns): array;
		
		/**
		 * Convenience method to filter events by a single signal name or pattern
		 *
		 * This is a wrapper around getEventsBySignals() for simpler single-pattern usage
		 *
		 * @param string $signal The signal name or pattern to match against
		 * @return array Array of matching events with same structure as getEvents()
		 */
		public function getEventsBySignal(string $signal): array;
	}