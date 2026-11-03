<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Sculpt;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\Configuration as AnnotationReaderConfiguration;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;
	use Quellabs\ObjectQuel\DatabaseAdapter\ColumnDefinition;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Sculpt\Commands\MakeEntityFromTableCommand;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;

	/**
	 * MakeEntityFromTableCommand's generated entity code must follow the
	 * configured annotation_source at both class level (@Orm\Table/Index vs
	 * #[Table]/#[Index]/[ForeignKey]) and member level (@Orm\Column vs
	 * #[Column], including the ForeignKeyAction "presence means declared"
	 * convention), matching docblock output byte-for-byte with the
	 * pre-existing behavior.
	 */
	class MakeEntityFromTableCommandAnnotationSourceTest extends TestCase {

		/**
		 * Builds a command instance with the given annotation_source and a
		 * DatabaseAdapter stub (needed only by generateClassDocBlock() for
		 * index lookups), without opening a real database connection.
		 * @param string $annotationSource
		 * @param array<string, array{type: string, columns: array<int, string>}> $indexes
		 * @return MakeEntityFromTableCommand
		 */
		private function makeCommand(string $annotationSource, array $indexes = []): MakeEntityFromTableCommand {
			$adapter = $this->createMock(DatabaseAdapter::class);
			$adapter->method('getIndexes')->willReturn($indexes);

			$provider = new class($adapter) extends ServiceProvider {
				private DatabaseAdapter $testAdapter;

				/**
				 * @param DatabaseAdapter $testAdapter
				 */
				public function __construct(DatabaseAdapter $testAdapter) {
					$this->testAdapter = $testAdapter;
				}

				/**
				 * @return DatabaseAdapter
				 */
				public function getDatabaseAdapter(): DatabaseAdapter {
					return $this->testAdapter;
				}
			};

			$provider->setConfig(['annotation_source' => $annotationSource]);

			$output = new ConsoleOutput();

			return new MakeEntityFromTableCommand(new ConsoleInput($output), $output, $provider);
		}

		/**
		 * @param MakeEntityFromTableCommand $command
		 * @param string $tableName
		 * @param string $tableCamelCase
		 * @param array $compositeForeignKeys
		 * @return string
		 */
		private function generateClassDocBlock(MakeEntityFromTableCommand $command, string $tableName, string $tableCamelCase, array $compositeForeignKeys = []): string {
			$method = new \ReflectionMethod(MakeEntityFromTableCommand::class, 'generateClassDocBlock');
			$method->setAccessible(true);
			return $method->invoke($command, $tableName, $tableCamelCase, $compositeForeignKeys);
		}

		/**
		 * @param MakeEntityFromTableCommand $command
		 * @param array<string, ColumnDefinition> $tableDescription
		 * @param array $foreignKeys
		 * @return string
		 */
		private function generateMemberVariables(MakeEntityFromTableCommand $command, array $tableDescription, array $foreignKeys = []): string {
			$method = new \ReflectionMethod(MakeEntityFromTableCommand::class, 'generateMemberVariables');
			$method->setAccessible(true);
			return $method->invoke($command, $tableDescription, $foreignKeys);
		}

		/**
		 * @param array<string, mixed> $overrides
		 * @return ColumnDefinition
		 */
		private function baseColumn(array $overrides = []): ColumnDefinition {
			$merged = array_merge([
				'type'        => 'string',
				'php_type'    => 'string',
				'limit'       => 100,
				'default'     => null,
				'nullable'    => false,
				'precision'   => null,
				'scale'       => null,
				'unsigned'    => false,
				'generated'   => null,
				'identity'    => false,
				'primary_key' => false,
				'values'      => null,
			], $overrides);

			return new ColumnDefinition(...$merged);
		}

		/**
		 * @return void
		 */
		public function testGeneratesClassLevelTableAndUniqueIndexDocblockByDefault(): void {
			$command = $this->makeCommand(AnnotationReaderConfiguration::SOURCE_DOCBLOCKS, [
				'idx_sku' => ['type' => 'UNIQUE', 'columns' => ['sku']],
			]);

			$docBlock = $this->generateClassDocBlock($command, 'products', 'Products');

			$this->assertStringContainsString('@Orm\\Table(name="products")', $docBlock);
			$this->assertStringContainsString('@Orm\\UniqueIndex(name="idx_sku", columns={\'sku\'})', $docBlock);
			$this->assertStringNotContainsString('#[Table', $docBlock);
		}

		/**
		 * @return void
		 */
		public function testGeneratesClassLevelTableAndUniqueIndexAttributesWhenConfigured(): void {
			$command = $this->makeCommand(AnnotationReaderConfiguration::SOURCE_ATTRIBUTES, [
				'idx_sku' => ['type' => 'UNIQUE', 'columns' => ['sku']],
			]);

			$attributes = $this->generateClassDocBlock($command, 'products', 'Products');

			$this->assertStringContainsString('#[Table(name: "products")]', $attributes);
			$this->assertStringContainsString("#[UniqueIndex(name: \"idx_sku\", columns: ['sku'])]", $attributes);
			$this->assertStringNotContainsString('@Orm\\Table', $attributes);
		}

		/**
		 * A composite foreign key with a deviating action emits
		 * ForeignKeyAction only in the matching style.
		 * @return void
		 */
		public function testCompositeForeignKeyActionDeviationUsesAttributeStyleWhenConfigured(): void {
			$command = $this->makeCommand(AnnotationReaderConfiguration::SOURCE_ATTRIBUTES);

			$attributes = $this->generateClassDocBlock($command, 'order_items', 'OrderItems', [
				[
					'target'                => 'OrderEntity',
					'localProperties'       => ['orderId', 'orderRegion'],
					'referencedProperties'  => ['id', 'region'],
					'onDelete'              => 'CASCADE',
					'onUpdate'              => 'NO ACTION',
				],
			]);

			$this->assertStringContainsString("#[ForeignKey(target: OrderEntity::class, columns: ['orderId' => 'id', 'orderRegion' => 'region'])]", $attributes);
			$this->assertStringContainsString('#[ForeignKeyAction(localColumns: [\'orderId\', \'orderRegion\'], onDelete: "CASCADE", onUpdate: "NO ACTION")]', $attributes);
		}

		/**
		 * @return void
		 */
		public function testGeneratesMemberVariableColumnAndForeignKeyDocblockByDefault(): void {
			$command = $this->makeCommand(AnnotationReaderConfiguration::SOURCE_DOCBLOCKS);

			$output = $this->generateMemberVariables($command, [
				'customer_id' => $this->baseColumn(['type' => 'integer', 'php_type' => 'int', 'limit' => null]),
			], [
				'customer_id' => ['target' => 'CustomerEntity', 'referencedColumn' => 'id', 'onDelete' => 'CASCADE', 'onUpdate' => 'NO ACTION'],
			]);

			$this->assertStringContainsString('@Orm\\Column(name="customer_id", type="integer")', $output);
			$this->assertStringContainsString('@Orm\\ForeignKey(target=CustomerEntity::class, referencedColumn="id")', $output);
			$this->assertStringContainsString('@Orm\\ForeignKeyAction(onDelete="CASCADE", onUpdate="NO ACTION")', $output);
			$this->assertStringContainsString('protected int $customerId;', $output);
			$this->assertStringNotContainsString('#[Column', $output);
		}

		/**
		 * @return void
		 */
		public function testGeneratesMemberVariableColumnAndForeignKeyAttributesWhenConfigured(): void {
			$command = $this->makeCommand(AnnotationReaderConfiguration::SOURCE_ATTRIBUTES);

			$output = $this->generateMemberVariables($command, [
				'customer_id' => $this->baseColumn(['type' => 'integer', 'php_type' => 'int', 'limit' => null]),
			], [
				'customer_id' => ['target' => 'CustomerEntity', 'referencedColumn' => 'id', 'onDelete' => 'CASCADE', 'onUpdate' => 'NO ACTION'],
			]);

			$this->assertStringContainsString('#[Column(name: "customer_id", type: "integer")]', $output);
			$this->assertStringContainsString('#[ForeignKey(target: CustomerEntity::class, referencedColumn: "id")]', $output);
			$this->assertStringContainsString('#[ForeignKeyAction(onDelete: "CASCADE", onUpdate: "NO ACTION")]', $output);
			$this->assertStringContainsString('protected int $customerId;', $output);
			$this->assertStringNotContainsString('@Orm\\', $output);
		}

		/**
		 * ForeignKeyAction is only emitted on deviation from RESTRICT/NO
		 * ACTION — this must hold in attribute mode exactly like docblock mode.
		 * @return void
		 */
		public function testForeignKeyActionIsOmittedAtDefaultsInAttributeMode(): void {
			$command = $this->makeCommand(AnnotationReaderConfiguration::SOURCE_ATTRIBUTES);

			$output = $this->generateMemberVariables($command, [
				'customer_id' => $this->baseColumn(['type' => 'integer', 'php_type' => 'int', 'limit' => null]),
			], [
				'customer_id' => ['target' => 'CustomerEntity', 'referencedColumn' => 'id', 'onDelete' => 'RESTRICT', 'onUpdate' => 'NO ACTION'],
			]);

			$this->assertStringContainsString('#[ForeignKey(target: CustomerEntity::class, referencedColumn: "id")]', $output);
			$this->assertStringNotContainsString('ForeignKeyAction', $output);
		}

		/**
		 * A primary, auto-incrementing column emits PrimaryKeyStrategy in
		 * whichever style is configured.
		 * @return void
		 */
		public function testPrimaryKeyStrategyUsesAttributeStyleWhenConfigured(): void {
			$command = $this->makeCommand(AnnotationReaderConfiguration::SOURCE_ATTRIBUTES);

			$output = $this->generateMemberVariables($command, [
				'id' => $this->baseColumn(['type' => 'integer', 'php_type' => 'int', 'limit' => null, 'unsigned' => true, 'primary_key' => true, 'identity' => true]),
			]);

			$this->assertStringContainsString('#[PrimaryKeyStrategy(strategy: "identity")]', $output);
			$this->assertStringNotContainsString('@Orm\\PrimaryKeyStrategy', $output);
		}
	}
