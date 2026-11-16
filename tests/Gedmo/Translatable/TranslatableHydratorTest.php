<?php

declare(strict_types=1);

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Tests\Translatable;

use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\ResultSetMapping;
use Gedmo\Tests\Tool\BaseTestCaseORM;
use Gedmo\Tests\Translatable\Fixture\HydrationArticle;
use Gedmo\Tests\Translatable\Fixture\HydrationLabel;
use Gedmo\Translatable\Entity\Translation;
use Gedmo\Translatable\Hydrator\ORM\ObjectHydrator;
use Gedmo\Translatable\Hydrator\ORM\SimpleObjectHydrator;
use Gedmo\Translatable\Query\TreeWalker\TranslationWalker;
use Gedmo\Translatable\TranslatableListener;

final class TranslatableHydratorTest extends BaseTestCaseORM
{
    private TranslatableListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->listener = new TranslatableListener();
        $this->listener->setDefaultLocale('en');
        $this->listener->setTranslatableLocale('de');
        $events = new EventManager();
        $events->addEventSubscriber($this->listener);
        $this->getDefaultMockSqliteEntityManager($events);
    }

    /**
     * @return iterable<string, array{class-string<AbstractHydrator>}>
     */
    public static function hydrators(): iterable
    {
        yield 'object' => [ObjectHydrator::class];
        yield 'simple object' => [SimpleObjectHydrator::class];
    }

    /**
     * @dataProvider hydrators
     *
     * @param class-string<AbstractHydrator> $hydratorClass
     */
    public function testDeferredPostLoadPreservesTypedValuesAndOriginalData(string $hydratorClass): void
    {
        $article = $this->hydrate($hydratorClass)[0];

        static::assertSame('Geige', $article->title);
        static::assertSame('violin', $article->untranslatedTitle);
        static::assertSame(42, $article->number);
        static::assertSame(7, $article->untranslatedNumber);
        $this->em->getUnitOfWork()->computeChangeSets();
        static::assertSame([], $this->em->getUnitOfWork()->getEntityChangeSet($article));
        static::assertSame('Geige', $this->em->getUnitOfWork()->getOriginalEntityData($article)['title']);
    }

    /**
     * @dataProvider hydrators
     *
     * @param class-string<AbstractHydrator> $hydratorClass
     */
    public function testDefaultLocaleAndNullTranslation(string $hydratorClass): void
    {
        $this->listener->setTranslatableLocale('en');
        $article = $this->hydrate($hydratorClass, "SELECT 1 AS id, 'violin' AS title, 'violin' AS __gedmo_untranslated_title, NULL AS number, 7 AS __gedmo_untranslated_number")[0];

        static::assertSame('violin', $article->title);
        static::assertSame('violin', $article->untranslatedTitle);
        static::assertNull($article->number);
        static::assertSame(7, $article->untranslatedNumber);
    }

    /**
     * @dataProvider hydrators
     *
     * @param class-string<AbstractHydrator> $hydratorClass
     */
    public function testIterationConsumesTranslationsBeforeYield(string $hydratorClass): void
    {
        $hydrator = new $hydratorClass($this->em);
        $rows = $hydrator->toIterable($this->em->getConnection()->executeQuery($this->sql()), $this->mapping());
        foreach ($rows as $article) {
            static::assertSame('Geige', $article->title);
            static::assertSame('violin', $article->untranslatedTitle);
            static::assertSame(42, $article->number);
        }
    }

    /**
     * @dataProvider hydrators
     *
     * @param class-string<AbstractHydrator> $hydratorClass
     */
    public function testContextSurvivesAnOuterHydrationSection(string $hydratorClass): void
    {
        $uow = $this->em->getUnitOfWork();
        $uow->enterHydration();

        try {
            $article = $this->hydrate($hydratorClass)[0];
            static::assertSame('violin', $article->title);
            static::assertNull($article->untranslatedTitle);
        } finally {
            $uow->leaveHydration();
        }

        static::assertSame('Geige', $article->title);
        static::assertSame('violin', $article->untranslatedTitle);
    }

    public function testNestedOrdinaryLoadDoesNotConsumeAnotherEntityContext(): void
    {
        $this->em->getConnection()->executeStatement("INSERT INTO HydrationArticle (id, title, number) VALUES (2, 'ordinary', 9)");
        $em = $this->em;
        $observer = new class($em) {
            public ?HydrationArticle $nested = null;

            private EntityManagerInterface $em;

            public function __construct(EntityManagerInterface $em)
            {
                $this->em = $em;
            }

            public function postLoad(PostLoadEventArgs $args): void
            {
                if ($args->getObject() instanceof HydrationArticle && 1 === $args->getObject()->id) {
                    $this->nested = $this->em->find(HydrationArticle::class, 2);
                }
            }
        };
        $this->em->getEventManager()->addEventListener(['postLoad'], $observer);
        $article = $this->hydrate(ObjectHydrator::class)[0];

        static::assertSame('Geige', $article->title);
        static::assertSame('violin', $article->untranslatedTitle);
        static::assertSame('ordinary', $observer->nested->untranslatedTitle);
        static::assertNull($observer->nested->number);
    }

    public function testAlreadyManagedEntityDoesNotAcquireStaleContext(): void
    {
        $article = $this->hydrate(ObjectHydrator::class)[0];
        $article->title = 'user edit';
        static::assertSame($article, $this->hydrate(ObjectHydrator::class)[0]);
        static::assertSame('user edit', $article->title);

        $article->title = 'ordinary';
        $this->listener->postLoad(new PostLoadEventArgs($article, $this->em));
        static::assertSame('ordinary', $article->untranslatedTitle);
    }

    public function testClearDiscardsPendingContext(): void
    {
        $this->listener->setTranslationFallback(true);
        $article = new HydrationArticle();
        $article->id = 1;
        $article->title = 'ordinary';
        $this->listener->setHydratedTranslations($this->em, $article, ['title' => 'stale']);
        $this->em->clear();
        $this->listener->postLoad(new PostLoadEventArgs($article, $this->em));

        static::assertSame('ordinary', $article->untranslatedTitle);
        static::assertNotSame('stale', $article->title);
    }

    public function testWalkerHandlesScalarFields(): void
    {
        $this->em->getConnection()->executeStatement("INSERT INTO HydrationArticle (id, title, number) VALUES (1, 'violin', 7)");
        $this->listener->setTranslationFallback(true);
        $query = $this->em->createQuery('SELECT a, a.title AS scalarTitle FROM '.HydrationArticle::class.' a');
        $query->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, TranslationWalker::class);
        $result = $query->getResult();

        static::assertSame('violin', $result[0][0]->title);
        static::assertSame('violin', $result[0][0]->untranslatedTitle);
        static::assertSame('violin', $result[0]['scalarTitle']);
    }

    /**
     * @dataProvider hydrators
     *
     * @param class-string<AbstractHydrator> $hydratorClass
     */
    public function testCompositeAssociationIdentifier(string $hydratorClass): void
    {
        $mapping = new ResultSetMapping();
        $mapping->addEntityResult(HydrationLabel::class, 'l');
        $mapping->addMetaResult('l', 'article_id', 'article_id', true, 'integer');
        $mapping->addFieldResult('l', 'code', 'code');
        $mapping->addFieldResult('l', 'label', 'label');
        $sql = "SELECT 12 AS article_id, 'string-id' AS code, 'Geige' AS label, 'violin' AS __gedmo_untranslated_label";
        $labels = (new $hydratorClass($this->em))->hydrateAll($this->em->getConnection()->executeQuery($sql), $mapping);

        static::assertSame('Geige', $labels[0]->label);
        static::assertSame('violin', $labels[0]->untranslatedLabel);
        static::assertSame('string-id', $labels[0]->code);
        static::assertSame(12, $labels[0]->article->id);
    }

    public function testFetchJoinPreservesRepeatedAndEmptyAliases(): void
    {
        $mapping = $this->mapping();
        $mapping->addJoinedEntityResult(HydrationLabel::class, 'l', 'a', 'labels');
        $mapping->addMetaResult('l', 'article_id', 'article_id', true, 'integer');
        $mapping->addFieldResult('l', 'code', 'code');
        $mapping->addFieldResult('l', 'label', 'label');
        $row = $this->sql().", 1 AS article_id, 'first' AS code, 'Saite' AS label, 'string' AS __gedmo_untranslated_label";
        $empty = "SELECT 2 AS id, 'Bass' AS title, 'bass' AS __gedmo_untranslated_title, NULL AS number, NULL AS __gedmo_untranslated_number, NULL AS article_id, NULL AS code, NULL AS label, NULL AS __gedmo_untranslated_label";
        $articles = (new ObjectHydrator($this->em))->hydrateAll(
            $this->em->getConnection()->executeQuery($row.' UNION ALL '.$row.' UNION ALL '.$empty),
            $mapping
        );

        static::assertCount(2, $articles);
        static::assertCount(1, $articles[0]->labels);
        static::assertSame('Geige', $articles[0]->title);
        static::assertSame('violin', $articles[0]->untranslatedTitle);
        static::assertSame('Saite', $articles[0]->labels->first()->label);
        static::assertSame('string', $articles[0]->labels->first()->untranslatedLabel);
        static::assertCount(0, $articles[1]->labels);
        static::assertSame('bass', $articles[1]->untranslatedTitle);
    }

    public function testThrowingPostLoadDoesNotLeakHydrationContext(): void
    {
        $observer = new class {
            public function postLoad(PostLoadEventArgs $args): void
            {
                throw new \RuntimeException('postLoad failed');
            }
        };
        $events = $this->em->getEventManager();
        $events->addEventListener(['postLoad'], $observer);

        try {
            $this->hydrate(ObjectHydrator::class);
            static::fail('Expected the postLoad exception to propagate.');
        } catch (\RuntimeException $exception) {
            static::assertSame('postLoad failed', $exception->getMessage());
        } finally {
            $events->removeEventListener(['postLoad'], $observer);
        }

        $article = $this->em->find(HydrationArticle::class, 1);
        $article->title = 'ordinary';
        $this->listener->postLoad(new PostLoadEventArgs($article, $this->em));
        static::assertSame('ordinary', $article->untranslatedTitle);
    }

    protected function getUsedEntityFixtures(): array
    {
        return [HydrationArticle::class, HydrationLabel::class, Translation::class];
    }

    /**
     * @param class-string<AbstractHydrator> $hydratorClass
     *
     * @return list<HydrationArticle>
     */
    private function hydrate(string $hydratorClass, ?string $sql = null): array
    {
        $statement = $this->em->getConnection()->executeQuery($sql ?? $this->sql());

        return (new $hydratorClass($this->em))->hydrateAll($statement, $this->mapping());
    }

    private function sql(): string
    {
        return "SELECT 1 AS id, 'Geige' AS title, 'violin' AS __gedmo_untranslated_title, 42 AS number, 7 AS __gedmo_untranslated_number";
    }

    private function mapping(): ResultSetMapping
    {
        $mapping = new ResultSetMapping();
        $mapping->addEntityResult(HydrationArticle::class, 'a');
        $mapping->addFieldResult('a', 'id', 'id');
        $mapping->addFieldResult('a', 'title', 'title');
        $mapping->addFieldResult('a', 'number', 'number');

        return $mapping;
    }
}
