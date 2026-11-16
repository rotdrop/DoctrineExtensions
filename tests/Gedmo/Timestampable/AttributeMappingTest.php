<?php

declare(strict_types=1);

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Tests\Timestampable;

use Doctrine\ODM\MongoDB\Mapping\ClassMetadata as DocumentMetadata;
use Doctrine\ORM\Mapping\ClassMetadata as EntityMetadata;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Gedmo\Exception\InvalidMappingException;
use Gedmo\Mapping\Annotation\Timestampable;
use Gedmo\Mapping\Driver\AttributeReader;
use Gedmo\Timestampable\Mapping\Driver\Attribute;
use PHPUnit\Framework\TestCase;

final class AttributeMappingTest extends TestCase
{
    public function testScalarTimestampWithValueForOrmAndOdm(): void
    {
        $entity = new EntityMetadata(ScalarTimestampFixture::class);
        $entity->initializeReflection(new RuntimeReflectionService());
        $entity->mapField(['fieldName' => 'changedAt', 'type' => 'datetime']);

        $document = new DocumentMetadata(ScalarTimestampFixture::class);
        $document->mapField(['fieldName' => 'changedAt', 'type' => 'date']);

        foreach ([$entity, $document] as $metadata) {
            $driver = new Attribute();
            $driver->setAnnotationReader(new AttributeReader());
            $config = [];
            $driver->readExtendedMetadata($metadata, $config);

            static::assertSame('status', $config['change'][0]['trackedField']);
            static::assertSame('published', $config['change'][0]['value']);
        }
    }

    public function testSingleValuedAssociationWithValueIsAllowed(): void
    {
        $metadata = new EntityMetadata(AssociationTimestampFixture::class);
        $metadata->initializeReflection(new RuntimeReflectionService());
        $metadata->mapManyToOne(['fieldName' => 'related', 'targetEntity' => AssociationTimestampFixture::class]);

        $driver = new Attribute();
        $driver->setAnnotationReader(new AttributeReader());
        $config = [];
        $driver->readExtendedMetadata($metadata, $config);

        static::assertSame('related', $config['change'][0]['field']);
        static::assertSame('published', $config['change'][0]['value']);
    }

    public function testMultiValuedAssociationWithValueIsRejected(): void
    {
        $metadata = new EntityMetadata(AssociationTimestampFixture::class);
        $metadata->initializeReflection(new RuntimeReflectionService());
        $metadata->mapManyToMany(['fieldName' => 'related', 'targetEntity' => AssociationTimestampFixture::class]);

        $driver = new Attribute();
        $driver->setAnnotationReader(new AttributeReader());
        $config = [];

        $this->expectException(InvalidMappingException::class);
        $this->expectExceptionMessage('Timestampable extension does not support changeset detection for multi-valued association fields.');
        $driver->readExtendedMetadata($metadata, $config);
    }
}

class ScalarTimestampFixture
{
    #[Timestampable(on: 'change', field: 'status', value: 'published')]
    public ?\DateTime $changedAt = null;
}

class AssociationTimestampFixture
{
    #[Timestampable(on: 'change', field: 'status', value: 'published', timestampField: 'changedAt')]
    public mixed $related = null;
}
