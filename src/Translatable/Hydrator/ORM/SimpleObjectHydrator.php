<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Translatable\Hydrator\ORM;

use Doctrine\ORM\Internal\Hydration\SimpleObjectHydrator as BaseSimpleObjectHydrator;
use Gedmo\Translatable\TranslatableListener;

/**
 * If query uses TranslationQueryWalker and is hydrating
 * objects - when it requires this custom object hydrator
 * in order to skip onLoad event from triggering retranslation
 * of the fields
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 */
class SimpleObjectHydrator extends BaseSimpleObjectHydrator
{
    /**
     * State of postProcessHydrator for listener between hydrations
     *
     * @see SimpleObjectHydrator::prepare()
     * @see SimpleObjectHydrator::cleanup()
     *
     * @var bool|null
     */
    private $savedPostProcessHydrator;

    /**
     * @return void
     */
    protected function prepare()
    {
        $listener = $this->getTranslatableListener();
        $this->savedPostProcessHydrator = $listener->isPostProcessHydrator();
        $listener->setPostProcessHydrator(true);
        parent::prepare();
    }

    protected function hydrateRowData(array $row, array &$result)
    {
        foreach ($row as $key => $value) {
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
    protected function cleanup()
    {
        parent::cleanup();
        $listener = $this->getTranslatableListener();
        $listener->setPostProcessHydrator($this->savedPostProcessHydrator ?? false);
    }

    /**
     * Get the currently used TranslatableListener
     *
     * @throws \Gedmo\Exception\RuntimeException if listener is not found
     *
     * @return TranslatableListener
     */
    protected function getTranslatableListener()
    {
        $translatableListener = null;
        foreach ($this->_em->getEventManager()->getListeners() as $event => $listeners) {
            foreach ($listeners as $hash => $listener) {
                if ($listener instanceof TranslatableListener) {
                    $translatableListener = $listener;

                    break 2;
                }
            }
        }

        if (null === $translatableListener) {
            throw new \Gedmo\Exception\RuntimeException('The translation listener could not be found');
        }

        return $translatableListener;
    }
}
