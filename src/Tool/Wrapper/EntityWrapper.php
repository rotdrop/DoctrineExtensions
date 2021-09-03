<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Tool\Wrapper;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Proxy as PersistenceProxy;
use Doctrine\ORM\Utility\IdentifierFlattener;

/**
 * Wraps entity or proxy for more convenient
 * manipulation
 *
 * @template TObject of object
 *
 * @template-extends AbstractWrapper<ClassMetadata<TObject>, TObject, EntityManagerInterface>
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
     * Id-flattener for foreign keys, not to be mixed with flattening
     * composite ids, we need both.
     *
     * @var IdentifierFlattener
     */
    private $identifierFlattener;

    /**
     * True if entity or proxy is loaded
     */
    private bool $initialized = false;

    /**
     * Wrap entity
     *
     * @param TObject $entity
     */
    public function __construct($entity, EntityManagerInterface $em)
    {
        $this->om = $em;
        $this->object = $entity;
        $this->meta = $em->getClassMetadata(get_class($this->object));
        $this->identifierFlattener = new IdentifierFlattener($this->om->getUnitOfWork(), $this->om->getMetadataFactory());
    }

    public function getPropertyValue($property)
    {
        $this->initialize();

        return $this->meta->getFieldValue($this->object, $property);
    }

    public function setPropertyValue($property, $value)
    {
        $this->initialize();
        $this->meta->setFieldValue($this->object, $property, $value);

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
                $identifier = $this->meta->getIdentifierValues($this->object);
                $this->identifier = $this->meta->containsForeignIdentifier
                  ? $this->identifierFlattener->flattenIdentifier($this->meta, $identifier)
                  : $identifier;
            }
            if ((is_array($this->identifier) && empty($this->identifier))) {
                $this->identifier = null;
            }
        }
        if (is_array($this->identifier)) {
            if ($single) {
                return reset($this->identifier);
            }
            if ($flatten) {
                // "flatten" foreign keys
                // compute the "hash", which in the current UOW is a simple implode, separated with spaces
                return implode(' ', (array)$this->identifier);
            }
        }

        return $this->identifier;
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
        if ($this->om->isUninitializedObject($this->object)) {
            $this->om->initializeObject($this->object);
        }
    }
}
