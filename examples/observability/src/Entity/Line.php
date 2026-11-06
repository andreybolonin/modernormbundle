<?php

namespace ModernORM\Bundle\Example\Observability\Entity;

use ModernORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'morm_otel_line')]
class Line
{
    #[ORM\Id] #[ORM\Column(type: 'integer')] public int $id;
    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id')]
    public Order $order;
}
