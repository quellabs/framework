<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroy;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyEventAttachment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstEventAttachment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AttachmentEvent;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventAttachmentAnalyzer;

	/**
	 * `after ... call ...(...)` and `destroy event ...` (objectquel-equel-triggers-design.md).
	 */
	class EventAttachmentTest extends TestCase {

		/**
		 * Parses one statement.
		 * @param string $source Statement source
		 * @return AstEventAttachment|AstDestroyEventAttachment|AstDestroy|AstDestroyIndex
		 */
		private function parse(string $source): mixed {
			$entityStore = $GLOBALS['test_em']->getEntityStore();
			return (new Parser(new Lexer($source), $entityStore))->parse();
		}

		/**
		 * Parses and runs the database-independent attachment checks.
		 * @param string $source Statement source
		 * @return AstEventAttachment
		 */
		private function analyze(string $source): AstEventAttachment {
			$entityStore = $GLOBALS['test_em']->getEntityStore();
			$attachment = $this->parse($source);

			if (!$attachment instanceof AstEventAttachment) {
				throw new ParserException('Expected an event attachment statement.');
			}

			(new EventAttachmentAnalyzer($entityStore))->analyze($attachment);
			return $attachment;
		}

		/**
		 * @return void
		 */
		public function testAppendAttachmentBindsOnlyNew(): void {
			$attachment = $this->analyze('
				range of u is UserEntity
				after append to u call on_created(new)
			');

			self::assertSame(AttachmentEvent::Append, $attachment->getEvent());
			self::assertSame('u', $attachment->getRange()->getName());
			self::assertSame('on_created', $attachment->getCall()->getName());
			self::assertCount(1, $attachment->getCall()->getArguments());
			self::assertInstanceOf(AstIdentifier::class, $attachment->getCall()->getArguments()[0]);
			self::assertSame('new', $attachment->getCall()->getArguments()[0]->getName());
		}

		/**
		 * @return void
		 */
		public function testReplaceAttachmentBindsOldAndNew(): void {
			$attachment = $this->analyze('
				range of u is UserEntity
				after replace u call on_changed(old, new)
			');

			self::assertSame(AttachmentEvent::Replace, $attachment->getEvent());
			self::assertCount(2, $attachment->getCall()->getArguments());
		}

		/**
		 * @return void
		 */
		public function testDeleteAttachmentBindsOnlyOld(): void {
			$attachment = $this->analyze('
				range of u is UserEntity
				after delete u call on_removed(old)
			');

			self::assertSame(AttachmentEvent::Delete, $attachment->getEvent());
			self::assertCount(1, $attachment->getCall()->getArguments());
		}

		/**
		 * A single field of `old`/`new` is a legal scalar argument alongside the whole row.
		 * @return void
		 */
		public function testFieldOfOldOrNewIsAValidArgument(): void {
			$attachment = $this->analyze('
				range of u is UserEntity
				after replace u call audit_user(old.username, new.username, new)
			');

			self::assertCount(3, $attachment->getCall()->getArguments());
		}

		/**
		 * A trailing semicolon is accepted, same as other top-level statements.
		 * @return void
		 */
		public function testAcceptsTrailingSemicolon(): void {
			$this->analyze('
				range of u is UserEntity
				after replace u call on_changed(old, new);
			');
			$this->addToAssertionCount(1);
		}

		/**
		 * @return void
		 */
		public function testDestroyEventRemovesOneAttachment(): void {
			$statement = $this->parse('
				range of u is UserEntity
				destroy event after replace u call audit_user if exists
			');

			self::assertInstanceOf(AstDestroyEventAttachment::class, $statement);
			self::assertSame(AttachmentEvent::Replace, $statement->getEvent());
			self::assertSame('u', $statement->getRange()->getName());
			self::assertSame('audit_user', $statement->getRoutineName());
			self::assertTrue($statement->isIfExists());
		}

		/**
		 * @return void
		 */
		public function testDestroyEventWithoutIfExists(): void {
			$statement = $this->parse('
				range of u is UserEntity
				destroy event after append to u call on_created
			');

			self::assertInstanceOf(AstDestroyEventAttachment::class, $statement);
			self::assertSame(AttachmentEvent::Append, $statement->getEvent());
			self::assertFalse($statement->isIfExists());
		}

		/**
		 * `event` immediately followed by `after` is the attachment-removal form; otherwise a
		 * table/index literally named `event` keeps its ordinary meaning.
		 * @return void
		 */
		public function testEventKeywordDoesNotShadowATableNamedEvent(): void {
			self::assertInstanceOf(AstDestroy::class, $this->parse('destroy event'));
			self::assertInstanceOf(AstDestroy::class, $this->parse('destroy event if exists'));
			self::assertInstanceOf(AstDestroyIndex::class, $this->parse('destroy event on sometable'));
		}

		/**
		 * Attachment-specific and shared range-resolution rejections.
		 * @return array<string, array{string, string}>
		 */
		public static function rejectedAttachments(): array {
			$range = 'range of u is UserEntity ';

			return [
				'old unavailable for append'    => ["{$range}after append to u call f(old)", "'old' is not available for 'append to'"],
				'new unavailable for delete'    => ["{$range}after delete u call f(new)", "'new' is not available for 'delete'"],
				'undefined bare name'           => ["{$range}after replace u call f(x)", "Undefined name 'x'"],
				'unmapped field'                => ["{$range}after replace u call f(old.bogus)", "has no mapped column 'bogus'"],
				'relationship field'            => ["{$range}after replace u call f(old.posts)", "has no mapped column 'posts'"],
				'field has no further fields'   => ["{$range}after replace u call f(old.username.length)", 'a row field has no further fields'],
			];
		}

		/**
		 * The target range must already be declared ahead of `after`, same as replace/delete —
		 * a parse-time error (TargetRangeResolver), not one of the analyzer's own checks.
		 * @return void
		 */
		public function testRejectsUndeclaredTargetRange(): void {
			$this->expectException(ParserException::class);
			$this->expectExceptionMessage("Undefined range reference 'u'");
			$this->analyze('after replace u call f(old, new)');
		}

		/**
		 * @param string $source Statement source
		 * @param string $message Expected error message fragment
		 * @return void
		 */
		#[DataProvider('rejectedAttachments')]
		public function testRejects(string $source, string $message): void {
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage($message);
			$this->analyze($source);
		}

		/**
		 * The target must be a declared database entity range, same restriction `replace`/`delete` have.
		 * @return void
		 */
		public function testRejectsJsonSourceTarget(): void {
			$this->expectException(ParserException::class);
			$this->expectExceptionMessage("after target 'j' must be a database entity range");
			$this->analyze('range of j is json_source("data.json") after replace j call f(old, new)');
		}

		/**
		 * The parser allows only one statement per query, same as every other statement.
		 * @return void
		 */
		public function testOnlyOneStatementPerQuery(): void {
			$this->expectException(ParserException::class);
			$this->expectExceptionMessage('only one statement is allowed per query');
			$this->parse('
				range of u is UserEntity
				after replace u call on_changed(old, new)
				after replace u call on_changed(old, new)
			');
		}
	}
