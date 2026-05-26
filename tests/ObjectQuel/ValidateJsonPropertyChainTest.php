<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ValidateJsonPropertyChain;
	
	/**
	 * Unit tests for ValidateJsonPropertyChain.
	 *
	 * Verifies that the validator accepts correctly typed chains and rejects
	 * JsonProperty nodes that appear after a non-JSON entity column or in any
	 * other invalid position.
	 */
	class ValidateJsonPropertyChainTest extends TestCase {
		
		/**
		 * Build a minimal EntityMetadataRecord for the given entity.
		 *
		 * @param string                             $entityName
		 * @param array<string, string>              $columnMap
		 * @param array<string, array<string, mixed>> $columnDefinitions
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
				oneToManyRelations: [],
				oneToOneRelations:  [],
				indexes:            [],
				autoIncrementColumn: null,
				columnDefinitions:  $columnDefinitions,
			);
		}
		
		/**
		 * Build a mock EntityStore that returns metadata for one entity.
		 */
		private function makeEntityStore(string $entityName, EntityMetadataRecord $metadata): EntityStore {
			$store = $this->createMock(EntityStore::class);
			$store->method('getMetadata')
				->with($entityName)
				->willReturn($metadata);
			return $store;
		}
		
		// -------------------------------------------------------------------------
		// Valid chains — no exception expected
		// -------------------------------------------------------------------------
		
		/**
		 * @test
		 * JsonProperty whose parent is a JSON-typed EntityProperty: valid.
		 */
		public function acceptsJsonPropertyAfterJsonTypedEntityProperty(): void {
			$entityName = 'App\Entity\Post';
			$metadata   = $this->makeMetadata(
				$entityName,
				['meta' => 'meta_col'],
				['meta_col' => ['type' => 'json']]
			);
			$store = $this->makeEntityStore($entityName, $metadata);
			
			$range = $this->createMock(AstRangeDatabase::class);
			$range->method('getEntityName')->willReturn($entityName);
			
			// p (EntityRoot) → meta (EntityProperty) → id (JsonProperty)
			$root = new AstIdentifier('p', IdentifierType::EntityRoot);
			$root->setRange($range);
			
			$meta = new AstIdentifier('meta', IdentifierType::EntityProperty);
			$root->setNext($meta);
			
			$id = new AstIdentifier('id', IdentifierType::JsonProperty);
			$meta->setNext($id);
			
			$validator = new ValidateJsonPropertyChain($store);
			
			// Must not throw.
			$validator->visitNode($id);
			$this->addToAssertionCount(1);
		}
		
		/**
		 * @test
		 * JsonProperty whose parent is another JsonProperty: valid (deep path).
		 */
		public function acceptsJsonPropertyAfterJsonProperty(): void {
			$entityName = 'App\Entity\Post';
			$metadata   = $this->makeMetadata(
				$entityName,
				['meta' => 'meta_col'],
				['meta_col' => ['type' => 'json']]
			);
			$store = $this->makeEntityStore($entityName, $metadata);
			
			$range = $this->createMock(AstRangeDatabase::class);
			$range->method('getEntityName')->willReturn($entityName);
			
			// p (EntityRoot) → meta (EntityProperty) → user (JsonProperty) → id (JsonProperty)
			$root = new AstIdentifier('p', IdentifierType::EntityRoot);
			$root->setRange($range);
			
			$meta = new AstIdentifier('meta', IdentifierType::EntityProperty);
			$root->setNext($meta);
			
			$user = new AstIdentifier('user', IdentifierType::JsonProperty);
			$meta->setNext($user);
			
			$id = new AstIdentifier('id', IdentifierType::JsonProperty);
			$user->setNext($id);
			
			$validator = new ValidateJsonPropertyChain($store);
			$validator->visitNode($id);
			$this->addToAssertionCount(1);
		}
		
		/**
		 * @test
		 * Non-JsonProperty nodes are silently ignored.
		 */
		public function ignoresNonJsonPropertyNodes(): void {
			$store = $this->createMock(EntityStore::class);
			
			$root     = new AstIdentifier('p', IdentifierType::EntityRoot);
			$property = new AstIdentifier('title', IdentifierType::EntityProperty);
			$root->setNext($property);
			
			$validator = new ValidateJsonPropertyChain($store);
			$validator->visitNode($property);
			$this->addToAssertionCount(1);
		}
		
		// -------------------------------------------------------------------------
		// Invalid chains — SemanticException expected
		// -------------------------------------------------------------------------
		
		/**
		 * @test
		 * JsonProperty after a string-typed EntityProperty must be rejected.
		 */
		public function rejectsJsonPropertyAfterNonJsonColumn(): void {
			$entityName = 'App\Entity\Post';
			$metadata   = $this->makeMetadata(
				$entityName,
				['title' => 'title'],
				['title' => ['type' => 'string']]
			);
			$store = $this->makeEntityStore($entityName, $metadata);
			
			$range = $this->createMock(AstRangeDatabase::class);
			$range->method('getEntityName')->willReturn($entityName);
			
			// p (EntityRoot) → title (EntityProperty/string) → id (JsonProperty) ← invalid
			$root = new AstIdentifier('p', IdentifierType::EntityRoot);
			$root->setRange($range);
			
			$title = new AstIdentifier('title', IdentifierType::EntityProperty);
			$root->setNext($title);
			
			$id = new AstIdentifier('id', IdentifierType::JsonProperty);
			$title->setNext($id);
			
			$validator = new ValidateJsonPropertyChain($store);
			
			$this->expectException(SemanticException::class);
			$validator->visitNode($id);
		}
		
		/**
		 * @test
		 * JsonProperty whose parent is an EntityRoot (no EntityProperty boundary) must be rejected.
		 */
		public function rejectsJsonPropertyDirectlyUnderEntityRoot(): void {
			$store = $this->createMock(EntityStore::class);
			
			// p (EntityRoot) → id (JsonProperty) ← invalid: no EntityProperty boundary
			$root = new AstIdentifier('p', IdentifierType::EntityRoot);
			$id   = new AstIdentifier('id', IdentifierType::JsonProperty);
			$root->setNext($id);
			
			$validator = new ValidateJsonPropertyChain($store);
			
			$this->expectException(SemanticException::class);
			$validator->visitNode($id);
		}
	}