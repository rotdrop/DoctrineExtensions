<?php

declare(strict_types=1);

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Tests\Mapping\Xml;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Gedmo\Loggable\Entity\LogEntry;
use Gedmo\Loggable\LoggableListener;
use Gedmo\Tests\Mapping\Fixture\Xml\Embedded;
use Gedmo\Tests\Mapping\Fixture\Xml\Loggable;
use Gedmo\Tests\Mapping\Fixture\Xml\LoggableWithEmbedded;
use Gedmo\Tests\Mapping\Fixture\Xml\Status;
use Gedmo\Tests\Tool\BaseTestCaseOM;

/**
 * These are mapping extension tests
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 */
final class LoggableMappingTest extends BaseTestCaseOM
{
    const COMPOSITE = 'Mapping\\Fixture\\Xml\\LoggableComposite';
    const COMPOSITE_RELATION = 'Mapping\\Fixture\\Xml\\LoggableCompositeRelation';

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var LoggableListener
     *
     * @phpstan-var LoggableListener<Loggable>
     */
    private $loggable;

    protected function setUp(): void
    {
        parent::setUp();

        $reader = new AnnotationReader();
        $annotationDriver = new AnnotationDriver($reader);

        $xmlDriver = new XmlDriver(__DIR__.'/../Driver/Xml');

        $chain = new MappingDriverChain();
        $chain->addDriver($annotationDriver, 'Gedmo\Loggable');
        $chain->addDriver($xmlDriver, 'Gedmo\Tests\Mapping\Fixture\Xml');

        $this->loggable = new LoggableListener();
        $this->evm = new EventManager();
        $this->evm->addEventSubscriber($this->loggable);

        $this->em = $this->getDefaultMockSqliteEntityManager([
            LogEntry::class,
            Loggable::class,
            LoggableWithEmbedded::class,
            Embedded::class,
            Status::class,
        ], $chain);
    }

    public function testLoggableMetadata(): void
    {
        $meta = $this->em->getClassMetadata(Loggable::class);
        $config = $this->loggable->getConfiguration($this->em, $meta->getName());

        static::assertArrayHasKey('logEntryClass', $config);
        static::assertSame(LogEntry::class, $config['logEntryClass']);
        static::assertArrayHasKey('loggable', $config);
        static::assertTrue($config['loggable']);

        static::assertArrayHasKey('versioned', $config);
        static::assertCount(2, $config['versioned']);
        static::assertContains('title', $config['versioned']);
        static::assertContains('status', $config['versioned']);
    }

    public function testLoggableCompositeMetadata(): void
    {
        $meta = $this->em->getClassMetadata(self::COMPOSITE);
        $config = $this->loggable->getConfiguration($this->em, $meta->name);

        $this->assertArrayHasKey('logEntryClass', $config);
        $this->assertEquals('Gedmo\Loggable\Entity\LogEntry', $config['logEntryClass']);
        $this->assertArrayHasKey('loggable', $config);
        $this->assertTrue($config['loggable']);

        $this->assertArrayHasKey('versioned', $config);
        $this->assertCount(1, $config['versioned']);
        $this->assertContains('title', $config['versioned']);
    }

    public function testLoggableCompositeRelationMetadata(): void
    {
        $meta = $this->em->getClassMetadata(self::COMPOSITE_RELATION);
        $config = $this->loggable->getConfiguration($this->em, $meta->name);

        $this->assertArrayHasKey('logEntryClass', $config);
        $this->assertEquals('Gedmo\Loggable\Entity\LogEntry', $config['logEntryClass']);
        $this->assertArrayHasKey('loggable', $config);
        $this->assertTrue($config['loggable']);

        $this->assertArrayHasKey('versioned', $config);
        $this->assertCount(1, $config['versioned']);
        $this->assertContains('title', $config['versioned']);
    }

    public function testLoggableMetadataWithEmbedded(): void
    {
        $meta = $this->em->getClassMetadata(LoggableWithEmbedded::class);
        $config = $this->loggable->getConfiguration($this->em, $meta->getName());

        static::assertArrayHasKey('logEntryClass', $config);
        static::assertSame(LogEntry::class, $config['logEntryClass']);
        static::assertArrayHasKey('loggable', $config);
        static::assertTrue($config['loggable']);

        static::assertArrayHasKey('versioned', $config);
        static::assertCount(3, $config['versioned']);
        static::assertContains('title', $config['versioned']);
        static::assertContains('status', $config['versioned']);
        static::assertContains('embedded', $config['versioned']);
    }
}
