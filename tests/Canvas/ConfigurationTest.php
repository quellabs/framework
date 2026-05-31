<?php
	
	namespace Quellabs\Canvas\Tests\Configuration;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Configuration\Configuration;
	
	/**
	 * Unit tests for Configuration.
	 *
	 * Covers: get/has/set/keys/all, getAs type-casting (all branches),
	 * merge, and iteration.
	 */
	class ConfigurationTest extends TestCase {
		
		// -------------------------------------------------------------------------
		// has / get / set / keys / all
		// -------------------------------------------------------------------------
		
		public function testHasReturnsTrueForExistingKey(): void {
			$config = new Configuration(['debug' => true]);
			$this->assertTrue($config->has('debug'));
		}
		
		public function testHasReturnsFalseForMissingKey(): void {
			$config = new Configuration();
			$this->assertFalse($config->has('missing'));
		}
		
		public function testGetReturnsValueForExistingKey(): void {
			$config = new Configuration(['name' => 'canvas']);
			$this->assertSame('canvas', $config->get('name'));
		}
		
		public function testGetReturnsDefaultForMissingKey(): void {
			$config = new Configuration();
			$this->assertSame('fallback', $config->get('missing', 'fallback'));
		}
		
		public function testGetReturnsNullDefaultWhenNotSpecified(): void {
			$config = new Configuration();
			$this->assertNull($config->get('missing'));
		}
		
		public function testSetStoresValue(): void {
			$config = new Configuration();
			$config->set('foo', 'bar');
			$this->assertSame('bar', $config->get('foo'));
		}
		
		public function testSetOverwritesExistingValue(): void {
			$config = new Configuration(['x' => 1]);
			$config->set('x', 99);
			$this->assertSame(99, $config->get('x'));
		}
		
		public function testKeysReturnsAllKeys(): void {
			$config = new Configuration(['a' => 1, 'b' => 2]);
			$this->assertSame(['a', 'b'], $config->keys());
		}
		
		public function testAllReturnsFullArray(): void {
			$data   = ['host' => 'localhost', 'port' => 3306];
			$config = new Configuration($data);
			$this->assertSame($data, $config->all());
		}
		
		public function testEmptyConfigurationHasNoKeys(): void {
			$config = new Configuration();
			$this->assertSame([], $config->keys());
		}
		
		// -------------------------------------------------------------------------
		// getIterator
		// -------------------------------------------------------------------------
		
		public function testConfigurationIsIterable(): void {
			$config   = new Configuration(['a' => 1, 'b' => 2]);
			$collected = [];
			
			foreach ($config as $key => $value) {
				$collected[$key] = $value;
			}
			
			$this->assertSame(['a' => 1, 'b' => 2], $collected);
		}
		
		// -------------------------------------------------------------------------
		// getAs — string
		// -------------------------------------------------------------------------
		
		public function testGetAsStringCastsInteger(): void {
			$config = new Configuration(['port' => 3306]);
			$this->assertSame('3306', $config->getAs('port', 'string'));
		}
		
		public function testGetAsStringCastsBool(): void {
			$config = new Configuration(['flag' => true]);
			$this->assertSame('1', $config->getAs('flag', 'string'));
		}
		
		public function testGetAsStringReturnsDefaultForNonScalar(): void {
			$config = new Configuration(['data' => ['a', 'b']]);
			$this->assertSame('default', $config->getAs('data', 'string', 'default'));
		}
		
		public function testGetAsStringReturnsDefaultForMissingKey(): void {
			$config = new Configuration();
			$this->assertNull($config->getAs('missing', 'string'));
		}
		
		// -------------------------------------------------------------------------
		// getAs — int / integer
		// -------------------------------------------------------------------------
		
		public function testGetAsIntCastsStringNumber(): void {
			$config = new Configuration(['port' => '3306']);
			$this->assertSame(3306, $config->getAs('port', 'int'));
		}
		
		public function testGetAsIntegerAliasWorks(): void {
			$config = new Configuration(['port' => '80']);
			$this->assertSame(80, $config->getAs('port', 'integer'));
		}
		
		public function testGetAsIntReturnsDefaultForNonScalar(): void {
			$config = new Configuration(['data' => ['a']]);
			$this->assertSame(0, $config->getAs('data', 'int', 0));
		}
		
		// -------------------------------------------------------------------------
		// getAs — float / double
		// -------------------------------------------------------------------------
		
		public function testGetAsFloatCastsStringValue(): void {
			$config = new Configuration(['ratio' => '3.14']);
			$this->assertEqualsWithDelta(3.14, $config->getAs('ratio', 'float'), 0.001);
		}
		
		public function testGetAsDoubleAliasWorks(): void {
			$config = new Configuration(['ratio' => '2.71']);
			$this->assertEqualsWithDelta(2.71, $config->getAs('ratio', 'double'), 0.001);
		}
		
		// -------------------------------------------------------------------------
		// getAs — bool / boolean (castToBoolean branches)
		// -------------------------------------------------------------------------
		
		public function testGetAsBoolTrueForStringTrue(): void {
			$config = new Configuration(['debug' => 'true']);
			$this->assertTrue($config->getAs('debug', 'bool'));
		}
		
		public function testGetAsBoolTrueForStringOne(): void {
			$config = new Configuration(['debug' => '1']);
			$this->assertTrue($config->getAs('debug', 'bool'));
		}
		
		public function testGetAsBoolTrueForStringYes(): void {
			$config = new Configuration(['debug' => 'yes']);
			$this->assertTrue($config->getAs('debug', 'bool'));
		}
		
		public function testGetAsBoolTrueForStringOn(): void {
			$config = new Configuration(['debug' => 'on']);
			$this->assertTrue($config->getAs('debug', 'bool'));
		}
		
		public function testGetAsBoolFalseForStringFalse(): void {
			$config = new Configuration(['debug' => 'false']);
			$this->assertFalse($config->getAs('debug', 'bool'));
		}
		
		public function testGetAsBoolFalseForStringZero(): void {
			$config = new Configuration(['debug' => '0']);
			$this->assertFalse($config->getAs('debug', 'bool'));
		}
		
		public function testGetAsBoolTrueForNativeBoolTrue(): void {
			$config = new Configuration(['flag' => true]);
			$this->assertTrue($config->getAs('flag', 'boolean'));
		}
		
		public function testGetAsBoolFalseForNativeBoolFalse(): void {
			$config = new Configuration(['flag' => false]);
			$this->assertFalse($config->getAs('flag', 'boolean'));
		}
		
		public function testGetAsBoolIsCaseInsensitive(): void {
			$config = new Configuration(['debug' => 'TRUE']);
			$this->assertTrue($config->getAs('debug', 'bool'));
		}
		
		// -------------------------------------------------------------------------
		// getAs — array (castToArray branches)
		// -------------------------------------------------------------------------
		
		public function testGetAsArraySplitsCommaSeparatedString(): void {
			$config = new Configuration(['tags' => 'php,canvas,testing']);
			$this->assertSame(['php', 'canvas', 'testing'], $config->getAs('tags', 'array'));
		}
		
		public function testGetAsArrayTrimsSurroundingWhitespace(): void {
			$config = new Configuration(['tags' => ' php , canvas , testing ']);
			$this->assertSame(['php', 'canvas', 'testing'], $config->getAs('tags', 'array'));
		}
		
		public function testGetAsArrayReturnsArrayAsIs(): void {
			$config = new Configuration(['items' => ['a', 'b', 'c']]);
			$this->assertSame(['a', 'b', 'c'], $config->getAs('items', 'array'));
		}
		
		public function testGetAsArrayWrapsScalarInArray(): void {
			$config = new Configuration(['count' => 5]);
			$this->assertSame([5], $config->getAs('count', 'array'));
		}
		
		// -------------------------------------------------------------------------
		// getAs — unknown type falls back to raw value
		// -------------------------------------------------------------------------
		
		public function testGetAsUnknownTypeReturnsRawValue(): void {
			$config = new Configuration(['x' => 42]);
			$this->assertSame(42, $config->getAs('x', 'unknowntype'));
		}
		
		// -------------------------------------------------------------------------
		// getAs — null key returns default
		// -------------------------------------------------------------------------
		
		public function testGetAsReturnDefaultWhenKeyMissingAndValueIsNull(): void {
			$config = new Configuration();
			$this->assertSame('default', $config->getAs('missing', 'string', 'default'));
		}
		
		// -------------------------------------------------------------------------
		// merge
		// -------------------------------------------------------------------------
		
		public function testMergeAddsNewKeys(): void {
			$base  = new Configuration(['a' => 1]);
			$extra = new Configuration(['b' => 2]);
			$base->merge($extra);
			$this->assertSame(1, $base->get('a'));
			$this->assertSame(2, $base->get('b'));
		}
		
		public function testMergeOverwritesExistingKeys(): void {
			$base  = new Configuration(['a' => 'old']);
			$extra = new Configuration(['a' => 'new']);
			$base->merge($extra);
			$this->assertSame('new', $base->get('a'));
		}
		
		public function testMergeReturnsSelf(): void {
			$config = new Configuration(['a' => 1]);
			$result = $config->merge(new Configuration(['b' => 2]));
			$this->assertSame($config, $result);
		}
	}