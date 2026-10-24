<?php

declare(strict_types=1);

namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

use Quellabs\ObjectQuel\Annotations\Orm\Column;
use Quellabs\ObjectQuel\Annotations\Orm\Table;
use Quellabs\ObjectQuel\Annotations\Validation\Length;

#[Table(name: 'native_attribute_entity')]
class NativeAttributeEntity {
    #[Column(name: 'id', type: 'integer', primary_key: true)]
    public int $id = 0;

    #[Column(name: 'name', type: 'string', limit: 40)]
    #[Length(property: 'name', min: 2)]
    public string $name = '';
}
