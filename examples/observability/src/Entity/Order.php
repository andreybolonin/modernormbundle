<?php
namespace ModernORM\Bundle\Example\Observability\Entity;
use ModernORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
#[ORM\Entity]
#[ORM\Table(name: 'morm_otel_order')]
class Order
{
    #[ORM\Id] #[ORM\Column(type: 'integer')] public int $id;
    #[ORM\ManyToOne(targetEntity: Customer::class, batchSize: 32)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id')]
    public Customer $customer;
    #[ORM\OneToMany(targetEntity: Line::class, mappedBy: 'order', batchSize: 32)]
    public Collection $lines;
    public function __construct() { $this->lines = new ArrayCollection(); }
}
