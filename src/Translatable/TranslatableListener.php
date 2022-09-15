<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Translatable;

use Doctrine\Common\EventArgs;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\Persistence\Event\LoadClassMetadataEventArgs;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use Gedmo\Mapping\MappedEventSubscriber;
use Gedmo\Tool\Wrapper\AbstractWrapper;
use Gedmo\Tool\WrapperInterface;
use Gedmo\Translatable\Mapping\Event\TranslatableAdapter;

/**
 * The translation listener handles the generation and
 * loading of translations for entities which implements
 * the Translatable interface.
 *
 * This behavior can impact the performance of your application
 * since it does an additional query for each field to translate.
 *
 * Nevertheless the annotation metadata is properly cached and
 * it is not a big overhead to lookup all entity annotations since
 * the caching is activated for metadata
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 *
 * @phpstan-type TranslatableConfiguration = array{
 *   fields?: string[],
 *   fallback?: array<string, bool>,
 *   locale?: string,
 *   translationClass?: class-string,
 *   useObjectClass?: class-string,
 * }
 *
 * @phpstan-method TranslatableConfiguration getConfiguration(ObjectManager $objectManager, $class)
 *
 * @method TranslatableAdapter getEventAdapter(EventArgs $args)
 */
class TranslatableListener extends MappedEventSubscriber
{
    /**
     * Query hint to override the fallback of translations
     * integer 1 for true, 0 false
     */
    public const HINT_FALLBACK = 'gedmo.translatable.fallback';

    /**
     * Query hint to override the fallback locale
     */
    public const HINT_TRANSLATABLE_LOCALE = 'gedmo.translatable.locale';

    /**
     * Query hint to use inner join strategy for translations
     */
    public const HINT_INNER_JOIN = 'gedmo.translatable.inner_join.translations';

    /**
     * Locale which is set on this listener.
     * If Entity being translated has locale defined it
     * will override this one
     *
     * @var string
     */
    protected $locale = 'en_US';

    /**
     * Default locale, this changes behavior
     * to not update the original record field if locale
     * which is used for updating is not default. This
     * will load the default translation in other locales
     * if record is not translated yet
     *
     * @var string
     */
    private $defaultLocale = 'en_US';

    /**
     * If this is set to false, when if entity does
     * not have a translation for requested locale
     * it will show a blank value
     *
     * @var bool
     */
    private $translationFallback = false;

    /**
     * List of translations which do not have the foreign
     * key generated yet - MySQL case. These translations
     * will be updated with new keys on postPersist event
     *
     * @var array
     */
    private $pendingTranslationInserts = [];

    /**
     * Currently in case if there is TranslationQueryWalker
     * in charge. We need to skip issuing additional queries
     * on load
     *
     * @var bool
     */
    private $postProcessHydrator = false;

    /**
     * Tracks locale the objects currently translated in
     *
     * @var array
     */
    private $translatedInLocale = [];

    /**
     * Whether or not, to persist default locale
     * translation or keep it in original record
     *
     * @var bool
     */
    private $persistDefaultLocaleTranslation = false;

    /**
     * Tracks translation object for default locale
     *
     * @var array
     */
    private $translationInDefaultLocale = [];

    /**
     * Default translation value upon missing translation
     *
     * @var string|null
     */
    private $defaultTranslationValue;

    /**
     * Tracks empty fields in default locale
     *
     * @var array
     */
    private $missingInDefaultLocale = [];

    /**
     * Backup translation data to be restored post-flush when forcing
     * default-locale updates during flush.
     *
     * @var array
     * ```
     * [ ROOT_CLASS_NAME => [ OID => [ FIELD => DATA, ... ], ... ], ... ],
     * ```
     */
    private $preFlushBackup = [];

    /**
     * Specifies the list of events to listen
     *
     * @return string[]
     */
    public function getSubscribedEvents()
    {
        return [
            'postLoad',
            'postPersist',
            'preFlush',
            'onFlush',
            'postFlush',
            'loadClassMetadata',
        ];
    }

    /**
     * Set to skip or not onLoad event
     *
     * @param bool $bool
     *
     * @return static
     */
    public function setPostProcessHydrator($bool)
    {
        $this->postProcessHydrator = (bool) $bool;

        return $this;
    }

    /**
     * Whether or not, to persist default locale
     * translation or keep it in original record
     *
     * @param bool $bool
     *
     * @return static
     */
    public function setPersistDefaultLocaleTranslation($bool)
    {
        $this->persistDefaultLocaleTranslation = (bool) $bool;

        return $this;
    }

    /**
     * Check if should persist default locale
     * translation or keep it in original record
     *
     * @return bool
     */
    public function getPersistDefaultLocaleTranslation()
    {
        return (bool) $this->persistDefaultLocaleTranslation;
    }

    /**
     * Add additional $translation for pending $oid object
     * which is being inserted
     *
     * @param int    $oid
     * @param object $translation
     *
     * @return void
     */
    public function addPendingTranslationInsert($oid, $translation)
    {
        $this->pendingTranslationInserts[$oid][] = $translation;
    }

    /**
     * Maps additional metadata
     *
     * @param LoadClassMetadataEventArgs $eventArgs
     *
     * @return void
     */
    public function loadClassMetadata(EventArgs $eventArgs)
    {
        $this->loadMetadataForObjectClass($eventArgs->getObjectManager(), $eventArgs->getClassMetadata());
    }

    /**
     * Get the translation class to be used
     * for the object $class
     *
     * @param string $class
     * @phpstan-param class-string $class
     *
     * @return string
     * @phpstan-return class-string
     */
    public function getTranslationClass(TranslatableAdapter $ea, $class)
    {
        return self::$configurations[$this->name][$class]['translationClass'] ?? $ea->getDefaultTranslationClass()
        ;
    }

    /**
     * Enable or disable translation fallback
     * to original record value
     *
     * @param bool $bool
     *
     * @return static
     */
    public function setTranslationFallback($bool)
    {
        $this->translationFallback = (bool) $bool;

        return $this;
    }

    /**
     * Weather or not is using the translation
     * fallback to original record
     *
     * @return bool
     */
    public function getTranslationFallback()
    {
        return $this->translationFallback;
    }

    /**
     * Set the locale to use for translation listener
     *
     * @param string $locale
     *
     * @return static
     */
    public function setTranslatableLocale($locale)
    {
        $this->validateLocale($locale);
        $this->locale = $locale;

        return $this;
    }

    /**
     * Set the default translation value on missing translation
     *
     * @deprecated usage of a non nullable value for defaultTranslationValue is deprecated
     * and will be removed on the next major release which will rely on the expected types
     */
    public function setDefaultTranslationValue(?string $defaultTranslationValue): void
    {
        $this->defaultTranslationValue = $defaultTranslationValue;
    }

    /**
     * Sets the default locale, this changes behavior
     * to not update the original record field if locale
     * which is used for updating is not default
     *
     * @param string $locale
     *
     * @return static
     */
    public function setDefaultLocale($locale)
    {
        $this->validateLocale($locale);
        $this->defaultLocale = $locale;

        return $this;
    }

    /**
     * Gets the default locale
     *
     * @return string
     */
    public function getDefaultLocale()
    {
        return $this->defaultLocale;
    }

    /**
     * Get currently set global locale, used
     * extensively during query execution
     *
     * @return string
     */
    public function getListenerLocale()
    {
        return $this->locale;
    }

    /**
     * Optionally tweak the configuration on a per object basis.
     */
    public function getObjectConfiguration($object, ObjectManager $objectManager, string $class)
    {
        return $this->getConfiguration($objectManager, $class);
    }

    /**
     * Gets the locale to use for translation. Loads object
     * defined locale first..
     *
     * @param object        $object
     * @param ClassMetadata $meta
     * @param object        $om
     *
     * @throws \Gedmo\Exception\RuntimeException if language or locale property is not
     *                                           found in entity
     *
     * @return string
     */
    public function getTranslatableLocale($object, $meta, $om = null)
    {
        $locale = $this->locale;
        $localeProperty = self::$configurations[$this->name][$meta->getName()]['locale']['field'];
        if (!empty($localeProperty)) {
            /** @var \ReflectionClass $class */
            $class = $meta->getReflectionClass();
            $reflectionProperty = $class->getProperty($localeProperty);
            if (!$reflectionProperty) {
                throw new \Gedmo\Exception\RuntimeException("There is no locale or language property ({$localeProperty}) found on object: {$meta->getName()}");
            }
            $reflectionProperty->setAccessible(true);
            $value = $reflectionProperty->getValue($object);
            if (is_object($value) && method_exists($value, '__toString')) {
                $value = (string) $value;
            }
            if ($this->isValidLocale($value)) {
                $locale = $value;
            }
            if (self::$configurations[$this->name][$meta->getName()]['locale']['initialize']) {
                $reflectionProperty->setValue($object, $locale);
            }
        } elseif ($om instanceof DocumentManager) {
            [$mapping, $parentObject] = $om->getUnitOfWork()->getParentAssociation($object);
            if (null != $parentObject) {
                $parentMeta = $om->getClassMetadata(get_class($parentObject));
                $locale = $this->getTranslatableLocale($parentObject, $parentMeta, $om);
            }
        }

        return $locale;
    }

    /**
     * Handle translation changes in default locale
     *
     * This has to be done in the preFlush because, when an entity has been loaded
     * in a different locale, no changes will be detected.
     *
     * @return void
     */
    public function preFlush(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();

        foreach ($this->translationInDefaultLocale as $oid => $fields) {
            $trans = reset($fields);
            if ($ea->usesPersonalTranslation(get_class($trans))) {
                $entity = $trans->getObject();
            } else {
                $entity = $uow->tryGetById($trans->getForeignKey(), $trans->getObjectClass());
            }

            if (!$entity) {
                continue;
            }

            try {
                $uow->scheduleForUpdate($entity);
            } catch (ORMInvalidArgumentException $e) {
                foreach ($fields as $field => $trans) {
                    $this->removeTranslationInDefaultLocale($oid, $field);
                }
            }
        }
    }

    /**
     * Looks for translatable objects being inserted or updated
     * for further processing
     *
     * @return void
     */
    public function onFlush(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();
        // check all scheduled inserts for Translatable objects
        foreach ($ea->getScheduledObjectInsertions($uow) as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            $config = $this->getObjectConfiguration($object, $om, $meta->getName());
            if (isset($config['fields'])) {
                $this->handleTranslatableObjectUpdate($ea, $object, true);
            }
        }
        // check all scheduled updates for Translatable entities
        foreach ($ea->getScheduledObjectUpdates($uow) as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            $config = $this->getObjectConfiguration($object, $om, $meta->getName());
            if (isset($config['fields'])) {
                $this->handleTranslatableObjectUpdate($ea, $object, false);
            }
        }
        // check scheduled deletions for Translatable entities
        foreach ($ea->getScheduledObjectDeletions($uow) as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            $config = $this->getObjectConfiguration($object, $om, $meta->getName());
            if (isset($config['fields'])) {
                $wrapped = AbstractWrapper::wrap($object, $om);
                $transClass = $this->getTranslationClass($ea, $meta->getName());
                $ea->removeAssociatedTranslations($wrapped, $transClass, $config['useObjectClass']);
            }
        }
    }

    /**
     * Restore the translated properties previously backed up in the
     * preFlush() handler.
     */
    public function postFlush(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();

        $identityMap = $uow->getIdentityMap();
        foreach ($this->preFlushBackup as $rootClass => $backup) {
            foreach (($identityMap[$rootClass]??[]) as $object) {
                $oid = spl_object_id($object);
                if (empty($backup[$oid])) {
                    continue;
                }
                $wrapped = AbstractWrapper::wrap($object, $om);
                foreach ($backup[$oid] as $field => $value) {
                    // restore the backup and fake clean change-sets
                    $wrapped->setPropertyValue($field, $value);
                    $ea->setOriginalObjectProperty($uow, $object, $field, $value);
                }
            }
        }
        $this->preFlushBackup = [];
    }

    /**
     * Checks for inserted object to update their translation
     * foreign keys
     *
     * @return void
     */
    public function postPersist(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $object = $ea->getObject();
        $meta = $om->getClassMetadata(get_class($object));
        // check if entity is tracked by translatable and without foreign key
        if ($this->getObjectConfiguration($object, $om, $meta->getName()) && count($this->pendingTranslationInserts)) {
            $oid = spl_object_id($object);
            if (array_key_exists($oid, $this->pendingTranslationInserts)) {
                // load the pending translations without key
                $wrapped = AbstractWrapper::wrap($object, $om);
                $objectId = $wrapped->getIdentifier(false, true);
                $translationClass = $this->getTranslationClass($ea, $meta->name);
                foreach ($this->pendingTranslationInserts[$oid] as $translation) {
                    if ($ea->usesPersonalTranslation($translationClass)) {
                        $translation->setObject($objectId);
                    } else {
                        $translation->setForeignKey($objectId);
                    }
                    $ea->insertTranslationRecord($translation);
                }
                unset($this->pendingTranslationInserts[$oid]);
            }
        }
    }

    private function setUntranslatedPropertyValue($object, $field, $originalValue, $meta, $config)
    {
        // if requested install the original object property into the given PHP field.
        if (isset($config['untranslated'][$field])) {
            $untranslatedProperty = $config['untranslated'][$field];
            $reflectionProperty = $meta->getReflectionClass()->getProperty($untranslatedProperty);
            if (!$reflectionProperty) {
                throw new \Gedmo\Exception\RuntimeException("There is no property ({$untranslatedProperty}) to hold the untranslated original value of property ({$field}) on object: {$meta->name}");
            }
            $reflectionProperty->setAccessible(true);
            $reflectionProperty->setValue($object, $originalValue);
        }
    }

    /**
     * After object is loaded, listener updates the translations
     * by currently used locale
     *
     * @return void
     */
    public function postLoad(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $object = $ea->getObject();
        $meta = $om->getClassMetadata(get_class($object));
        if (!$this->postProcessHydrator) {
            $config = $this->getObjectConfiguration($object, $om, $meta->getName());
        } else {
            $config = $this->getConfiguration($om, $meta->getName());
            $objectConfig = $this->getObjectConfiguration($object, $om, $meta->getName());
        }
        $locale = $this->defaultLocale;
        $oid = null;
        if (isset($config['fields'])) {
            $locale = $this->getTranslatableLocale($object, $meta, $om);
            $oid = spl_object_id($object);
            $this->translatedInLocale[$oid] = $locale;
        }


        if (isset($config['fields']) && ($locale !== $this->defaultLocale || $this->persistDefaultLocaleTranslation)) {
            if (!$this->postProcessHydrator) {
                // fetch translations
                $translationClass = $this->getTranslationClass($ea, $config['useObjectClass']);
                $result = $ea->loadTranslations(
                    $object,
                    $translationClass,
                    $locale,
                    $config['useObjectClass']
                );
            }

            // translate object's translatable properties
            foreach ($config['fields'] as $field) {
                $translated = $this->defaultTranslationValue;

                if (!$this->postProcessHydrator) {
                    foreach ($result as $entry) {
                        if ($entry['field'] == $field) {
                            $translated = $entry['content'] ?? null;

                            break;
                        }
                    }
                }

                $originalValue = $meta->getReflectionProperty($field)->getValue($object);

                if ($this->postProcessHydrator) {
                    // object hydrator provides translated and untranslated properties as JSON data.
                    list('translated' => $translated, 'untranslated' => $originalValue) = json_decode($originalValue, true);
                    if (array_search($field, $objectConfig['fields']) === false) {
                        // just restore the original property and skip the rest of this code

                        // the following also converte to "PHP" value, as opposed to just using refl->setValue()
                        $ea->setTranslationValue($object, $field, $originalValue);

                        // provide a clean changeset
                        $ea->setOriginalObjectProperty(
                            $om->getUnitOfWork(),
                            $object,
                            $field,
                            $originalValue
                        );
                        continue; // getObjectConfiguration() told us to skip this field
                    }
                }

                // if requested install the original object property into the given PHP field.
                $this->setUntranslatedPropertyValue($object, $field, $originalValue, $meta, $config);

                $doFallback = ((!isset($config['fallback'][$field]) && $this->translationFallback)
                               ||
                               (isset($config['fallback'][$field]) && $config['fallback'][$field]));

                $cleanChangeSet = true;
                if ($doFallback) {
                    if (empty($originalValue)) {
                        $this->missingInDefaultLocale[$oid][$field] = true;
                    } else if (empty($translated)) {
                        $translated = $this->getFallbackTranslation($originalValue);
                        $cleanChangeSet = false;
                    }
                }

                // update translation
                if ($translated !== $this->defaultTranslationValue || !$doFallback) {
                    $ea->setTranslationValue($object, $field, $translated);
                    // ensure clean changeset only if no fallback-translation was computed
                    if ($cleanChangeSet) {
                        $ea->setOriginalObjectProperty(
                            $om->getUnitOfWork(),
                            $object,
                            $field,
                            $meta->getReflectionProperty($field)->getValue($object)
                        );
                    }
                }
            }
        }
    }

    /**
     * Sets translation object which represents translation in default language.
     *
     * @param int    $oid   hash of basic entity
     * @param string $field field of basic entity
     * @param mixed  $trans Translation object
     *
     * @return void
     */
    public function setTranslationInDefaultLocale($oid, $field, $trans)
    {
        if (!isset($this->translationInDefaultLocale[$oid])) {
            $this->translationInDefaultLocale[$oid] = [];
        }
        $this->translationInDefaultLocale[$oid][$field] = $trans;
    }

    /**
     * @return bool
     */
    public function isPostProcessHydrator()
    {
        return $this->postProcessHydrator;
    }

    /**
     * Check if object has any translation object which represents translation in default language.
     * This is for internal use only.
     *
     * @param int $oid hash of the basic entity
     *
     * @return bool
     */
    public function hasTranslationsInDefaultLocale($oid)
    {
        return array_key_exists($oid, $this->translationInDefaultLocale);
    }

    /**
     * Get a fallback tanslation. This function is only called if a
     * fallback is enabled for the respective field and the field has
     * no translation in the database. If null is returned then the
     * original field value will be used as fallback. If a non-empty
     * string is returned, then this string will be used as fallback
     * translation. The fallback translations will be stored in the
     * data-base on flush.
     *
     * @param string $originalValue Stored value from untranslated
     *   entity property.
     *
     * @return null|string The translated value in the current locale.
     *
     * @note Applications can derive from TranslatableListener and
     * override this method to their liking.
     */
    protected function getFallbackTranslation($originalValue)
    {
        return $this->defaultTranslationValue;
    }

    /**
     * Provide the reverse translation to the fallback locale. Used
     * for newly persisted entities without translation to the default
     * locale. If null is returned just the original (wrong) value of
     * the current locale is persistet.
     *
     * @param string $translatedValue Translated value in the current
     * locale.
     *
     * @return null|string The back-translated value or null.
     *
     * @note Applications can derive from TranslatableListener and
     * override this method to their liking.
     */
    protected function getFallbackUntranslation($translatedValue)
    {
        return null;
    }

    protected function getNamespace()
    {
        return __NAMESPACE__;
    }

    /**
     * Validates the given locale
     *
     * @param string $locale locale to validate
     *
     * @throws \Gedmo\Exception\InvalidArgumentException if locale is not valid
     *
     * @return void
     */
    protected function validateLocale($locale)
    {
        if (!$this->isValidLocale($locale)) {
            throw new \Gedmo\Exception\InvalidArgumentException('Locale or language cannot be empty and must be set through Listener or Entity');
        }
    }

    /**
     * Check if the given locale is valid
     */
    private function isValidlocale(?string $locale): bool
    {
        return is_string($locale) && strlen($locale);
    }

    /**
     * Creates the translation for object being flushed
     *
     * @throws \UnexpectedValueException if locale is not valid, or
     *                                   primary key is composite, missing or invalid
     */
    private function handleTranslatableObjectUpdate(TranslatableAdapter $ea, object $object, bool $isInsert): void
    {
        $om = $ea->getObjectManager();
        $wrapped = AbstractWrapper::wrap($object, $om);
        $meta = $wrapped->getMetadata();
        $config = $this->getObjectConfiguration($object, $om, $meta->getName());
        // no need cache, metadata is loaded only once in MetadataFactoryClass
        $translationClass = $this->getTranslationClass($ea, $config['useObjectClass']);
        $translationMetadata = $om->getClassMetadata($translationClass);

        // check for the availability of the primary key
        $objectId = $wrapped->getIdentifier(false, true);
        // load the currently used locale
        $locale = $this->getTranslatableLocale($object, $meta, $om);

        $uow = $om->getUnitOfWork();
        $oid = spl_object_id($object);
        $changeSet = $ea->getObjectChangeSet($uow, $object);
        $translatableFields = $config['fields'];
        foreach ($translatableFields as $field) {
            $wasPersistedSeparetely = false;
            $skip = $locale === ($this->translatedInLocale[$oid]??null);
            $skip = $skip && !isset($changeSet[$field]) && !$this->getTranslationInDefaultLocale($oid, $field);
            if ($skip) {
                continue; // locale is same and nothing changed
            }
            $translation = null;
            foreach ($ea->getScheduledObjectInsertions($uow) as $trans) {
                if ($locale !== $this->defaultLocale
                    && get_class($trans) === $translationClass
                    && $trans->getLocale() === $this->defaultLocale
                    && $trans->getField() === $field
                    && $this->belongsToObject($ea, $trans, $wrapped)) {
                    $this->setTranslationInDefaultLocale($oid, $field, $trans);

                    break;
                }
            }

            // lookup persisted translations
            foreach ($ea->getScheduledObjectInsertions($uow) as $trans) {
                if (get_class($trans) !== $translationClass
                    || $trans->getLocale() !== $locale
                    || $trans->getField() !== $field) {
                    continue;
                }

                if ($ea->usesPersonalTranslation($translationClass)) {
                    $wasPersistedSeparetely = $trans->getObject() === $object;
                } else {
                    $wasPersistedSeparetely = $trans->getObjectClass() === $config['useObjectClass']
                        && $trans->getForeignKey() === $objectId;
                }

                if ($wasPersistedSeparetely) {
                    $translation = $trans;

                    break;
                }
            }

            // check if translation already is created
            if (!$isInsert && !$translation) {
                $translation = $ea->findTranslation(
                    $wrapped,
                    $locale,
                    $field,
                    $translationClass,
                    $config['useObjectClass']
                );
            }

            // create new translation if translation not already created and locale is different from default locale, otherwise, we have the date in the original record
            $persistNewTranslation = !$translation
                && ($locale !== $this->defaultLocale || $this->persistDefaultLocaleTranslation)
            ;
            if ($persistNewTranslation) {
                $translation = $translationMetadata->newInstance();
                $translation->setLocale($locale);
                $translation->setField($field);
                if ($ea->usesPersonalTranslation($translationClass)) {
                    $translation->setObject($object);
                } else {
                    $translation->setObjectClass($config['useObjectClass']);
                    $translation->setForeignKey($objectId);
                }
            }

            if ($translation) {
                // set the translated field, take value using reflection
                $content = $ea->getTranslationValue($object, $field);
                $translation->setContent($content);
                // check if need to update in database
                $transWrapper = AbstractWrapper::wrap($translation, $om);
                if (((null === $content && !$isInsert) || is_bool($content) || is_int($content) || is_string($content) || !empty($content)) && ($isInsert || !$transWrapper->getIdentifier(false, true) || isset($changeSet[$field]))) {
                    if ($isInsert && !$objectId && !$ea->usesPersonalTranslation($translationClass)) {
                        // if we do not have the primary key yet available
                        // keep this translation in memory to insert it later with foreign key
                        $this->pendingTranslationInserts[spl_object_id($object)][] = $translation;
                    } else {
                        // persist and compute change set for translation
                        if ($wasPersistedSeparetely) {
                            $ea->recomputeSingleObjectChangeset($uow, $translationMetadata, $translation);
                        } else {
                            $om->persist($translation);
                            $uow->computeChangeSet($translationMetadata, $translation);
                        }
                    }
                }
            }

            if ($isInsert) {
                // We can't rely on object field value which is created in non-default locale.
                // If we provide translation for default locale as well, the latter is considered to be trusted
                // and object content should be overridden.
                $defaultValue = null;
                if (null !== $this->getTranslationInDefaultLocale($oid, $field)) {
                    $defaultValue = $this->getTranslationInDefaultLocale($oid, $field)->getContent();
                    $this->removeTranslationInDefaultLocale($oid, $field);
                } else {
                    $defaultValue = $this->getFallbackUntranslation($wrapped->getPropertyValue($field));
                }
                if ($defaultValue !== null) {
                    $this->preFlushBackup[$ea->getRootObjectClass($meta)][$oid][$field] = $wrapped->getPropertyValue($field);
                    $wrapped->setPropertyValue($field, $defaultValue);
                    $ea->recomputeSingleObjectChangeset($uow, $meta, $object);
                    $untranslated = $defaultValue;
                } else {
                    $untranslated = $wrapped->getPropertyValue($field);
                }

                // if requested install the original object property into the given PHP field.
                $this->setUntranslatedPropertyValue($object, $field, $untranslated, $meta, $config);
            }
        }

        $this->translatedInLocale[$oid] = $locale;
        // check if we have default translation and need to reset the translation
        if (!$isInsert && $this->isValidlocale($this->defaultLocale)) {

            if ($locale !== $this->defaultLocale) {

                // cleanup current changeset only if working in a another locale different than de default one, otherwise the changeset would always be reverted
                $modifiedChangeSet = $changeSet;
                foreach ($modifiedChangeSet as $field => $changes) {
                    if (in_array($field, $translatableFields, true)) {
                        $ea->setOriginalObjectProperty($uow, $object, $field, $changes[1]);
                        unset($modifiedChangeSet[$field]);
                    }
                }
                $ea->clearObjectChangeSet($uow, $object);
                // recompute changeset only if there are changes other than reverted translations
                if (!empty($modifiedChangeSet)
                    || $this->hasTranslationsInDefaultLocale($oid)
                    || !empty($this->missingInDefaultLocale[$oid][$field])) {
                    foreach ($modifiedChangeSet as $field => $changes) {
                        $ea->setOriginalObjectProperty($uow, $object, $field, $changes[0]);
                    }
                    foreach ($translatableFields as $field) {
                        $defaultValue = null;
                        if ($this->hasTranslationsInDefaultLocale($oid)) {
                            $defaultValue = $this->getTranslationInDefaultLocale($oid, $field)->getContent();
                            $this->removeTranslationInDefaultLocale($oid, $field);
                        } else if (!empty($this->missingInDefaultLocale[$oid][$field])) {
                            $translatedValue = $changeSet[$field][1] ?? $wrapped->getPropertyValue($field);
                            if (!empty($translatedValue)) {
                                $defaultValue = $this->getFallbackUntranslation($translatedValue);
                            }
                        }
                        if (!empty($defaultValue)) {
                            $this->preFlushBackup[$ea->getRootObjectClass($meta)][$oid][$field] = $wrapped->getPropertyValue($field);
                            $wrapped->setPropertyValue($field, $defaultValue);
                            unset($this->missingInDefaultLocale[$oid][$field]);

                            // if requested install the original object property into the given PHP field.
                            $this->setUntranslatedPropertyValue($object, $field, $defaultValue, $meta, $config);
                        }
                    }
                    $ea->recomputeSingleObjectChangeset($uow, $meta, $object);
                }
            }
        }
    }

    /**
     * Removes translation object which represents translation in default language.
     * This is for internal use only.
     *
     * @param int    $oid   hash of the basic entity
     * @param string $field field of basic entity
     */
    private function removeTranslationInDefaultLocale(int $oid, string $field): void
    {
        if (isset($this->translationInDefaultLocale[$oid])) {
            if (isset($this->translationInDefaultLocale[$oid][$field])) {
                unset($this->translationInDefaultLocale[$oid][$field]);
            }
            if (!$this->translationInDefaultLocale[$oid]) {
                // We removed the final remaining elements from the
                // translationInDefaultLocale[$oid] array, so we might as well
                // completely remove the entry at $oid.
                unset($this->translationInDefaultLocale[$oid]);
            }
        }
    }

    /**
     * Gets translation object which represents translation in default language.
     * This is for internal use only.
     *
     * @param int    $oid   hash of the basic entity
     * @param string $field field of basic entity
     *
     * @return mixed Returns translation object if it exists or NULL otherwise
     */
    private function getTranslationInDefaultLocale(int $oid, string $field)
    {
        if (array_key_exists($oid, $this->translationInDefaultLocale)) {
            $ret = $this->translationInDefaultLocale[$oid][$field] ?? null;
        } else {
            $ret = null;
        }

        return $ret;
    }

    /**
     * Checks if the translation entity belongs to the object in question
     *
     * @param object $trans
     * @param WrapperInterface $wrapped
     *
     * @return bool
     */
    private function belongsToObject(TranslatableAdapter $ea, object $trans, WrapperInterface $wrapped):bool
    {
        if ($ea->usesPersonalTranslation(get_class($trans))) {
            return $trans->getObject() === $wrapped->getObject();
        }

        return $trans->getForeignKey() === $wrapped->getIdentifier(false, true)
            && ($trans->getObjectClass() === $wrapped->getMetadata()->name);
    }
}
