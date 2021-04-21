<?php

namespace Gedmo\SoftDeleteable\Mapping\Event\Adapter;

use Gedmo\Mapping\Event\Adapter\ORM as BaseAdapterORM;
use Gedmo\SoftDeleteable\Mapping\Event\SoftDeleteableAdapter;

/**
 * Doctrine event adapter for ORM adapted
 * for SoftDeleteable behavior.
 *
 * @author David Buchmann <mail@davidbu.ch>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
final class ORM extends BaseAdapterORM implements SoftDeleteableAdapter
{
    /**
     * {@inheritDoc}
     */
    public function getDateValue($meta, $field, ?\DateTimeInterface $date = null)
    {
        $mapping = $meta->getFieldMapping($field);
        if (isset($mapping['type']) && 'integer' === $mapping['type']) {
            return empty($date) ? time() : $date->getTimestamp();
        }
        if (isset($mapping['type']) && in_array($mapping['type'], ['date_immutable', 'time_immutable', 'datetime_immutable', 'datetimetz_immutable'], true)) {
            $class = \DateTimeImmutable::class;
        } else {
            $class = \DateTime::class;
        }
        return empty($date)
            ? new $class()
            : $class::createFromFormat('U.u', $date->format('U.u'));
    }
}
