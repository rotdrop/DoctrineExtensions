<?php

declare(strict_types=1);

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Tests\Mapping;

use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\YamlDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Gedmo\Loggable\Entity\LogEntry;
use Gedmo\Loggable\LoggableListener;
use Gedmo\Mapping\ExtensionMetadataFactory;
use Gedmo\Tests\Mapping\Fixture\Yaml\Category;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Gedmo\Tests\Mapping\Fixture\LoggableComposite;
use Gedmo\Tests\Mapping\Fixture\LoggableCompositeRelation;

/**
 * These are mapping tests for tree extension
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 */
final class LoggableORMMappingTest extends ORMMappingTestCase
{
    public const YAML_CATEGORY = Category::class;
    public const COMPOSITE = LoggableComposite::class;
    public const COMPOSITE_RELATION = LoggableCompositeRelation::class;

    /**
     * @var EntityManager
     */
    private $em;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->getBasicConfiguration();
        $chainDriverImpl = new MappingDriverChain();
        $chainDriverImpl->addDriver(
            new YamlDriver([__DIR__.'/Driver/Yaml']),
            'Gedmo\Tests\Mapping\Fixture\Yaml'
        );
        $reader = new \Doctrine\Common\Annotations\AnnotationReader();
        \Doctrine\Common\Annotations\AnnotationRegistry::registerAutoloadNamespace(
            'Gedmo\\Mapping\\Annotation',
            VENDOR_PATH.'/../lib'
        );
        $chainDriverImpl->addDriver(
            new \Doctrine\ORM\Mapping\Driver\AnnotationDriver($reader),
            'Mapping\Fixture'
        );
        $config->setMetadataDriverImpl($chainDriverImpl);

        $conn = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ];

        $evm = new EventManager();
        $loggableListener = new LoggableListener();
        $loggableListener->setCacheItemPool($this->cache);
        $evm->addEventSubscriber($loggableListener);
        $this->em = EntityManager::create($conn, $config, $evm);
    }

    public function testLoggableMapping(): void
    {
        $meta = $this->em->getClassMetadata(self::YAML_CATEGORY);
        $cacheId = ExtensionMetadataFactory::getCacheId(self::YAML_CATEGORY, 'Gedmo\Loggable');
        $config = $this->cache->getItem($cacheId)->get();

        static::assertArrayHasKey('loggable', $config);
        static::assertTrue($config['loggable']);
        static::assertArrayHasKey('logEntryClass', $config);
        static::assertSame(LogEntry::class, $config['logEntryClass']);
    }

    public function testLoggableCompositeMapping()
    {
        $meta = $this->em->getClassMetadata(self::COMPOSITE);

        $this->assertTrue(is_array($meta->identifier));
        $this->assertCount(2, $meta->identifier);

        $cacheId = ExtensionMetadataFactory::getCacheId(self::COMPOSITE, 'Gedmo\Loggable');
        $config = $this->em->getMetadataFactory()->getCacheDriver()->fetch($cacheId);

        $this->assertArrayHasKey('loggable', $config);
        $this->assertTrue($config['loggable']);
    }

    public function testLoggableCompositeRelationMapping()
    {
        $meta = $this->em->getClassMetadata(self::COMPOSITE_RELATION);

        $this->assertTrue(is_array($meta->identifier));
        $this->assertCount(2, $meta->identifier);

        $cacheId = ExtensionMetadataFactory::getCacheId(self::COMPOSITE_RELATION, 'Gedmo\Loggable');
        $config = $this->em->getMetadataFactory()->getCacheDriver()->fetch($cacheId);

        $this->assertArrayHasKey('loggable', $config);
        $this->assertTrue($config['loggable']);
    }
}
