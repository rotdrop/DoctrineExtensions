<?php

namespace Gedmo\Tool\Wrapper;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Proxy\Proxy;
use Doctrine\ORM\Utility\IdentifierFlattener;

/**
 * Wraps entity or proxy for more convenient
 * manipulation
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class EntityWrapper extends AbstractWrapper
{
    /**
     * Entity identifier
     *
     * @var array
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
     *
     * @var bool
     */
    private $initialized = false;

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
        $this->identifierFlattener = new IdentifierFlattener($this->om->getUnitOfWork(), $this->om->getMetadataFactory());
    }

    /**
     * {@inheritdoc}
     */
    public function getPropertyValue($property)
    {
        $this->initialize();

        return $this->meta->getReflectionProperty($property)->getValue($this->object);
    }

    /**
     * {@inheritdoc}
     */
    public function setPropertyValue($property, $value)
    {
        $this->initialize();
        $this->meta->getReflectionProperty($property)->setValue($this->object, $value);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function hasValidIdentifier()
    {
        return null !== $this->getIdentifier();
    }

    /**
     * {@inheritdoc}
     */
    public function getRootObjectName()
    {
        return $this->meta->rootEntityName;
    }

    /**
     * {@inheritdoc}
     */
    public function getIdentifier($single = true, $flatten = false)
    {
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

    /**
     * Initialize the entity if it is proxy
     * required when is detached or not initialized
     */
    protected function initialize()
    {
        if (!$this->initialized) {
            if ($this->object instanceof Proxy) {
                if (!$this->object->__isInitialized__) {
                    $this->object->__load();
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isEmbeddedAssociation($field)
    {
        return false;
    }
}
