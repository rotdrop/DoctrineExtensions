<?php

declare(strict_types=1);

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Tests\Translatable\Fixture;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
class HydrationLabel
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: HydrationArticle::class, inversedBy: 'labels')]
    public HydrationArticle $article;

    #[ORM\Id]
    #[ORM\Column]
    public string $code;

    #[ORM\Column]
    #[Gedmo\Translatable(untranslated: 'untranslatedLabel')]
    public string $label;

    public ?string $untranslatedLabel = null;
}
