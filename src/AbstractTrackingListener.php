<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo;

use Doctrine\Common\EventArgs;
use Doctrine\DBAL\Types\Type;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Types\Type as TypeODM;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\Event\LoadClassMetadataEventArgs;
use Doctrine\Persistence\Event\ManagerEventArgs;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\NotifyPropertyChanged;
use Doctrine\Persistence\ObjectManager;
use Gedmo\Exception\UnexpectedValueException;
use Gedmo\Mapping\Event\AdapterInterface;
use Gedmo\Mapping\MappedEventSubscriber;
use Gedmo\Tool\Wrapper\AbstractWrapper;

/**
 * The AbstractTrackingListener provides generic functions for all listeners.
 *
 * @phpstan-template TConfig of array
 * @phpstan-template TEventAdapter of AdapterInterface
 *
 * @phpstan-extends MappedEventSubscriber<TConfig, TEventAdapter>
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 */
abstract class AbstractTrackingListener extends MappedEventSubscriber
{
    /**
     * Specifies the list of events to listen on.
     *
     * @return string[]
     */
    public function getSubscribedEvents()
    {
        return [
            'prePersist',
            'onFlush',
            'loadClassMetadata',
        ];
    }

    /**
     * Maps additional metadata for the object.
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

    /**
     * Processes object updates when the manager is flushed.
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
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();
        // check all scheduled updates
        $all = array_merge(
            $ea->getScheduledObjectInsertions($uow),
            $ea->getScheduledObjectUpdates($uow),
            $ea->getScheduledObjectDeletions($uow)
        );
        $changedObjects = []; // spl-hash => [ 'meta' => META, 'object' => OBJECT ]
        foreach ($all as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            if (!$config = $this->getConfiguration($om, $meta->getName())) {
                continue;
            }

            if ($uow->isScheduledForDelete($object)) {
                if (isset($config['delete'])) {
                    foreach ($config['delete'] as $options) {
                        $field = $options['field'];
                        $targetProperty = $options['targetProperty'] ?? null;

                        if (!$meta->hasAssociation($field) || empty($targetProperty)) {
                            continue; // timestamp make only sense if the stamp-field is inside a target entities which survives
                        }

                        $changes = $this->updateField($object, $ea, $meta, $field, $targetProperty);
                        $changedObjects = array_merge($changedObjects, $changes);
                    }
                }
                continue; // nothing further if object is about to be deleted
            }

            $changeSet = $ea->getObjectChangeSet($uow, $object);
            if ($uow->isScheduledForInsert($object) && isset($config['create'])) {
                foreach ($config['create'] as $field) {
                    if (is_array($field)) {
                        $options = $field;
                        $field = $options['field'];
                        $targetProperty = $options['targetProperty'] ?? null;
                    }
                    // Field may not exist in change set, i.e. when persisting an embedded object without a parent
                    // If we have a target-property, then always update
                    $doUpdate = !empty($targetProperty)
                        || (null === array_key_exists($field, $changeSet) ? $changeSet[$field][1] : false);
                    if ($doUpdate) { // let manual values
                        $changes = $this->updateField($object, $ea, $meta, $field, $targetProperty ?? null);
                        $changedObjects = array_merge($changedObjects, $changes);
                    }
                }
            }

            if (isset($config['update'])) {
                foreach ($config['update'] as $field) {
                    if (is_array($field)) {
                        $options = $field;
                        $field = $options['field'];
                        $targetProperty = $options['targetProperty'] ?? null;
                    }
                    $doUpdate = !empty($targetProperty)
                        || ($uow->isScheduledForInsert($object)
                            && array_key_exists($field, $changeSet)
                            && null === $changeSet[$field][1])
                        || !isset($changeSet[$field]);
                    if ($doUpdate) { // let manual values
                        $changes = $this->updateField($object, $ea, $meta, $field, $targetProperty ?? null);
                        $changedObjects = array_merge($changedObjects, $changes);
                    }
                }
            }

            if (!$uow->isScheduledForInsert($object) && isset($config['change'])) {
                foreach ($config['change'] as $options) {
                    $field = $options['field'];
                    $targetProperty = $options['targetProperty'] ?? null;
                    if (isset($changeSet[$field]) && empty($targetProperty)) {
                        continue; // value was set manually
                    }

                    if (!is_array($options['trackedField'])) {
                        $singleField = true;
                        $trackedFields = [$options['trackedField']];
                    } else {
                        $singleField = false;
                        $trackedFields = $options['trackedField'];
                    }

                    foreach ($trackedFields as $trackedField) {
                        $trackedChild = null;
                        $tracked = null;
                        $parts = explode('.', $trackedField);
                        if (isset($parts[1])) {
                            $tracked = $parts[0];
                            $trackedChild = $parts[1];
                        }

                        if (!isset($tracked) || array_key_exists($trackedField, $changeSet)) {
                            $tracked = $trackedField;
                            $trackedChild = null;
                        }

                        if (isset($changeSet[$tracked])) {
                            $changes = $changeSet[$tracked];
                            if (isset($trackedChild)) {
                                $changingObject = $changes[1];
                                if (!is_object($changingObject)) {
                                    throw new UnexpectedValueException("Field - [{$tracked}] is expected to be object in class - {$meta->getName()}");
                                }
                                $objectMeta = $om->getClassMetadata(get_class($changingObject));
                                $om->initializeObject($changingObject);
                                $value = $objectMeta->getReflectionProperty($trackedChild)->getValue($changingObject);
                            } else {
                                $value = $changes[1];
                            }

                            $configuredValues = $this->getPhpValues($options['value'], $meta->getTypeOfField($tracked), $om);

                            if (null === $configuredValues || ($singleField && in_array($value, $configuredValues, true))) {
                                $changes = $this->updateField($object, $ea, $meta, $field, $targetProperty);
                                $changedObjects = array_merge($changedObjects, $changes);
                            }
                        } else if ($meta->hasAssociation($tracked)
                                   && !$meta->isSingleValuedAssociation($tracked)
                                   && ($trackedValue = $meta->getReflectionProperty($tracked)->getValue($object))->isDirty()) {
                            // The owning-side of MANY_TO_MANY will be
                            // included in the scheduled-for-update set, but
                            // the changeset of the corresponding field will
                            // be empty.
                            $configuredValues = $this->getPhpValues($options['value'], $meta->getTypeOfField($tracked), $om);

                            // How to handle changed-to if more than one value? That would have to be defined:
                            // - one element changes to the monitored value?
                            // - all elements have changed?
                            // - trigger again if another element of the collection changes?
                            // So for the moment: DON'T.
                            if (!empty($configuredValues)) {
                                throw new UnexpectedValueException("Field - [{$tracked}] is a multivalued association, cannot detect changeset values.");
                            }
                            $changes = $this->updateField($object, $ea, $meta, $field, $targetProperty);
                        }
                    }
                }
            }
        }

        foreach ($changedObjects as $splHash => $objectAndMeta) {
            $ea->recomputeSingleObjectChangeSet($uow, $objectAndMeta['meta'], $objectAndMeta['object']);
        }
    }

    /**
     * Processes updates when an object is persisted in the manager.
     *
     * @param LifecycleEventArgs $args
     *
     * @phpstan-param LifecycleEventArgs<ObjectManager> $args
     *
     * @return void
     */
    public function prePersist(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $object = $ea->getObject();
        $meta = $om->getClassMetadata(get_class($object));
        if ($config = $this->getConfiguration($om, $meta->getName())) {
            if (isset($config['update'])) {
                foreach ($config['update'] as $field) {
                    if (is_array($field)) {
                        $options = $field;
                        $field = $options['field'];
                        $targetProperty = $options['targetProperty'] ?? null;
                    }
                    if (null === $meta->getReflectionProperty($field)->getValue($object)) { // let manual values
                        $this->updateField($object, $ea, $meta, $field, $targetProperty ?? null);
                    }
                }
            }
            if (isset($config['create'])) {
                foreach ($config['create'] as $field) {
                    if (is_array($field)) {
                        $options = $field;
                        $field = $options['field'];
                        $targetProperty = $options['targetProperty'] ?? null;
                    }
                    if (null === $meta->getReflectionProperty($field)->getValue($object)) { // let manual values
                        $this->updateField($object, $ea, $meta, $field, $targetProperty ?? null);
                    }
                }
            }
        }
    }

    /**
     * Get the value for an updated field.
     *
     * @param ClassMetadata    $meta
     * @param string           $field
     * @param AdapterInterface $eventAdapter
     *
     * @return mixed
     */
    abstract protected function getFieldValue($meta, $field, $eventAdapter);

    /**
     * Updates a field.
     *
     * @param object           $object
     * @param AdapterInterface $eventAdapter
     * @param ClassMetadata    $meta
     * @param string           $field
     * @param string           $targetField
     *
     * @return array
     */
    protected function updateField($object, $eventAdapter, $meta, $field, $targetField)
    {
        $om = $eventAdapter->getObjectManager();
        $uow = $om->getUnitOfWork();

        $property = $meta->getReflectionProperty($field);

        if (empty($targetField)) {
            $newValue = $this->getFieldValue($meta, $field, $eventAdapter);
            $targetField = $field;
            $targetObject = $object;
            $targetMeta = $meta;
        }

        if ($meta->hasAssociation($field)) {
            $targetEntityClass = $meta->associationMappings[$field]['targetEntity'];
            if (!empty($targetField)) {
                $targetMeta = $om->getClassMetadata($targetEntityClass);
                $newValue = $this->getFieldValue($targetMeta, $targetField, $eventAdapter);
                $targetObject = $property->getValue($object);
            } else {
                if (($newValue instanceof $targetEntityClass) && !$om->contains($newValue)) {
                    // Check to persist only when the object isn't already managed, always persists for MongoDB
                    if (!($uow instanceof UnitOfWork) || UnitOfWork::STATE_MANAGED !== $uow->getEntityState($newValue)) {
                        $om->persist($newValue);
                    }
                }
            }
        }

        if (!$meta->hasAssociation($field) || $meta->isSingleValuedAssociation($field)) {
            $collection = empty($targetObject) ? [] : [ $targetObject ];
        } else {
            $collection = $targetObject;
        }

        $changed = [];
        foreach ($collection as $targetObject) {
            if ($uow->getEntityState($targetObject) != UnitOfWork::STATE_MANAGED) {
                continue;
            }
            $wrappedTarget = AbstractWrapper::wrap($targetObject, $om);
            $oldValue = $wrappedTarget->getPropertyValue($targetField);
            $eventAdapter->setOriginalObjectProperty($uow, $targetObject, $targetField, $oldValue);
            $wrappedTarget->setPropertyValue($targetField, $newValue);
            if ($targetObject instanceof NotifyPropertyChanged) {
                if (empty($oldValue)) {
                    $oldValue = $wrappedTarget->getPropertyValue($targetField);
                }
                $uow = $eventAdapter->getObjectManager()->getUnitOfWork();
                $uow->propertyChanged($targetObject, $targetField, $oldValue, $newValue);
            }
            $changed[spl_object_hash($targetObject)] = [ 'object' => $targetObject, 'meta' => $targetMeta ];
        }

        // return changes in order to be able to recompute change-sets.
        return $changed;
    }

    /**
     * @param mixed $values
     *
     * @return mixed[]|null
     */
    private function getPhpValues($values, ?string $type, ObjectManager $om): ?array
    {
        if (null === $values) {
            return null;
        }

        if (!is_array($values)) {
            $values = [$values];
        }

        if (null !== $type) {
            foreach ($values as $i => $value) {
                if ($om instanceof DocumentManager) {
                    if (TypeODM::hasType($type)) {
                        $values[$i] = TypeODM::getType($type)
                            ->convertToPHPValue($value);
                    } else {
                        $values[$i] = $value;
                    }
                } elseif ($om instanceof EntityManagerInterface) {
                    if (Type::hasType($type)) {
                        $values[$i] = $om->getConnection()
                            ->convertToPHPValue($value, $type);
                    } else {
                        $values[$i] = $value;
                    }
                }
            }
        }

        return $values;
    }
}
