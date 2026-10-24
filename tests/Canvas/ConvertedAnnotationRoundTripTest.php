<?php

declare(strict_types=1);

namespace Quellabs\Canvas\Tests\AnnotationReader;

use PHPUnit\Framework\TestCase;
use Quellabs\AnnotationReader\Annotation\AnnotationFactory;
use Quellabs\Canvas\Annotations as Canvas;
use Quellabs\CanvasObjectQuel\Annotations as CanvasObjectQuel;
use Quellabs\ObjectQuel\Annotations\Orm;
use Quellabs\ObjectQuel\Annotations\Validation;
use Quellabs\ObjectQuel\Annotations\SerializationGroups;

class ConvertedAnnotationRoundTripTest extends TestCase {
    /**
     * @return void
     */
    public function testEveryConvertedAnnotationResolvesAndReconstructsItsNamedParameters(): void {
        $examples = [
            Canvas\Route::class => ['value' => '/users'],
            Canvas\RoutePrefix::class => ['value' => '/api'],
            Canvas\ListenTo::class => ['value' => 'users.created'],
            CanvasObjectQuel\ResolveEntity::class => ['parameter' => 'user', 'routeParam' => 'id'],
            Orm\Cascade::class => ['operations' => ['remove']],
            Orm\Column::class => ['name' => 'id', 'type' => 'integer'],
            Orm\DiscriminatorColumn::class => ['name' => 'kind'],
            Orm\DiscriminatorValue::class => ['value' => 'user'],
            Orm\EntityBridge::class => [],
            Orm\ForeignKey::class => ['target' => 'User'],
            Orm\ForeignKeyAction::class => [],
            Orm\FullTextIndex::class => ['name' => 'search', 'columns' => ['title']],
            Orm\Immutable::class => [],
            Orm\Index::class => ['name' => 'idx', 'columns' => ['id']],
            Orm\InverseOf::class => ['targetEntity' => 'User', 'relation' => 'posts'],
            Orm\LifecycleAware::class => [],
            Orm\ManyToOne::class => ['targetEntity' => 'User'],
            Orm\OneToOne::class => ['targetEntity' => 'User'],
            Orm\PostDelete::class => [],
            Orm\PostPersist::class => [],
            Orm\PostUpdate::class => [],
            Orm\PreDelete::class => [],
            Orm\PrePersist::class => [],
            Orm\PreUpdate::class => [],
            Orm\PrimaryKeyStrategy::class => [],
            Orm\RequiredRelation::class => [],
            Orm\SoftDelete::class => [],
            Orm\SourceField::class => ['field' => 'summary'],
            Orm\Table::class => ['name' => 'users'],
            Orm\UniqueIndex::class => ['name' => 'unique_id', 'columns' => ['id']],
            Orm\Version::class => [],
            SerializationGroups::class => ['groups' => ['public']],
            Validation\Date::class => [],
            Validation\Email::class => [],
            Validation\Length::class => [],
            Validation\NotBlank::class => [],
            Validation\RegExp::class => [],
            Validation\Type::class => [],
            Validation\ValueIn::class => [],
        ];

        // Route and ForeignKey keep a sparse getParameters() contract (only the keys the
        // caller actually supplied); every other converted class is dense via the
        // ReflectsAnnotationParameters trait (every constructor parameter, defaults included).
        $sparseClasses = [Canvas\Route::class, Orm\ForeignKey::class];

        foreach ($examples as $class => $parameters) {
            $annotation = AnnotationFactory::create($class, $parameters);
            $constructor = (new \ReflectionClass($class))->getConstructor();
            self::assertNotNull($constructor);
            $names = array_map(static fn(\ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters());

            if (in_array($class, $sparseClasses, true)) {
                self::assertSame(array_keys($parameters), array_keys($annotation->getParameters()), $class);
            } else {
                self::assertSame($names, array_keys($annotation->getParameters()), $class);
            }

            $reconstructed = AnnotationFactory::create($class, $annotation->getParameters());
            self::assertSame($annotation->getParameters(), $reconstructed->getParameters(), $class);
        }
    }
}
