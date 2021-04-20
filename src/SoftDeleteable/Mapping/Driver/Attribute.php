<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\SoftDeleteable\Mapping\Driver;

use Doctrine\Persistence\Mapping\ClassMetadata;
use Gedmo\Exception\InvalidMappingException;
use Gedmo\Mapping\Annotation\SoftDeleteable as SoftDeleteableAnnotation;
use Gedmo\Mapping\Annotation\SoftDeleteableCascade as SoftDeleteableCascadeAnnotation;
use Gedmo\Mapping\Driver\AbstractAnnotationDriver;
use Gedmo\SoftDeleteable\HardDeleteable\HardDeleteableInterface;
use Gedmo\SoftDeleteable\Mapping\Validator;

/**
 * Mapping driver for the soft-deletable extension which reads extended metadata from attributes on a soft-deletable class.
 *
 * @author Gustavo Falco <comfortablynumb84@gmail.com>
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 *
 * @internal
 */
class Attribute extends AbstractAnnotationDriver
{
    /**
     * Mapping object for the soft-deletable extension.
     */
    public const SOFT_DELETEABLE = SoftDeleteableAnnotation::class;

    /**
     * Annotation to define that soft-deletion cascade over this property
     */
    public const SOFT_DELETEABLE_CASCADE = SoftDeleteableCascadeAnnotation::class;

    /**
     * Hard-delete decision interface_exists
     */
    public const HARD_DELETEABLE_INTERFACE = HardDeleteableInterface::class;

    public function readExtendedMetadata($meta, array &$config)
    {
        $class = $this->getMetaReflectionClass($meta);

        // class annotations
        if (null !== $class && $annot = $this->reader->getClassAnnotation($class, self::SOFT_DELETEABLE)) {
            \assert($annot instanceof SoftDeleteableAnnotation);

            $config['softDeleteable'] = true;

            Validator::validateField($meta, $annot->fieldName);

            $config['fieldName'] = $annot->fieldName;

            $config['timeAware'] = false;

            if (isset($annot->timeAware)) {
                if (!is_bool($annot->timeAware)) {
                    throw new InvalidMappingException('timeAware must be boolean. '.gettype($annot->timeAware).' provided.');
                }

                $config['timeAware'] = $annot->timeAware;
            }

            $config['hardDelete'] = true;

            if (isset($annot->hardDelete)) {
                if (!is_bool($annot->hardDelete)
                    && (!is_string($annot->hardDelete)
                        || !class_exists($annot->hardDelete)
                        || !is_subclass_of($annot->hardDelete, self::HARD_DELETEABLE_INTERFACE))) {
                    throw new InvalidMappingException('hardDelete must be boolean or the name of an exististing PHP cla
ss implementing '.self::HARD_DELETEABLE_INTERFACE);
                }

                $config['hardDelete'] = $annot->hardDelete;
            }
        }

        // property annotations
        foreach ($class->getProperties() as $property) {
            $field = $property->getName();
            if ($meta->isMappedSuperclass && !$property->isPrivate()) {
                continue;
            }

            // versioned property
            if ($annot = $this->reader->getPropertyAnnotation($property, self::SOFT_DELETEABLE_CASCADE)) {
                if (!$this->isMappingValid($meta, $field)) {
                    throw new InvalidMappingException("Cannot apply versioning to field [{$field}] as it does not have an association - {$meta->name}");
                }

                if (!empty($annot->delete)) {
                    $config['cascadeDelete'][] = $field;
                }
                if (!empty($annot->undelete)) {
                    $config['cascadeUndelete'][] = $field;
                }
            }
        }

        $this->validateFullMetadata($meta, $config);

        return $config;
    }

    /**
     * @param string $field
     *
     * @return bool
     */
    protected function isMappingValid(ClassMetadata $meta, $field)
    {
        return $meta->hasAssociation($field);
    }
}
