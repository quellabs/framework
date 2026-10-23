<?php

namespace Quellabs\AnnotationReader;

use Quellabs\AnnotationReader\Collection\AnnotationCollection;

/**
 * Operations supplied by each metadata source.
 */
interface AnnotationReaderInterface {
    /**
     * Returns annotations declared on a class.
     * @param class-string|object $class
     * @param string|null $annotationClass
     * @return AnnotationCollection
     */
    public function getClassAnnotations(string|object $class, ?string $annotationClass = null): AnnotationCollection;

    /**
     * Checks whether a class has an annotation.
     * @param class-string|object $class
     * @param string $annotationClass
     * @return bool
     */
    public function classHasAnnotation(string|object $class, string $annotationClass): bool;

    /**
     * Returns annotations declared on a method.
     * @param class-string|object $class
     * @param string $methodName
     * @param string|null $annotationClass
     * @return AnnotationCollection
     */
    public function getMethodAnnotations(string|object $class, string $methodName, ?string $annotationClass = null): AnnotationCollection;

    /**
     * Checks whether a method has an annotation.
     * @param class-string|object $class
     * @param string $methodName
     * @param string $annotationClass
     * @return bool
     */
    public function methodHasAnnotation(string|object $class, string $methodName, string $annotationClass): bool;

    /**
     * Returns annotations declared on a property.
     * @param class-string|object $class
     * @param string $propertyName
     * @param string|null $annotationClass
     * @return AnnotationCollection
     */
    public function getPropertyAnnotations(string|object $class, string $propertyName, ?string $annotationClass = null): AnnotationCollection;

    /**
     * Checks whether a property has an annotation.
     * @param class-string|object $class
     * @param string $propertyName
     * @param string $annotationClass
     * @return bool
     */
    public function propertyHasAnnotation(string|object $class, string $propertyName, string $annotationClass): bool;

    /**
     * Parses annotations in explicit docblock text.
     * @param string $string
     * @return AnnotationCollection
     */
    public function getAnnotations(string $string): AnnotationCollection;

    /**
     * Clears caches containing the annotation class.
     * @param string $annotationClass
     * @return void
     */
    public function clearCacheByAnnotationClass(string $annotationClass): void;

    /**
     * Clears all caches owned by this reader.
     * @return void
     */
    public function clearAllCaches(): void;
}
