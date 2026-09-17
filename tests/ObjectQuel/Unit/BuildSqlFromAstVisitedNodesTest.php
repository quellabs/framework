<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Execution\Visitors\BuildSqlFromAst;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;

	/**
	 * BuildSqlFromAst::addToVisitedNodes() used to store `true` for each visited
	 * node instead of the node itself. Once a locally-scoped node (e.g. a deep
	 * clone built, converted to SQL, and then discarded) had no other references
	 * left, PHP could garbage-collect it and reuse its object handle for a later,
	 * unrelated node — spl_object_hash() is only unique among currently-live
	 * objects, not across a request. A later node landing on a reused handle
	 * would then be misidentified as "already visited" and its SQL silently
	 * dropped.
	 *
	 * Storing the node itself keeps it alive for the visitor's lifetime, which
	 * structurally prevents its handle from ever being reused. This test verifies
	 * that mechanism directly rather than trying to force a handle collision,
	 * since collision timing depends on the garbage collector and isn't a
	 * reliable thing to assert on.
	 */
	class BuildSqlFromAstVisitedNodesTest extends TestCase {

		public function testVisitedNodesStoresTheNodeItselfNotJustABoolean(): void {
			$parameters = [];
			$visitor = new BuildSqlFromAst($GLOBALS['test_em']->getEntityStore(), $parameters);

			$node = new AstString('hello', '"');

			$reflectionMethod = new \ReflectionMethod($visitor, 'addToVisitedNodes');
			$reflectionMethod->setAccessible(true);
			$reflectionMethod->invoke($visitor, $node);

			$reflectionProperty = new \ReflectionProperty($visitor, 'visitedNodes');
			$reflectionProperty->setAccessible(true);
			$visitedNodes = $reflectionProperty->getValue($visitor);

			$hash = spl_object_hash($node);
			$this->assertArrayHasKey($hash, $visitedNodes);
			$this->assertSame($node, $visitedNodes[$hash]);
		}
	}
