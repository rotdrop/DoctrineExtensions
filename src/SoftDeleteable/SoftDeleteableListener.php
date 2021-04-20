<?php

namespace Gedmo\SoftDeleteable;

use Doctrine\Common\EventArgs;
use Doctrine\ODM\MongoDB\UnitOfWork as MongoDBUnitOfWork;
use Gedmo\Mapping\MappedEventSubscriber;

/**
 * SoftDeleteable listener
 *
 * @author Gustavo Falco <comfortablynumb84@gmail.com>
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class SoftDeleteableListener extends MappedEventSubscriber
{
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

        //getScheduledDocumentDeletions
        foreach ($ea->getScheduledObjectDeletions($uow) as $object) {
            $this->softDelete($ea, object);
        }

        // perhaps track undeletions? Undelete can only happen on update
        foreach ($ea->getScheduledObjectUpdates($uow) as $object) {
            $this->softUndelete($ea, $object, null);
        }
    }

    protected function handleUndelete($ea, $object, $undeleteStart = null)
    {
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();
        $evm = $om->getEventManager();
        $meta = $om->getClassMetadata(get_class($object));
        $config = $this->getConfiguration($om, $meta->name);

        if (!isset($config['softDeleteable']) || !$config['softDeleteable']) {
            return;
        }

        $fieldName = $config['fieldName'];

        $reflProp = $meta->getReflectionProperty($fieldName);
        $currentValue = $reflProp->getValue($object);

        if (!empty($undeleteStart) && !empty($currentValue)
            && $currentValue > $undeleteStart) {
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

            foreach ($config['cascadeDelete'] as $cascadeField) {
                $association = $meta->getReflectionProperty($cascadeField)->getValue($object);
                if ($meta->isCollectionValuedAssociation($cascadeField)) {
                    $collection = $association;
                } else {
                    $collection = [ $association ];
                }
                foreach ($collection as $softDeleteable) {
                    $this->softUndelete($ea, $softDeleteable, $oldValue);
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

    protected function softDelete($ea, $object)
    {
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();
        $evm = $om->getEventManager();

        $meta = $om->getClassMetadata(get_class($object));
        $config = $this->getConfiguration($om, $meta->name);

        if (isset($config['softDeleteable']) && $config['softDeleteable']) {
            $fieldName = $config['fieldName'];
            $reflProp = $meta->getReflectionProperty($fieldName);
            $oldValue = $reflProp->getValue($object);
            $date = $ea->getDateValue($meta, $config['fieldName']);

            // Remove `$oldValue instanceof \DateTime` check when PHP version is bumped to >=5.5
            if (isset($config['hardDelete']) && $config['hardDelete'] && ($oldValue instanceof \DateTime || $oldValue instanceof \DateTimeInterface) && $oldValue <= $date) {
                return; // want to hard delete or skip
            }

            $evm->dispatchEvent(
                self::PRE_SOFT_DELETE,
                $ea->createLifecycleEventArgsInstance($object, $om)
            );

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

            foreach ($config['cascadeDelete'] as $cascadeField) {
                $association = $meta->getReflectionProperty($cascadeField)->getValue($object);
                if ($meta->isCollectionValuedAssociation($cascadeField)) {
                    $collection = $association;
                } else {
                    $collection = [ $association ];
                }
                foreach ($collection as $softDeleteable) {
                    $this->softDelete($ea, $softDeleteable);
                }
            }

            $evm->dispatchEvent(
                self::POST_SOFT_DELETE,
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
