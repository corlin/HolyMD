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
        self::assertStringContainsString("str_starts_with(\$path, '/assets/')", $source);
        self::assertStringContainsString("str_starts_with(\$path, '/media/')", $source);
        self::assertStringContainsString('clearstatcache(true)', $source);
        self::assertStringContainsString("/content/media/", $source);
    }

    public function test_apache_serves_generated_files_directly_and_preserves_admin_and_assets(): void
    {
        $rules = (string) file_get_contents(__DIR__ . '/../../public/.htaccess');
        self::assertStringContainsString('RewriteRule ^admin', $rules);
        self::assertStringContainsString('RewriteRule ^assets/(?:admin\\.css|admin\\.js|fonts/[a-z0-9.-]+\\.woff2)$', $rules);
        self::assertStringNotContainsString('RewriteRule ^assets/ -', $rules);
        self::assertStringContainsString('.holymd-current/$1', $rules);
        self::assertStringContainsString('.holymd-current/$1/index.html', $rules);
        self::assertStringContainsString('(?:site|\\.holymd-current)', $rules);
        self::assertStringContainsString('.holymd-current/$1 [END]', $rules);
    }

    public function test_entrypoint_serves_the_self_hosted_icon_font(): void
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=1', $root . '/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            ['REQUEST_URI' => '/assets/fonts/material-symbols-outlined-v2.woff2', 'REQUEST_METHOD' => 'GET'],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $body = stream_get_contents($pipes[1]);
        $errorLog = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $errorLog);
        self::assertIsString($body);
        // wOF2 magic bytes.
        self::assertStringStartsWith('wOF2', $body);
    }

    public function test_entrypoint_rejects_unknown_asset_paths(): void
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=1', $root . '/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            ['REQUEST_URI' => '/assets/fonts/nope.woff2', 'REQUEST_METHOD' => 'GET'],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $body = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertSame('', $body);
    }

    public function test_entrypoint_serves_hashed_site_assets_from_the_generated_tree(): void
    {
        $root = dirname(__DIR__, 2);
        $fixture = sys_get_temp_dir() . '/holymd-entrypoint-' . bin2hex(random_bytes(6));
        mkdir($fixture . '/assets', 0777, true);
        file_put_contents($fixture . '/assets/site.abcdef1234.css', 'body{color:#123}');
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=1', $root . '/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            ['REQUEST_URI' => '/assets/site.abcdef1234.css', 'REQUEST_METHOD' => 'GET', 'HOLYMD_PUBLIC_TREE' => $fixture],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $body = stream_get_contents($pipes[1]);
        $errorLog = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $errorLog);
        self::assertSame('body{color:#123}', $body);

        unlink($fixture . '/assets/site.abcdef1234.css');
        rmdir($fixture . '/assets');
        rmdir($fixture);
    }

    public function test_admin_boot_failure_returns_a_generic_service_page_without_a_stack_trace(): void
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=1', $root . '/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            // A non-empty, unreachable DSN: proc_open drops empty-string env vars,
            // and an unset HOLYMD_DSN would fall back to the developer's .env.
            ['REQUEST_URI' => '/admin/login', 'REQUEST_METHOD' => 'GET', 'HOLYMD_DSN' => 'mysql:host=127.0.0.1;dbname=holymd_does_not_exist'],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $body = stream_get_contents($pipes[1]);
        $errorLog = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $errorLog);
        self::assertIsString($body);
        self::assertStringContainsString('Administration is temporarily unavailable', $body);
        self::assertStringNotContainsString('Stack trace', $body);
        self::assertStringNotContainsString('HOLYMD_DSN', $body);
        self::assertStringNotContainsString($root, $body);
    }
}
