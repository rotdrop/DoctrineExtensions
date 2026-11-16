<?php

declare(strict_types=1);

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Tests\Mapping;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\YamlDriver;
use Doctrine\Persistence\Mapping\Driver\DefaultFileLocator;
use Gedmo\Mapping\ExtensionMetadataFactory;
use Gedmo\Tests\Mapping\Fixture\Category as AnnotatedCategory;
use Gedmo\Tests\Mapping\Fixture\Xml\Timestampable;
use Gedmo\Tests\Mapping\Fixture\Yaml\BaseCategory as YamlBaseCategory;
use Gedmo\Tests\Mapping\Fixture\Yaml\Category as YamlCategory;
use Gedmo\Timestampable\Mapping\Driver\Yaml as TimestampableYamlDriver;
use Gedmo\Timestampable\TimestampableListener;

/**
 * These are mapping tests for timestampable extension
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 */
final class TimestampableMappingTest extends ORMMappingTestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        parent::setUp();

        $listener = new TimestampableListener();
        $listener->setCacheItemPool($this->cache);

        $this->em = $this->getBasicEntityManager();
        $this->em->getEventManager()->addEventSubscriber($listener);
    }

    /**
     * @return \Generator<string, array{class-string}>
     *
     * @note the XML fixture has a different mapping from the other configs, so it is tested separately
     */
    public static function dataTimestampableObject(): \Generator
    {
        if (PHP_VERSION_ID >= 80000) {
            yield 'Model with attributes' => [AnnotatedCategory::class];
        } elseif (class_exists(AnnotationReader::class)) {
            yield 'Model with annotations' => [AnnotatedCategory::class];
        }

        if (class_exists(YamlDriver::class)) {
            yield 'Model with YAML mapping' => [YamlCategory::class];
        }
    }

    /**
     * @param class-string $className
     *
     * @dataProvider dataTimestampableObject
     */
    public function testTimestampableMapping(string $className): void
    {
        // Force metadata class loading.
        $this->em->getClassMetadata($className);
        $cacheId = ExtensionMetadataFactory::getCacheId($className, 'Gedmo\Timestampable');
        $config = $this->cache->getItem($cacheId)->get();

        static::assertArrayHasKey('create', $config);
        static::assertArrayHasKey('update', $config);
        static::assertSame(['field' => 'created', 'targetProperty' => null], $config['create'][0]);
        static::assertSame(['field' => 'updated', 'targetProperty' => null], $config['update'][0]);
        static::assertArrayHasKey('change', $config);
        $onChange = $config['change'][0];

        static::assertSame('changed', $onChange['field']);
        static::assertNull($onChange['targetProperty']);
        static::assertSame('title', $onChange['trackedField']);
        static::assertSame('Test', $onChange['value']);
    }

    public function testTimestampableXmlMapping(): void
    {
        $className = Timestampable::class;

        // Force metadata class loading.
        $this->em->getClassMetadata($className);
        $cacheId = ExtensionMetadataFactory::getCacheId($className, 'Gedmo\Timestampable');
        $config = $this->cache->getItem($cacheId)->get();

        static::assertArrayHasKey('create', $config);
        static::assertSame(['field' => 'created', 'targetProperty' => null], $config['create'][0]);
        static::assertArrayHasKey('update', $config);
        static::assertSame(['field' => 'updated', 'targetProperty' => null], $config['update'][0]);
        static::assertArrayHasKey('change', $config);
        $onChange = $config['change'][0];

        static::assertSame('published', $onChange['field']);
        static::assertNull($onChange['targetProperty']);
        static::assertSame('status.title', $onChange['trackedField']);
        static::assertSame('Published', $onChange['value']);
    }

    public function testTimestampableYamlDriverMapping(): void
    {
        $driver = new TimestampableYamlDriver();
        $driver->setLocator(new DefaultFileLocator(__DIR__.'/Driver/Yaml', '.dcm.yml'));

        $baseMetadata = new ClassMetadata(YamlBaseCategory::class);
        $baseMetadata->mapField(['fieldName' => 'created', 'type' => 'datetime']);
        $baseMetadata->mapField(['fieldName' => 'updated', 'type' => 'date']);
        $config = [];
        $driver->readExtendedMetadata($baseMetadata, $config);

        static::assertSame(['field' => 'created', 'targetProperty' => null], $config['create'][0]);
        static::assertSame(['field' => 'updated', 'targetProperty' => null], $config['update'][0]);

        $metadata = new ClassMetadata(YamlCategory::class);
        $metadata->mapField(['fieldName' => 'changed', 'type' => 'date']);
        $driver->readExtendedMetadata($metadata, $config);

        static::assertSame([
            'field' => 'changed',
            'targetProperty' => null,
            'trackedField' => 'title',
            'value' => 'Test',
        ], $config['change'][0]);
    }
}
