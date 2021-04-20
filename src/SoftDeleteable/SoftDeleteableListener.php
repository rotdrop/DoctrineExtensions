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
        $evm = $om->getEventManager();

        //getScheduledDocumentDeletions
        foreach ($ea->getScheduledObjectDeletions($uow) as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            $config = $this->getConfiguration($om, $meta->name);

            if (isset($config['softDeleteable']) && $config['softDeleteable']) {
                $reflProp = $meta->getReflectionProperty($config['fieldName']);
                $oldValue = $reflProp->getValue($object);
                $date = $ea->getDateValue($meta, $config['fieldName']);

                // Remove `$oldValue instanceof \DateTime` check when PHP version is bumped to >=5.5
                if (isset($config['hardDelete']) && $config['hardDelete'] && ($oldValue instanceof \DateTime || $oldValue instanceof \DateTimeInterface) && $oldValue <= $date) {
                    continue; // want to hard delete
                }

                $evm->dispatchEvent(
                    self::PRE_SOFT_DELETE,
                    $ea->createLifecycleEventArgsInstance($object, $om)
                );

                $reflProp->setValue($object, $date);

                $om->persist($object);
                $uow->propertyChanged($object, $config['fieldName'], $oldValue, $date);
                if ($uow instanceof MongoDBUnitOfWork && !method_exists($uow, 'scheduleExtraUpdate')) {
                    $ea->recomputeSingleObjectChangeSet($uow, $meta, $object);
                } else {
                    $uow->scheduleExtraUpdate($object, [
                        $config['fieldName'] => [$oldValue, $date],
                    ]);
                }

                $evm->dispatchEvent(
                    self::POST_SOFT_DELETE,
                    $ea->createLifecycleEventArgsInstance($object, $om)
                );
            }
        }

        // perhaps track undeletions? Undelete can only happen on update
        foreach ($ea->getScheduledObjectUpdates($uow) as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            $config = $this->getConfiguration($om, $meta->name);

            if (!isset($config['softDeleteable']) || !$config['softDeleteable']) {
                continue;
            }

            $fieldName = $config['fieldName'];
            $changeSet = $ea->getObjectChangeSet($uow, $object);
            if (!isset($changeSet[$fieldName])) {
                continue;
            }

            $oldValue = $changeSet[$fieldName][0];

            $reflProp = $meta->getReflectionProperty($fieldName);
            $newValue = $reflProp->getValue($object);
            if (!empty($oldValue) && empty($newValue)) {

                // fake old date-stamp and call pre-undelete handler
                $reflProp->setValue($object, $oldValue);
                $evm->dispatchEvent(
                    self::PRE_SOFT_UNDELETE,
                    $ea->createLifecycleEventArgsInstance($object, $om)
                );

                // restore new value and call post-undlete handler
                $reflProp->setValue($object, $newValue);

                $evm->dispatchEvent(
                    self::POST_SOFT_UNDELETE,
                    $ea->createLifecycleEventArgsInstance($object, $om)
                );
            }
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
