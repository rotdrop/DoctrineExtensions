<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\SoftDeleteable\HardDeleteable;

use Gedmo\Mapping\Event\AdapterInterface;

/** Overidable "decider" on final deleting. See SoftDeleteableListener. */
class HardDeleteExpired implements HardDeleteableInterface
{
    /** @var AdapterInterface */
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
        $meta = $om->getClassMetadata(get_class($object));
        $fieldName = $config['fieldName'];
        // $reflProp = $meta->getReflectionProperty($fieldName);
        // $oldValue = $reflProp->getValue($object);
        $oldValue = $meta->getPropertyAccessor($fieldName)->getValue($object);

        if (empty($oldValue)) {
            return false;
        }

        $now = $this->eventAdapter->getDateValue($meta, $fieldName);

        return $oldValue <= $now;
    }
}
