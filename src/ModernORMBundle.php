<?php

declare(strict_types=1);

namespace ModernORM\Bundle;

use ModernORM\Bundle\DependencyInjection\ModernORMExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class ModernORMBundle extends Bundle
{
    public function getPath(): string { return dirname(__DIR__); }
    public function getContainerExtension(): ?ExtensionInterface
    {
        return $this->extension ??= new ModernORMExtension();
    }
}
