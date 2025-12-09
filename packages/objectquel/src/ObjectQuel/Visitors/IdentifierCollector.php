<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * AST visitor that collects base identifiers from the query tree.
	 *
	 * Traverses the Abstract Syntax Tree and collects only base identifiers
	 * (identifiers that represent root entities, not property paths or derived identifiers).
	 * Used during query analysis to determine which entities are referenced in a query.
	 */
	class IdentifierCollector implements AstVisitorInterface {
		
		/**
		 * Collection of base identifier nodes found during traversal.
		 * @var AstIdentifier[] Array of base identifier AST nodes
		 */
		private array $collectedNodes;
		
		/**
		 * Initializes a new identifier collector with an empty collection.
		 */
		public function __construct() {
			$this->collectedNodes = [];
		}
		
		/**
		 * Visits a node in the AST and collects it if it's a base identifier.
		 * @param AstInterface $node The current node being visited during AST traversal
		 */
		public function visitNode(AstInterface $node): void {
			// Only process identifier nodes
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Only collect base identifiers (e.g., "user" not "user.address.city")
			if (!$node->isBaseIdentifier()) {
				return;
			}
			
			// Add the base identifier to our collection
			$this->collectedNodes[] = $node;
		}
		
		/**
		 * Returns all base identifiers collected during AST traversal.
		 * @return AstIdentifier[] Array of collected base identifier nodes
		 */
		public function getCollectedNodes(): array {
			return $this->collectedNodes;
		}
	}