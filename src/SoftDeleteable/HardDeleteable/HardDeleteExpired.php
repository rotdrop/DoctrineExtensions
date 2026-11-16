<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\SoftDeleteable\HardDeleteable;

use Doctrine\ODM\MongoDB\Mapping\ClassMetadata as MongoDBClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadata as ORMClassMetadata;
use Gedmo\Mapping\Event\AdapterInterface;
use Gedmo\SoftDeleteable\Mapping\Event\SoftDeleteableAdapter;

/** Overidable "decider" on final deleting. See SoftDeleteableListener. */
class HardDeleteExpired implements HardDeleteableInterface
{
    /** @var SoftDeleteableAdapter */
    protected $eventAdapter;

    /** {@inheritdoc} */
    public function __construct(AdapterInterface $ea)
    {
        $this->eventAdapter = $ea;
    }

    /** {@inheritdoc} */
    public function hardDeleteAllowed($object, $config)
    {
        $om = $this->eventAdapter->getObjectManager();
        /** @var ORMClassMetadata<object>|MongoDBClassMetadata<object> $meta */
        $meta = $om->getClassMetadata(get_class($object));
        $fieldName = $config['fieldName'];
        $oldValue = $meta->getPropertyAccessor($fieldName)->getValue($object);

        if (empty($oldValue)) {
            return false;
        }

        $now = $this->eventAdapter->getDateValue($meta, $fieldName);

        return $oldValue <= $now;
    }
}
