<?php

namespace Gedmo\SoftDeleteable;

use Doctrine\Common\EventArgs;
use Doctrine\ODM\MongoDB\UnitOfWork as MongoDBUnitOfWork;
use Gedmo\Mapping\MappedEventSubscriber;
use Gedmo\SoftDeleteable\HardDeleteable\HardDeleteExpired;

/**
 * SoftDeleteable listener
 *
 * @author Gustavo Falco <comfortablynumb84@gmail.com>
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class SoftDeleteableListener extends MappedEventSubscriber
{
    static protected $defaultHardDeleteableValidator = HardDeleteExpired::class;

    /**
     * Pre soft-delete event
     *
     * @var string
     */
    const PRE_SOFT_DELETE = 'preSoftDelete';

    /**
     * Post soft-delete event
     *
     * @var string
     */
    const POST_SOFT_DELETE = 'postSoftDelete';

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
     * {@inheritdoc}
     */
    public function getSubscribedEvents()
    {
        return [
            'loadClassMetadata',
            'onFlush',
        ];
    }

    /**
     * If it's a SoftDeleteable object, update the "deletedAt" field
     * and skip the removal of the object
     *
     * @return void
     */
    public function onFlush(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();

        // one time stamp for all deletions and undeletions
        $flushTime = new \DateTimeImmutable;

        //getScheduledDocumentDeletions
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
        $reflProp = $meta->getReflectionProperty($fieldName);
        $oldValue = $reflProp->getValue($object);

        if ($cascadeLevel > 0 && !empty($oldValue)) {
            // don't cascade soft-delete to already soft-deleted entities
            return;
        }

        foreach ($config['cascadeDelete'] as $cascadeField) {
            $association = $meta->getReflectionProperty($cascadeField)->getValue($object);
            if ($meta->isCollectionValuedAssociation($cascadeField)) {
                $collection = $association;
            } else {
                $collection = [ $association ];
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

        $evm->dispatchEvent(
            self::PRE_SOFT_DELETE,
            $ea->createLifecycleEventArgsInstance($object, $om)
        );

        $date = $ea->getDateValue($meta, $fieldName, $flushTime);
        $reflProp->setValue($object, $date);

        $om->persist($object); // undo delete

        $uow->propertyChanged($object, $fieldName, $oldValue, $date);
        if ($uow instanceof MongoDBUnitOfWork && !method_exists($uow, 'scheduleExtraUpdate')) {
            $ea->recomputeSingleObjectChangeSet($uow, $meta, $object);
        } else {
            $uow->scheduleExtraUpdate($object, [
                $fieldName=> [$oldValue, $date],
            ]);
        }

        $evm->dispatchEvent(
            self::POST_SOFT_DELETE,
            $ea->createLifecycleEventArgsInstance($object, $om)
        );
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

        $reflProp = $meta->getReflectionProperty($fieldName);
        $currentValue = $reflProp->getValue($object);

        if ($cascadeLevel > 0 && !empty($currentValue)
            && $currentValue >= $ea->getDateValue($meta, $fieldName, $undeleteStart)
            && $currentValue < $ea->getDateValue($meta, $fieldName, $flushTime)) {

            // cascade undelete if soft-deletion was later than $undeleteStart
            $reflProp->setValue($object, null);
            $uow->propertyChanged($object, $fieldName, $currentValue, null);
            if ($uow instanceof MongoDBUnitOfWork && !method_exists($uow, 'scheduleExtraUpdate')) {
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
            $reflProp->setValue($object, $oldValue);
            $evm->dispatchEvent(
                self::PRE_SOFT_UNDELETE,
                $ea->createLifecycleEventArgsInstance($object, $om)
            );

            if (!empty($config['cascadeUndelete'])) {

                if ($cascadeLevel == 0) {
                    if (!($oldValue instanceof \DateTimeInterface)) {
                        $undeleteStart = (new \DateTimeImmutable)->setTimestamp($oldValue);
                    } else {
                        $undeleteStart = \DateTimeImmutable::createFromFormat('U.u', $oldValue->format('U.u'));
                    }
                }

                foreach ($config['cascadeUndelete'] as $cascadeField) {
                    $association = $meta->getReflectionProperty($cascadeField)->getValue($object);
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
            $reflProp->setValue($object, $currentValue);

            $evm->dispatchEvent(
                self::POST_SOFT_UNDELETE,
                $ea->createLifecycleEventArgsInstance($object, $om)
            );
        }
    }

    /**
     * Maps additional metadata
     *
     * @return void
     */
    public function loadClassMetadata(EventArgs $eventArgs)
    {
        $ea = $this->getEventAdapter($eventArgs);
        $this->loadMetadataForObjectClass($ea->getObjectManager(), $eventArgs->getClassMetadata());
    }

    /**
     * {@inheritdoc}
     */
    protected function getNamespace()
    {
        return __NAMESPACE__;
    }
}
