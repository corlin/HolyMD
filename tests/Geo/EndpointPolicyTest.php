<?php
declare(strict_types=1);
namespace HolyMD\Tests\Geo;
use HolyMD\Geo\EndpointPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
final class EndpointPolicyTest extends TestCase {
 public function test_accepts_https_host_resolving_only_public_addresses(): void { (new EndpointPolicy(static fn(string $host):array=>['203.0.113.10']))->validate('https://provider.test/v1/chat/completions',true); self::assertTrue(true); }
 #[DataProvider('blocked')] public function test_rejects_unsafe_endpoint(string $url,array $addresses): void { $this->expectException(InvalidArgumentException::class);(new EndpointPolicy(static fn(string $host):array=>$addresses))->validate($url,true); }
 public static function blocked(): array{return [['http://provider.test/api',['8.8.8.8']],['https://user@provider.test/api',['8.8.8.8']],['https://127.0.0.1/api',['127.0.0.1']],['https://provider.test/api',['10.0.0.1']],['https://provider.test/api',['169.254.1.1']]];}
}
