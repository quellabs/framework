<?php

namespace Quellabs\AnnotationReader\Annotation;

/**
 * Reads each constructor parameter from its same-named declared property.
 */
trait ReflectsAnnotationParameters {
    /**
     * Returns all resolved constructor parameters, including defaults.
     * @return array<string, mixed>
     * @throws \ReflectionException
     */
    public function getParameters(): array {
        $reflection = new \ReflectionClass($this);
        $constructor = $reflection->getConstructor();
        $parameters = [];
        if ($constructor === null) {
            return $parameters;
        }
        foreach ($constructor->getParameters() as $parameter) {
            $property = $reflection->getProperty($parameter->getName());
            $parameters[$parameter->getName()] = $property->getValue($this);
        }
        return $parameters;
    }
}
