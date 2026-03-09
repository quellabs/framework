<?php
	
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Configuration;
	
	/*
	|--------------------------------------------------------------------------
	| Test Case
	|--------------------------------------------------------------------------
	|
	| The following tests verify that the AnnotationReader correctly parses
	| PHP annotations from docblocks and returns the expected results.
	*/
	
	beforeEach(function (): void {
		// Create a new AnnotationReader instance before each test
		$this->config = new Configuration();
		$this->reader = new AnnotationReader($this->config);
	});
	
	it('instantiates without errors', function (): void {
		expect($this->reader)->toBeInstanceOf(AnnotationReader::class);
	});
	
	it('reads annotation correctly', function (): void {
		$classWithMethodAnnotations = new class {
			/**
			 * @Quellabs\AnnotationReader\AnnotationTest\Test(
			 *     Quellabs\AnnotationReader\AnnotationTest\AnotherTest::class,
			 *     name="test",
			 *     xyz={@Quellabs\AnnotationReader\AnnotationTest\AnotherTest()}
			 * )
			 */
			public function test() {
			}
		};
		
		$annotations = $this->reader->getMethodAnnotations($classWithMethodAnnotations, 'test');
		
		expect($annotations)
			->toBeInstanceOf(\Quellabs\AnnotationReader\Collection\AnnotationCollection::class)
			->toHaveCount(1);
		
		// Get the first annotation (using key 'Quellabs\AnnotationReader\AnnotationTest\Test')
		$annotationClass = 'Quellabs\\AnnotationReader\\AnnotationTest\\Test';
		expect($annotations)->toHaveKey($annotationClass);
		
		$annotation = $annotations[$annotationClass];
		
		// Test the annotation object
		expect($annotation)
			->toBeInstanceOf($annotationClass)
			->and($annotation->getParameters())->toBeArray()
			->and($annotation->getParameters())->toHaveKey('name')
			->and($annotation->getParameters()['name'])->toBe('test');
	});
	
	it('handles invalid annotations gracefully', function (): void {
		$classWithInvalidAnnotation = new class {
			/**
			 * @InvalidAnnotation(unclosed="value)
			 */
			public function broken() {
			}
		};
		
		expect(fn() => $this->reader->getMethodAnnotations($classWithInvalidAnnotation, 'broken'))
			->toThrow(\Quellabs\AnnotationReader\Exception\AnnotationReaderException::class);
	});
	
	it('has use statement parser', function (): void {
		// This test would require a file to parse with use statements
		// For this example, we'll just verify the UseStatementParser exists and is used
		$reflectionReader = new ReflectionClass($this->reader);
		$property = $reflectionReader->getProperty('use_statement_parser');
		$property->setAccessible(true);
		
		expect($property->getValue($this->reader))
			->toBeInstanceOf(\Quellabs\AnnotationReader\LexerParser\UseStatementParser::class);
	});
	
	it('handles annotations with use statements correctly', function (): void {
		// Create a temporary test file
		$tempFile = sys_get_temp_dir() . '/annotation_use_test_' . uniqid() . '.php';
		
		// Write a class with use statements and annotations to the file
		file_put_contents($tempFile, '<?php
			namespace Quellabs\AnnotationReader\Tests;
			
			use Quellabs\AnnotationReader\AnnotationTest\Test as TestAnnotation;
			use Quellabs\AnnotationReader\AnnotationTest\AnotherTest;
			
			class TestClassWithUseStatements {
			    /**
                 * Naam met een \' erin
			     * @TestAnnotation(name="imported via alias")
			     * @AnotherTest(value=123)
			     */
			    public function testMethod()
			    {
			    }
			}
		');
		
		try {
			// Include the file to make the class available
			require_once $tempFile;

			// Use the fully qualified class name
			$className = 'Quellabs\\AnnotationReader\\Tests\\TestClassWithUseStatements';

			// Check if the class exists
			if (!class_exists($className)) {
				throw new \Exception("Test class '$className' was not properly loaded from the temporary file");
			}
			
			// Read annotations
			$annotations = $this->reader->getMethodAnnotations($className, 'testMethod');
			
			// Test that both annotations were properly resolved
			expect($annotations)->toBeInstanceOf(\Quellabs\AnnotationReader\Collection\AnnotationCollection::class)->toHaveCount(2);
			
			// Check the first annotation (imported with alias)
			$annotationClass1 = 'Quellabs\\AnnotationReader\\AnnotationTest\\Test';
			expect($annotations)->toHaveKey($annotationClass1);
			expect($annotations[$annotationClass1])
				->toBeInstanceOf(Quellabs\AnnotationReader\AnnotationTest\Test::class)
				->and($annotations[$annotationClass1]->getParameters())->toHaveKey('name')
				->and($annotations[$annotationClass1]->getParameters()['name'])->toBe('imported via alias');
			
			// Check the second annotation (imported without alias)
			$annotationClass2 = 'Quellabs\\AnnotationReader\\AnnotationTest\\AnotherTest';
			expect($annotations)->toHaveKey($annotationClass2);
			expect($annotations[$annotationClass2])
				->toBeInstanceOf(Quellabs\AnnotationReader\AnnotationTest\AnotherTest::class)
				->and($annotations[$annotationClass2]->getParameters())->toHaveKey('value')
				->and($annotations[$annotationClass2]->getParameters()['value'])->toBe(123);
		} finally {
			// Clean up - delete the temporary file
			if (file_exists($tempFile)) {
				unlink($tempFile);
			}
		}
	});