<?php
namespace ModernORM\Bundle\Tests\Fixtures\Entity;
use ModernORM\Mapping as ORM;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
#[ORM\Entity]
#[ORM\Table(name: 'mb_order')]
class Order
{
    #[ORM\Id] #[ORM\Column(type: 'integer')] public int $id;
    #[ORM\Column] public string $name;
    #[ORM\ManyToOne(targetEntity: Customer::class, batchSize: 32)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false)]
    public Customer $customer;
    #[ORM\OneToMany(targetEntity: Line::class, mappedBy: 'order', batchSize: 32)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $lines;
    #[ORM\ManyToMany(targetEntity: Tag::class, batchSize: 32)]
    #[ORM\JoinTable(name: 'mb_order_tag', joinColumns: [new ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id')], inverseJoinColumns: [new ORM\JoinColumn(name: 'tag_id', referencedColumnName: 'id')])]
    public Collection $tags;
    public function __construct() { $this->lines = new ArrayCollection(); $this->tags = new ArrayCollection(); }
}
