<?php

	namespace Quellabs\SignalHub\Tests\Fixtures;

	use Quellabs\SignalHub\Signal;

	/**
	 * Object with a Signal-typed property that is never assigned, used to
	 * verify SignalHub::discoverSignals() rejects uninitialized properties.
	 */
	class UninitializedSignalOwner {

		/**
		 * @var Signal Deliberately left uninitialized
		 */
		public Signal $signal;
	}
