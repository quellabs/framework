<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Sculpt;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\Configuration as AnnotationReaderConfiguration;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\Sculpt\Helpers\EntityModifier;

	/**
	 * EntityModifier's class-level docblock/attribute generation must follow
	 * Configuration::getAnnotationSource(), emitting @Orm\Table/Index by
	 * default and #[Table]/#[Index] when the source is attributes.
	 */
	class EntityModifierAnnotationSourceTest extends TestCase {

		/**
		 * Invokes the private generateClassDocBlock() method under test.
		 * @param EntityModifier $modifier
		 * @param string $entityName
		 * @param array $indexes
		 * @return string
		 */
		private function generateClassDocBlock(EntityModifier $modifier, string $entityName, array $indexes): string {
			$method = new \ReflectionMethod(EntityModifier::class, 'generateClassDocBlock');
			$method->setAccessible(true);
			return $method->invoke($modifier, $entityName, $indexes);
		}

		/**
		 * @return void
		 */
		public function testEmitsTableAndUniqueIndexDocblockAnnotationsByDefault(): void {
			$configuration = new Configuration();
			$modifier = new EntityModifier($configuration);

			$content = $this->generateClassDocBlock($modifier, 'Product', [
				['name' => 'idx_product_sku', 'columns' => ['sku'], 'type' => 'UNIQUE'],
			]);

			$this->assertStringContainsString('@Orm\\Table(name="products")', $content);
			$this->assertStringContainsString('@Orm\\UniqueIndex(name="idx_product_sku", columns={"sku"})', $content);
			$this->assertStringNotContainsString('#[Table', $content);
		}

		/**
		 * @return void
		 */
		public function testEmitsTableAndUniqueIndexAttributesWhenConfigured(): void {
			$configuration = new Configuration();
			$configuration->setAnnotationSource(AnnotationReaderConfiguration::SOURCE_ATTRIBUTES);
			$modifier = new EntityModifier($configuration);

			$content = $this->generateClassDocBlock($modifier, 'Product', [
				['name' => 'idx_product_sku', 'columns' => ['sku'], 'type' => 'UNIQUE'],
			]);

			$this->assertStringContainsString('#[Table(name: "products")]', $content);
			$this->assertStringContainsString("#[UniqueIndex(name: \"idx_product_sku\", columns: ['sku'])]", $content);
			$this->assertStringNotContainsString('@Orm\\Table', $content);
		}

		/**
		 * A plain (non-unique, non-fulltext) index renders as #[Index] in
		 * attribute mode.
		 * @return void
		 */
		public function testPlainIndexRendersAsIndexAttribute(): void {
			$configuration = new Configuration();
			$configuration->setAnnotationSource(AnnotationReaderConfiguration::SOURCE_ATTRIBUTES);
			$modifier = new EntityModifier($configuration);

			$content = $this->generateClassDocBlock($modifier, 'Product', [
				['name' => 'idx_product_name', 'columns' => ['name'], 'type' => 'INDEX'],
			]);

			$this->assertStringContainsString("#[Index(name: \"idx_product_name\", columns: ['name'])]", $content);
			$this->assertStringNotContainsString('UniqueIndex', $content);
		}
	}
