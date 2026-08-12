<?php

declare(strict_types=1);

namespace HolyMD;

use DI\Container;
use DI\ContainerBuilder;
use HolyMD\Config\Settings;
use HolyMD\Database\Connection;
use PDO;
use HolyMD\Geo\AiClient;
use HolyMD\Geo\ConfiguredAiClient;
use HolyMD\Geo\EncryptedApiCredential;

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
            AiClient::class => static fn (): AiClient => new ConfiguredAiClient((getenv('HOLYMD_GEO_API_CREDENTIAL') && getenv('HOLYMD_GEO_API_KEY')) ? EncryptedApiCredential::fromEnvironment() : null, (string) (getenv('HOLYMD_GEO_API_ENDPOINT') ?: 'https://api.invalid/geo')),
        ]);

        return $builder->build();
    }
}
