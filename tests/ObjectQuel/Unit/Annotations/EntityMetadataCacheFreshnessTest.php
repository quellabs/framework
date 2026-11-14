<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Annotations;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReaderLocator;
	use Quellabs\AnnotationReader\Configuration as ReaderConfiguration;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\MigRegCustomerEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\NativeAttributeEntity;

	class EntityMetadataCacheFreshnessTest extends TestCase {
		/**
		 * Checks stale disk metadata across fresh readers without changing fixture sources.
		 * @param string $source
		 * @param class-string $entity
		 * @param string $table
		 * @param bool $developmentMode
		 * @return void
		 */
		#[DataProvider('cacheModes')]
		public function testStaleMetadataRespectsDevelopmentMode(string $source, string $entity, string $table, bool $developmentMode): void {
			$directory = sys_get_temp_dir() . '/objectquel-metadata-' . bin2hex(random_bytes(8));
			mkdir($directory);
			$previous = AnnotationReaderLocator::getInstance('objectquel');
			AnnotationReaderLocator::reset('objectquel');

			try {
				$configuration = new Configuration();
				$configuration->setEntityPath(__DIR__ . '/../../Fixtures/Entities');
				$configuration->setEntityNameSpace('Quellabs\\ObjectQuel\\Tests\\Fixtures\\Entities');
				$configuration->setMetadataCachePath($directory);
				$configuration->setUseMetadataCache(true);
				$configuration->setAnnotationSource($source);
				$configuration->setDevelopmentMode($developmentMode);
				$store = new EntityStore($configuration);
				self::assertSame($table, $store->getMetadata($entity)->tableName);

				$suffix = $source === ReaderConfiguration::SOURCE_ATTRIBUTES ? '.attributes.cache' : '.cache';
				$cacheFile = $directory . '/' . str_replace('\\', '#', $entity) . $suffix;
				$payload = file_get_contents($cacheFile);
				self::assertIsString($payload);
				self::assertStringContainsString($table, $payload);
				file_put_contents($cacheFile, str_replace($table, 'stale_entity_table', $payload));
				$sourceFile = (new \ReflectionClass($entity))->getFileName();
				self::assertIsString($sourceFile);
				touch($cacheFile, filemtime($sourceFile) - 10);
				clearstatcache();

				AnnotationReaderLocator::reset('objectquel');
				$store = new EntityStore($configuration);
				$expected = $developmentMode ? $table : 'stale_entity_table';
				self::assertSame($expected, $store->getMetadata($entity)->tableName);
			} finally {
				AnnotationReaderLocator::reset('objectquel');

				if ($previous !== null) {
					AnnotationReaderLocator::setInstance($previous, 'objectquel');
				}

				foreach (glob($directory . '/*') ?: [] as $file) {
					unlink($file);
				}

				rmdir($directory);
			}
		}

		/**
		 * Covers development refresh and production reuse for both metadata sources.
		 * @return iterable<string, array{string, class-string, string, bool}>
		 */
		public static function cacheModes(): iterable {
			yield 'docblocks development' => [ReaderConfiguration::SOURCE_DOCBLOCKS, MigRegCustomerEntity::class, 'mig_reg_customers', true];
			yield 'docblocks production' => [ReaderConfiguration::SOURCE_DOCBLOCKS, MigRegCustomerEntity::class, 'mig_reg_customers', false];
			yield 'attributes development' => [ReaderConfiguration::SOURCE_ATTRIBUTES, NativeAttributeEntity::class, 'native_attribute_entity', true];
			yield 'attributes production' => [ReaderConfiguration::SOURCE_ATTRIBUTES, NativeAttributeEntity::class, 'native_attribute_entity', false];
		}
	}
