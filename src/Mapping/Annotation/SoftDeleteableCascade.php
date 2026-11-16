<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Mapping\Annotation;

use Doctrine\Common\Annotations\Annotation;

/**
 * Group annotation for SoftDeleteable extension. Cascade options.
 *
 * @Annotation
 *
 * @Target("PROPERTY")
 */
final class SoftDeleteableCascade extends Annotation
{
    /** @var bool
     * Cascade soft delete operation. This should not be necessary, or
     * better: can equivalently already achieved with
     * orphanRemoval=true and cascade=delete.
     */
    public $delete = true;

    /** @var bool
     *
     * Cascade undelete operations where the deleted-at timestamp is
     * set to null. Soft-deleted entities with timestamp between now
     * and the soft-deletion stamp of the current entity are undeleted
     * if the current entity is undeleted.
     */
    public $undelete = true;
}
