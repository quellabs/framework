<?php

	namespace Quellabs\ObjectQuel\Tests\Universal;

	use App\Entities\UserEntity;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;
	use Quellabs\SignalHub\SignalHubLocator;
	use Quellabs\SignalHub\Slot;

	/**
	 * Tests that several EntityManagers coexist, each with its own lifecycle signals.
	 */
	class MultipleEntityManagerTest extends ObjectQuelTestCase {

		/**
		 * Creates a second EntityManager sharing the suite's configuration and connection.
		 * @return EntityManager
		 */
		private function createSecondEntityManager(): EntityManager {
			return new EntityManager($this->em->getConfiguration(), $GLOBALS['test_connection']);
		}

		/**
		 * Each EntityManager's hub holds its own UnitOfWork's lifecycle signals.
		 * @return void
		 */
		public function testEachEntityManagerHasItsOwnLifecycleSignals(): void {
			$second = $this->createSecondEntityManager();

			$this->assertNotSame($this->em->getSignalHub(), $second->getSignalHub());

			$this->assertSame(
				$this->em->getUnitOfWork()->signalPrePersist,
				$this->em->getSignalHub()->getSignal('orm.prePersist')
			);

			$this->assertSame(
				$second->getUnitOfWork()->signalPrePersist,
				$second->getSignalHub()->getSignal('orm.prePersist')
			);

			$this->assertNotSame(
				$this->em->getUnitOfWork()->signalPrePersist,
				$second->getUnitOfWork()->signalPrePersist
			);
		}

		/**
		 * Lifecycle signals are not registered on the global hub.
		 * @return void
		 */
		public function testLifecycleSignalsAreNotOnGlobalHub(): void {
			$this->assertNull(SignalHubLocator::getInstance()->getSignal('orm.prePersist'));
		}

		/**
		 * Flushing on one EntityManager only emits that EntityManager's lifecycle signals.
		 * @return void
		 */
		public function testFlushEmitsOnlyOnOwningEntityManager(): void {
			$second = $this->createSecondEntityManager();

			$firstEmitted = [];
			$secondEmitted = [];
			$firstSlot = new Slot(function (object $entity) use (&$firstEmitted): void { $firstEmitted[] = $entity; });
			$secondSlot = new Slot(function (object $entity) use (&$secondEmitted): void { $secondEmitted[] = $entity; });
			$this->em->getUnitOfWork()->signalPrePersist->connect($firstSlot);
			$second->getUnitOfWork()->signalPrePersist->connect($secondSlot);

			$user = new UserEntity();
			$user->setUsername('carol');
			$user->setPassword('hash3');

			// Disconnect even on failure, since the first EntityManager is shared by the whole suite
			try {
				$second->persist($user);
				$second->flush();
			} finally {
				$this->em->getUnitOfWork()->signalPrePersist->disconnect($firstSlot);
				$second->getUnitOfWork()->signalPrePersist->disconnect($secondSlot);
			}

			$this->assertSame([], $firstEmitted);
			$this->assertSame([$user], $secondEmitted);
		}

		/**
		 * All EntityManagers share the global debug query signal, and destroying one keeps it registered.
		 * @return void
		 */
		public function testDebugQuerySignalSurvivesDestroyedEntityManager(): void {
			$globalHub = SignalHubLocator::getInstance();
			$signalBefore = $globalHub->getSignal('debug.database.query');

			$second = $this->createSecondEntityManager();
			$secondReference = \WeakReference::create($second);
			unset($second);
			gc_collect_cycles();

			$this->assertNull($secondReference->get(), 'Second EntityManager was destroyed');
			$this->assertNotNull($signalBefore);
			$this->assertSame($signalBefore, $globalHub->getSignal('debug.database.query'));
		}
	}
