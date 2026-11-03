<?php

	namespace Quellabs\CanvasDoctrine\Tests;
	require_once __DIR__ . '/Fixtures/DoctrineTestEntity.php';

	use Doctrine\DBAL\Connection;
	use Doctrine\ORM\EntityManagerInterface;
	use Doctrine\ORM\Proxy\ProxyFactory;
	use PHPUnit\Framework\TestCase;
	use Quellabs\CanvasDoctrine\ServiceProvider;

	/**
	 * Verifies the one connection and shared manager contract.
	 */
	class ServiceProviderTest extends TestCase {
		/**
		 * Resolves one manager and uses it to persist an entity.
		 * @return void
		 */
		public function testSingleManagerAndConnection(): void {
			$provider = new ServiceProvider();
			$provider->setConfig(['entity_paths' => [__DIR__], 'connection' => ['driver' => 'pdo_sqlite', 'memory' => true]]);
			$manager = $provider->createInstance(EntityManagerInterface::class, [], []);
			$this->assertSame($manager, $provider->createInstance(EntityManagerInterface::class, [], []));
			$this->assertSame($manager->getConnection(), $provider->createInstance(Connection::class, [], []));
			if (!$manager->getConfiguration()->isNativeLazyObjectsEnabled()) {
				$this->assertSame(
					\Quellabs\Support\ComposerUtils::getProjectRoot() . '/storage/doctrine/proxies',
					$manager->getConfiguration()->getProxyDir()
				);
				$this->assertSame(ProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS, $manager->getConfiguration()->getAutoGenerateProxyClasses());
			}

			$manager->getConnection()->executeStatement('CREATE TABLE DoctrineTestEntity (id INTEGER PRIMARY KEY)');
			$entity = new DoctrineTestEntity(7);
			$manager->persist($entity);
			$manager->flush();
			$manager->clear();
			$this->assertInstanceOf(DoctrineTestEntity::class, $manager->find(DoctrineTestEntity::class, 7));
			$manager->clear();

			$this->assertInstanceOf(DoctrineTestEntity::class, $manager->getReference(DoctrineTestEntity::class, 7));
			if (!$manager->getConfiguration()->isNativeLazyObjectsEnabled()) {
				$this->assertFileExists($manager->getConfiguration()->getProxyDir() . '/__CG__QuellabsCanvasDoctrineTestsDoctrineTestEntity.php');
			}
		}

		/**
		 * Rejects multiple connection definitions.
		 * @return void
		 */
		public function testRejectsMultipleConnections(): void {
			$provider = new ServiceProvider();
			$provider->setConfig(['connections' => ['first' => [], 'second' => []]]);
			$this->expectException(\InvalidArgumentException::class);
			$provider->createInstance(EntityManagerInterface::class, [], []);
		}

		/**
		 * Rejects configuration values that would silently change behavior.
		 * @return void
		 */
		public function testRejectsStringDevelopmentMode(): void {
			$provider = new ServiceProvider();
			$provider->setConfig(['development_mode' => 'false']);
			$this->expectException(\InvalidArgumentException::class);
			$provider->createInstance(EntityManagerInterface::class, [], []);
		}

		/**
		 * Rejects a list of named connection definitions.
		 * @return void
		 */
		public function testRejectsConnectionList(): void {
			$provider = new ServiceProvider();
			$provider->setConfig(['connection' => [['driver' => 'pdo_sqlite'], ['driver' => 'pdo_mysql']]]);
			$this->expectException(\InvalidArgumentException::class);
			$provider->createInstance(EntityManagerInterface::class, [], []);
		}
	}
