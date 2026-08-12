<?php

declare(strict_types=1);

namespace HolyMD;

use DI\Container;
use DI\ContainerBuilder;
use HolyMD\Config\Settings;
use HolyMD\Database\Connection;
use PDO;

final class Bootstrap
{
    public static function createContainer(?string $projectRoot = null): Container
    {
        $projectRoot ??= dirname(__DIR__);
        $settings = Settings::fromEnvironment($projectRoot);

        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            Settings::class => $settings,
            Connection::class => static fn (): Connection => new Connection($settings),
            PDO::class => static fn (Connection $connection): PDO => $connection->pdo(),
        ]);

        return $builder->build();
    }
}
