<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Sculpt;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\Configuration as AnnotationReaderConfiguration;
	use Quellabs\ObjectQuel\Sculpt\Helpers\PhpClassGenerator;

	/**
	 * PhpClassGenerator emits docblock @Orm annotations by default and native
	 * PHP attributes when constructed with Configuration::SOURCE_ATTRIBUTES —
	 * covers generateIdProperty(), generatePropertyDocComment() (including the
	 * ForeignKey/ForeignKeyAction "presence means declared" convention), and
	 * generateRelationshipDocComment().
	 */
	class PhpClassGeneratorAnnotationSourceTest extends TestCase {

		/**
		 * @return void
		 */
		public function testGenerateIdPropertyEmitsDocblockAnnotationsByDefault(): void {
			$generator = new PhpClassGenerator();

			$code = $generator->generateIdProperty();

			$this->assertStringContainsString('@Orm\\Column(name="id", type="integer", unsigned=true, primary_key=true)', $code);
			$this->assertStringContainsString('@Orm\\PrimaryKeyStrategy(strategy="identity")', $code);
			$this->assertStringNotContainsString('#[Column', $code);
		}

		/**
		 * @return void
		 */
		public function testGenerateIdPropertyEmitsNativeAttributesWhenConfigured(): void {
			$generator = new PhpClassGenerator(AnnotationReaderConfiguration::SOURCE_ATTRIBUTES);

			$code = $generator->generateIdProperty();

			$this->assertStringContainsString('#[Column(name: "id", type: "integer", unsigned: true, primary_key: true)]', $code);
			$this->assertStringContainsString('#[PrimaryKeyStrategy(strategy: "identity")]', $code);
			$this->assertStringNotContainsString('@Orm\\Column', $code);
		}

		/**
		 * ForeignKeyAction is only emitted when the rule deviates from the safe
		 * defaults (RESTRICT / NO ACTION) — this must hold in both modes.
		 * @return void
		 */
		public function testGeneratePropertyDocCommentEmitsForeignKeyActionOnlyOnDeviationInDocblockMode(): void {
			$generator = new PhpClassGenerator();

			$property = [
				'name'       => 'customerId',
				'type'       => 'integer',
				'foreignKey' => ['target' => 'CustomerEntity', 'onDelete' => 'CASCADE', 'onUpdate' => 'NO ACTION'],
			];

			$comment = $generator->generatePropertyDocComment($property);

			$this->assertStringContainsString('@Orm\\ForeignKey(target=CustomerEntity::class)', $comment);
			$this->assertStringContainsString('@Orm\\ForeignKeyAction(onDelete="CASCADE", onUpdate="NO ACTION")', $comment);
		}

		/**
		 * @return void
		 */
		public function testGeneratePropertyDocCommentOmitsForeignKeyActionAtDefaultsInAttributeMode(): void {
			$generator = new PhpClassGenerator(AnnotationReaderConfiguration::SOURCE_ATTRIBUTES);

			$property = [
				'name'       => 'customerId',
				'type'       => 'integer',
				'foreignKey' => ['target' => 'CustomerEntity', 'onDelete' => 'RESTRICT', 'onUpdate' => 'NO ACTION'],
			];

			$comment = $generator->generatePropertyDocComment($property);

			$this->assertStringContainsString('#[ForeignKey(target: CustomerEntity::class)]', $comment);
			$this->assertStringNotContainsString('ForeignKeyAction', $comment);
			$this->assertStringNotContainsString('@Orm\\', $comment);
		}

		/**
		 * InverseOf collections get an additional @var hint in both modes so
		 * IDEs know the collection's generic type.
		 * @return void
		 */
		public function testGenerateRelationshipDocCommentAddsCollectionVarHintInAttributeMode(): void {
			$generator = new PhpClassGenerator(AnnotationReaderConfiguration::SOURCE_ATTRIBUTES);

			$property = [
				'relationshipType' => 'InverseOf',
				'targetEntity'     => 'Order',
				'collection'       => true,
				'relation'         => 'customer',
			];

			$comment = $generator->generateRelationshipDocComment($property);

			$this->assertStringContainsString('@var CollectionInterface<OrderEntity>', $comment);
			$this->assertStringContainsString('#[InverseOf(targetEntity: "OrderEntity", relation: "customer", fetch: "LAZY")]', $comment);
			$this->assertStringNotContainsString('@Orm\\InverseOf', $comment);
		}

		/**
		 * @return void
		 */
		public function testGenerateRelationshipDocCommentUsesDocblockAnnotationByDefault(): void {
			$generator = new PhpClassGenerator();

			$property = [
				'relationshipType' => 'InverseOf',
				'targetEntity'     => 'Order',
				'collection'       => true,
				'relation'         => 'customer',
			];

			$comment = $generator->generateRelationshipDocComment($property);

			$this->assertStringContainsString('@Orm\\InverseOf(targetEntity="OrderEntity", relation="customer", fetch="LAZY")', $comment);
			$this->assertStringContainsString('@var CollectionInterface<OrderEntity>', $comment);
			$this->assertStringNotContainsString('#[InverseOf', $comment);
		}
	}
