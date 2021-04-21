<?php

namespace Gedmo\SoftDeleteable\Mapping\Event;

use Gedmo\Mapping\Event\AdapterInterface;

/**
 * Doctrine event adapter interface
 * for SoftDeleteable behavior
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
interface SoftDeleteableAdapter extends AdapterInterface
{
    /**
     * Get the current or given date value in a format accepted by field.
     *
     * @param object $meta
     * @param string $field
     * @param null|\DateTimeInterface $date
     *
     * @return mixed
     */
    public function getDateValue($meta, $field, ?\DateTimeInterface $date = null);
}
