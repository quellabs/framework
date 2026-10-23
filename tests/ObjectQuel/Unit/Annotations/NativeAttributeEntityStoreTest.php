<?php

declare(strict_types=1);

namespace Quellabs\ObjectQuel\Tests\Unit\Annotations;

use PHPUnit\Framework\TestCase;
use Quellabs\AnnotationReader\AnnotationReaderLocator;
use Quellabs\AnnotationReader\Configuration as ReaderConfiguration;
use Quellabs\ObjectQuel\Configuration;
use Quellabs\ObjectQuel\EntityStore;
use Quellabs\ObjectQuel\Tests\Fixtures\Entities\NativeAttributeEntity;
use Quellabs\ObjectQuel\Annotations\Validation\Length;
use Quellabs\ObjectQuel\Validation\AnnotationsToValidation;
use Quellabs\ObjectQuel\Validation\EntityToValidation;

class NativeAttributeEntityStoreTest extends TestCase {
    /**
     * @return void
     */
    public function testObjectQuelBuildsMetadataAndValidationFromAttributes(): void {
        $previous = AnnotationReaderLocator::getInstance('objectquel');
        try {
            $configuration = new Configuration();
            $configuration->setEntityPath(__DIR__ . '/../../Fixtures/Entities');
            $configuration->setEntityNameSpace('Quellabs\\ObjectQuel\\Tests\\Fixtures\\Entities');
            $configuration->setUseMetadataCache(false);
            $configuration->setAnnotationSource(ReaderConfiguration::SOURCE_ATTRIBUTES);
            $store = new EntityStore($configuration);
            self::assertSame(ReaderConfiguration::SOURCE_ATTRIBUTES, $store->getAnnotationReader()->getSource());
            $metadata = $store->getMetadata(NativeAttributeEntity::class);
            self::assertSame('native_attribute_entity', $metadata->tableName);
            self::assertSame('name', $metadata->getColumnName('name'));
            self::assertSame(40, $metadata->getColumnAnnotation('name')->getLimit());

            $annotations = $store->getAnnotationReader()->getPropertyAnnotations(NativeAttributeEntity::class, 'name', Length::class);
            $rules = (new AnnotationsToValidation())->convert($annotations->toArray());
            self::assertArrayHasKey('name', $rules);
            $entityRules = (new EntityToValidation($store->getAnnotationReader()))->convert(new NativeAttributeEntity());
            self::assertSame(['min' => 2, 'max' => null], $entityRules['name'][0]->getConditions());
        } finally {
            AnnotationReaderLocator::reset('objectquel');
            if ($previous !== null) {
                AnnotationReaderLocator::setInstance($previous, 'objectquel');
            }
        }
    }
}
