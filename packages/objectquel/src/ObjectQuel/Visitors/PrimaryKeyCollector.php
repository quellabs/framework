<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	class PrimaryKeyCollector implements AstVisitorInterface {
		
		private EntityStore $entityStore;

		/** @var array All nodes */
		private array $collectedNodes;
		
		/**
		 * CollectIdentifiers constructor
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
			$this->collectedNodes = [];
		}
		
		/**
		 * Visits a node in the AST
		 * @param AstInterface $node The current node being visited
		 */
		public function visitNode(AstInterface $node): void {
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			if (!$node->isBaseIdentifier()) {
				return;
			}
			
			if ($node->getRange() === null) {
				return;
			}
			
			if (!$this->entityStore->isPrimaryKey($node->getEntityName(), $node->getNext()->getName())) {
				return;
			}
			
			$this->collectedNodes[] = $node;
		}
		
		/**
		 * Returns all collected nodes
		 * @return AstIdentifier[]
		 */
		public function getCollectedNodes(): array {
			return $this->collectedNodes;
		}
	}