<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Translatable\Mapping\Driver;

use Doctrine\ORM\Mapping\EmbeddedClassMapping;
use Gedmo\Exception\InvalidMappingException;
use Gedmo\Mapping\Annotation\Language;
use Gedmo\Mapping\Annotation\Locale;
use Gedmo\Mapping\Annotation\Translatable;
use Gedmo\Mapping\Annotation\TranslationEntity;
use Gedmo\Mapping\Driver\AbstractAnnotationDriver;

/**
 * Mapping driver for the translatable extension which reads extended metadata from annotations on a translatable class.
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 *
 * @internal
 */
class Attribute extends AbstractAnnotationDriver
{
    /**
     * Mapping object to configure the translation model for a translatable class.
     */
    public const ENTITY_CLASS = TranslationEntity::class;

    /**
     * Mapping object to identify a field as translatable in a translatable class.
     */
    public const TRANSLATABLE = Translatable::class;

    /**
     * Mapping object to identify the field which stores the locale or language for the translation.
     *
     * This object is an alias of {@see self::LANGUAGE}
     */
    public const LOCALE = Locale::class;

    /**
     * Mapping object to identify the field which stores the locale or language for the translation.
     *
     * This object is an alias of {@see self::LOCALE}
     */
    public const LANGUAGE = Language::class;

    /**
     * Annotation to identify a field which can store the original translated
     * values. This might be handy as Translatable has to clean the changeset
     * of the original entity.
     */
    public const TRANSLATION_CHANGE_SET = TranslationChangeSet::class;

    public function readExtendedMetadata($meta, array &$config)
    {
        $class = $this->getMetaReflectionClass($meta);

        // class annotations
        if ($annot = $this->reader->getClassAnnotation($class, self::ENTITY_CLASS)) {
            \assert($annot instanceof TranslationEntity);

            if (!$cl = $this->getRelatedClassName($meta, $annot->class)) {
                throw new InvalidMappingException("Translation class: {$annot->class} does not exist.");
            }

            $config['translationClass'] = $cl;
            $config['idToString'] = $annot->idToString;
        }

        // property annotations
        foreach ($class->getProperties() as $property) {
            if ($meta->isMappedSuperclass && !$property->isPrivate()
                || $meta->isInheritedField($property->name)
                || isset($meta->associationMappings[$property->name]['inherited'])
            ) {
                continue;
            }

            /** @var Translatable $translatable */
            if ($translatable = $this->reader->getPropertyAnnotation($property, self::TRANSLATABLE)) {
                \assert($translatable instanceof Translatable);

                $field = $property->getName();

                if (!$meta->hasField($field)) {
                    throw new InvalidMappingException("Unable to find translatable [{$field}] as mapped property in entity - {$meta->getName()}");
                }

                // fields cannot be overrided and throws mapping exception (?)
                $config['fields'][] = $field;

                if (isset($translatable->fallback)) {
                    $config['fallback'][$field] = $translatable->fallback;
                }

                // annotation requests untranslated database-value in this class property
                if (isset($translatable->untranslated)) {
                    $config['untranslated'][$field] = $translatable->untranslated;
                }
            }

            // locale property
            $locale = $this->reader->getPropertyAnnotation($property, self::LOCALE);
            if ($locale) {
                $field = $property->getName();

                if ($meta->hasField($field)) {
                    throw new InvalidMappingException("Locale field [{$field}] should not be mapped as column property in entity - {$meta->getName()}, since it makes no sense");
                }

                $config['locale'] = [
                    'field' => $field,
                    'initialize' => isset($locale->initialize) && $locale->initialize,
                ];
            } elseif ($locale = $this->reader->getPropertyAnnotation($property, self::LANGUAGE)) {
                $field = $property->getName();

                if ($meta->hasField($field)) {
                    throw new InvalidMappingException("Language field [{$field}] should not be mapped as column property in entity - {$meta->getName()}, since it makes no sense");
                }

                $config['locale'] = [
                    'field' => $field,
                    'initialize' => isset($locale->initialize) && $locale->initialize,
                ];
            }
            // translation changeset property
            if ($this->reader->getPropertyAnnotation($property, self::TRANSLATION_CHANGE_SET)) {
                $field = $property->getName();
                if ($meta->hasField($field)) {
                    throw new InvalidMappingException("Translation change-set field [{$field}] should not be mapped as column property in entity - {$meta->getName()}, since it makes no sense");
                }
                $config['translationChangeSet'] = $field;
            }
        }

        // Embedded entity
        if (property_exists($meta, 'embeddedClasses') && $meta->embeddedClasses) {
            foreach ($meta->embeddedClasses as $propertyName => $embeddedClassInfo) {
                if ($meta->isInheritedEmbeddedClass($propertyName)) {
                    continue;
                }

                /** Remove conditional when ORM 2.x is no longer supported. */
                $className = ($embeddedClassInfo instanceof EmbeddedClassMapping) ? $embeddedClassInfo->class : $embeddedClassInfo['class'];
                $embeddedClass = new \ReflectionClass($className);

                foreach ($embeddedClass->getProperties() as $embeddedProperty) {
                    if ($translatable = $this->reader->getPropertyAnnotation($embeddedProperty, self::TRANSLATABLE)) {
                        \assert($translatable instanceof Translatable);

                        $field = $propertyName.'.'.$embeddedProperty->getName();

                        $config['fields'][] = $field;

                        if (isset($translatable->fallback)) {
                            $config['fallback'][$field] = $translatable->fallback;
                        }
                    }
                }
            }
        }

        if (!$meta->isMappedSuperclass && $config) {
            if ($meta instanceof \Doctrine\ODM\MongoDB\Mapping\ClassMetadata && is_array($meta->identifier) && count($meta->identifier) > 1) {
+                throw new InvalidMappingException("Translatable does not support composite identifiers in class - {$meta->name}");
            }
        }

        return $config;
    }
}
