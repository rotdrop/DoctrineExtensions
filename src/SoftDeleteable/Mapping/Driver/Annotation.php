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
use Gedmo\Mapping\Annotation\SoftDeleteable;
use Gedmo\Mapping\Driver\AbstractAnnotationDriver;
use Gedmo\SoftDeleteable\Mapping\Validator;

/**
 * This is an annotation mapping driver for SoftDeleteable
 * behavioral extension. Used for extraction of extended
 * metadata from Annotations specifically for SoftDeleteable
 * extension.
 *
 * @author Gustavo Falco <comfortablynumb84@gmail.com>
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 */
class Annotation extends AbstractAnnotationDriver
{
    /**
     * Annotation to define that this object is soft-deleteable
     */
    public const SOFT_DELETEABLE = SoftDeleteable::class;

    /**
     * Annotation to define that soft-deletion cascade over this property
     */
    const SOFT_DELETEABLE_CASCADE = 'Gedmo\\Mapping\\Annotation\\SoftDeleteableCascade';

    public function readExtendedMetadata($meta, array &$config)
    {
        $class = $this->getMetaReflectionClass($meta);
        // class annotations
        if (null !== $class && $annot = $this->reader->getClassAnnotation($class, self::SOFT_DELETEABLE)) {
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
                if (!is_bool($annot->hardDelete)) {
                    throw new InvalidMappingException('hardDelete must be boolean. '.gettype($annot->hardDelete).' provided.');
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
