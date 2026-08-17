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
use HolyMD\Geo\GeoConfiguration;
use HolyMD\Geo\StreamHttpTransport;
use HolyMD\Config\SiteTimezone;
use HolyMD\Admin\AdminTimeFormatter;

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
            SiteTimezone::class => static fn (): SiteTimezone => SiteTimezone::fromEnvironment(),
            AdminTimeFormatter::class => static fn (SiteTimezone $timezone): AdminTimeFormatter => new AdminTimeFormatter($timezone),
            GeoConfiguration::class => static fn (): GeoConfiguration => GeoConfiguration::fromEnvironment(),
            AiClient::class => static function (GeoConfiguration $configuration): AiClient { $credential = $configuration->configured ? EncryptedApiCredential::fromEnvironment()->reveal() : ''; return new ConfiguredAiClient($credential, $configuration->endpoint, $configuration->model, new StreamHttpTransport(), $configuration->timeoutSeconds, $configuration->maxResponseBytes); },
        ]);

        return $builder->build();
    }
}
