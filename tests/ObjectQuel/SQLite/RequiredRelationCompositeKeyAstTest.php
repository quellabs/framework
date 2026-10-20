<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\Pipeline\IdentifierTypeResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Pipeline\QueryNormalizer;
	use Quellabs\ObjectQuel\ObjectQuel\SemanticAnalyzer;
	use Quellabs\ObjectQuel\Planner\QueryOptimizer;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Parsed/rewritten-AST-level counterpart to RequiredRelationCompositeKeyTest
	 * (which checks the generated SQL and executed rows). Runs the same pipeline
	 * RetrieveSqlCompiler uses up through QueryOptimizer, entirely offline (no SQL
	 * is generated or executed), and asserts directly on the range's own
	 * isRequired() flag — confirming RangeOptimizer itself marked the composite
	 * relation's range required, rather than inferring it from JOIN-keyword text
	 * in the compiled SQL.
	 */
	class RequiredRelationCompositeKeyAstTest extends TestCase {

		private static function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		/**
		 * Parses, normalizes (resolving `via` relation names into join
		 * conditions), validates, and optimizes a retrieve query, returning the
		 * resulting AstRetrieve for direct inspection.
		 */
		private function planQuery(string $query): AstRetrieve {
			$em = self::em();
			$entityStore = $em->getEntityStore();
			$platform = new FakePlatformCapabilities('sqlite');
			$parameters = [];

			$ast = (new Parser(new Lexer($query), $entityStore))->parse();
			self::assertInstanceOf(AstRetrieve::class, $ast);

			(new IdentifierTypeResolver($entityStore))->resolve($ast);
			(new QueryNormalizer($entityStore))->transform($ast);
			(new SemanticAnalyzer($entityStore, $platform))->validate($ast);
			(new QueryOptimizer($em, $platform))->transform($ast, $parameters);

			return $ast;
		}

		private function findRange(AstRetrieve $ast, string $name): AstRangeDatabase {
			foreach ($ast->getRanges() as $range) {
				if ($range instanceof AstRangeDatabase && $range->getName() === $name) {
					return $range;
				}
			}

			self::fail("Range '{$name}' not found in optimized AST.");
		}

		public function testRequiredRelationMarksTheCompositeRelationRangeRequired(): void {
			$ast = $this->planQuery('
				range of ol is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderLineEntity
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderEntity via ol.order
				retrieve (ol.id, o.amount)
			');

			self::assertTrue($this->findRange($ast, 'o')->isRequired());
		}

		public function testRelationWithoutRequiredRelationStaysOptional(): void {
			$ast = $this->planQuery('
				range of ol is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkOptionalOrderLineEntity
				range of o is Quellabs\\ObjectQuel\\Tests\\Fixtures\\CompositeKeyRelationshipEntities\\CkRequiredOrderEntity via ol.order
				retrieve (ol.id, o.amount)
			');

			self::assertFalse($this->findRange($ast, 'o')->isRequired());
		}
	}
