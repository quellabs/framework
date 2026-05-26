<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Serialization\Normalizer\JsonNormalizer;
	
	/**
	 * Unit tests for JsonNormalizer.
	 *
	 * Covers the full normalize/denormalize contract: null handling, round-trips,
	 * non-string inputs, malformed JSON, and scalar JSON values.
	 */
	class JsonNormalizerTest extends TestCase {
		
		private JsonNormalizer $normalizer;
		
		protected function setUp(): void {
			// Parameters are unused by JsonNormalizer; pass an empty array.
			$this->normalizer = new JsonNormalizer([]);
		}
		
		// -------------------------------------------------------------------------
		// normalize() — database string → PHP value
		// -------------------------------------------------------------------------
		
		/**
		 * @test
		 */
		public function normalizeReturnsNullForNullInput(): void {
			$this->assertNull($this->normalizer->normalize(null));
		}
		
		/**
		 * @test
		 */
		public function normalizeDecodesJsonObjectToAssocArray(): void {
			$json = '{"id": 10, "name": "alice"}';
			$result = $this->normalizer->normalize($json);
			
			$this->assertSame(['id' => 10, 'name' => 'alice'], $result);
		}
		
		/**
		 * @test
		 */
		public function normalizeDecodesJsonArrayToIndexedArray(): void {
			$json = '[1, 2, 3]';
			$result = $this->normalizer->normalize($json);
			
			$this->assertSame([1, 2, 3], $result);
		}
		
		/**
		 * @test
		 */
		public function normalizeDecodesNestedJsonObject(): void {
			$json = '{"user": {"id": 1, "tags": ["a", "b"]}}';
			$result = $this->normalizer->normalize($json);
			
			$this->assertSame(['user' => ['id' => 1, 'tags' => ['a', 'b']]], $result);
		}
		
		/**
		 * @test
		 */
		public function normalizeDecodesJsonScalarString(): void {
			// A bare JSON string is valid JSON.
			$result = $this->normalizer->normalize('"hello"');
			$this->assertSame('hello', $result);
		}
		
		/**
		 * @test
		 */
		public function normalizeDecodesJsonScalarInteger(): void {
			$result = $this->normalizer->normalize('42');
			$this->assertSame(42, $result);
		}
		
		/**
		 * @test
		 */
		public function normalizeDecodesJsonScalarBoolean(): void {
			$this->assertTrue($this->normalizer->normalize('true'));
			$this->assertFalse($this->normalizer->normalize('false'));
		}
		
		/**
		 * @test
		 */
		public function normalizePassesThroughNonStringValueUnchanged(): void {
			// The database always delivers strings, but if something else arrives
			// (e.g. already-decoded value in a pipeline) it must pass through safely.
			$array = ['already' => 'decoded'];
			$this->assertSame($array, $this->normalizer->normalize($array));
			$this->assertSame(123, $this->normalizer->normalize(123));
			$this->assertSame(true, $this->normalizer->normalize(true));
		}
		
		/**
		 * @test
		 */
		public function normalizeMalformedJsonReturnsNull(): void {
			// json_decode returns null for invalid JSON strings.
			$result = $this->normalizer->normalize('{not valid json}');
			$this->assertNull($result);
		}
		
		// -------------------------------------------------------------------------
		// denormalize() — PHP value → database string
		// -------------------------------------------------------------------------
		
		/**
		 * @test
		 */
		public function denormalizeReturnsNullForNullInput(): void {
			$this->assertNull($this->normalizer->denormalize(null));
		}
		
		/**
		 * @test
		 */
		public function denormalizeEncodesAssocArrayToJsonObject(): void {
			$result = $this->normalizer->denormalize(['id' => 10, 'name' => 'alice']);
			$this->assertSame('{"id":10,"name":"alice"}', $result);
		}
		
		/**
		 * @test
		 */
		public function denormalizeEncodesIndexedArrayToJsonArray(): void {
			$result = $this->normalizer->denormalize([1, 2, 3]);
			$this->assertSame('[1,2,3]', $result);
		}
		
		/**
		 * @test
		 */
		public function denormalizeEncodesNestedStructure(): void {
			$value = ['user' => ['id' => 1, 'tags' => ['a', 'b']]];
			$result = $this->normalizer->denormalize($value);
			$this->assertSame('{"user":{"id":1,"tags":["a","b"]}}', $result);
		}
		
		/**
		 * @test
		 */
		public function denormalizeEncodesScalarString(): void {
			$result = $this->normalizer->denormalize('hello');
			$this->assertSame('"hello"', $result);
		}
		
		/**
		 * @test
		 */
		public function denormalizeEncodesScalarInteger(): void {
			$result = $this->normalizer->denormalize(42);
			$this->assertSame('42', $result);
		}
		
		/**
		 * @test
		 */
		public function denormalizeEncodesBoolean(): void {
			$this->assertSame('true', $this->normalizer->denormalize(true));
			$this->assertSame('false', $this->normalizer->denormalize(false));
		}
		
		/**
		 * @test
		 */
		public function denormalizeReturnsNullForUnserializableValue(): void {
			// Resources cannot be JSON-encoded; JSON_THROW_ON_ERROR causes an
			// exception which the normalizer catches and converts to null.
			$resource = fopen('php://memory', 'r');
			$result = $this->normalizer->denormalize($resource);
			fclose($resource);
			
			$this->assertNull($result);
		}
		
		// -------------------------------------------------------------------------
		// Round-trip
		// -------------------------------------------------------------------------
		
		/**
		 * @test
		 */
		public function roundTripPreservesJsonObject(): void {
			$original = ['id' => 1, 'meta' => ['active' => true, 'score' => 9.5]];
			$encoded = $this->normalizer->denormalize($original);
			$decoded = $this->normalizer->normalize($encoded);
			
			$this->assertSame($original, $decoded);
		}
		
		/**
		 * @test
		 */
		public function roundTripPreservesJsonArray(): void {
			$original = ['alpha', 'beta', 'gamma'];
			$encoded = $this->normalizer->denormalize($original);
			$decoded = $this->normalizer->normalize($encoded);
			
			$this->assertSame($original, $decoded);
		}
	}