<?php
declare(strict_types=1);
namespace HolyMD\Tests\Publish;
use PHPUnit\Framework\TestCase;
final class PublicEntrypointTest extends TestCase
{
    public function test_entrypoint_routes_public_requests_to_dedicated_generated_tree(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../public/index.php');
        self::assertStringContainsString('HOLYMD_PUBLIC_TREE', $source);
        self::assertStringContainsString("'index.html'", $source);
        self::assertStringContainsString('readfile($candidate)', $source);
        self::assertStringContainsString("str_starts_with(\$path, '/admin')", $source);
    }
}
