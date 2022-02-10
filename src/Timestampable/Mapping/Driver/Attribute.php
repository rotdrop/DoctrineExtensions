<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Timestampable\Mapping\Driver;

use Gedmo\Exception\InvalidMappingException;
use Gedmo\Mapping\Annotation\Timestampable;
use Gedmo\Mapping\Driver\AbstractAnnotationDriver;

/**
 * Mapping driver for the timestampable extension which reads extended metadata from attributes on a timestampable class.
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @author Kevin Mian Kraiker <kevin.mian@gmail.com>
 *
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 *
 * @internal
 */
class Attribute extends AbstractAnnotationDriver
{
    /**
     * Mapping object for the timestampable extension.
     */
    public const TIMESTAMPABLE = Timestampable::class;

    /**
     * List of types which are valid for timestamp
     *
     * @var string[]
     */
    protected $validTypes = [
        'date',
        'date_immutable',
        'time',
        'time_immutable',
        'datetime',
        'datetime_immutable',
        'datetimetz',
        'datetimetz_immutable',
        'timestamp',
        'vardatetime',
        'integer',
    ];

    public function readExtendedMetadata($meta, array &$config)
    {
        $class = $this->getMetaReflectionClass($meta);

        // property annotations
        foreach ($class->getProperties() as $property) {
            if ($meta->isMappedSuperclass && !$property->isPrivate()
                || $meta->isInheritedField($property->name)
                || isset($meta->associationMappings[$property->name]['inherited'])
            ) {
                continue;
            }

            if ($timestampable = $this->reader->getPropertyAnnotation($property, self::TIMESTAMPABLE)) {
                \assert($timestampable instanceof Timestampable);

                $field = $property->getName();

                if (!$meta->hasField($field) && !$meta->hasAssociation($field)) {
                    throw new InvalidMappingException("Unable to find timestampable [{$field}] as mapped property in entity - {$meta->getName()}");
                }

                if ((!$meta->hasAssociation($field) ||
                     empty($timestampable->timestampField)) &&
                    !$this->isValidField($meta, $field)) {
                    throw new InvalidMappingException("Field - [{$field}] type is not valid and must be 'date', 'datetime' or 'time' in class - {$meta->getName()}");
                }

                $triggers = is_array($timestampable->on) ? $timestampable->on : [ $timestampable->on ];
                foreach ($triggers as $on) {
                    if (!in_array($on, ['update', 'create', 'change', 'delete'], true)) {
                        throw new InvalidMappingException("Field - [{$field}] trigger 'on' is not one of [update, create, change, delete] in class - {$meta->getName()}");
                    }
                    if ($on === 'delete') {
                        if (!$meta->hasAssociation($field)) {
                            throw new InvalidMappingException("Field - [{$field}] trigger 'on' is 'delete', but [{$field}] is not an association in class - {$meta->getName()}");
                        }
                        if (empty($timestampable->timestampField)) {
                            throw new InvalidMappingException("Field - [{$field}] trigger 'on' is 'delete', but not target-property for storing the stamp is defined in class - {$meta->getName()}");
                        }
                    }
                    $options = [
                        'field' => $field,
                        'targetProperty' => $timestampable->timestampField,
                    ];
                    if ('change' === $on) {
                        if (!isset($timestampable->field)) {
                            throw new InvalidMappingException("Missing parameters on property - {$field}, field must be set on [change] trigger in class - {$meta->getName()}");
                        }
                        if (is_array($timestampable->field) && isset($timestampable->value)) {
                            throw new InvalidMappingException('Timestampable extension does not support multiple value changeset detection yet.');
                        }
                        if (!$meta->isSingleValuedAssociation($field) && isset($timestampable->value)) {
                            throw new InvalidMappingException('Timestampable extension does not support changeset detection for multi-valued association fields.');
                        }
                        $options['trackedField'] = $timestampable->field;
                        $options['value'] = $timestampable->value;
                    }
                    // properties are unique and mapper checks that, no risk here
                    $config[$on][] = $field;
                }
            }
        }

        return $config;
    }
}
