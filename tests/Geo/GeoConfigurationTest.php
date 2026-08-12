<?php
declare(strict_types=1);
namespace HolyMD\Tests\Geo;
use HolyMD\Geo\GeoConfiguration;
use PHPUnit\Framework\TestCase;
final class GeoConfigurationTest extends TestCase {
 public function test_status_discloses_configuration_not_credentials(): void { $status=(new GeoConfiguration('https://api.test/v1/chat/completions','model-x',true,20,1000))->status(); self::assertSame(['configured'=>true,'endpointHost'=>'api.test','model'=>'model-x','timeoutSeconds'=>20,'maxResponseBytes'=>1000],$status); self::assertStringNotContainsString('secret',json_encode($status,JSON_THROW_ON_ERROR)); }
}
