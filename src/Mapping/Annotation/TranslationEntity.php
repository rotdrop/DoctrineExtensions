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
 * TranslationEntity annotation for Translatable behavioral extension
 *
 * @Annotation
 * @NamedArgumentConstructor
 * @Target("CLASS")
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class TranslationEntity implements GedmoAnnotation
{
    use ForwardCompatibilityTrait;

    /** @var string @Required */
    public $class;

    /**
     * @var array
     *
     * Mapping of "complicated" columns to strings. This can be used by the
     * tree-walker where the type-to-php-value conversion cannot be used. The
     * value can be a valid SQL expression. The actual quoted column name is
     * replaced by PHP sprintf(FMT, COL_NAME)
     *
     * Example: [ 'binary_uuid_column' => 'BIN2UUID(%s)' ],
     */
    public $idToString;

    public function __construct(array $data = [], string $class = '', array $idToString = [])
    {
        if ([] !== $data) {
            @trigger_error(sprintf(
                'Passing an array as first argument to "%s()" is deprecated. Use named arguments instead.',
                __METHOD__
            ), E_USER_DEPRECATED);

            $args = func_get_args();

            $this->class = $this->getAttributeValue($data, 'class', $args, 1, $class);
            $this->idToString = $this->getAttributeValue($data, 'idToString', $args, 1, $idToString);

            return;
        }

        $this->class = $class;
        $this->idToString = $idToString;
    }
}
