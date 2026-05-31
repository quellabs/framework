<?php
	
	namespace Quellabs\Canvas\Tests\Routing;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Routing\Components\RoutePatternCompiler;
	use Quellabs\Canvas\Routing\Components\RouteSegmentAnalyzer;
	use Quellabs\Canvas\Routing\SegmentTypes;
	
	/**
	 * Unit tests for RoutePatternCompiler.
	 *
	 * Covers:
	 * - parseRoutePath: path string → segment list
	 * - resolveTypeToRegex: type name → regex string
	 * - compileRoute: full compilation of route patterns into CompiledSegment arrays
	 * - compilePartialSegmentPattern: partial segment regex generation
	 */
	class RoutePatternCompilerTest extends TestCase {
		
		private RoutePatternCompiler $compiler;
		
		protected function setUp(): void {
			$this->compiler = new RoutePatternCompiler(new RouteSegmentAnalyzer());
		}
		
		// -------------------------------------------------------------------------
		// parseRoutePath
		// -------------------------------------------------------------------------
		
		public function testParseRoutePathSplitsOnSlash(): void {
			$this->assertSame(['users', 'profile'], $this->compiler->parseRoutePath('/users/profile'));
		}
		
		public function testParseRoutePathStripsLeadingSlash(): void {
			$this->assertSame(['api', 'v1'], $this->compiler->parseRoutePath('/api/v1'));
		}
		
		public function testParseRoutePathHandlesDoubleSlashes(): void {
			// Double slashes produce empty segments that are filtered out
			$this->assertSame(['users', 'profile'], $this->compiler->parseRoutePath('/users//profile'));
		}
		
		public function testParseRoutePathHandlesTrailingSlash(): void {
			$this->assertSame(['users'], $this->compiler->parseRoutePath('/users/'));
		}
		
		public function testParseRoutePathReturnsEmptyArrayForRootPath(): void {
			$this->assertSame([], $this->compiler->parseRoutePath('/'));
		}
		
		public function testParseRoutePathPreservesVariableSegments(): void {
			$this->assertSame(['users', '{id}', 'posts'], $this->compiler->parseRoutePath('/users/{id}/posts'));
		}
		
		// -------------------------------------------------------------------------
		// resolveTypeToRegex
		// -------------------------------------------------------------------------
		
		public function testResolveTypeToRegexForInt(): void {
			$this->assertSame('\d+', $this->compiler->resolveTypeToRegex('int'));
		}
		
		public function testResolveTypeToRegexForInteger(): void {
			$this->assertSame('\d+', $this->compiler->resolveTypeToRegex('integer'));
		}
		
		public function testResolveTypeToRegexForAlpha(): void {
			$this->assertSame('[a-zA-Z]+', $this->compiler->resolveTypeToRegex('alpha'));
		}
		
		public function testResolveTypeToRegexForSlug(): void {
			$this->assertSame('[a-zA-Z0-9-]+', $this->compiler->resolveTypeToRegex('slug'));
		}
		
		public function testResolveTypeToRegexForUuid(): void {
			$pattern = $this->compiler->resolveTypeToRegex('uuid');
			// UUID pattern must match a valid UUID
			$this->assertMatchesRegularExpression('/' . $pattern . '/', 'f47ac10b-58cc-4372-a567-0e02b2c3d479');
		}
		
		public function testResolveTypeToRegexForSingleWildcard(): void {
		$this->assertSame('[^\/]*', $this->compiler->resolveTypeToRegex('*'));
		}
		
		public function testResolveTypeToRegexForMultiWildcard(): void {
			$this->assertSame('.*', $this->compiler->resolveTypeToRegex('**'));
		}
		
		public function testResolveTypeToRegexFallsBackToDefaultForUnknownType(): void {
			// Unknown types fall back to [^/]+ (match any single segment)
		$this->assertSame('[^\/]+', $this->compiler->resolveTypeToRegex('unknowntype'));
		}
		
		// -------------------------------------------------------------------------
		// compileRoute — static segments
		// -------------------------------------------------------------------------
		
		public function testCompileRouteStaticSegmentHasCorrectType(): void {
			$segments = $this->compiler->compileRoute('/users');
			$this->assertCount(1, $segments);
			$this->assertSame(SegmentTypes::STATIC, $segments[0]['type']);
		}
		
		public function testCompileRouteStaticSegmentPreservesOriginal(): void {
			$segments = $this->compiler->compileRoute('/users');
			$this->assertSame('users', $segments[0]['original']);
		}
		
		public function testCompileRouteStaticSegmentHasNoVariableName(): void {
			$segments = $this->compiler->compileRoute('/users');
			$this->assertNull($segments[0]['variable_name']);
		}
		
		public function testCompileRouteMultipleStaticSegments(): void {
			$segments = $this->compiler->compileRoute('/api/v1/users');
			$this->assertCount(3, $segments);
			
			foreach ($segments as $segment) {
				$this->assertSame(SegmentTypes::STATIC, $segment['type']);
			}
		}
		
		// -------------------------------------------------------------------------
		// compileRoute — variable segments
		// -------------------------------------------------------------------------
		
		public function testCompileRouteSimpleVariableHasCorrectType(): void {
			$segments = $this->compiler->compileRoute('/{id}');
			$this->assertSame(SegmentTypes::VARIABLE, $segments[0]['type']);
		}
		
		public function testCompileRouteSimpleVariableExtractsName(): void {
			$segments = $this->compiler->compileRoute('/{id}');
			$this->assertSame('id', $segments[0]['variable_name']);
		}
		
		public function testCompileRouteTypedVariableUsesCorrectPattern(): void {
			$segments = $this->compiler->compileRoute('/{id:int}');
			$this->assertStringContainsString('\d+', $segments[0]['pattern']);
		}
		
		public function testCompileRouteAlphaTypedVariable(): void {
			$segments = $this->compiler->compileRoute('/{slug:alpha}');
			$this->assertStringContainsString('[a-zA-Z]+', $segments[0]['pattern']);
		}
		
		public function testCompileRouteMixedStaticAndVariable(): void {
			$segments = $this->compiler->compileRoute('/users/{id}/posts');
			$this->assertCount(3, $segments);
			$this->assertSame(SegmentTypes::STATIC, $segments[0]['type']);
			$this->assertSame(SegmentTypes::VARIABLE, $segments[1]['type']);
			$this->assertSame(SegmentTypes::STATIC, $segments[2]['type']);
			$this->assertSame('id', $segments[1]['variable_name']);
		}
		
		// -------------------------------------------------------------------------
		// compileRoute — wildcards
		// -------------------------------------------------------------------------
		
		public function testCompileRouteSingleWildcardHasCorrectType(): void {
			$segments = $this->compiler->compileRoute('/files/*');
			$this->assertSame(SegmentTypes::SINGLE_WILDCARD, $segments[1]['type']);
		}
		
		public function testCompileRouteMultiWildcardHasCorrectType(): void {
			$segments = $this->compiler->compileRoute('/files/**');
			$this->assertSame(SegmentTypes::MULTI_WILDCARD, $segments[1]['type']);
		}
		
		public function testCompileRouteMultiWildcardIsMultiWildcardFlag(): void {
			$segments = $this->compiler->compileRoute('/files/**');
			$this->assertTrue($segments[1]['is_multi_wildcard']);
		}
		
		public function testCompileRouteNamedMultiWildcard(): void {
			$segments = $this->compiler->compileRoute('/files/{path:**}');
			$this->assertSame(SegmentTypes::MULTI_WILDCARD_VAR, $segments[1]['type']);
		}
		
		// -------------------------------------------------------------------------
		// compileRoute — remaining_segments_count post-processing
		// -------------------------------------------------------------------------
		
		public function testCompileRouteMultiWildcardWithTrailingStaticSegment(): void {
			// /files/**/README.md → ** must leave 1 segment for README.md
			$segments = $this->compiler->compileRoute('/files/**/README.md');
			$multiIdx = 1; // ** is at index 1
			$this->assertSame(1, $segments[$multiIdx]['remaining_segments_count']);
		}
		
		public function testCompileRouteMultiWildcardAtEndHasZeroRemainingSegments(): void {
			$segments = $this->compiler->compileRoute('/files/**');
			$this->assertSame(0, $segments[1]['remaining_segments_count']);
		}
		
		public function testCompileRouteStaticSegmentsHaveZeroRemainingSegmentsCount(): void {
			$segments = $this->compiler->compileRoute('/users/profile');
			foreach ($segments as $segment) {
				$this->assertSame(0, $segment['remaining_segments_count']);
			}
		}
		
		// -------------------------------------------------------------------------
		// compileRoute — partial variables
		// -------------------------------------------------------------------------
		
		public function testCompileRoutePartialVariableHasCorrectType(): void {
			$segments = $this->compiler->compileRoute('/v{version}');
			$this->assertSame(SegmentTypes::PARTIAL_VARIABLE, $segments[0]['type']);
		}
		
		public function testCompileRoutePartialVariableHasCompiledRegex(): void {
			$segments = $this->compiler->compileRoute('/v{version}');
			$this->assertNotNull($segments[0]['compiled_regex']);
		}
		
		public function testCompileRoutePartialVariableCompiledRegexIsValidRegex(): void {
			$segments = $this->compiler->compileRoute('/v{version}');
			$regex    = $segments[0]['compiled_regex'];
			// A valid regex does not return false from preg_match
			$this->assertNotFalse(@preg_match($regex, 'v1'));
		}
		
		public function testCompileRoutePartialVariableRegexMatchesExpectedInput(): void {
			$segments = $this->compiler->compileRoute('/v{version}');
			$regex    = $segments[0]['compiled_regex'];
			$this->assertMatchesRegularExpression($regex, 'v1');
			$this->assertMatchesRegularExpression($regex, 'v42');
		}
		
		public function testCompileRoutePartialVariableRegexDoesNotMatchWithoutPrefix(): void {
			$segments = $this->compiler->compileRoute('/v{version}');
			$regex    = $segments[0]['compiled_regex'];
			// "1" alone must not match the "v{version}" pattern
			$this->assertDoesNotMatchRegularExpression($regex, '1');
		}
		
		public function testCompileRoutePartialVariableHasLiteralPrefix(): void {
			$segments = $this->compiler->compileRoute('/v{version}');
			$this->assertSame('v', $segments[0]['literal_prefix']);
		}
		
		public function testCompileRoutePartialVariableHasVariableNames(): void {
			$segments = $this->compiler->compileRoute('/{name}.json');
			$this->assertContains('name', $segments[0]['variable_names']);
		}
		
		// -------------------------------------------------------------------------
		// compilePartialSegmentPattern
		// -------------------------------------------------------------------------
		
		public function testCompilePartialSegmentPatternReturnsNullForMalformedSegment(): void {
			// Unclosed brace — should return null
			$this->assertNull($this->compiler->compilePartialSegmentPattern('v{unclosed'));
		}
		
		public function testCompilePartialSegmentPatternReturnsArrayForValidSegment(): void {
			$result = $this->compiler->compilePartialSegmentPattern('v{version}');
			$this->assertIsArray($result);
		}
		
		public function testCompilePartialSegmentPatternContainsExpectedKeys(): void {
			$result = $this->compiler->compilePartialSegmentPattern('v{version}');
			$this->assertArrayHasKey('pattern', $result);
			$this->assertArrayHasKey('variables', $result);
			$this->assertArrayHasKey('literal_prefix', $result);
			$this->assertArrayHasKey('literal_suffix', $result);
		}
		
		public function testCompilePartialSegmentPatternExtractsLiteralPrefix(): void {
			$result = $this->compiler->compilePartialSegmentPattern('v{version}');
			$this->assertSame('v', $result['literal_prefix']);
		}
		
		public function testCompilePartialSegmentPatternExtractsLiteralSuffix(): void {
			$result = $this->compiler->compilePartialSegmentPattern('{name}.json');
			$this->assertSame('.json', $result['literal_suffix']);
		}
		
		public function testCompilePartialSegmentPatternExtractsVariableName(): void {
			$result = $this->compiler->compilePartialSegmentPattern('v{version}');
			$this->assertContains('version', $result['variables']);
		}
		
		public function testCompilePartialSegmentPatternGeneratesValidRegex(): void {
			$result = $this->compiler->compilePartialSegmentPattern('{name}.json');
			$this->assertNotFalse(@preg_match($result['pattern'], 'report.json'));
		}
		
		public function testCompilePartialSegmentPatternRegexMatchesExpectedValue(): void {
			$result = $this->compiler->compilePartialSegmentPattern('{name}.json');
			$this->assertMatchesRegularExpression($result['pattern'], 'report.json');
		}
		
		public function testCompilePartialSegmentPatternRegexRejectsNonMatchingValue(): void {
			$result = $this->compiler->compilePartialSegmentPattern('{name}.json');
			$this->assertDoesNotMatchRegularExpression($result['pattern'], 'report.xml');
		}
	}