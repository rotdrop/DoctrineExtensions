<?php

declare(strict_types=1);

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Tests\Sluggable\Fixture\Handler;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Sluggable\Handler\RelativeSlugHandler;

#[ORM\Entity]
class HandlerOnlySlug
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[Gedmo\Slug]
    #[Gedmo\SlugHandler(class: RelativeSlugHandler::class, options: ['relationField' => 'article', 'relationSlugField' => 'slug', 'separator' => ''])]
    #[ORM\Column(length: 64, unique: true)]
    public ?string $slug = null;

    #[ORM\ManyToOne(targetEntity: Article::class)]
    public ?Article $article = null;
}
