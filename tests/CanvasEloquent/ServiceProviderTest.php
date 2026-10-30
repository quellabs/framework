<?php

	namespace Quellabs\CanvasEloquent\Tests;

	use Illuminate\Database\ConnectionInterface;
	use Illuminate\Database\Eloquent\Model;
	use PHPUnit\Framework\TestCase;
	use Quellabs\CanvasEloquent\ServiceProvider;

	/**
	 * Eloquent model fixture used to confirm that a plain model (no constructor
	 * injection involved) can resolve a connection and run queries once the
	 * provider's Capsule has been booted.
	 */
	class ServiceProviderTestModel extends Model {

		/**
		 * @var string
		 */
		protected $table = 'service_provider_test_model';

		/**
		 * @var array<int, string>
		 */
		protected $fillable = ['name'];

		/**
		 * @var bool
		 */
		public $timestamps = false;
	}

	/**
	 * Unit tests for ServiceProvider: the supports()/createInstance() contract
	 * used by Canvas's DI container, the eager Capsule boot inside setConfig()
	 * that lets Eloquent models work without any constructor injection, and the
	 * config defaults/merging that feed the single configured connection.
	 */
	class ServiceProviderTest extends TestCase {

		/**
		 * Resets the provider's cached Capsule and Eloquent's static connection
		 * resolver before each test, since both are process-wide static state
		 * that would otherwise leak between tests.
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();
			$this->resetCapsule();
			Model::unsetConnectionResolver();
		}

		/**
		 * Leaves no booted Capsule or connection resolver behind for tests
		 * outside this file that may run in the same PHPUnit process.
		 * @return void
		 */
		protected function tearDown(): void {
			$this->resetCapsule();
			Model::unsetConnectionResolver();
			parent::tearDown();
		}

		/**
		 * Clears ServiceProvider's private static Capsule cache via reflection,
		 * since the class has no public reset method of its own.
		 * @return void
		 */
		private function resetCapsule(): void {
			$property = new \ReflectionProperty(ServiceProvider::class, 'capsule');
			$property->setAccessible(true);
			$property->setValue(null, null);
		}

		/**
		 * Builds a provider configured with an in-memory SQLite connection,
		 * optionally overriding/extending the config.
		 * @param array<string, mixed> $config
		 * @return ServiceProvider
		 */
		private function makeProvider(array $config = []): ServiceProvider {
			$provider = new ServiceProvider();
			$provider->setConfig(array_merge([
				'driver'   => 'sqlite',
				'database' => ':memory:',
			], $config));
			return $provider;
		}

		public function testSupportsOnlyTheConnectionInterface(): void {
			$provider = new ServiceProvider();

			$this->assertTrue($provider->supports(ConnectionInterface::class, []));
			$this->assertFalse($provider->supports(ServiceProviderTestModel::class, []));
			$this->assertFalse($provider->supports(\stdClass::class, []));
		}

		public function testGetConnectionDefaultsReturnsExpectedValues(): void {
			$this->assertSame([
				'driver'    => 'mysql',
				'host'      => 'localhost',
				'database'  => '',
				'username'  => '',
				'password'  => '',
				'port'      => 3306,
				'charset'   => 'utf8mb4',
				'collation' => 'utf8mb4_unicode_ci',
				'prefix'    => '',
			], ServiceProvider::getConnectionDefaults());
		}

		public function testSetConfigBootsEloquentWithoutAnyDiResolution(): void {
			// setConfig() alone -- never calling createInstance() -- must be
			// enough for a plain Eloquent model to resolve a connection.
			$this->makeProvider();

			$connection = ServiceProviderTestModel::getConnectionResolver()->connection();
			$connection->statement('CREATE TABLE service_provider_test_model (id INTEGER PRIMARY KEY, name TEXT)');

			ServiceProviderTestModel::create(['name' => 'jane']);

			$this->assertSame('jane', ServiceProviderTestModel::first()->name);
		}

		public function testCreateInstanceReturnsAWorkingConnection(): void {
			$provider = $this->makeProvider();

			$connection = $provider->createInstance(ConnectionInterface::class, [], []);

			$this->assertInstanceOf(ConnectionInterface::class, $connection);

			$connection->statement('CREATE TABLE direct_connection_test (id INTEGER PRIMARY KEY, name TEXT)');
			$connection->table('direct_connection_test')->insert(['id' => 1, 'name' => 'via-di']);

			$this->assertSame('via-di', $connection->table('direct_connection_test')->first()->name);
		}

		public function testCustomConfigOverridesDefaults(): void {
			$provider = $this->makeProvider(['prefix' => 'wp_']);

			$connection = $provider->createInstance(ConnectionInterface::class, [], []);

			$this->assertSame('wp_', $connection->getTablePrefix());
		}

		public function testUnspecifiedConfigValuesFallBackToDefaults(): void {
			$provider = $this->makeProvider();

			$connection = $provider->createInstance(ConnectionInterface::class, [], []);

			// Only 'driver' and 'database' were overridden in makeProvider();
			// everything else must come from getConnectionDefaults().
			$this->assertSame('utf8mb4', $connection->getConfig('charset'));
			$this->assertSame('utf8mb4_unicode_ci', $connection->getConfig('collation'));
		}

		public function testCapsuleIsBuiltOnlyOnceAndReusedAcrossCalls(): void {
			$provider = $this->makeProvider();

			$first = $provider->createInstance(ConnectionInterface::class, [], []);
			$second = $provider->createInstance(ConnectionInterface::class, [], []);

			// Both calls must hand back the same underlying connection: the
			// private static Capsule cache is built once per process, not
			// once per provider instance or per createInstance() call.
			$this->assertSame($first, $second);
		}
	}
