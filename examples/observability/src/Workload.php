<?php

declare(strict_types=1);
namespace ModernORM\Bundle\Example\Observability;

use ModernORM\EntityManagerInterface;
use ModernORM\Statistics;
use ModernORM\Bulk\BulkPolicy;
use Symfony\Component\HttpFoundation\JsonResponse;

final class Workload
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly Statistics $statistics) {}

    public function __invoke(): JsonResponse { return new JsonResponse($this->run()); }

    public function run(): array
    {
        $em = $this->em;
        $db = $em->getConnection();
        foreach ([
            'CREATE TEMP TABLE morm_otel_customer (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL)',
            'CREATE TEMP TABLE morm_otel_order (id INT PRIMARY KEY, customer_id INT NOT NULL)',
            'CREATE TEMP TABLE morm_otel_line (id INT PRIMARY KEY, order_id INT NOT NULL)',
            'CREATE TEMP TABLE morm_otel_product (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL)',
        ] as $sql) { $db->executeStatement($sql); }
        $em->getCache()->evictAll(Entity\Product::class);
        for ($i = 1; $i <= 3; ++$i) {
            $customer = new Entity\Customer(); $customer->id = $i; $customer->name = 'Customer '.$i; $em->persist($customer);
            $order = new Entity\Order(); $order->id = $i; $order->customer = $customer; $em->persist($order);
            $line = new Entity\Line(); $line->id = $i; $line->order = $order; $em->persist($line);
        }
        $product = new Entity\Product(); $product->id = 1; $product->name = 'before'; $em->persist($product);
        $em->flush(); $em->clear();
        $orders = $em->getRepository(Entity\Order::class)->findBy([], ['id' => 'ASC']);
        foreach ($orders as $order) { $order->customer->name; $order->lines->count(); }
        $em->getCache()->evict(Entity\Product::class, 1);
        $em->clear(); $em->find(Entity\Product::class, 1);
        $em->clear(); $product = $em->find(Entity\Product::class, 1);
        $product->name = 'after'; $em->flush(); $em->clear();
        if ($em->find(Entity\Product::class, 1)->name !== 'after') { throw new \RuntimeException('Stale L2 data.'); }
        $rows = [];
        for ($i = 100; $i < 132; ++$i) { $rows[] = ['id' => $i, 'name' => 'Imported '.$i]; }
        $em->bulk()->insert(Entity\Product::class, $rows, BulkPolicy::CLEAR, returning: ['id']);
        $em->getRepository(Entity\Product::class)->findBy([], ['id' => 'ASC']);
        $em->bulk()->update(Entity\Product::class, ['name' => 'Published'], 'id >= :start', BulkPolicy::CLEAR, ['start' => 100]);
        $em->bulk()->delete(Entity\Product::class, 'id >= :start', BulkPolicy::CLEAR, ['start' => 124]);
        // One known SQL error outside a transaction, to validate error counters as well as successes.
        try { $db->fetchOne('SELECT 1 / 0'); } catch (\Doctrine\DBAL\Exception) {}
        $snapshot = $this->statistics->snapshot();
        return ['pid' => getmypid(), 'sql' => $snapshot->sqlCount, 'sqlErrors' => $snapshot->sqlErrors, 'flush' => $snapshot->flushCount, 'batch' => $snapshot->batch->count, 'bulk' => $snapshot->bulk->count ?? null, 'observerErrors' => $snapshot->observerErrors];
    }
}
