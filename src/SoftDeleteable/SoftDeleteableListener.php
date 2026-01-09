<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\SoftDeleteable;

use Doctrine\Common\EventArgs;
use Doctrine\Common\EventManager;
use Doctrine\Deprecations\Deprecation;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\UnitOfWork as MongoDBUnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\Persistence\Event\LoadClassMetadataEventArgs;
use Doctrine\Persistence\Event\ManagerEventArgs;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use Gedmo\Mapping\MappedEventSubscriber;
use Gedmo\SoftDeleteable\Event\PostSoftDeleteEventArgs;
use Gedmo\SoftDeleteable\Event\PreSoftDeleteEventArgs;
use Gedmo\SoftDeleteable\Mapping\Event\SoftDeleteableAdapter;
use Gedmo\SoftDeleteable\Event\PostSoftUnDeleteEventArgs;
use Gedmo\SoftDeleteable\Event\PreSoftUnDeleteEventArgs;
use Gedmo\SoftDeleteable\HardDeleteable\HardDeleteExpired;

/**
 * SoftDeleteable listener
 *
 * @phpstan-extends MappedEventSubscriber<array, SoftDeleteableAdapter>
 *
 * @author Gustavo Falco <comfortablynumb84@gmail.com>
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 *
 * @final since gedmo/doctrine-extensions 3.11
 */
class SoftDeleteableListener extends MappedEventSubscriber
{
    protected static $defaultHardDeleteableValidator = HardDeleteExpired::class;

    /**
     * Pre soft-delete event
     *
     * @var string
     */
    public const PRE_SOFT_DELETE = 'preSoftDelete';

    /**
     * Post soft-delete event
     *
     * @var string
     */
    public const POST_SOFT_DELETE = 'postSoftDelete';

    /**
     * Whether the postFlush event should be handled.
     */
    private bool $handlePostFlushEvent;

    /**
     * Objects soft-deleted on flush.
     *
     * @var array<object>
     */
    private array $softDeletedObjects = [];

    public function __construct(bool $handlePostFlushEvent = false)
    {
        parent::__construct();

        $this->handlePostFlushEvent = $handlePostFlushEvent;
    }

    /**
     * Pre soft-undelete event
     *
     * @var string
     */
    const PRE_SOFT_UNDELETE = 'preSoftUndelete';

    /**
     * Post soft-undelete event
     *
     * @var string
     */
    const POST_SOFT_UNDELETE = 'postSoftUndelete';

    /**
     * @return string[]
     */
    public function getSubscribedEvents()
    {
        return [
            'loadClassMetadata',
            'onFlush',
            'postFlush',
        ];
    }

    /**
     * If it's a SoftDeleteable object, update the "deletedAt" field
     * and skip the removal of the object
     *
     * @param ManagerEventArgs $args
     *
     * @phpstan-param ManagerEventArgs<ObjectManager> $args
     *
     * @return void
     */
    public function onFlush(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        /** @var EntityManagerInterface|DocumentManager $om */
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();

        // one time stamp for all deletions and undeletions
        $flushTime = new \DateTimeImmutable;

        // getScheduledDocumentDeletions
        foreach ($ea->getScheduledObjectDeletions($uow) as $object) {
            $this->softDelete($ea, $object, $flushTime);
        }

        // perhaps track undeletions? Undelete can only happen on update
        foreach ($ea->getScheduledObjectUpdates($uow) as $object) {
            $this->handleUndelete($ea, $object, $flushTime);
        }
    }

    protected function softDelete($ea, $object, \DateTimeImmutable $flushTime, $cascadeLevel = 0)
    {
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();
        $evm = $om->getEventManager();

        $meta = $om->getClassMetadata(get_class($object));
        $config = $this->getConfiguration($om, $meta->name);

        if (empty($config['softDeleteable'])) {
            return;
        }

        $fieldName = $config['fieldName'];
        // $reflProp = $meta->getReflectionProperty($fieldName);
        $propAcc = $meta->getPropertyAccessor($fieldName);
        // $oldValue = $reflProp->getValue($object);
        $oldValue = $propAcc->getValue($object);

        if ($cascadeLevel > 0 && !empty($oldValue)) {
            // don't cascade soft-delete to already soft-deleted entities
            return;
        }

        foreach (($config['cascadeDelete']??[]) as $cascadeField) {
            // $association = $meta->getReflectionProperty($cascadeField)->getValue($object);
            $association = $meta->getPropertyAccessor($cascadeField)->getValue($object);
            if ($meta->isCollectionValuedAssociation($cascadeField)) {
                $collection = $association;
            } else if (!empty($association)) {
                $collection = [ $association ];
            } else {
                $collection = [];
            }
            foreach ($collection as $softDeleteable) {
                $this->softDelete($ea, $softDeleteable, $flushTime, $cascadeLevel + 1);
            }
        }

        if (!$uow->isScheduledForDelete($object)) {
            $uow->remove($object);
        }

        if (!empty($config['hardDelete'])) {
            // give way to hard-deletion if appropriate
            $evaluator = $config['hardDelete'] === true
                       ? self::$defaultHardDeleteableValidator
                       : $config['hardDelete'];
            $hardDelete = false;
            if (method_exists($object, $evaluator) && $object->$evaluator()) {
                $hardDelete = true;
            } else if (!empty($evaluator)) {
                $evaluator = new $evaluator($ea);
                $hardDelete = $evaluator->hardDeleteAllowed($object, $config);
            }
            if ($hardDelete) {
                return;
            }
        }

        if ($evm->hasListeners(self::PRE_SOFT_DELETE)) {
            // @todo: in the next major remove check and only instantiate the event
            $preSoftDeleteEventArgs = $this->hasToDispatchNewEvent($om, $evm, self::PRE_SOFT_DELETE, PreSoftDeleteEventArgs::class)
                ? new PreSoftDeleteEventArgs($object, $om)
                : $ea->createLifecycleEventArgsInstance($object, $om);

            $evm->dispatchEvent(
                self::PRE_SOFT_DELETE,
                $preSoftDeleteEventArgs
            );
        }

        $date = $ea->getDateValue($meta, $fieldName, $flushTime);
        // $reflProp->setValue($object, $date);
        $propAcc->setValue($object, $date);

        $om->persist($object); // undo delete

        $uow->propertyChanged($object, $fieldName, $oldValue, $date);
        if ($uow instanceof MongoDBUnitOfWork && !method_exists($uow, 'scheduleExtraUpdate')) {
            $ea->recomputeSingleObjectChangeSet($uow, $meta, $object);
        } else {
            $uow->scheduleExtraUpdate($object, [
                $fieldName=> [$oldValue, $date],
            ]);
        }

        if ($evm->hasListeners(self::POST_SOFT_DELETE)) {
            // @todo: in the next major remove check and only instantiate the event
            $postSoftDeleteEventArgs = $this->hasToDispatchNewEvent($om, $evm, self::POST_SOFT_DELETE, PostSoftDeleteEventArgs::class)
                ? new PostSoftDeleteEventArgs($object, $om)
                : $ea->createLifecycleEventArgsInstance($object, $om);

            $evm->dispatchEvent(
                self::POST_SOFT_DELETE,
                $postSoftDeleteEventArgs
            );
        }

        if ($this->handlePostFlushEvent) {
            $this->softDeletedObjects[] = $object;
        }
    }

    protected function handleUndelete(
        $ea,
        $object,
        \DateTimeImmutable $flushTime,
        ?\DateTimeImmutable $undeleteStart = null,
        $cascadeLevel = 0)
    {
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();
        $evm = $om->getEventManager();
        $meta = $om->getClassMetadata(get_class($object));
        $config = $this->getConfiguration($om, $meta->name);

        if (empty($config['softDeleteable'])) {
            return;
        }

        $fieldName = $config['fieldName'];

        // $reflProp = $meta->getReflectionProperty($fieldName);
        // $currentValue = $reflProp->getValue($object);
        $propAcc = $meta->getPropertyAccessor($fieldName);
        $currentValue = $propAcc->getValue($object);

        if ($cascadeLevel > 0 && !empty($currentValue)
            && $currentValue >= $ea->getDateValue($meta, $fieldName, $undeleteStart)
            && $currentValue < $ea->getDateValue($meta, $fieldName, $flushTime)) {

            // cascade undelete if soft-deletion was later than $undeleteStart
            // $reflProp->setValue($object, null);
            $propAcc->setValue($object, null);
            $uow->propertyChanged($object, $fieldName, $currentValue, null);
            if ($uow instanceof MongoDBUnitOfWork) {
                $ea->recomputeSingleObjectChangeSet($uow, $meta, $object);
            } else {
                $uow->scheduleExtraUpdate($object, [
                    $fieldName=> [$currentValue, null],
                ]);
            }
            $currentValue = null;
        }

        $changeSet = $ea->getObjectChangeSet($uow, $object);
        if (!isset($changeSet[$fieldName])) {
            return;
        }

        $oldValue = $changeSet[$fieldName][0];

        if (!empty($oldValue) && empty($currentValue)) {

            // fake old date-stamp and call pre-undelete handler
            // $reflProp->setValue($object, $oldValue);
            $propAcc->setValue($object, $oldValue);

            if ($evm->hasListeners(self::PRE_SOFT_UNDELETE)) {
                // @todo: in the next major remove check and only instantiate the event
                $preSoftUnDeleteEventArgs = $this->hasToDispatchNewEvent($om, $evm, self::PRE_SOFT_UNDELETE, PreSoftUnDeleteEventArgs::class)
                    ? new PreSoftUnDeleteEventArgs($object, $om)
                    : $ea->createLifecycleEventArgsInstance($object, $om);

                $evm->dispatchEvent(
                    self::PRE_SOFT_DELETE,
                    $preSoftUnDeleteEventArgs
                );
            }

            if (!empty($config['cascadeUndelete'])) {

                if ($cascadeLevel == 0) {
                    if (!($oldValue instanceof \DateTimeInterface)) {
                        $undeleteStart = (new \DateTimeImmutable)->setTimestamp($oldValue);
                    } else {
                        $undeleteStart = \DateTimeImmutable::createFromFormat('U.u', $oldValue->format('U.u'));
                    }
                }

                foreach ($config['cascadeUndelete'] as $cascadeField) {
                    // $association = $meta->getReflectionProperty($cascadeField)->getValue($object);
                    $association = $meta->getPropertyAccessor($cascadeField)->getValue($object);
                    if ($meta->isCollectionValuedAssociation($cascadeField)) {
                        $collection = $association;
                    } else {
                        $collection = [ $association ];
                    }
                    foreach ($collection as $softDeleteable) {
                        $this->handleUndelete($ea, $softDeleteable, $flushTime, $undeleteStart, $cascadeLevel + 1);
                    }
                }
            }

            // restore new value and call post-undelete handler
            // $reflProp->setValue($object, $currentValue);
            $propAcc->setValue($object, $currentValue);

            if ($evm->hasListeners(self::POST_SOFT_UNDELETE)) {
                // @todo: in the next major remove check and only instantiate the event
                $postSoftUnDeleteEventArgs = $this->hasToDispatchNewEvent($om, $evm, self::POST_SOFT_UNDELETE, PostSoftUnDeleteEventArgs::class)
                    ? new PostSoftUnDeleteEventArgs($object, $om)
                    : $ea->createLifecycleEventArgsInstance($object, $om);

                $evm->dispatchEvent(
                    self::POST_SOFT_UNDELETE,
                    $postSoftUnDeleteEventArgs
                );
            }
        }
    }

    /**
     * Detach soft-deleted objects from object manager.
     *
     * @return void
     */
    public function postFlush(EventArgs $args)
    {
        if (!$this->handlePostFlushEvent) {
            return;
        }

        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        foreach ($this->softDeletedObjects as $index => $object) {
            $om->detach($object);
            unset($this->softDeletedObjects[$index]);
        }
    }

    /**
     * Maps additional metadata
     *
     * @param LoadClassMetadataEventArgs $eventArgs
     *
     * @phpstan-param LoadClassMetadataEventArgs<ClassMetadata<object>, ObjectManager> $eventArgs
     *
     * @return void
     */
    public function loadClassMetadata(EventArgs $eventArgs)
    {
        $this->loadMetadataForObjectClass($eventArgs->getObjectManager(), $eventArgs->getClassMetadata());
    }

    public function setHandlePostFlushEvent(bool $handlePostFlushEvent): void
    {
        $this->handlePostFlushEvent = $handlePostFlushEvent;
    }

    public function shouldHandlePostFlushEvent(): bool
    {
        return $this->handlePostFlushEvent;
    }

    protected function getNamespace()
    {
        return __NAMESPACE__;
    }

    /** @param class-string $eventClass */
    private function hasToDispatchNewEvent(ObjectManager $objectManager, EventManager $eventManager, string $eventName, string $eventClass): bool
    {
        if ($objectManager instanceof EntityManagerInterface && !class_exists(LifecycleEventArgs::class)) {
            return true;
        }

        foreach ($eventManager->getListeners($eventName) as $listener) {
            $reflMethod = new \ReflectionMethod($listener, $eventName);

            $parameters = $reflMethod->getParameters();

            if (
                1 !== count($parameters)
                || !$parameters[0]->hasType()
                || !$parameters[0]->getType() instanceof \ReflectionNamedType
                || $eventClass !== $parameters[0]->getType()->getName()
            ) {
                Deprecation::trigger(
                    'gedmo/doctrine-extensions',
                    'https://github.com/doctrine-extensions/DoctrineExtensions/pull/2649',
                    'Type-hinting to something different than "%s" in "%s::%s()" is deprecated.',
                    $eventClass,
                    get_class($listener),
                    $reflMethod->getName()
                );

                return false;
            }
        }

        return true;
    }
}
