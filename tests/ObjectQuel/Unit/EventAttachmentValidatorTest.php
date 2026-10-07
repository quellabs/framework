<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Execution\Helpers\EventAttachmentValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstEventAttachment;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;

	/**
	 * Attachment validation against a called routine's live metadata (objectquel-equel-
	 * triggers-design.md). The routine catalog is mocked; only EventAttachmentValidator's own
	 * logic is under test here, not RoutineDefinitionInspector's SQL (see that class's own tests).
	 */
	class EventAttachmentValidatorTest extends TestCase {

		/**
		 * Parses one `after ... call ...(...)` statement.
		 * @param string $source Statement source
		 * @return AstEventAttachment
		 */
		private function parse(string $source): AstEventAttachment {
			$entityStore = $GLOBALS['test_em']->getEntityStore();
			$attachment = (new Parser(new Lexer($source), $entityStore))->parse();
			self::assertInstanceOf(AstEventAttachment::class, $attachment);
			return $attachment;
		}

		/**
		 * @param array<string, array<string, mixed>> $metadataByRoutine Decoded metadata, by routine name
		 * @return DatabaseAdapter&\PHPUnit\Framework\MockObject\MockObject
		 */
		private function adapterReturning(array $metadataByRoutine): DatabaseAdapter {
			$adapter = $this->createMock(DatabaseAdapter::class);
			$adapter->method('getRoutineMetadata')->willReturnCallback(
				function (string $name) use ($metadataByRoutine) {
					if (!isset($metadataByRoutine[$name])) {
						throw new QuelException("Can't attach to '{$name}': no routine by that name exists.", 'routine_call_error');
					}

					return $metadataByRoutine[$name];
				}
			);

			return $adapter;
		}

		/**
		 * @param string $entityClass Fully qualified entity class
		 * @param list<array{kind: string, type: string}> $parameters
		 * @param string[] $writes
		 * @param string[] $calls
		 * @return array<string, mixed>
		 */
		private static function triggerMetadata(array $parameters, array $writes = [], array $calls = []): array {
			return [
				'objectQuel'  => 1,
				'returnType'  => 'trigger',
				'atomic'      => false,
				'parameters'  => $parameters,
				'safety'      => ['calls' => $calls, 'reads' => [], 'writes' => $writes, 'features' => []],
			];
		}

		/**
		 * A valid attachment whose called routine's row parameter matches the target entity and
		 * writes an unrelated table passes without error.
		 * @return void
		 */
		public function testValidAttachmentPasses(): void {
			$attachment = $this->parse('
				range of u is UserEntity
				after replace u call audit_user(old, new)
			');
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$adapter = $this->adapterReturning([
				'audit_user' => self::triggerMetadata([
					['kind' => 'entity', 'type' => $entityClass],
					['kind' => 'entity', 'type' => $entityClass],
				], writes: ['posts']),
			]);

			(new EventAttachmentValidator($adapter, $GLOBALS['test_em']->getEntityStore()))->validate($attachment);
			$this->addToAssertionCount(1);
		}

		/**
		 * @return void
		 */
		public function testRejectsNonTriggerRoutine(): void {
			$attachment = $this->parse('
				range of u is UserEntity
				after replace u call helper(old, new)
			');
			$adapter = $this->adapterReturning([
				'helper' => ['objectQuel' => 1, 'returnType' => 'void', 'atomic' => false, 'parameters' => [], 'safety' => ['calls' => [], 'reads' => [], 'writes' => [], 'features' => []]],
			]);

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("'helper': it isn't declared 'trigger'");
			(new EventAttachmentValidator($adapter, $GLOBALS['test_em']->getEntityStore()))->validate($attachment);
		}

		/**
		 * @return void
		 */
		public function testRejectsArgumentCountMismatch(): void {
			$attachment = $this->parse('
				range of u is UserEntity
				after replace u call audit_user(old, new)
			');
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$adapter = $this->adapterReturning([
				'audit_user' => self::triggerMetadata([['kind' => 'entity', 'type' => $entityClass]]),
			]);

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('takes 1 parameter(s), but the attachment passes 2');
			(new EventAttachmentValidator($adapter, $GLOBALS['test_em']->getEntityStore()))->validate($attachment);
		}

		/**
		 * A whole-row argument against a scalar parameter is rejected.
		 * @return void
		 */
		public function testRejectsWholeRowAgainstScalarParameter(): void {
			$attachment = $this->parse('
				range of u is UserEntity
				after append to u call on_created(new)
			');
			$adapter = $this->adapterReturning([
				'on_created' => self::triggerMetadata([['kind' => 'scalar', 'type' => 'integer']]),
			]);

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("parameter 1 isn't a row parameter");
			(new EventAttachmentValidator($adapter, $GLOBALS['test_em']->getEntityStore()))->validate($attachment);
		}

		/**
		 * A field argument against an entity-row parameter is rejected.
		 * @return void
		 */
		public function testRejectsFieldArgumentAgainstRowParameter(): void {
			$attachment = $this->parse('
				range of u is UserEntity
				after append to u call on_created(new.username)
			');
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$adapter = $this->adapterReturning([
				'on_created' => self::triggerMetadata([['kind' => 'entity', 'type' => $entityClass]]),
			]);

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('parameter 1 is a row parameter, but the attachment doesn\'t pass the whole row');
			(new EventAttachmentValidator($adapter, $GLOBALS['test_em']->getEntityStore()))->validate($attachment);
		}

		/**
		 * A row parameter typed for a different entity than the attachment's target is rejected.
		 * @return void
		 */
		public function testRejectsMismatchedRowEntity(): void {
			$attachment = $this->parse('
				range of u is UserEntity
				after append to u call on_created(new)
			');
			$adapter = $this->adapterReturning([
				'on_created' => self::triggerMetadata([['kind' => 'entity', 'type' => 'App\\Entities\\PostEntity']]),
			]);

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("is typed 'App\\Entities\\PostEntity'");
			(new EventAttachmentValidator($adapter, $GLOBALS['test_em']->getEntityStore()))->validate($attachment);
		}

		/**
		 * A direct write to the triggering table is rejected on all three engines (no native
		 * mutating-table exemption honored at this layer).
		 * @return void
		 */
		public function testRejectsDirectWriteToTriggeringTable(): void {
			$attachment = $this->parse('
				range of u is UserEntity
				after replace u call audit_user(old, new)
			');
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$adapter = $this->adapterReturning([
				'audit_user' => self::triggerMetadata([
					['kind' => 'entity', 'type' => $entityClass],
					['kind' => 'entity', 'type' => $entityClass],
				], writes: ['users']),
			]);

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("writes 'users', the table the attachment fires on");
			(new EventAttachmentValidator($adapter, $GLOBALS['test_em']->getEntityStore()))->validate($attachment);
		}

		/**
		 * A transitive write (through a called helper routine) is rejected the same way.
		 * @return void
		 */
		public function testRejectsTransitiveWriteToTriggeringTable(): void {
			$attachment = $this->parse('
				range of u is UserEntity
				after replace u call audit_user(old, new)
			');
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$adapter = $this->adapterReturning([
				'audit_user' => self::triggerMetadata([
					['kind' => 'entity', 'type' => $entityClass],
					['kind' => 'entity', 'type' => $entityClass],
				], writes: ['posts'], calls: ['helper']),
				'helper' => ['objectQuel' => 1, 'returnType' => 'void', 'atomic' => false, 'parameters' => [], 'safety' => ['calls' => [], 'reads' => [], 'writes' => ['users'], 'features' => []]],
			]);

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("'helper' writes 'users'");
			(new EventAttachmentValidator($adapter, $GLOBALS['test_em']->getEntityStore()))->validate($attachment);
		}

		/**
		 * A call cycle (helper calls back into audit_user) does not infinite-loop.
		 * @return void
		 */
		public function testCallGraphCycleTerminates(): void {
			$attachment = $this->parse('
				range of u is UserEntity
				after replace u call audit_user(old, new)
			');
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$adapter = $this->adapterReturning([
				'audit_user' => self::triggerMetadata([
					['kind' => 'entity', 'type' => $entityClass],
					['kind' => 'entity', 'type' => $entityClass],
				], writes: ['posts'], calls: ['helper']),
				'helper' => ['objectQuel' => 1, 'returnType' => 'void', 'atomic' => false, 'parameters' => [], 'safety' => ['calls' => ['audit_user'], 'reads' => [], 'writes' => [], 'features' => []]],
			]);

			(new EventAttachmentValidator($adapter, $GLOBALS['test_em']->getEntityStore()))->validate($attachment);
			$this->addToAssertionCount(1);
		}

		/**
		 * A feature flag anywhere in the call graph (even a routine not authored by EQUEL,
		 * whose metadata was crafted to claim one) is rejected.
		 * @return void
		 */
		public function testRejectsUnsafeFeatureInCallGraph(): void {
			$attachment = $this->parse('
				range of u is UserEntity
				after replace u call audit_user(old, new)
			');
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$adapter = $this->adapterReturning([
				'audit_user' => [
					'objectQuel' => 1,
					'returnType' => 'trigger',
					'atomic'     => false,
					'parameters' => [
						['kind' => 'entity', 'type' => $entityClass],
						['kind' => 'entity', 'type' => $entityClass],
					],
					'safety'     => ['calls' => [], 'reads' => [], 'writes' => [], 'features' => ['dynamic-sql']],
				],
			]);

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("'dynamic-sql'");
			(new EventAttachmentValidator($adapter, $GLOBALS['test_em']->getEntityStore()))->validate($attachment);
		}
	}
