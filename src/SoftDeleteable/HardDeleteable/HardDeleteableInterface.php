<?php

namespace Gedmo\SoftDeleteable\HardDeleteable;

use Gedmo\Mapping\Event\AdapterInterface;

interface HardDeleteableInterface
{
  public function __construct(AdapterInterface $ea);

  /**
   * Decide whether $object may be hard deleted.
   *
   * @param mixed $object The entity in question.
   *
   * @param array $config The soft-deleteable configuration for the
   * entity-class.
   */
  public function hardDeleteAllowed($object, $config);
}
