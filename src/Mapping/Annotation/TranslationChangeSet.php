<?php

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Mapping\Annotation;

use Attribute;
use Doctrine\Common\Annotations\Annotation;
use Gedmo\Mapping\Annotation\Annotation as GedmoAnnotation;

/**
 * Original values annotation for Translatable behavioral extension. The given
 * property records the original pre-change values of the translated
 * properties. Unfortunately Translatable has to clean the change-set of the
 * original entities before they are handed back to ORM. This, however, spoils
 * any {pre,post}Update handlers as they will only receive the clean changeset
 * of the entity.
 *
 * @Annotation
 * @Target("PROPERTY")
 *
 * @author Clausd-Justus Heine <himself@claus-justus-heine.de>
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class TranslationChangeSet implements GedmoAnnotation
{}
