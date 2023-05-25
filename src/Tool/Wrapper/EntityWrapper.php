<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Tool\Wrapper;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Proxy as PersistenceProxy;
use Doctrine\ORM\Utility\IdentifierFlattener;

/**
 * Wraps entity or proxy for more convenient
 * manipulation
 *
 * @phpstan-extends AbstractWrapper<ClassMetadata>
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 *
 * @final since gedmo/doctrine-extensions 3.11
 */
class EntityWrapper extends AbstractWrapper
{
    /**
     * Entity identifier
     *
     * @var array<string, mixed>|null
     */
    private $identifier;

    /**
     * True if entity or proxy is loaded
     */
    private bool $initialized = false;

    /**
     * Wrap entity
     *
     * @param object $entity
     */
    public function __construct($entity, EntityManagerInterface $em)
    {
        $this->om = $em;
        $this->object = $entity;
        $this->meta = $em->getClassMetadata(get_class($this->object));
    }

    public function getPropertyValue($property)
    {
        $this->initialize();

        return $this->meta->getReflectionProperty($property)->getValue($this->object);
    }

    public function setPropertyValue($property, $value)
    {
        $this->initialize();
        $this->meta->getReflectionProperty($property)->setValue($this->object, $value);

        return $this;
    }

    public function hasValidIdentifier()
    {
        return null !== $this->getIdentifier();
    }

    public function getRootObjectName()
    {
        return $this->meta->rootEntityName;
    }

    /**
     * @param bool $flatten
     */
    public function getIdentifier($single = true, $flatten = false)
    {
        $flatten = 1 < \func_num_args() && true === func_get_arg(1);
        if (null === $this->identifier) {
            $uow = $this->om->getUnitOfWork();
            if ($uow->isInIdentityMap($this->object)) {
                $this->identifier = $uow->getEntityIdentifier($this->object);
            } else {
                // mimic the code from the UOW in order to support foreign ids
                $this->identifier = $this->meta->getIdentifierValues($this->object);
                if ($this->meta->containsForeignIdentifier || $this->meta->containsEnumIdentifier) {
                    $this->identifier = $this->flattenIdentifier($this->meta, $this->identifier);
                }
            }
            if (empty($this->identifier) || count($this->identifier) != count(array_filter($this->identifier))) {
                $this->identifier = null;
            }
        }
        if (null !== $this->identifier) {
            if ($single) {
                return reset($this->identifier);
            }
            if ($flatten) {
                // "flatten" foreign keys
                // compute the "hash", which in the current UOW is a simple implode, separated with spaces
                return implode(' ', (array)$this->identifier);
            }
        }

        return null;
    }

    public function isEmbeddedAssociation($field)
    {
        return false;
    }

    /**
     * Initialize the entity if it is proxy
     * required when is detached or not initialized
     *
     * @return void
     */
    protected function initialize()
    {
        if (!$this->initialized) {
            if ($this->object instanceof PersistenceProxy) {
                if (!$this->object->__isInitialized()) {
                    $this->object->__load();
                }
            }
        }
    }

    /**
     * Convert foreign identifiers into scalar foreign key values to avoid
     * object to string conversion failures. This is a copy of the
     * corresponding ORM utility method but it also checks for empty
     * identifier values. If an invalid (i.e. empty) identifier value is
     * detected, then an empty array is returned.
     *
     * @param mixed[] $id
     *
     * @return mixed[]
     * @psalm-return array<string, mixed>
     */
    private function flattenIdentifier(ClassMetadata $class, array $id): array
    {
        if (count($id) != count(array_filter($id))) {
            return [];
        }
        $flatId = [];

        $unitOfWork = $this->om->getUnitOfWork();
        $metadataFactory = $this->om->getMetadataFactory();
        foreach ($class->identifier as $field) {
            if (isset($class->associationMappings[$field]) && isset($id[$field])) {
                $targetEntity = $class->associationMappings[$field]['targetEntity'];
                $fieldValue = $id[$field];
                if (is_object($fieldValue) && $fieldValue instanceof $targetEntity) {
                    $targetClassMetadata = $metadataFactory->getMetadataFor($targetEntity);
                    assert($targetClassMetadata instanceof ClassMetadata);

                    if ($unitOfWork->isInIdentityMap($id[$field])) {
                        $associatedId = $this->flattenIdentifier($targetClassMetadata, $unitOfWork->getEntityIdentifier($id[$field]));
                    } else {
                        $associatedId = $this->flattenIdentifier($targetClassMetadata, $targetClassMetadata->getIdentifierValues($id[$field]));
                    }
                    if (empty($associatedId)) {
                        return []; // abort
                    }
                    $flatId[$field] = implode(' ', $associatedId);
                } else {
                    // assume that then $id[$field] is the single identifier
                    assert(count($class->associationMappings[$field]['joinColumns']) == 1);
                    $flatId[$field] = $fieldValue;
                }
            } elseif (isset($class->associationMappings[$field])) {
                $associatedId = [];

                foreach ($class->associationMappings[$field]['joinColumns'] as $joinColumn) {
                    $associatedId[] = $id[$joinColumn['name']];
                }

                $flatId[$field] = implode(' ', $associatedId);
            } else if (isset($id[$field])) {
                if ($id[$field] instanceof BackedEnum) {
                    $flatId[$field] = $id[$field]->value;
                } else {
                    $flatId[$field] = $id[$field];
                }
            }
        }

        return $flatId;
    }
}
