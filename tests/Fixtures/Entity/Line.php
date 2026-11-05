<?php
namespace ModernORM\Bundle\Tests\Fixtures\Entity;
use ModernORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: 'mb_line')]
class Line
{
    #[ORM\Id] #[ORM\Column(type: 'integer')] public int $id;
    #[ORM\Column] public string $name;
    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false)]
    public Order $order;
}
