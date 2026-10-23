<?php

namespace Quellabs\AnnotationReader;

use Quellabs\AnnotationReader\Collection\AnnotationCollection;
use Quellabs\AnnotationReader\Reader\DocblockAnnotationReader;
use Quellabs\AnnotationReader\Reader\NativeAttributeAnnotationReader;

/**
 * Public entry point for the configured metadata source.
 */
class AnnotationReader implements AnnotationReaderInterface {
    private AnnotationReaderInterface $implementation;
    private string $source;

    /**
     * Selects the metadata reader for this configuration.
     * @param Configuration $configuration
     * @return void
     */
    public function __construct(Configuration $configuration) {
        $this->source = $configuration->getSource();
        if ($this->source === Configuration::SOURCE_ATTRIBUTES) {
            $this->implementation = new NativeAttributeAnnotationReader($configuration);
        } else {
            $this->implementation = new DocblockAnnotationReader($configuration);
        }
    }

    /**
     * Returns the source fixed at construction time.
     * @return string
     */
    public function getSource(): string {
        return $this->source;
    }

    /**
     * Returns class annotations from the selected source.
     * @param class-string|object $class
     * @param string|null $annotationClass
     * @return AnnotationCollection
     */
    public function getClassAnnotations(string|object $class, ?string $annotationClass = null): AnnotationCollection {
        return $this->implementation->getClassAnnotations($class, $annotationClass);
    }

    /**
     * Checks for a class annotation.
     * @param class-string|object $class
     * @param string $annotationClass
     * @return bool
     */
    public function classHasAnnotation(string|object $class, string $annotationClass): bool {
        return $this->implementation->classHasAnnotation($class, $annotationClass);
    }

    /**
     * Returns annotations on a method.
     * @param class-string|object $class
     * @param string $methodName
     * @param string|null $annotationClass
     * @return AnnotationCollection
     */
    public function getMethodAnnotations(string|object $class, string $methodName, ?string $annotationClass = null): AnnotationCollection {
        return $this->implementation->getMethodAnnotations($class, $methodName, $annotationClass);
    }

    /**
     * Checks for an annotation on a method.
     * @param class-string|object $class
     * @param string $methodName
     * @param string $annotationClass
     * @return bool
     */
    public function methodHasAnnotation(string|object $class, string $methodName, string $annotationClass): bool {
        return $this->implementation->methodHasAnnotation($class, $methodName, $annotationClass);
    }

    /**
     * Returns annotations on a property.
     * @param class-string|object $class
     * @param string $propertyName
     * @param string|null $annotationClass
     * @return AnnotationCollection
     */
    public function getPropertyAnnotations(string|object $class, string $propertyName, ?string $annotationClass = null): AnnotationCollection {
        return $this->implementation->getPropertyAnnotations($class, $propertyName, $annotationClass);
    }

    /**
     * Checks for an annotation on a property.
     * @param class-string|object $class
     * @param string $propertyName
     * @param string $annotationClass
     * @return bool
     */
    public function propertyHasAnnotation(string|object $class, string $propertyName, string $annotationClass): bool {
        return $this->implementation->propertyHasAnnotation($class, $propertyName, $annotationClass);
    }

    /**
     * Parses explicitly supplied docblock text in either source mode.
     * @param string $string
     * @return AnnotationCollection
     */
    public function getAnnotations(string $string): AnnotationCollection {
        return $this->implementation->getAnnotations($string);
    }

    /**
     * Clears this reader's caches containing an annotation class.
     * @param string $annotationClass
     * @return void
     */
    public function clearCacheByAnnotationClass(string $annotationClass): void {
        $this->implementation->clearCacheByAnnotationClass($annotationClass);
    }

    /**
     * Clears all caches owned by this reader.
     * @return void
     */
    public function clearAllCaches(): void {
        $this->implementation->clearAllCaches();
    }
}
