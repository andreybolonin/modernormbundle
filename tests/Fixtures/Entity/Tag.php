<?php
namespace ModernORM\Bundle\Tests\Fixtures\Entity;
use ModernORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: 'mb_tag')]
class Tag
{
    #[ORM\Id] #[ORM\Column(type: 'integer')] public int $id;
    #[ORM\Column] public string $name;
}
