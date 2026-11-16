<?php

declare(strict_types=1);

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Tests\Translatable\Fixture;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
class HydrationArticle
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    public int $id;

    #[ORM\Column]
    #[Gedmo\Translatable(untranslated: 'untranslatedTitle')]
    public string $title;

    public ?string $untranslatedTitle = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Gedmo\Translatable(untranslated: 'untranslatedNumber')]
    public ?int $number = null;

    public ?int $untranslatedNumber = null;

    /**
     * @var Collection<int, HydrationLabel>
     */
    #[ORM\OneToMany(targetEntity: HydrationLabel::class, mappedBy: 'article')]
    public Collection $labels;

    public function __construct()
    {
        $this->labels = new ArrayCollection();
    }
}
