<?php
	
	namespace Quellabs\Support\Tests;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\Support\UseStatementParser;
	use Quellabs\Support\Tests\Fixtures\AliasedImportsFixture;
	use Quellabs\Support\Tests\Fixtures\CommaSeparatedImportsFixture;
	use Quellabs\Support\Tests\Fixtures\FunctionAndConstImportsFixture;
	use Quellabs\Support\Tests\Fixtures\GroupedAliasedImportsFixture;
	use Quellabs\Support\Tests\Fixtures\GroupedImportsFixture;
	use Quellabs\Support\Tests\Fixtures\GroupedMixedKindImportsFixture;
	use Quellabs\Support\Tests\Fixtures\NoImportsFixture;
	use Quellabs\Support\Tests\Fixtures\SimpleImportsFixture;
	use Quellabs\Support\Tests\Fixtures\TraitUseInsideBodyFixture;
	
	/**
	 * Unit tests for UseStatementParser.
	 *
	 * Each test group exercises one parsing scenario by pointing the parser at a
	 * dedicated fixture class. Fixture files live in fixtures/ alongside this file.
	 * The parser reads the fixture's source file through ReflectionClass::getFileName(),
	 * so the fixtures must be real files — they cannot be eval'd or anonymous classes.
	 */
	class UseStatementParserTest extends TestCase {
		
		// =========================================================================
		// Internal / built-in classes
		// =========================================================================
		
		public function testInternalClassReturnsEmptyArray(): void {
			// Built-in classes have no source file; the parser must return [] cleanly
			// rather than throwing or attempting to open a non-existent path.
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(\stdClass::class));
			$this->assertSame([], $result);
		}
		
		// =========================================================================
		// No imports
		// =========================================================================
		
		public function testClassWithNoImportsReturnsEmptyArray(): void {
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(NoImportsFixture::class));
			$this->assertSame([], $result);
		}
		
		// =========================================================================
		// Simple (plain) imports
		// =========================================================================
		
		public function testSimpleImportProducesShortNameKey(): void {
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(SimpleImportsFixture::class));
			$this->assertArrayHasKey('stdClass', $result);
		}
		
		public function testSimpleImportProducesFullyQualifiedValue(): void {
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(SimpleImportsFixture::class));
			$this->assertSame('stdClass', $result['stdClass']);
		}
		
		public function testSimpleImportAllEntriesPresent(): void {
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(SimpleImportsFixture::class));
			$this->assertArrayHasKey('ArrayObject', $result);
			$this->assertArrayHasKey('RuntimeException', $result);
			$this->assertArrayHasKey('stdClass', $result);
		}
		
		// =========================================================================
		// Aliased imports
		// =========================================================================
		
		public function testAliasedImportUsesAliasAsKey(): void {
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(AliasedImportsFixture::class));
			$this->assertArrayHasKey('AO', $result);
			$this->assertArrayNotHasKey('ArrayObject', $result);
		}
		
		public function testAliasedImportPreservesFullyQualifiedValue(): void {
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(AliasedImportsFixture::class));
			$this->assertSame('ArrayObject', $result['AO']);
		}
		
		public function testAliasedImportCaseInsensitiveAsKeyword(): void {
			// AliasedImportsFixture uses uppercase AS for RuntimeException
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(AliasedImportsFixture::class));
			$this->assertArrayHasKey('RE', $result);
			$this->assertSame('RuntimeException', $result['RE']);
		}
		
		public function testAliasedImportAllEntriesPresent(): void {
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(AliasedImportsFixture::class));
			$this->assertArrayHasKey('AO', $result);
			$this->assertArrayHasKey('RE', $result);
			$this->assertArrayHasKey('Std', $result);
		}
		
		// =========================================================================
		// Comma-separated imports (multiple imports in one statement)
		// =========================================================================
		
		public function testCommaSeparatedImportsAllPresent(): void {
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(CommaSeparatedImportsFixture::class));
			$this->assertArrayHasKey('ArrayObject', $result);
			$this->assertArrayHasKey('RuntimeException', $result);
		}
		
		public function testCommaSeparatedAliasedImportsAllPresent(): void {
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(CommaSeparatedImportsFixture::class));
			$this->assertArrayHasKey('Std', $result);
			$this->assertSame('stdClass', $result['Std']);
			$this->assertArrayHasKey('AA', $result);
			$this->assertSame('ArrayAccess', $result['AA']);
		}
		
		// =========================================================================
		// Grouped imports
		// =========================================================================
		
		public function testGroupedImportAllEntriesPresent(): void {
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(GroupedImportsFixture::class));
			$this->assertArrayHasKey('Alpha', $result);
			$this->assertArrayHasKey('Beta', $result);
			$this->assertArrayHasKey('Gamma', $result);
		}
		
		public function testGroupedImportFullyQualifiedValueIsCorrect(): void {
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(GroupedImportsFixture::class));
			$this->assertSame('Quellabs\Support\Tests\Fixtures\Sub\Alpha', $result['Alpha']);
			$this->assertSame('Quellabs\Support\Tests\Fixtures\Sub\Beta', $result['Beta']);
			$this->assertSame('Quellabs\Support\Tests\Fixtures\Sub\Gamma', $result['Gamma']);
		}
		
		// =========================================================================
		// Grouped imports with aliases
		// =========================================================================
		
		public function testGroupedAliasedImportUsesAliasAsKey(): void {
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(GroupedAliasedImportsFixture::class));
			$this->assertArrayHasKey('A', $result);
			$this->assertArrayNotHasKey('Alpha', $result);
			$this->assertArrayHasKey('B', $result);
			$this->assertArrayNotHasKey('Beta', $result);
		}
		
		public function testGroupedAliasedImportPreservesFullyQualifiedValue(): void {
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(GroupedAliasedImportsFixture::class));
			$this->assertSame('Quellabs\Support\Tests\Fixtures\Sub\Alpha', $result['A']);
			$this->assertSame('Quellabs\Support\Tests\Fixtures\Sub\Beta', $result['B']);
		}
		
		public function testGroupedNonAliasedEntryAlongsideAliasedEntries(): void {
			// Gamma has no alias; its short name should be used as the key
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(GroupedAliasedImportsFixture::class));
			$this->assertArrayHasKey('Gamma', $result);
			$this->assertSame('Quellabs\Support\Tests\Fixtures\Sub\Gamma', $result['Gamma']);
		}
		
		// =========================================================================
		// function / const imports are excluded
		// =========================================================================
		
		public function testFunctionImportIsExcluded(): void {
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(FunctionAndConstImportsFixture::class));
			$this->assertArrayNotHasKey('array_map', $result);
		}
		
		public function testConstImportIsExcluded(): void {
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(FunctionAndConstImportsFixture::class));
			$this->assertArrayNotHasKey('PHP_EOL', $result);
		}
		
		public function testClassImportAlongsideFunctionAndConstIsIncluded(): void {
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(FunctionAndConstImportsFixture::class));
			$this->assertArrayHasKey('stdClass', $result);
		}
		
		// =========================================================================
		// Grouped imports with mixed function/const/class entries
		// =========================================================================
		
		public function testGroupedMixedKindExcludesFunctionEntry(): void {
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(GroupedMixedKindImportsFixture::class));
			$this->assertArrayNotHasKey('helperA', $result);
		}
		
		public function testGroupedMixedKindExcludesConstEntry(): void {
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(GroupedMixedKindImportsFixture::class));
			$this->assertArrayNotHasKey('SOME_CONST', $result);
		}
		
		public function testGroupedMixedKindIncludesClassEntries(): void {
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(GroupedMixedKindImportsFixture::class));
			$this->assertArrayHasKey('Alpha', $result);
			$this->assertArrayHasKey('Beta', $result);
		}
		
		// =========================================================================
		// Trait use inside class body is excluded
		// =========================================================================
		
		public function testTraitUseInsideClassBodyIsExcluded(): void {
			// The fixture uses \Stringable as a trait inside the class body.
			// The parser must stop at the class declaration and never see that statement.
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(TraitUseInsideBodyFixture::class));
			$this->assertArrayNotHasKey('Stringable', $result);
		}
		
		public function testTraitUseInsideClassBodyDoesNotPreventFileImports(): void {
			// File-level imports above the class declaration must still be returned
			// even when a trait use exists inside the body.
			$result = UseStatementParser::getImportsForClass(new \ReflectionClass(TraitUseInsideBodyFixture::class));
			$this->assertArrayHasKey('stdClass', $result);
		}
		
		// =========================================================================
		// Caching
		// =========================================================================
		
		public function testResultIsCached(): void {
			// Call twice with the same ReflectionClass; both calls must return
			// identical results. This validates that the cache path is consistent
			// with the parse path — it does not test the cache mechanism directly
			// since $importsCache is private.
			$reflection = new \ReflectionClass(SimpleImportsFixture::class);
			$first  = UseStatementParser::getImportsForClass($reflection);
			$second = UseStatementParser::getImportsForClass($reflection);
			$this->assertSame($first, $second);
		}
	}