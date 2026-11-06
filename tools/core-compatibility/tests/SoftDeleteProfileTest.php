<?php

declare(strict_types=1);

namespace ModernORM\Bundle\CoreCompatibility;

use ModernORM\Bundle\ConnectionFactory;
use ModernORM\Bundle\Profiler\DataCollector;
use ModernORM\Bundle\Profiler\OperationHistory;
use ModernORM\Bundle\Profiler\SqlHistory;
use ModernORM\Bulk\BulkPolicy;
use ModernORM\Cache\Logging\StatisticsCacheLogger;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use ModernORM\Statistics\BulkOperation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;

#[ORM\Entity, ORM\SoftDelete, ORM\Table(name: 'morm_profile_soft_item')]
final class ProfileSoftItem
{
    use \ModernORM\Behavior\SoftDeleteTrait;
    #[ORM\Id, ORM\Column(type: 'integer')] public int $id;
    #[ORM\Column(unique: true)] public string $code;
}

final class SoftDeleteProfileTest extends TestCase
{
    public function testSavedProfilerRendersRealBulkStateTransitionsAndFailure(): void
    {
        self::assertSame(realpath(getenv('MODERNORM_CORE_PATH').'/src/Bulk/BulkExecutor.php'), realpath((new \ReflectionClass(\ModernORM\Bulk\BulkExecutor::class))->getFileName()));
        $profiler = $this->createMock(Profiler::class);
        $profiler->method('isEnabled')->willReturn(true);
        $history = new OperationHistory(20, static fn (): Profiler => $profiler);
        $history->startRequest();
        $statistics = new Statistics(true, $history);
        $connection = ConnectionFactory::create(getenv('MODERNORM_TEST_URL'), '17', $statistics);
        $config = new Configuration(); $config->setMetadataDriverImpl(new AttributeDriver([]));
        $em = new EntityManager($connection, $config);
        try {
            $connection->executeStatement('CREATE TEMP TABLE morm_profile_soft_item (id INT PRIMARY KEY, code TEXT NOT NULL UNIQUE, deleted_at TIMESTAMP DEFAULT NULL)');
            $connection->executeStatement("INSERT INTO morm_profile_soft_item VALUES (1, 'private-one'), (2, 'private-two')");
            $history->reset(); $history->startRequest(); $statistics->reset();
            $em->bulk()->softDelete(ProfileSoftItem::class, 'id = :id', BulkPolicy::CLEAR, ['id' => 1], returning: ['id']);
            $em->bulk()->restore(ProfileSoftItem::class, 'id = :id', BulkPolicy::BYPASS, ['id' => 1], returning: ['id']);
            $em->bulk()->restore(ProfileSoftItem::class, 'id = :id', BulkPolicy::CLEAR, ['id' => 1]);
            try {
                $em->bulk()->softDelete(ProfileSoftItem::class, 'TRUE', BulkPolicy::CLEAR, values: ['code' => 'private-conflict']);
                self::fail('Expected atomic unique violation');
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {}
            $collector = new DataCollector($statistics, new StatisticsCacheLogger(), $history, false, new SqlHistory(false, 0, 128));
            $collector->collect(Request::create('/'), new Response());
            $saved = unserialize(serialize($collector));
            $statistics->reset(); $history->reset();
            $calls = $saved->getEventRows('bulk');
            self::assertSame(['soft_delete', 'restore', 'restore', 'soft_delete'], array_column(array_column($calls, 'bulk'), 'operation'));
            self::assertSame(['CLEAR', 'BYPASS', 'CLEAR', 'CLEAR'], array_column(array_column($calls, 'bulk'), 'policy'));
            self::assertSame([1, 1, 0, null], array_column(array_column($calls, 'bulk'), 'affectedRows'));
            self::assertSame([true, true, true, null], array_column(array_column($calls, 'bulk'), 'databaseCommitted'));
            self::assertSame(4, $saved->getMetrics()['bulk']['count']);
            self::assertSame(1, $saved->getMetrics()['bulk']['errors']);
            $loader = new \Twig\Loader\FilesystemLoader();
            $loader->addPath(dirname(__DIR__, 3).'/templates', 'ModernORM');
            $twig = new \Twig\Environment($loader, ['strict_variables' => true]);
            $html = $twig->render('@ModernORM/Collector/tabs/bulk.html.twig', ['collector' => $saved, 'metrics' => $saved->getMetrics()]);
            foreach (['SOFT_DELETE', 'RESTORE', 'CLEAR', 'BYPASS', 'Committed', 'Not established'] as $text) { self::assertStringContainsString($text, $html); }
            self::assertStringNotContainsString('private-', $html);
            self::assertSame('soft_delete', BulkOperation::SOFT_DELETE->value);
        } finally { $connection->close(); }
    }
}
