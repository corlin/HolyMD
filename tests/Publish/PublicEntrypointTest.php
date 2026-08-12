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
        self::assertStringContainsString("str_starts_with(\$path, '/media/')", $source);
        self::assertStringContainsString("/content/media/", $source);
    }

    public function test_apache_serves_generated_files_directly_and_preserves_admin_and_assets(): void
    {
        $rules = (string) file_get_contents(__DIR__ . '/../../public/.htaccess');
        self::assertStringContainsString('RewriteRule ^admin', $rules);
        self::assertStringContainsString('RewriteRule ^assets/', $rules);
        self::assertStringContainsString('.holymd-current/$1', $rules);
        self::assertStringContainsString('.holymd-current/$1/index.html', $rules);
        self::assertStringContainsString('(?:site|\\.holymd-current)', $rules);
        self::assertStringContainsString('.holymd-current/$1 [END]', $rules);
    }
}
