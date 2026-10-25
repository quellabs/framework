<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineCall;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ExpandMacros;
	use Quellabs\ObjectQuel\Planner\Helpers\AstNodeReplacer;

	class RoutineArgumentReplacementTest extends TestCase {

		/**
		 * Expands repeated aliases in direct and nested arguments as independent expressions.
		 * @return void
		 */
		public function testExpandsAliasesInsideRoutineArguments(): void {
			$expression = new AstTerm(new AstNumber('1'), new AstNumber('2'), '+');
			$nested = new AstRoutineCall('g', [new AstIdentifier('score')]);
			$literal = new AstNumber('9');
			$call = new AstRoutineCall('f', [new AstIdentifier('score'), $literal, $nested, new AstIdentifier('score')]);
			$query = new AstRetrieve([], [], false);
			$query->addValue(new AstAlias('score', $expression));
			$query->addValue(new AstAlias('result', $call));

			$query->accept(new ExpandMacros($query));

			$arguments = $call->getArguments();
			self::assertCount(4, $arguments);
			self::assertSame($literal, $arguments[1]);
			self::assertSame($nested, $arguments[2]);
			foreach ([$arguments[0], $arguments[3], $nested->getArguments()[0]] as $replacement) {
				self::assertInstanceOf(AstTerm::class, $replacement);
				self::assertNotSame($expression, $replacement);
				self::assertSame('+', $replacement->getOperator());
				self::assertInstanceOf(AstNumber::class, $replacement->getLeft());
				self::assertSame('1', $replacement->getLeft()->getValue());
				self::assertSame($replacement, $replacement->getLeft()->getParent());
			}
			self::assertSame($call, $arguments[0]->getParent());
			self::assertSame($call, $arguments[3]->getParent());
			self::assertSame($nested, $nested->getArguments()[0]->getParent());
			self::assertInstanceOf(AstTerm::class, $arguments[0]);
			$arguments[0]->setLeft(new AstNumber('7'));
			self::assertSame('1', $expression->getLeft()->getValue());
			self::assertInstanceOf(AstTerm::class, $arguments[3]);
			self::assertInstanceOf(AstNumber::class, $arguments[3]->getLeft());
			self::assertSame('1', $arguments[3]->getLeft()->getValue());
		}

		/**
		 * Replacing a cloned nested argument leaves the source tree and return types intact.
		 * @return void
		 */
		public function testReplacementInClonePreservesOriginal(): void {
			$nested = new AstRoutineCall('g', [new AstNumber('1')]);
			$nested->setRoutineReturnType('integer');
			$call = new AstRoutineCall('f', [$nested]);
			$call->setRoutineReturnType('string');
			$clone = $call->deepClone();
			$clonedNested = $clone->getArguments()[0];
			self::assertInstanceOf(AstRoutineCall::class, $clonedNested);
			$replacement = new AstNumber('2');

			AstNodeReplacer::replaceChild($clonedNested, $clonedNested->getArguments()[0], $replacement);

			self::assertSame([$replacement], $clonedNested->getArguments());
			self::assertSame($clonedNested, $replacement->getParent());
			self::assertSame($clone, $clonedNested->getParent());
			self::assertSame('integer', $clonedNested->getRoutineReturnType());
			self::assertSame('string', $clone->getRoutineReturnType());
			self::assertInstanceOf(AstNumber::class, $nested->getArguments()[0]);
			self::assertSame('1', $nested->getArguments()[0]->getValue());
			self::assertSame($call, $nested->getParent());
		}

		/**
		 * Rejects a node with equal contents that is not actually an argument.
		 * @return void
		 */
		public function testRejectsMissingArgument(): void {
			$argument = new AstNumber('1');
			$call = new AstRoutineCall('f', [$argument]);

			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage('Cannot replace child');
			try {
				AstNodeReplacer::replaceChild($call, new AstNumber('1'), new AstNumber('2'));
			} finally {
				self::assertSame([$argument], $call->getArguments());
				self::assertSame($call, $argument->getParent());
			}
		}
	}
