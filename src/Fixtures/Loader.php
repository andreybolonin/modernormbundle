<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Fixtures;

use Doctrine\Common\DataFixtures\FixtureInterface;

final class Loader extends \Doctrine\Common\DataFixtures\Loader
{
    private array $services = [];

    public function __construct(iterable $fixtures)
    {
        foreach ($fixtures as $fixture) {
            if (!$fixture instanceof FixtureInterface) {
                throw new \InvalidArgumentException('modernorm.fixture services must implement FixtureInterface.');
            }
            if (isset($this->services[$fixture::class])) {
                throw new \LogicException('Duplicate ModernORM fixture class: '.$fixture::class);
            }
            $this->services[$fixture::class] = $fixture;
        }
    }

    public function select(array $groups): array
    {
        foreach ($this->services as $class => $fixture) {
            $names = [$class, (new \ReflectionClass($class))->getShortName()];
            if ($fixture instanceof FixtureGroupInterface) {
                $names = [...$names, ...$fixture::getGroups()];
            }
            if ([] === $groups || [] !== array_intersect($groups, $names)) {
                $this->addFixture($fixture);
            }
        }
        $fixtures = $this->getFixtures();
        if ([] === $fixtures) {
            throw new \InvalidArgumentException('No ModernORM fixtures match the selected groups.');
        }

        return array_values($fixtures);
    }

    protected function createFixture(string $class): FixtureInterface
    {
        return $this->services[$class] ?? throw new \LogicException('Register the fixture dependency as a modernorm.fixture service: '.$class);
    }
}
