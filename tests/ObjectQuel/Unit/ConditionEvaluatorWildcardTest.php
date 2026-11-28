<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Execution\Helpers\ConditionEvaluator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;

	class ConditionEvaluatorWildcardTest extends TestCase {

		/**
		 * Lists literal value, wildcard offsets, subject and expected = result.
		 * @return array<string, array{string, int[], string, bool}>
		 */
		public static function comparisons(): array {
			return [
				'unescaped star matches'         => ['a*b', [1], 'axb', true],
				'escaped star is literal'        => ['a*b', [], 'axb', false],
				'escaped star matches itself'    => ['a*b', [], 'a*b', true],
				'partly escaped'                 => ['?*b', [0], 'x*b', true],
				'partly escaped rejects'         => ['?*b', [0], 'xyb', false],
				'literal bracket in pattern'     => ['[a]*', [3], '[a]bc', true],
				'literal bracket is no class'    => ['[a]*', [3], 'abc', false],
				'literal backslash in pattern'   => ['a\\*', [2], 'a\\bc', true],
				'case-insensitive wildcard'      => ['A*', [1], 'abc', true]
			];
		}

		/**
		 * The in-memory evaluator honors the literal's wildcard offsets for = and <>.
		 * @param string $value Literal value
		 * @param int[] $wildcardOffsets Unescaped wildcard offsets
		 * @param string $subject Value compared against the literal
		 * @param bool $expected Expected result of =
		 * @return void
		 */
		#[DataProvider('comparisons')]
		public function testStringLiteralComparison(string $value, array $wildcardOffsets, string $subject, bool $expected): void {
			$literal = new AstString($value, "'", $wildcardOffsets);
			$params = ['subject' => $subject];

			$equals = ConditionEvaluator::evaluate(new AstExpression(new AstParameter('subject'), $literal, '='), [], [], $params);
			$notEquals = ConditionEvaluator::evaluate(new AstExpression(new AstParameter('subject'), $literal, '<>'), [], [], $params);

			$this->assertSame($expected, $equals);
			$this->assertSame(!$expected, $notEquals);
		}

		/**
		 * Lists parameter value, subject and expected = result.
		 * @return array<string, array{string, string, bool}>
		 */
		public static function parameterComparisons(): array {
			return [
				'star is not a wildcard'          => ['a*b', 'axb', false],
				'question mark is not a wildcard' => ['a?b', 'axb', false],
				'star matches itself'             => ['a*b', 'a*b', true]
			];
		}

		/**
		 * A parameter on the right side compares exactly, as in SQL: * and ? are not wildcards.
		 * @param string $parameter Parameter value
		 * @param string $subject Value compared against the parameter
		 * @param bool $expected Expected result of =
		 * @return void
		 */
		#[DataProvider('parameterComparisons')]
		public function testParameterComparisonIsExact(string $parameter, string $subject, bool $expected): void {
			$params = ['subject' => $subject, 'pattern' => $parameter];
			$expression = new AstExpression(new AstParameter('subject'), new AstParameter('pattern'), '=');

			$this->assertSame($expected, ConditionEvaluator::evaluate($expression, [], [], $params));
		}
	}
