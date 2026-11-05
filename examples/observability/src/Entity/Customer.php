<?php
namespace ModernORM\Bundle\Example\Observability\Entity;
use ModernORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: 'morm_otel_customer')]
class Customer
{
    #[ORM\Id] #[ORM\Column(type: 'integer')] public int $id;
    #[ORM\Column] public string $name;
}
