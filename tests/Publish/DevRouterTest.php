<?php

declare(strict_types=1);

namespace HolyMD\Tests\Publish;

use PHPUnit\Framework\TestCase;

final class DevRouterTest extends TestCase
{
    public function test_dev_router_forwards_clean_article_routes_to_the_front_controller(): void
    {
        $router = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/holymd-dev-router.php');

        self::assertStringContainsString("require \$publicRoot . '/index.php'", $router);
        self::assertStringContainsString("return false", $router);
        self::assertStringContainsString("DIRECTORY_SEPARATOR . 'site'", $router);
    }
}
