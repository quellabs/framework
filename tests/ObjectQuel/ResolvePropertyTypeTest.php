<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ResolvePropertyType;
	
	/**
	 * Unit tests for ResolvePropertyType.
	 *
	 * Verifies that the visitor correctly propagates IdentifierType values through
	 * identifier chains, with special attention to the JSON column boundary: when
	 * an EntityProperty node maps to a json-typed column, its children must become
	 * JsonProperty rather than EntityProperty.
	 */
	class ResolvePropertyTypeTest extends TestCase {
		
		/**
		 * Build a minimal EntityMetadataRecord stub for the given entity with the
		 * provided columnMap and columnDefinitions entries.
		 *
		 * @param string                           $entityName
		 * @param array<string, string>            $columnMap        property → column name
		 * @param array<string, array<string,mixed>> $columnDefinitions column name → definition
		 * @return EntityMetadataRecord
		 */
		private function makeMetadata(string $entityName, array $columnMap, array $columnDefinitions): EntityMetadataRecord {
			return new EntityMetadataRecord(
				className:          $entityName,
				tableName:          'stub',
				properties:         [],
				annotations:        [],
				columnMap:          $columnMap,
				identifierKeys:     [],
				identifierColumns:  [],
				versionColumns:     [],
				manyToOneRelations: [],
				inverseOfRelations: [],
				oneToOneRelations:  [],
				indexes:            [],
				autoIncrementColumn: null,
				columnDefinitions:  $columnDefinitions,
			);
		}
		
		/**
		 * Build a mock EntityStore that returns the given metadata for $entityName.
		 */
		private function makeEntityStore(string $entityName, EntityMetadataRecord $metadata): EntityStore {
			$store = $this->createMock(EntityStore::class);
			$store->method('getMetadata')
				->with($entityName)
				->willReturn($metadata);
			return $store;
		}
		
		/**
		 * Wire an AstRangeDatabase and create a root→property chain, setting
		 * the root type to EntityRoot and returning [$root, $property].
		 *
		 * @return array{AstIdentifier, AstIdentifier}
		 */
		private function makeEntityChain(string $entityName, string $rangeName, string $propertyName, AstRangeDatabase $range): array {
			$root = new AstIdentifier($rangeName, IdentifierType::EntityRoot);
			$root->setRange($range);
			
			$property = new AstIdentifier($propertyName, IdentifierType::Unresolved);
			$root->setNext($property);   // setNext also calls $property->setParent($root)
			
			return [$root, $property];
		}
		
		// -------------------------------------------------------------------------
		// EntityRoot → EntityProperty (non-JSON column)
		// -------------------------------------------------------------------------
		
		/**
		 * @test
		 */
		public function directChildOfEntityRootBecomesEntityProperty(): void {
			$entityName = 'App\Entity\Post';
			$metadata = $this->makeMetadata($entityName, ['title' => 'title'], ['title' => ['type' => 'string']]);
			$store = $this->makeEntityStore($entityName, $metadata);
			
			$range = $this->createMock(AstRangeDatabase::class);
			$range->method('getEntityName')->willReturn($entityName);
			
			[, $property] = $this->makeEntityChain($entityName, 'p', 'title', $range);
			
			$visitor = new ResolvePropertyType($store);
			$visitor->visitNode($property);
			
			$this->assertSame(IdentifierType::EntityProperty, $property->getType());
		}
		
		// -------------------------------------------------------------------------
		// EntityRoot → EntityProperty(json) → JsonProperty
		// -------------------------------------------------------------------------
		
		/**
		 * @test
		 */
		public function childOfJsonColumnBecomesJsonProperty(): void {
			$entityName = 'App\Entity\Post';
			$metadata = $this->makeMetadata(
				$entityName,
				['meta' => 'meta_col'],
				['meta_col' => ['type' => 'json']]
			);
			$store = $this->makeEntityStore($entityName, $metadata);
			
			$range = $this->createMock(AstRangeDatabase::class);
			$range->method('getEntityName')->willReturn($entityName);
			
			// Build: p (EntityRoot) → meta (EntityProperty) → id (Unresolved)
			$root = new AstIdentifier('p', IdentifierType::EntityRoot);
			$root->setRange($range);
			
			$meta = new AstIdentifier('meta', IdentifierType::EntityProperty);
			$root->setNext($meta);
			
			$id = new AstIdentifier('id', IdentifierType::Unresolved);
			$meta->setNext($id);
			
			$visitor = new ResolvePropertyType($store);
			$visitor->visitNode($id);
			
			$this->assertSame(IdentifierType::JsonProperty, $id->getType());
		}
		
		/**
		 * @test
		 */
		public function deepJsonPathSegmentsAllBecomeJsonProperty(): void {
			$entityName = 'App\Entity\Post';
			$metadata = $this->makeMetadata(
				$entityName,
				['meta' => 'meta_col'],
				['meta_col' => ['type' => 'json']]
			);
			$store = $this->makeEntityStore($entityName, $metadata);
			
			$range = $this->createMock(AstRangeDatabase::class);
			$range->method('getEntityName')->willReturn($entityName);
			
			// p (EntityRoot) → meta (EntityProperty) → user (JsonProperty) → id (Unresolved)
			$root = new AstIdentifier('p', IdentifierType::EntityRoot);
			$root->setRange($range);
			
			$meta = new AstIdentifier('meta', IdentifierType::EntityProperty);
			$root->setNext($meta);
			
			$user = new AstIdentifier('user', IdentifierType::JsonProperty);
			$meta->setNext($user);
			
			$id = new AstIdentifier('id', IdentifierType::Unresolved);
			$user->setNext($id);
			
			$visitor = new ResolvePropertyType($store);
			$visitor->visitNode($id);
			
			$this->assertSame(IdentifierType::JsonProperty, $id->getType());
		}
		
		// -------------------------------------------------------------------------
		// EntityRoot → EntityProperty(non-json) → EntityProperty
		// -------------------------------------------------------------------------
		
		/**
		 * @test
		 */
		public function childOfNonJsonColumnBecomesEntityProperty(): void {
			$entityName = 'App\Entity\Post';
			$metadata = $this->makeMetadata(
				$entityName,
				['title' => 'title'],
				['title' => ['type' => 'string']]
			);
			$store = $this->makeEntityStore($entityName, $metadata);
			
			$range = $this->createMock(AstRangeDatabase::class);
			$range->method('getEntityName')->willReturn($entityName);
			
			$root = new AstIdentifier('p', IdentifierType::EntityRoot);
			$root->setRange($range);
			
			$title = new AstIdentifier('title', IdentifierType::EntityProperty);
			$root->setNext($title);
			
			$sub = new AstIdentifier('sub', IdentifierType::Unresolved);
			$title->setNext($sub);
			
			$visitor = new ResolvePropertyType($store);
			$visitor->visitNode($sub);
			
			$this->assertSame(IdentifierType::EntityProperty, $sub->getType());
		}
		
		// -------------------------------------------------------------------------
		// Subquery chain
		// -------------------------------------------------------------------------
		
		/**
		 * @test
		 */
		public function childOfSubqueryRootBecomesSubqueryProperty(): void {
			$store = $this->createMock(EntityStore::class);
			
			$root = new AstIdentifier('s', IdentifierType::SubqueryRoot);
			$col  = new AstIdentifier('col', IdentifierType::Unresolved);
			$root->setNext($col);
			
			$visitor = new ResolvePropertyType($store);
			$visitor->visitNode($col);
			
			$this->assertSame(IdentifierType::SubqueryProperty, $col->getType());
		}
		
		/**
		 * @test
		 */
		public function childOfSubqueryPropertyBecomesSubqueryProperty(): void {
			$store = $this->createMock(EntityStore::class);
			
			$root   = new AstIdentifier('s', IdentifierType::SubqueryRoot);
			$colA   = new AstIdentifier('a', IdentifierType::SubqueryProperty);
			$colB   = new AstIdentifier('b', IdentifierType::Unresolved);
			$root->setNext($colA);
			$colA->setNext($colB);
			
			$visitor = new ResolvePropertyType($store);
			$visitor->visitNode($colB);
			
			$this->assertSame(IdentifierType::SubqueryProperty, $colB->getType());
		}
		
		// -------------------------------------------------------------------------
		// JsonRoot chain
		// -------------------------------------------------------------------------
		
		/**
		 * @test
		 */
		public function childOfJsonRootBecomesJsonProperty(): void {
			$store = $this->createMock(EntityStore::class);
			
			$root  = new AstIdentifier('j', IdentifierType::JsonRoot);
			$field = new AstIdentifier('field', IdentifierType::Unresolved);
			$root->setNext($field);
			
			$visitor = new ResolvePropertyType($store);
			$visitor->visitNode($field);
			
			$this->assertSame(IdentifierType::JsonProperty, $field->getType());
		}
		
		// -------------------------------------------------------------------------
		// Root nodes are not touched
		// -------------------------------------------------------------------------
		
		/**
		 * @test
		 */
		public function rootNodeIsIgnored(): void {
			$store = $this->createMock(EntityStore::class);
			
			// A root node has no AstIdentifier parent; it must be left untouched.
			$root = new AstIdentifier('p', IdentifierType::EntityRoot);
			
			$visitor = new ResolvePropertyType($store);
			$visitor->visitNode($root);
			
			$this->assertSame(IdentifierType::EntityRoot, $root->getType());
		}
	}