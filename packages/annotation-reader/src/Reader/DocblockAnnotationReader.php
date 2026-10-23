<?php
namespace Quellabs\AnnotationReader\Reader;


use Quellabs\AnnotationReader\Collection\AnnotationCollection;
use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
use Quellabs\AnnotationReader\Exception\LexerException;
use Quellabs\AnnotationReader\Exception\ParserException;
use Quellabs\AnnotationReader\LexerParser\Lexer;
use Quellabs\AnnotationReader\LexerParser\Parser;

/**
 * Reads annotations from PHP docblocks.
 * @phpstan-import-type AnnotationSet from CachedAnnotationReader
 */
final class DocblockAnnotationReader extends CachedAnnotationReader {
		/**
		 * Parses class-level annotations from a docblock comment
		 * @param \ReflectionClass<object> $reflection Reflection class
		 * @param AnnotationCollection $result Reference to the result array where parsed annotations will be stored
		 * @return void
		 * @throws AnnotationReaderException When annotation parsing fails
		 */
		protected function parseClassAnnotations(\ReflectionClass $reflection, AnnotationCollection &$result): void {
			// Early return if no docblock comment exists or if it's empty
			if (empty($reflection->getDocComment())) {
				return;
			}
			
			try {
				$lexer = new Lexer($reflection->getDocComment());
				$parser = new Parser($lexer, $this->configuration, $reflection);
				$result = $parser->parse();
			} catch (LexerException|ParserException $e) {
				// Wrap in AnnotationReaderException for a consistent exception surface
				throw new AnnotationReaderException(
					"Failed to parse class annotations for '{$reflection->getName()}': {$e->getMessage()}",
					$e->getCode(),
					$e
				);
			}
		}
		
		/**
		 * Parse annotations for properties or methods and update the result array.
		 * @param array<\ReflectionProperty|\ReflectionMethod> $items An array of ReflectionProperty or ReflectionMethod objects.
		 * @param array<string, AnnotationCollection> $result The result array to be updated with parsed annotations.
		 * @param \ReflectionClass<object>|null $reflection The reflection class
		 * @return void
		 * @throws AnnotationReaderException
		 */
		protected function parseAnnotations(array $items, array &$result, ?\ReflectionClass $reflection = null): void {
			// Loop through each Reflection item (either property or method)
			foreach ($items as $item) {
				try {
					$docComment = $item->getDocComment();
					
					if (empty($docComment)) {
						continue;
					}
					
					$lexer = new Lexer($docComment);
					$parser = new Parser($lexer, $this->configuration, $reflection);
					$annotations = $parser->parse();
					
					if ($annotations->isEmpty()) {
						continue;
					}
					
					$result[$item->getName()] = $annotations;
				} catch (ParserException|LexerException $e) {
					$itemType = $item instanceof \ReflectionProperty ? 'property' : 'method';
					$itemName = $item->getName();
					$className = $item->getDeclaringClass()->getName();
					
					throw new AnnotationReaderException(
						"Failed to parse annotations for {$itemType} '{$itemName}' in class '{$className}': {$e->getMessage()}",
						$e->getCode(),
						$e
					);
				}
			}
		}
		
		/**
		 * Fetch all object annotations
		 * @param \ReflectionClass<object> $reflection
		 * @return AnnotationSet
		 * @throws AnnotationReaderException
		 */
		protected function readAllObjectAnnotations(\ReflectionClass $reflection): array {
			$result = [
				'class'      => new AnnotationCollection(),
				'properties' => [],
				'methods'    => []
			];
			
			$this->parseClassAnnotations($reflection, $result['class']);
			$this->parseAnnotations($reflection->getProperties(), $result['properties'], $reflection);
			$this->parseAnnotations($reflection->getMethods(), $result['methods'], $reflection);
			return $result;
		}
		

}
