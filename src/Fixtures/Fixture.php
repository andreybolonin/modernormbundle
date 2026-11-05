<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Fixtures;

/** Only ModernORM fixtures are autoconfigured; Doctrine fixtures keep their own manager. */
abstract class Fixture extends \Doctrine\Common\DataFixtures\AbstractFixture
{
}
