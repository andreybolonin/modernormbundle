<?php

declare(strict_types=1);

namespace ModernORM\Bundle\CoreCompatibility;

use ModernORM\Bundle\ConnectionFactory;
use ModernORM\Bundle\Profiler\DataCollector;
use ModernORM\Bundle\Profiler\OperationHistory;
use ModernORM\Bundle\Profiler\SqlHistory;
use ModernORM\Bundle\Profiler\ProfileView;
use ModernORM\Cache\Logging\StatisticsCacheLogger;
use ModernORM\Statistics;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DmlProfileTest extends TestCase
{
    public function testSavedProfileUsesExecutedStatementsAndKeepsRollbackAndZeroRows(): void
    {
        $statistics = new Statistics(true);
        $db = ConnectionFactory::create(getenv('MODERNORM_TEST_URL'), '17', $statistics);
        try {
            $db->executeStatement('CREATE TEMP TABLE morm_dml_profile (id INT PRIMARY KEY)');
            $db->executeStatement('INSERT INTO morm_dml_profile VALUES (1),(2)');
            $db->executeStatement('UPDATE morm_dml_profile SET id = id WHERE id = -1');
            $db->beginTransaction();
            $db->executeStatement('UPDATE morm_dml_profile SET id = id WHERE id = 1');
            $db->rollBack();
            try {
                $db->executeStatement('INSERT INTO morm_dml_profile VALUES (1)');
                self::fail();
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            }
            $collector = new DataCollector($statistics, new StatisticsCacheLogger(), new OperationHistory(0), false, new SqlHistory(false, 0, 128));
            $collector->collect(Request::create('/'), new Response());
            $saved = unserialize(serialize($collector));
            $statistics->reset();
            self::assertSame(1, $saved->getMetrics()['dml']['insert']['succeeded']);
            self::assertSame(2, $saved->getMetrics()['dml']['insert']['affectedRows']);
            self::assertSame(1, $saved->getMetrics()['dml']['insert']['errors']);
            self::assertSame(2, $saved->getMetrics()['dml']['update']['succeeded']);
            self::assertSame(1, $saved->getMetrics()['dml']['update']['affectedRows']);
            $loader = new \Twig\Loader\FilesystemLoader();
            $loader->addPath(dirname(__DIR__, 3).'/templates', 'ModernORM');
            $twig = new \Twig\Environment($loader, ['strict_variables' => true]);
            $html = $twig->render('@ModernORM/Collector/tabs/unitofwork.html.twig', ['collector' => $saved, 'metrics' => $saved->getMetrics()]);
            self::assertStringContainsString('Executed SQL writes', $html);
            self::assertStringContainsString('Known affected rows', $html);
            self::assertStringContainsString('rollback does not erase', $html);
        } finally {
            $db->close();
        }
    }

    public function testOlderSerializedSnapshotReportsUnavailableDml(): void
    {
        $snapshot = new \ModernORM\Statistics\Snapshot(0, 0, 0, 0, 0, 0);
        $values = get_object_vars($snapshot);
        unset($values['dml']);
        $class = $snapshot::class;
        $serialized = 'O:'.strlen($class).':"'.$class.'":'.count($values).':{';
        foreach ($values as $name => $value) {
            $serialized .= serialize($name).serialize($value);
        }
        $old = unserialize($serialized.'}');
        self::assertNull(ProfileView::metrics($old)['dml']);
    }


    public function testOlderSavedCollectorMetricsRemainReadable(): void
    {
        $collector = (new \ReflectionClass(DataCollector::class))->newInstanceWithoutConstructor();
        $collector->__unserialize(['data' => ['metrics' => ['sql' => ['count' => 5]]]]);
        self::assertNull($collector->getMetrics()['dml']);
        self::assertSame(5, $collector->getMetrics()['sql']['count']);
    }

}
