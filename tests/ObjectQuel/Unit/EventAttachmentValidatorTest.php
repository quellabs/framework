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
		 * Parses one `after ... call ...` statement.
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
				'safety'      => ['calls' => $calls, 'reads' => [], 'writes' => $writes],
			];
		}

		/**
		 * A valid attachment whose called routine's row parameters match the target entity, in
		 * the event's own row count, and write only an unrelated table passes without error.
		 * @return void
		 */
		public function testValidAttachmentPasses(): void {
			$attachment = $this->parse('
				range of u is UserEntity
				after replace u call audit_user
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
				after replace u call helper
			');
			$adapter = $this->adapterReturning([
				'helper' => ['objectQuel' => 1, 'returnType' => 'void', 'atomic' => false, 'parameters' => [], 'safety' => ['calls' => [], 'reads' => [], 'writes' => []]],
			]);

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("'helper': it isn't declared 'trigger'");
			(new EventAttachmentValidator($adapter, $GLOBALS['test_em']->getEntityStore()))->validate($attachment);
		}

		/**
		 * `replace` supplies two rows (old, new); a routine declaring only its first row
		 * parameter (old) binds by the leading-rows rule and passes, reporting 1 parameter back.
		 * @return void
		 */
		public function testPrefixBindingAllowsFewerParametersThanTheEventSupplies(): void {
			$attachment = $this->parse('
				range of u is UserEntity
				after replace u call audit_user
			');
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$adapter = $this->adapterReturning([
				'audit_user' => self::triggerMetadata([['kind' => 'entity', 'type' => $entityClass]]),
			]);

			$parameterCount = (new EventAttachmentValidator($adapter, $GLOBALS['test_em']->getEntityStore()))->validate($attachment);
			self::assertSame(1, $parameterCount);
		}

		/**
		 * `replace` supplies two rows (old, new); a routine declaring more entity-row parameters
		 * than that can't be attached, since there's no third row to bind.
		 * @return void
		 */
		public function testRejectsMoreParametersThanTheEventSupplies(): void {
			$attachment = $this->parse('
				range of u is UserEntity
				after replace u call audit_user
			');
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$adapter = $this->adapterReturning([
				'audit_user' => self::triggerMetadata([
					['kind' => 'entity', 'type' => $entityClass],
					['kind' => 'entity', 'type' => $entityClass],
					['kind' => 'entity', 'type' => $entityClass],
				]),
			]);

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("it declares 3 entity-row parameter(s), but 'replace' only supplies 2");
			(new EventAttachmentValidator($adapter, $GLOBALS['test_em']->getEntityStore()))->validate($attachment);
		}

		/**
		 * A non-entity parameter is rejected — unreachable through RoutineAnalyzer's own rule
		 * that a trigger routine's parameters must all be entity-row, but defended here too in
		 * case the deployed metadata was hand-edited or predates that rule.
		 * @return void
		 */
		public function testRejectsNonEntityParameter(): void {
			$attachment = $this->parse('
				range of u is UserEntity
				after append to u call on_created
			');
			$adapter = $this->adapterReturning([
				'on_created' => self::triggerMetadata([['kind' => 'scalar', 'type' => 'integer']]),
			]);

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("parameter 1 isn't an entity-row parameter");
			(new EventAttachmentValidator($adapter, $GLOBALS['test_em']->getEntityStore()))->validate($attachment);
		}

		/**
		 * A row parameter typed for a different entity than the attachment's target is rejected.
		 * @return void
		 */
		public function testRejectsMismatchedRowEntity(): void {
			$attachment = $this->parse('
				range of u is UserEntity
				after append to u call on_created
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
				after replace u call audit_user
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
				after replace u call audit_user
			');
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$adapter = $this->adapterReturning([
				'audit_user' => self::triggerMetadata([
					['kind' => 'entity', 'type' => $entityClass],
					['kind' => 'entity', 'type' => $entityClass],
				], writes: ['posts'], calls: ['helper']),
				'helper' => ['objectQuel' => 1, 'returnType' => 'void', 'atomic' => false, 'parameters' => [], 'safety' => ['calls' => [], 'reads' => [], 'writes' => ['users']]],
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
				after replace u call audit_user
			');
			$entityClass = $GLOBALS['test_em']->getEntityStore()->getMetadata('UserEntity')->className;
			$adapter = $this->adapterReturning([
				'audit_user' => self::triggerMetadata([
					['kind' => 'entity', 'type' => $entityClass],
					['kind' => 'entity', 'type' => $entityClass],
				], writes: ['posts'], calls: ['helper']),
				'helper' => ['objectQuel' => 1, 'returnType' => 'void', 'atomic' => false, 'parameters' => [], 'safety' => ['calls' => ['audit_user'], 'reads' => [], 'writes' => []]],
			]);

			(new EventAttachmentValidator($adapter, $GLOBALS['test_em']->getEntityStore()))->validate($attachment);
			$this->addToAssertionCount(1);
		}

	}
