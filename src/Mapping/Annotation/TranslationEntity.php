<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Mapping\Annotation;

use Doctrine\Common\Annotations\Annotation;
use Doctrine\Deprecations\Deprecation;
use Gedmo\Mapping\Annotation\Annotation as GedmoAnnotation;

/**
 * TranslationEntity annotation for Translatable behavioral extension
 *
 * @Annotation
 *
 * @NamedArgumentConstructor
 *
 * @Target("CLASS")
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class TranslationEntity implements GedmoAnnotation
{
    use ForwardCompatibilityTrait;

    /** @Required */
    public string $class;

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

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [], string $class = '', array $idToString = [])
    {
        if ([] !== $data) {
            Deprecation::trigger(
                'gedmo/doctrine-extensions',
                'https://github.com/doctrine-extensions/DoctrineExtensions/pull/2253',
                'Passing an array as first argument to "%s()" is deprecated. Use named arguments instead.',
                __METHOD__
            );

            $args = func_get_args();

            $this->class = $this->getAttributeValue($data, 'class', $args, 1, $class);
            $this->idToString = $this->getAttributeValue($data, 'idToString', $args, 1, $idToString);

            return;
        }

        $this->class = $class;
        $this->idToString = $idToString;
    }
}
