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
 * Translatable annotation for Translatable behavioral extension
 *
 * @Annotation
 *
 * @NamedArgumentConstructor
 *
 * @Target("PROPERTY")
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Translatable implements GedmoAnnotation
{
    use ForwardCompatibilityTrait;

    /** @var bool|null */
    public $fallback;

    /**
     * @var string|null
     *
     * Optional name of a property for storing the untranslated value as
     * stored in the data-base table.
     */
    public $untranslated;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [], ?bool $fallback = null, ?string $untranslated = null)
    {
        if ([] !== $data) {
            Deprecation::trigger(
                'gedmo/doctrine-extensions',
                'https://github.com/doctrine-extensions/DoctrineExtensions/pull/2253',
                'Passing an array as first argument to "%s()" is deprecated. Use named arguments instead.',
                __METHOD__
            );

            $args = func_get_args();

            $this->fallback = $this->getAttributeValue($data, 'fallback', $args, 1, $fallback);
            $this->untranslated = $this->getAttributeValue($data, 'untranslated', $args, 2, $untranslated);

            return;
        }

        $this->fallback = $fallback;
        $this->untranslated = $untranslated;
    }
}
