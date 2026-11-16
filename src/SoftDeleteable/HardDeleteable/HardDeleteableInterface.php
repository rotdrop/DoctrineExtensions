<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\SoftDeleteable\HardDeleteable;

use Gedmo\Mapping\Event\AdapterInterface;
use Gedmo\SoftDeleteable\Mapping\Event\SoftDeleteableAdapter;

interface HardDeleteableInterface
{
    /**
     * @phpstan-param SoftDeleteableAdapter $ea
     */
    public function __construct(AdapterInterface $ea);

    /**
     * Decide whether $object may be hard deleted.
     *
     * @param object               $object the entity in question
     * @param array<string, mixed> $config the soft-deleteable configuration for the
     *                                     entity-class
     *
     * @return bool
     */
    public function hardDeleteAllowed($object, $config);
}
