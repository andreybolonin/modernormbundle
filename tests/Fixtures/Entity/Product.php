<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests\Fixtures\Entity;

use ModernORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'modernorm_bundle_product')]
class Product
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    public int $id;

    #[ORM\Column]
    public string $name;
}
