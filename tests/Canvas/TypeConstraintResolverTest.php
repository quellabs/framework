<?php

	namespace Quellabs\Canvas\Tests\Routing;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Routing\TypeConstraintResolver;

	/**
	 * Unit tests for TypeConstraintResolver.
	 *
	 * Covers resolveTypeToRegex: type constraint name → regex pattern.
	 * Shared by RoutePatternCompiler (compile time) and PartialVariableStrategy
	 * (legacy-parsing match time).
	 */
	class TypeConstraintResolverTest extends TestCase {

		public function testResolveTypeToRegexForInt(): void {
			$this->assertSame('\d+', TypeConstraintResolver::resolveTypeToRegex('int'));
		}

		public function testResolveTypeToRegexForInteger(): void {
			$this->assertSame('\d+', TypeConstraintResolver::resolveTypeToRegex('integer'));
		}

		public function testResolveTypeToRegexForAlpha(): void {
			$this->assertSame('[a-zA-Z]+', TypeConstraintResolver::resolveTypeToRegex('alpha'));
		}

		public function testResolveTypeToRegexForSlug(): void {
			$this->assertSame('[a-zA-Z0-9-]+', TypeConstraintResolver::resolveTypeToRegex('slug'));
		}

		public function testResolveTypeToRegexForUuid(): void {
			$pattern = TypeConstraintResolver::resolveTypeToRegex('uuid');
			// UUID pattern must match a valid UUID
			$this->assertMatchesRegularExpression('/' . $pattern . '/', 'f47ac10b-58cc-4372-a567-0e02b2c3d479');
		}

		public function testResolveTypeToRegexForSingleWildcard(): void {
			$this->assertSame('[^\/]*', TypeConstraintResolver::resolveTypeToRegex('*'));
		}

		public function testResolveTypeToRegexForMultiWildcard(): void {
			$this->assertSame('.*', TypeConstraintResolver::resolveTypeToRegex('**'));
		}

		public function testResolveTypeToRegexFallsBackToDefaultForUnknownType(): void {
			// Unknown types fall back to [^/]+ (match any single segment)
			$this->assertSame('[^\/]+', TypeConstraintResolver::resolveTypeToRegex('unknowntype'));
		}
	}
