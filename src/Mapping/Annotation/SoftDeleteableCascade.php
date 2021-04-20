<?php

namespace Gedmo\Mapping\Annotation;

use Doctrine\Common\Annotations\Annotation;

/**
 * Group annotation for SoftDeleteable extension. Cascade options.
 *
 * @Annotation
 * @Target("PROPERTY")
 */
final class SoftDeleteableCascade extends Annotation
{
    /** @var bool */
    public $delete = true;

    /** @var bool */
    public $undelete = true;
}
