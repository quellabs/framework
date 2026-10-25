<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroy;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyEventBinding;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstEventBinding;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\BindingEvent;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;

	/**
	 * `after ... call ... [as <alias>]` and `destroy trigger ...`
	 * (objectquel-equel-triggers-design.md). The binding carries no argument list: a
	 * called routine's entity-row parameters receive the event's rows by declaration order
	 * (BindingEvent::rowRoles()), checked against live routine metadata by
	 * EventBindingValidator (see EventBindingValidatorTest), not at parse time.
	 */
	class EventBindingTest extends TestCase {

		/**
		 * Parses one statement.
		 * @param string $source Statement source
		 * @return AstEventBinding|AstDestroyEventBinding|AstDestroy|AstDestroyIndex
		 */
		private function parse(string $source): mixed {
			$entityStore = $GLOBALS['test_em']->getEntityStore();
			return (new Parser(new Lexer($source), $entityStore))->parse();
		}

		/**
		 * @return void
		 */
		public function testAppendBindingParsesRoutineName(): void {
			$binding = $this->parse('
				range of u is UserEntity
				after append to u call on_created
			');

			self::assertInstanceOf(AstEventBinding::class, $binding);
			self::assertSame(BindingEvent::Append, $binding->getEvent());
			self::assertSame('u', $binding->getRange()->getName());
			self::assertSame('on_created', $binding->getRoutineName());
		}

		/**
		 * @return void
		 */
		public function testReplaceBindingParsesRoutineName(): void {
			$binding = $this->parse('
				range of u is UserEntity
				after replace u call on_changed
			');

			self::assertInstanceOf(AstEventBinding::class, $binding);
			self::assertSame(BindingEvent::Replace, $binding->getEvent());
			self::assertSame('on_changed', $binding->getRoutineName());
		}

		/**
		 * @return void
		 */
		public function testDeleteBindingParsesRoutineName(): void {
			$binding = $this->parse('
				range of u is UserEntity
				after delete u call on_removed
			');

			self::assertInstanceOf(AstEventBinding::class, $binding);
			self::assertSame(BindingEvent::Delete, $binding->getEvent());
			self::assertSame('on_removed', $binding->getRoutineName());
		}

		/**
		 * A parenthesized argument list is no longer valid syntax: the routine's own entity-row
		 * parameters receive the event's rows by declaration order, so there is nothing to pass.
		 * @return void
		 */
		public function testParenthesizedArgumentListIsRejected(): void {
			$this->expectException(ParserException::class);
			$this->expectExceptionMessage('Unexpected content after the statement');
			$this->parse('
				range of u is UserEntity
				after replace u call audit_user(old, new)
			');
		}

		/**
		 * A trailing semicolon is accepted, same as other top-level statements.
		 * @return void
		 */
		public function testAcceptsTrailingSemicolon(): void {
			$this->parse('
				range of u is UserEntity
				after replace u call on_changed;
			');
			$this->addToAssertionCount(1);
		}

		/**
		 * `as <alias>` names the binding, for later reference in `destroy trigger`.
		 * @return void
		 */
		public function testAsAliasIsParsed(): void {
			$binding = $this->parse('
				range of u is UserEntity
				after replace u call audit_user as audit_trigger
			');

			self::assertInstanceOf(AstEventBinding::class, $binding);
			self::assertSame('audit_trigger', $binding->getAlias());
		}

		/**
		 * Omitting `as <alias>` leaves the alias null; it is resolved (given or generated) at
		 * compile time, not parse time — see EventBindingCompiler::resolveAlias().
		 * @return void
		 */
		public function testAliasIsNullWhenOmitted(): void {
			$binding = $this->parse('
				range of u is UserEntity
				after replace u call audit_user
			');

			self::assertInstanceOf(AstEventBinding::class, $binding);
			self::assertNull($binding->getAlias());
		}

		/**
		 * A trailing semicolon is accepted after `as <alias>` too.
		 * @return void
		 */
		public function testAcceptsTrailingSemicolonAfterAlias(): void {
			$this->parse('
				range of u is UserEntity
				after replace u call on_changed as named;
			');
			$this->addToAssertionCount(1);
		}

		/**
		 * @return void
		 */
		public function testDestroyTriggerRemovesOneBinding(): void {
			$statement = $this->parse('
				range of u is UserEntity
				destroy trigger u audit_trigger if exists
			');

			self::assertInstanceOf(AstDestroyEventBinding::class, $statement);
			self::assertSame('u', $statement->getRange()->getName());
			self::assertSame('audit_trigger', $statement->getAlias());
			self::assertTrue($statement->isIfExists());
		}

		/**
		 * @return void
		 */
		public function testDestroyTriggerWithoutIfExists(): void {
			$statement = $this->parse('
				range of u is UserEntity
				destroy trigger u audit_trigger
			');

			self::assertInstanceOf(AstDestroyEventBinding::class, $statement);
			self::assertFalse($statement->isIfExists());
		}

		/**
		 * `trigger` immediately followed by its range name is the binding-removal form;
		 * otherwise a table/index literally named `trigger` keeps its ordinary meaning.
		 * @return void
		 */
		public function testTriggerKeywordDoesNotShadowATableNamedTrigger(): void {
			self::assertInstanceOf(AstDestroy::class, $this->parse('destroy trigger'));
			self::assertInstanceOf(AstDestroy::class, $this->parse('destroy trigger if exists'));
			self::assertInstanceOf(AstDestroyIndex::class, $this->parse('destroy trigger on sometable'));
		}

		/**
		 * The target range must already be declared ahead of `after`, same as replace/delete —
		 * a parse-time error (TargetRangeResolver).
		 * @return void
		 */
		public function testRejectsUndeclaredTargetRange(): void {
			$this->expectException(ParserException::class);
			$this->expectExceptionMessage("Undefined range reference 'u'");
			$this->parse('after replace u call f');
		}

		/**
		 * The target must be a declared database entity range, same restriction `replace`/`delete` have.
		 * @return void
		 */
		public function testRejectsJsonSourceTarget(): void {
			$this->expectException(ParserException::class);
			$this->expectExceptionMessage("after target 'j' must be a database entity range");
			$this->parse('range of j is json_source("data.json") after replace j call f');
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
				after replace u call on_changed
				after replace u call on_changed
			');
		}
	}
