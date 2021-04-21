<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Mapping\Annotation;

use Attribute;
use Doctrine\Common\Annotations\Annotation;
use Gedmo\Mapping\Annotation\Annotation as GedmoAnnotation;

/**
 * Group annotation for SoftDeleteable extension
 *
 * @author Gustavo Falco <comfortablynumb84@gmail.com>
 *
 * @Annotation
 * @NamedArgumentConstructor
 * @Target("CLASS")
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class SoftDeleteable implements GedmoAnnotation
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

    public function __construct(array $data = [], string $fieldName = 'deletedAt', bool $timeAware = false, bool $hardDelete = true)
    {
        if ([] !== $data) {
            @trigger_error(sprintf(
                'Passing an array as first argument to "%s()" is deprecated. Use named arguments instead.',
                __METHOD__
            ), E_USER_DEPRECATED);
        }

        $this->fieldName = $data['fieldName'] ?? $fieldName;
        $this->timeAware = $data['timeAware'] ?? $timeAware;
        $this->hardDelete = $data['hardDelete'] ?? $hardDelete;
    }
}
