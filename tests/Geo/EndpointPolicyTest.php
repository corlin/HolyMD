<?php
declare(strict_types=1);
namespace HolyMD\Tests\Geo;
use HolyMD\Geo\EndpointPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
final class EndpointPolicyTest extends TestCase {
 public function test_accepts_https_host_resolving_only_public_addresses(): void { (new EndpointPolicy(static fn(string $host):array=>['8.8.8.8','2606:4700:4700::1111']))->validate('https://provider.test/v1/chat/completions',true); self::assertTrue(true); }
 #[DataProvider('blocked')] public function test_rejects_unsafe_endpoint(string $url,array $addresses): void { $this->expectException(InvalidArgumentException::class);(new EndpointPolicy(static fn(string $host):array=>$addresses))->validate($url,true); }
 public static function blocked(): array{return [['http://provider.test/api',['8.8.8.8']],['https://user@provider.test/api',['8.8.8.8']],['https://127.0.0.1/api',['127.0.0.1']],['https://provider.test/api',['10.0.0.1']],['https://provider.test/api',['169.254.1.1']]];}
 #[DataProvider('nonGlobalAddresses')] public function test_rejects_every_non_global_unicast_range(string $address): void { $this->expectException(InvalidArgumentException::class);(new EndpointPolicy(static fn(string $host):array=>[$address]))->validate('https://provider.test/api',true); }
 public static function nonGlobalAddresses(): array { return array_map(static fn(string $ip):array=>[$ip],['0.0.0.0','10.0.0.1','100.64.0.1','127.0.0.1','169.254.1.1','172.16.0.1','192.0.0.1','192.0.2.1','192.168.1.1','198.18.0.1','198.51.100.1','203.0.113.1','224.0.0.1','240.0.0.1','255.255.255.255','::','::1','fc00::1','fe80::1','ff00::1','2001:db8::1','4000::1','::10.0.0.1','::ffff:10.0.0.1','::ffff:192.168.1.1']); }
}
