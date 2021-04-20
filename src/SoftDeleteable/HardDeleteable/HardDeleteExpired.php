<?php

namespace Gedmo\SoftDeleteable\HardDeleteable;

use Gedmo\Mapping\Event\AdapterInterface;

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
    $reflProp = $meta->getReflectionProperty($fieldName);
    $oldValue = $reflProp->getValue($object);

    if (empty($oldValue)) {
      return false;
    }

    $now = $ea->getDateValue($meta, $fieldName);

    return $oldValue <= $now;
  }

}
