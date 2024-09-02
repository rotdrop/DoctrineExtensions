<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Translatable\Hydrator\ORM;

use Doctrine\ORM\Internal\Hydration\ObjectHydrator as BaseObjectHydrator;
use Gedmo\Exception\RuntimeException;
use Gedmo\Tool\ORM\Hydration\EntityManagerRetriever;
use Gedmo\Tool\ORM\Hydration\HydratorCompat;
use Gedmo\Translatable\TranslatableListener;
use Gedmo\Translatable\Query\TreeWalker\TranslationWalker;

/**
 * If query uses TranslationQueryWalker and is hydrating
 * objects - when it requires this custom object hydrator
 * in order to skip onLoad event from triggering retranslation
 * of the fields
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 *
 * @final since gedmo/doctrine-extensions 3.11
 */
class ObjectHydrator extends BaseObjectHydrator
{
    use EntityManagerRetriever;
    use HydratorCompat;

    /**
     * State of postProcessHydrator for listener between hydrations
     *
     * @see ObjectHydrator::prepare()
     * @see ObjectHydrator::cleanup()
     */
    private $savedPostProcessHydrator;

    protected function doPrepareWithCompat(): void
    {
        $listener = $this->getTranslatableListener();
        $this->savedPostProcessHydrator = $listener->isPostProcessHydrator();
        $listener->setPostProcessHydrator(true);
        parent::prepare();
    }

    protected function doCleanupWithCompat(): void
    {
        parent::cleanup();
        $listener = $this->getTranslatableListener();
        $listener->setPostProcessHydrator($this->savedPostProcessHydrator ?? false);
    }

    protected function hydrateRowData(array $row, array &$result): void
    {
        foreach (array_keys($row) as $key) {
            if (str_starts_with($key, TranslationWalker::UNTRANSLATED_FIELD_PREFIX)) {
                $baseKey = substr($key, strlen(TranslationWalker::UNTRANSLATED_FIELD_PREFIX) + 1);
                $baseKeyInfo = $this->hydrateColumnInfo($baseKey) ?? [];
                if (!($baseKeyInfo['isScalar'] ?? false)) {
                    $row[$baseKey] = json_encode([ 'translated' => $row[$baseKey], 'untranslated' => $row[$key] ]);
                }
            }
        }
        parent::hydrateRowData($row, $result);
    }

    /**
     * Get the currently used TranslatableListener
     *
     * @throws RuntimeException if listener is not found
     *
     * @return TranslatableListener
     */
    protected function getTranslatableListener()
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
