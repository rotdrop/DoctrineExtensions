<?php

namespace Gedmo\Mapping\Annotation;

use Doctrine\Common\Annotations\Annotation;

/**
 * Group annotation for SoftDeleteable extension
 *
 * @author Gustavo Falco <comfortablynumb84@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 *
 * @Annotation
 * @Target("CLASS")
 */
final class SoftDeleteable extends Annotation
{
    /** @var string */
    public $fieldName = 'deletedAt';

    /** @var bool */
    public $timeAware = false;

    /**
     * @var bool|string
     * The "decider" which determines if hard-deletion is allowed.
     *
     * - an entity method returning a boolean. If the return value is
     *   true then hard-deletion is allowed.
     *
     * - a class name satisfying the
     *   \Gedmo\SoftDeleteable\HardDeleteablex\HardDeleteableInterface, in
     *   particular implementing the hardDeleteAllowed() method.
     *
     * - true Use the default validator class
     *   \Gedmo\SoftDeleteable\HardDeleteable\HardDeleteExpired which
     *   leads to hard-deletion if the time-stamp of an already
     *   soft-deleted entity is in the past.
     */
    public $hardDelete = true;
}
