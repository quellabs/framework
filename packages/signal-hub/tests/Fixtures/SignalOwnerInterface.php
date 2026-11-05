<?php

	namespace Quellabs\SignalHub\Tests\Fixtures;

	/**
	 * Marker interface implemented by SignalOwner, used to verify that
	 * SignalHub::getSignal()/findSignals() can look up object-owned signals
	 * by interface name, not just by concrete class name.
	 */
	interface SignalOwnerInterface {
	}
