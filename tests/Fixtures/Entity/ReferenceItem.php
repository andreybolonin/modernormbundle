<?php
namespace ModernORM\Bundle\Tests\Fixtures\Entity;
use ModernORM\Mapping as ORM;
use ModernORM\Cache\CacheWritePolicy;
#[ORM\Entity]
#[ORM\Table(name: 'mb_reference')]
#[ORM\Cacheable(ttl: 60, writePolicy: CacheWritePolicy::INVALIDATE)]
class ReferenceItem
{
    #[ORM\Id] #[ORM\Column(type: 'integer')] public int $id;
    #[ORM\Column] public string $name;
}
