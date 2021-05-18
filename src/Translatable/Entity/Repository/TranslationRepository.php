<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Translatable\Entity\Repository;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Gedmo\Exception\InvalidArgumentException;
use Gedmo\Exception\RuntimeException;
use Gedmo\Exception\UnexpectedValueException;
use Gedmo\Tool\Wrapper\EntityWrapper;
use Gedmo\Translatable\Entity\MappedSuperclass\AbstractPersonalTranslation;
use Gedmo\Translatable\Mapping\Event\Adapter\ORM as TranslatableAdapterORM;
use Gedmo\Translatable\TranslatableListener;

/**
 * The TranslationRepository has some useful functions
 * to interact with translations.
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 *
 * @phpstan-extends EntityRepository<object>
 */
class TranslationRepository extends EntityRepository
{
    /**
     * Current TranslatableListener instance used
     * in EntityManager
     */
    private ?TranslatableListener $listener = null;

    public function __construct(EntityManagerInterface $em, ClassMetadata $class)
    {
        if ($class->getReflectionClass()->isSubclassOf(AbstractPersonalTranslation::class)) {
            throw new UnexpectedValueException('This repository is useless for personal translations');
        }
        parent::__construct($em, $class);
    }

    /**
     * Makes additional translation of $entity $field into $locale
     * using $value
     *
     * @param object $entity
     * @param string $field
     * @param string $locale
     * @param mixed  $value
     *
     * @throws InvalidArgumentException
     *
     * @return static
     */
    public function translate($entity, $field, $locale, $value)
    {
        $em = $this->getEntityManager();
        $meta = $em->getClassMetadata(get_class($entity));
        $listener = $this->getTranslatableListener();
        $config = $listener->getConfiguration($this->getEntityManager(), $meta->getName());
        if (!isset($config['fields']) || !in_array($field, $config['fields'], true)) {
            throw new InvalidArgumentException("Entity: {$meta->getName()} does not translate field - {$field}");
        }
        $needsPersist = true;
        if ($locale === $listener->getTranslatableLocale($entity, $meta, $em)) {
            $meta->getReflectionProperty($field)->setValue($entity, $value);
            $em->persist($entity);
        } else {
            if (isset($config['translationClass'])) {
                $class = $config['translationClass'];
            } else {
                $ea = new TranslatableAdapterORM();
                $class = $listener->getTranslationClass($ea, $config['useObjectClass']);
            }

            $wrapper = new EntityWrapper($entity, $em);
            $foreignKey = $wrapper->getIdentifier(false, true);
            $objectClass = $config['useObjectClass'];
            $transMeta = $em->getClassMetadata($class);
            $trans = $this->findOneBy([
                'locale' => $locale,
                'objectClass' => $objectClass,
                'field' => $field,
                'foreignKey' => $foreignKey,
            ]);
            if (!$trans) {
                $trans = $transMeta->newInstance();
                $transMeta->getReflectionProperty('foreignKey')->setValue($trans, $foreignKey);
                $transMeta->getReflectionProperty('objectClass')->setValue($trans, $objectClass);
                $transMeta->getReflectionProperty('field')->setValue($trans, $field);
                $transMeta->getReflectionProperty('locale')->setValue($trans, $locale);
            }
            if ($listener->getDefaultLocale() != $listener->getTranslatableLocale($entity, $meta, $em)
                && $locale === $listener->getDefaultLocale()) {
                $listener->setTranslationInDefaultLocale(spl_object_id($entity), $field, $trans);
                $needsPersist = $listener->getPersistDefaultLocaleTranslation();
            }
            $type = Type::getType($meta->getTypeOfField($field));
            $transformed = $type->convertToDatabaseValue($value, $em->getConnection()->getDatabasePlatform());
            $transMeta->getReflectionProperty('content')->setValue($trans, $transformed);
            if ($needsPersist) {
                if ($em->getUnitOfWork()->isInIdentityMap($entity)) {
                    $em->persist($trans);
                } else {
                    $oid = spl_object_id($entity);
                    $listener->addPendingTranslationInsert($oid, $trans);
                }
            }
        }

        return $this;
    }

    /**
     * Loads all translations with all translatable
     * fields from the given entity
     *
     * @param object $entity Must implement Translatable
     *
     * @return array<string, array<string, string>> list of translations in locale groups
     */
    public function findTranslations($entity)
    {
        $result = [];
        $em = $this->getEntityManager();
        $wrapped = new EntityWrapper($entity, $em);
        if ($wrapped->hasValidIdentifier()) {
            $entityId = $wrapped->getIdentifier(false, true);
            $config = $this
                ->getTranslatableListener()
                ->getConfiguration($em, $wrapped->getMetadata()->getName());

            if (!$config) {
                return $result;
            }

            $entityClass = $config['useObjectClass'];
            $translationMeta = $this->getClassMetadata(); // table inheritance support

            $translationClass = $config['translationClass'] ?? $translationMeta->rootEntityName;

            $qb = $em->createQueryBuilder();
            $qb->select('trans.content, trans.field, trans.locale')
                ->from($translationClass, 'trans')
                ->where('trans.foreignKey = :entityId', 'trans.objectClass = :entityClass')
                ->orderBy('trans.locale')
                ->setParameter('entityId', $entityId)
                ->setParameter('entityClass', $entityClass);

            foreach ($qb->getQuery()->toIterable([], Query::HYDRATE_ARRAY) as $row) {
                $result[$row['locale']][$row['field']] = $row['content'];
            }
        }

        return $result;
    }

    /**
     * Find the entity $class by the translated field.
     * Result is the first occurrence of translated field.
     * Query can be slow, since there are no indexes on such
     * columns
     *
     * @param string $field
     * @param string $value
     * @param string $class
     *
     * @phpstan-param class-string $class
     *
     * @return object instance of $class or null if not found
     */
    public function findObjectByTranslatedField($field, $value, $class)
    {
        $entity = null;
        $em = $this->getEntityManager();
        $meta = $em->getClassMetadata($class);
        $translationMeta = $this->getClassMetadata(); // table inheritance support
        if ($meta->hasField($field)) {
            $dql = "SELECT trans.foreignKey FROM {$translationMeta->rootEntityName} trans";
            $dql .= ' WHERE trans.objectClass = :class';
            $dql .= ' AND trans.field = :field';
            $dql .= ' AND trans.content = :value';
            $q = $em->createQuery($dql);
            $q->setParameters([
                'class' => $class,
                'field' => $field,
                'value' => $value,
            ]);
            $q->setMaxResults(1);
            $id = $q->getSingleScalarResult();

            if (null !== $id) {
                $entity = $em->find($class, $id);
            }
        }

        return $entity;
    }

    /**
     * Loads all translations with all translatable
     * fields by a given entity primary key
     *
     * @param mixed $id primary key value of an entity
     *
     * @return array<string, array<string, string>>
     */
    public function findTranslationsByObjectId($id)
    {
        $result = [];
        if ($id) {
            $translationMeta = $this->getClassMetadata(); // table inheritance support
            $qb = $this->getEntityManager()->createQueryBuilder();
            $qb->select('trans.content, trans.field, trans.locale')
                ->from($translationMeta->rootEntityName, 'trans')
                ->where('trans.foreignKey = :entityId')
                ->orderBy('trans.locale')
                ->setParameter('entityId', $id);
            $q = $qb->getQuery();

            foreach ($q->toIterable([], Query::HYDRATE_ARRAY) as $row) {
                $result[$row['locale']][$row['field']] = $row['content'];
            }
        }

        return $result;
    }

    /**
     * Get the currently used TranslatableListener
     *
     * @throws RuntimeException if listener is not found
     */
    private function getTranslatableListener(): TranslatableListener
    {
        if (null === $this->listener) {
            foreach ($this->getEntityManager()->getEventManager()->getAllListeners() as $listeners) {
                foreach ($listeners as $listener) {
                    if ($listener instanceof TranslatableListener) {
                        return $this->listener = $listener;
                    }
                }
            }

            throw new RuntimeException('The translation listener could not be found');
        }

        return $this->listener;
    }
}
