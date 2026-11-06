<?php

	declare(strict_types=1);

	namespace Quellabs\AnnotationReader\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Configuration;

	/**
	 * @template T
	 * @extends \ArrayObject<int, T>
	 * @implements \Countable
	 * @method string getName()
	 * @property int $id
	 * @property-read string $label
	 * @mixin \Countable
	 */
	class ClassWithGenericsAndMagicMemberTags extends \ArrayObject {
	}

	/**
	 * phpDocumentor/PHPStan/Psalm's generics and magic-member tags
	 * (@extends, @implements, @method, @property, @property-read/-write,
	 * @mixin) are common, valid PHPDoc -- not real annotations -- and
	 * must be silently skipped the same way @param and the phpstan/psalm
	 * pseudo-tag prefixes already are. Before this fix, any of them
	 * (confirmed in production by @extends on a generic repository base
	 * class, e.g. "@extends Repository<SomeEntity>") made
	 * getClassAnnotations() throw a ParserException ("Annotation class
	 * not found: extends") instead of returning normally -- fatal
	 * wherever a class with such a tag was autowired, not just wherever
	 * its annotations were read on purpose.
	 */
	class DocblockIgnoredTagsTest extends TestCase {

		private function reader(): AnnotationReader {
			$configuration = new Configuration();
			$configuration->setSource(Configuration::SOURCE_DOCBLOCKS);
			return new AnnotationReader($configuration);
		}

		public function testClassAnnotationsDoesNotThrowOnGenericsAndMagicMemberTags(): void {
			$annotations = $this->reader()->getClassAnnotations(ClassWithGenericsAndMagicMemberTags::class);

			self::assertTrue($annotations->isEmpty());
		}
	}
