<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Translatable\Hydrator\ORM;

use Doctrine\ORM\Internal\Hydration\SimpleObjectHydrator as BaseSimpleObjectHydrator;
use Gedmo\Exception\RuntimeException;
use Gedmo\Tool\ORM\Hydration\EntityManagerRetriever;
use Gedmo\Tool\ORM\Hydration\HydratorCompat;
use Gedmo\Translatable\TranslatableListener;

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
class SimpleObjectHydrator extends BaseSimpleObjectHydrator
{
    use EntityManagerRetriever;
    use HydratorCompat;

    /**
     * State of postProcessHydrator for listener between hydrations
     *
     * @see SimpleObjectHydrator::prepare()
     * @see SimpleObjectHydrator::cleanup()
     */
    private $savedPostProcessHydrator;

    protected function doPrepareWithCompat(): void
    {
        $listener = $this->getTranslatableListener();
        $this->savedPostProcessHydrator = $listener->isPostProcessHydrator();
        $listener->setPostProcessHydrator(true);
        parent::prepare();
    }

    protected function hydrateRowData(array $row, array &$result)
    {
        foreach (array_keys($row) as $key) {
            if (str_starts_with($key, TranslationWalker::UNTRANSLATED_FIELD_PREFIX)) {
                $baseKey = substr($key, strlen(TranslationWalker::UNTRANSLATED_FIELD_PREFIX) + 1);
                $row[$baseKey] = json_encode([ 'translated' => $row[$baseKey], 'untranslated' => $row[$key] ]);
            }
        }
        parent::hydrateRowData($row, $result);
    }

    /**
     * @return void
     */
    protected function doCleanupWithCompat(): void
    {
        parent::cleanup();
        $listener = $this->getTranslatableListener();
        $listener->setPostProcessHydrator($this->savedPostProcessHydrator ?? false);
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
