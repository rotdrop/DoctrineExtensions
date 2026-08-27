<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Translatable\Hydrator\ORM;

use Doctrine\ORM\Query;
use Gedmo\Exception\RuntimeException;
use Gedmo\Tool\ORM\Hydration\EntityManagerRetriever;
use Gedmo\Translatable\Query\TreeWalker\TranslationWalker;
use Gedmo\Translatable\TranslatableListener;

/**
 * @internal
 */
trait HydratesTranslations
{
    use EntityManagerRetriever;

    public function hydrateRow(array $row): void
    {
        $translations = [];
        $prefix = TranslationWalker::UNTRANSLATED_FIELD_PREFIX.'_';
        foreach ($row as $column => $value) {
            if (!str_starts_with($column, $prefix)) {
                continue;
            }

            $translatedColumn = substr($column, strlen($prefix));
            $info = $this->hydrateColumnInfo($translatedColumn);
            if (null === $info || ($info['isScalar'] ?? false)) {
                continue;
            }

            $translations[$info['dqlAlias']][$info['fieldName']] = $row[$translatedColumn];
            // Hydrate real PHP values, never transport envelopes in mapped properties.
            $row[$translatedColumn] = $value;
        }

        $identifiers = [];
        foreach ($row as $column => $value) {
            $info = $this->hydrateColumnInfo($column);
            if (null !== $info && ($info['isIdentifier'] ?? false) && isset($translations[$info['dqlAlias']])) {
                $identifiers[$info['dqlAlias']][$info['fieldName']] = isset($info['type'])
                    ? $info['type']->convertToPHPValue($value, $this->platform)
                    : $value;
            }
        }

        $em = $this->getEntityManager();
        $uow = $em->getUnitOfWork();
        $pending = [];
        foreach ($identifiers as $alias => $data) {
            if (in_array(null, $data, true)) {
                continue; // An empty side of an outer join has no entity.
            }

            $meta = $em->getClassMetadata($this->resultSetMapping()->aliasMap[$alias]);
            $id = $this->identifierFlattener->flattenIdentifier($meta, $data);
            $entity = $uow->tryGetById($id, $meta->rootEntityName);
            if (false !== $entity && !$em->isUninitializedObject($entity)
                && (!isset($this->hints[Query::HINT_REFRESH])
                    || (isset($this->hints[Query::HINT_REFRESH_ENTITY]) && $this->hints[Query::HINT_REFRESH_ENTITY] !== $entity))) {
                continue;
            }

            $pending[] = [$id, $meta->rootEntityName, $translations[$alias]];
        }

        parent::hydrateRow($row);

        if ([] === $pending) {
            return;
        }

        // ORM 3.8 dispatches postLoad after this row, possibly after outer hydrator cleanup.
        $fallback = $this->hints[TranslatableListener::HINT_FALLBACK] ?? false;
        $listener = $this->getTranslatableListener();
        foreach ($pending as [$id, $rootClass, $fields]) {
            $entity = $uow->tryGetById($id, $rootClass);
            if (false !== $entity) {
                $listener->setHydratedTranslations($em, $entity, $fields, false === $fallback ? $listener->getTranslationFallback() : (bool) $fallback);
            }
        }
    }

    protected function getTranslatableListener(): TranslatableListener
    {
        foreach ($this->getEntityManager()->getEventManager()->getAllListeners() as $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof TranslatableListener) {
                    return $listener;
                }
            }
        }

        throw new RuntimeException('The translation listener could not be found');
    }
}
