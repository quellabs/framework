<?php

	namespace Quellabs\SignalHub\Tests\Fixtures;

	use Quellabs\SignalHub\Signal;

	/**
	 * Object with two initialized Signal-typed properties, one of which
	 * carries an explicit name that differs from its property name.
	 */
	class SignalOwner implements SignalOwnerInterface {

		/**
		 * @var Signal Discovered under its property name "signalA"
		 */
		public Signal $signalA;

		/**
		 * @var Signal Discovered under its explicit name "custom.signal.name"
		 */
		public Signal $signalB;

		/**
		 * @var string Not Signal-typed, must be ignored by discovery
		 */
		public string $notASignal = 'irrelevant';

		/**
		 * SignalOwner constructor
		 */
		public function __construct() {
			$this->signalA = new Signal();
			$this->signalB = new Signal('custom.signal.name');
		}
	}
