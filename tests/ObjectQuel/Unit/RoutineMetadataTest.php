<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\RoutineAnalyzer;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\RoutineMetadata;

	/**
	 * JSON metadata built for a deployed routine (objectquel-equel-triggers-design.md,
	 * "Routine metadata and attachment dependencies"). Dialect-independent: built once from
	 * the analyzed AST, read back per dialect by RoutineDefinitionInspector.
	 */
	class RoutineMetadataTest extends TestCase {

		/**
		 * Parses, analyzes and builds metadata for a routine.
		 * @param string $source Routine source text
		 * @return array<string, mixed> Decoded metadata
		 */
		private function metadata(string $source): array {
			$entityStore = $GLOBALS['test_em']->getEntityStore();
			$routine = (new Parser(new Lexer($source), $entityStore))->parse();

			if (!$routine instanceof AstRoutineDefinition) {
				throw new ParserException("A routine source must contain exactly one 'define function'.");
			}

			(new RoutineAnalyzer($entityStore))->analyze($routine);
			$json = RoutineMetadata::build($routine, $entityStore);
			$decoded = json_decode($json, true);
			self::assertIsArray($decoded, 'Metadata must be valid JSON');

			return $decoded;
		}

		/**
		 * A plain scalar routine with no writes, calls or atomic block.
		 * @return void
		 */
		public function testScalarRoutine(): void {
			$metadata = $this->metadata('define function add (integer a, integer b) integer { return a + b }');

			self::assertSame(1, $metadata['objectQuel']);
			self::assertSame('integer', $metadata['returnType']);
			self::assertFalse($metadata['atomic']);
			self::assertSame([
				['kind' => 'scalar', 'type' => 'integer'],
				['kind' => 'scalar', 'type' => 'integer'],
			], $metadata['parameters']);
			self::assertSame(['calls' => [], 'reads' => [], 'writes' => []], $metadata['safety']);
		}

		/**
		 * `void` is reported as such, distinct from `trigger`.
		 * @return void
		 */
		public function testVoidReturnType(): void {
			$metadata = $this->metadata('
				range of u is UserEntity
				define function ban_all () void { replace u (banned = true) where u.id > 0 }
			');

			self::assertSame('void', $metadata['returnType']);
		}

		/**
		 * A `trigger`-returning routine reports its entity-row parameters by fully qualified class.
		 * @return void
		 */
		public function testTriggerRoutineParameters(): void {
			$metadata = $this->metadata('
				range of u is UserEntity
				define function audit_user (UserEntity old, UserEntity new) trigger {
					string oldName = old.username
					string newName = new.username
				}
			');

			self::assertSame('trigger', $metadata['returnType']);
			self::assertCount(2, $metadata['parameters']);
			self::assertSame('entity', $metadata['parameters'][0]['kind']);
			self::assertSame('entity', $metadata['parameters'][1]['kind']);
			self::assertStringEndsWith('UserEntity', $metadata['parameters'][0]['type']);
			self::assertStringEndsWith('UserEntity', $metadata['parameters'][1]['type']);
		}

		/**
		 * A scalar parameter alongside an entity-row parameter keeps its own 'scalar' kind.
		 * @return void
		 */
		public function testMixedScalarAndEntityRowParameters(): void {
			$metadata = $this->metadata('
				range of u is UserEntity
				define function f (integer attempt, UserEntity old) trigger { string n = old.username }
			');

			self::assertSame(['kind' => 'scalar', 'type' => 'integer'], $metadata['parameters'][0]);
			self::assertSame('entity', $metadata['parameters'][1]['kind']);
		}

		/**
		 * `atomic` is true only when the body contains an atomic block.
		 * @return void
		 */
		public function testAtomicFlag(): void {
			$withAtomic = $this->metadata('
				range of u is UserEntity
				define function f (integer n) void { atomic { replace u (banned = true) where u.id = n } }
			');
			$withoutAtomic = $this->metadata('
				range of u is UserEntity
				define function g (integer n) void { replace u (banned = true) where u.id = n }
			');

			self::assertTrue($withAtomic['atomic']);
			self::assertFalse($withoutAtomic['atomic']);
		}

		/**
		 * Writes list the distinct physical tables targeted by append/replace/delete; reads list
		 * every other declared range's table, not whatever a cursor happens to select from it.
		 * @return void
		 */
		public function testSafetyReadsAndWrites(): void {
			$metadata = $this->metadata('
				range of u is UserEntity
				range of p is PostEntity
				define function f () void {
					cursor c = retrieve (p.id) where p.id > 0
					foreach (c as row) {
						replace u (banned = true) where u.id = row.id
					}
				}
			');

			self::assertSame(['posts'], $metadata['safety']['reads']);
			self::assertSame(['users'], $metadata['safety']['writes']);
		}

		/**
		 * A table that is both read and written appears only in `writes`.
		 * @return void
		 */
		public function testWrittenTableIsNotAlsoListedAsRead(): void {
			$metadata = $this->metadata('
				range of u is UserEntity
				define function f () void {
					cursor c = retrieve (u.id) where u.banned = false
					foreach (c as row) {
						replace u (banned = true) where u.id = row.id
					}
				}
			');

			self::assertSame([], $metadata['safety']['reads']);
			self::assertSame(['users'], $metadata['safety']['writes']);
		}

		/**
		 * Distinct called routine names are recorded once each, as written.
		 * @return void
		 */
		public function testSafetyCalls(): void {
			$metadata = $this->metadata('define function f (integer n) void { helper(n) helper(n + 1) other(n) }');

			self::assertSame(['helper', 'other'], $metadata['safety']['calls']);
		}

	}
