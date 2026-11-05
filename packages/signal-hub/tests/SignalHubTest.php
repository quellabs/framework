<?php

	namespace Quellabs\SignalHub\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\SignalHub\Signal;
	use Quellabs\SignalHub\SignalHub;
	use Quellabs\SignalHub\Slot;
	use Quellabs\SignalHub\Tests\Fixtures\SignalOwner;
	use Quellabs\SignalHub\Tests\Fixtures\SignalOwnerInterface;
	use Quellabs\SignalHub\Tests\Fixtures\UninitializedSignalOwner;

	/**
	 * Unit tests for SignalHub: object-signal discovery, standalone signal
	 * registration, lookup, wildcard search, and the meta-signals that
	 * report hub activity.
	 */
	class SignalHubTest extends TestCase {

		/**
		 * discoverSignals() finds every Signal-typed property and returns
		 * the discovered Signal instances.
		 * @return void
		 */
		public function testDiscoverSignalsReturnsEveryDiscoveredSignal(): void {
			$hub = new SignalHub();
			$owner = new SignalOwner();

			$signals = $hub->discoverSignals($owner);

			$this->assertCount(2, $signals);

			foreach ($signals as $signal) {
				$this->assertInstanceOf(Signal::class, $signal);
			}
		}

		/**
		 * A Signal property without an explicit name is keyed by its
		 * property name; one with an explicit name is keyed by that name
		 * instead, even though the two differ.
		 * @return void
		 */
		public function testDiscoveredSignalsAreKeyedByNameFallingBackToPropertyName(): void {
			$hub = new SignalHub();
			$owner = new SignalOwner();

			$hub->discoverSignals($owner);

			$this->assertSame($owner->signalA, $hub->getSignal('signalA', $owner));
			$this->assertSame($owner->signalB, $hub->getSignal('custom.signal.name', $owner));
			$this->assertNull($hub->getSignal('signalB', $owner));
		}

		/**
		 * discoverSignals() on an object with no Signal-typed properties
		 * returns an empty array rather than throwing.
		 * @return void
		 */
		public function testDiscoverSignalsOnObjectWithoutSignalPropertiesReturnsEmptyArray(): void {
			$hub = new SignalHub();

			$this->assertSame([], $hub->discoverSignals(new \stdClass()));
		}

		/**
		 * Discovering the same object twice is rejected, since a silent
		 * overwrite would mask a dispatcher bug.
		 * @return void
		 */
		public function testDiscoveringTheSameObjectTwiceThrows(): void {
			$hub = new SignalHub();
			$owner = new SignalOwner();

			$hub->discoverSignals($owner);

			$this->expectException(\RuntimeException::class);
			$hub->discoverSignals($owner);
		}

		/**
		 * An uninitialized Signal-typed property is rejected rather than
		 * silently skipped, since the hub is a registry, not a factory.
		 * @return void
		 */
		public function testDiscoverSignalsRejectsAnUninitializedSignalProperty(): void {
			$hub = new SignalHub();

			$this->expectException(\RuntimeException::class);
			$hub->discoverSignals(new UninitializedSignalOwner());
		}

		/**
		 * unregisterSignals() removes a previously discovered object,
		 * after which its signals are no longer reachable through the hub.
		 * @return void
		 */
		public function testUnregisterSignalsRemovesAPreviouslyDiscoveredObject(): void {
			$hub = new SignalHub();
			$owner = new SignalOwner();

			$hub->discoverSignals($owner);
			$hub->unregisterSignals($owner);

			$this->assertNull($hub->getSignal('signalA', $owner));
		}

		/**
		 * unregisterSignals() on an object that was never discovered is a
		 * no-op, not an error.
		 * @return void
		 */
		public function testUnregisterSignalsOnUndiscoveredObjectIsANoOp(): void {
			$hub = new SignalHub();

			$hub->unregisterSignals(new SignalOwner());

			$this->assertTrue(true);
		}

		/**
		 * After unregisterSignals(), the object may be discovered again —
		 * the double-discovery guard is lifted along with the entry.
		 * @return void
		 */
		public function testObjectCanBeRediscoveredAfterUnregistering(): void {
			$hub = new SignalHub();
			$owner = new SignalOwner();

			$hub->discoverSignals($owner);
			$hub->unregisterSignals($owner);

			$signals = $hub->discoverSignals($owner);

			$this->assertCount(2, $signals);
		}

		/**
		 * getSignal() with a direct object reference performs a WeakMap
		 * lookup and returns null for an object that was never discovered,
		 * instead of throwing.
		 * @return void
		 */
		public function testGetSignalByObjectReturnsNullWhenNeverDiscovered(): void {
			$hub = new SignalHub();

			$this->assertNull($hub->getSignal('signalA', new SignalOwner()));
		}

		/**
		 * getSignal() with a class name scans discovered objects and
		 * returns the matching signal via an instanceof check, so a
		 * concrete class name resolves correctly.
		 * @return void
		 */
		public function testGetSignalByClassNameFindsTheDiscoveredInstance(): void {
			$hub = new SignalHub();
			$owner = new SignalOwner();

			$hub->discoverSignals($owner);

			$this->assertSame($owner->signalA, $hub->getSignal('signalA', SignalOwner::class));
		}

		/**
		 * getSignal() with an interface name also resolves, since the
		 * owner lookup uses instanceof rather than an exact class match.
		 * @return void
		 */
		public function testGetSignalByInterfaceNameFindsTheDiscoveredInstance(): void {
			$hub = new SignalHub();
			$owner = new SignalOwner();

			$hub->discoverSignals($owner);

			$this->assertSame($owner->signalA, $hub->getSignal('signalA', SignalOwnerInterface::class));
		}

		/**
		 * getSignal() without an owner only looks at standalone signals,
		 * even if an object-owned signal of the same name exists.
		 * @return void
		 */
		public function testGetSignalWithoutOwnerOnlyLooksAtStandaloneSignals(): void {
			$hub = new SignalHub();
			$hub->discoverSignals(new SignalOwner());

			$this->assertNull($hub->getSignal('signalA'));
		}

		/**
		 * hasSignal() mirrors getSignal(): true when found, false otherwise.
		 * @return void
		 */
		public function testHasSignalMirrorsGetSignal(): void {
			$hub = new SignalHub();
			$owner = new SignalOwner();

			$hub->discoverSignals($owner);

			$this->assertTrue($hub->hasSignal('signalA', $owner));
			$this->assertFalse($hub->hasSignal('doesNotExist', $owner));
		}

		/**
		 * registerSignal() makes a standalone signal reachable by name
		 * without any owning object.
		 * @return void
		 */
		public function testRegisterSignalMakesAStandaloneSignalReachableByName(): void {
			$hub = new SignalHub();
			$signal = new Signal('app.booted');

			$hub->registerSignal($signal);

			$this->assertSame($signal, $hub->getSignal('app.booted'));
		}

		/**
		 * registerSignal() rejects a signal that has no name, since
		 * standalone signals can only be looked up by name.
		 * @return void
		 */
		public function testRegisterSignalRejectsAnUnnamedSignal(): void {
			$hub = new SignalHub();

			$this->expectException(\RuntimeException::class);
			$hub->registerSignal(new Signal());
		}

		/**
		 * registerSignal() rejects a second signal registered under a
		 * name that is already taken.
		 * @return void
		 */
		public function testRegisterSignalRejectsADuplicateName(): void {
			$hub = new SignalHub();
			$hub->registerSignal(new Signal('app.booted'));

			$this->expectException(\RuntimeException::class);
			$hub->registerSignal(new Signal('app.booted'));
		}

		/**
		 * unregisterSignal() removes a registered standalone signal and
		 * reports success.
		 * @return void
		 */
		public function testUnregisterSignalRemovesARegisteredStandaloneSignal(): void {
			$hub = new SignalHub();
			$signal = new Signal('app.booted');
			$hub->registerSignal($signal);

			$this->assertTrue($hub->unregisterSignal($signal));
			$this->assertNull($hub->getSignal('app.booted'));
		}

		/**
		 * unregisterSignal() reports failure for a signal that was never
		 * registered, or that has no name at all.
		 * @return void
		 */
		public function testUnregisterSignalReportsFailureWhenNotRegistered(): void {
			$hub = new SignalHub();

			$this->assertFalse($hub->unregisterSignal(new Signal('never.registered')));
			$this->assertFalse($hub->unregisterSignal(new Signal()));
		}

		/**
		 * findSignals() without an owner matches standalone signal names
		 * against a wildcard pattern.
		 * @return void
		 */
		public function testFindSignalsMatchesStandaloneSignalsByWildcard(): void {
			$hub = new SignalHub();
			$paid = new Signal('payment.paid');
			$failed = new Signal('payment.failed');
			$booted = new Signal('app.booted');

			$hub->registerSignal($paid);
			$hub->registerSignal($failed);
			$hub->registerSignal($booted);

			$results = $hub->findSignals('payment.*');

			$this->assertSame(['payment.paid' => $paid, 'payment.failed' => $failed], $results);
		}

		/**
		 * findSignals() with a direct object owner only searches that
		 * object's own signals.
		 * @return void
		 */
		public function testFindSignalsWithObjectOwnerSearchesOnlyThatObject(): void {
			$hub = new SignalHub();
			$owner = new SignalOwner();
			$hub->discoverSignals($owner);

			$results = $hub->findSignals('*', $owner);

			$this->assertSame(['signalA' => $owner->signalA, 'custom.signal.name' => $owner->signalB], $results);
		}

		/**
		 * findSignals() with an object owner that was never discovered
		 * returns an empty array instead of throwing.
		 * @return void
		 */
		public function testFindSignalsWithUndiscoveredObjectOwnerReturnsEmptyArray(): void {
			$hub = new SignalHub();

			$this->assertSame([], $hub->findSignals('*', new SignalOwner()));
		}

		/**
		 * findSignals() with a class or interface name collects matches
		 * across every discovered instance of that type.
		 * @return void
		 */
		public function testFindSignalsWithClassNameCollectsAcrossInstances(): void {
			$hub = new SignalHub();
			$owner = new SignalOwner();
			$hub->discoverSignals($owner);

			$results = $hub->findSignals('signalA', SignalOwnerInterface::class);

			$this->assertSame(['signalA' => $owner->signalA], $results);
		}

		/**
		 * signalRegistered() fires once per Signal property discovered on
		 * an object, passing the Signal instance that was just registered.
		 * @return void
		 */
		public function testSignalRegisteredMetaSignalFiresOncePerDiscoveredSignal(): void {
			$hub = new SignalHub();
			$registered = [];

			$hub->signalRegistered()->connect(new Slot(function (Signal $signal) use (&$registered) {
				$registered[] = $signal;
			}));

			$owner = new SignalOwner();
			$hub->discoverSignals($owner);

			$this->assertCount(2, $registered);
			$this->assertContains($owner->signalA, $registered);
			$this->assertContains($owner->signalB, $registered);
		}

		/**
		 * signalRegistered() also fires for a manually registered
		 * standalone signal.
		 * @return void
		 */
		public function testSignalRegisteredMetaSignalFiresForStandaloneRegistration(): void {
			$hub = new SignalHub();
			$registered = null;

			$hub->signalRegistered()->connect(new Slot(function (Signal $signal) use (&$registered) {
				$registered = $signal;
			}));

			$signal = new Signal('app.booted');
			$hub->registerSignal($signal);

			$this->assertSame($signal, $registered);
		}

		/**
		 * signalUnregistered() fires for every signal removed by
		 * unregisterSignals(), while the signal is still accessible to
		 * the listener.
		 * @return void
		 */
		public function testSignalUnregisteredMetaSignalFiresForEachObjectSignal(): void {
			$hub = new SignalHub();
			$unregistered = [];

			$hub->signalUnregistered()->connect(new Slot(function (Signal $signal) use (&$unregistered) {
				$unregistered[] = $signal;
			}));

			$owner = new SignalOwner();
			$hub->discoverSignals($owner);
			$hub->unregisterSignals($owner);

			$this->assertCount(2, $unregistered);
			$this->assertContains($owner->signalA, $unregistered);
			$this->assertContains($owner->signalB, $unregistered);
		}

		/**
		 * signalUnregistered() fires for a standalone signal removed via
		 * unregisterSignal().
		 * @return void
		 */
		public function testSignalUnregisteredMetaSignalFiresForStandaloneRemoval(): void {
			$hub = new SignalHub();
			$unregistered = null;

			$hub->signalUnregistered()->connect(new Slot(function (Signal $signal) use (&$unregistered) {
				$unregistered = $signal;
			}));

			$signal = new Signal('app.booted');
			$hub->registerSignal($signal);
			$hub->unregisterSignal($signal);

			$this->assertSame($signal, $unregistered);
		}

		/**
		 * Object-owned signals are stored in a WeakMap: once every strong
		 * reference to the owning object is dropped, the hub's entry is
		 * collected automatically without an explicit unregisterSignals()
		 * call.
		 * @return void
		 */
		public function testObjectSignalsAreGarbageCollectedWhenTheOwnerGoesOutOfScope(): void {
			$hub = new SignalHub();
			$owner = new SignalOwner();

			$hub->discoverSignals($owner);
			$this->assertCount(1, $hub->findSignals('signalA', SignalOwner::class));

			unset($owner);

			$this->assertCount(0, $hub->findSignals('signalA', SignalOwner::class));
		}
	}
