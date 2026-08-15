<?php
declare(strict_types=1);
namespace HolyMD\Tests\Geo;
use HolyMD\Geo\EndpointPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
 final class EndpointPolicyTest extends TestCase {
  protected function setUp(): void {
   putenv('HOLYMD_ALLOW_TUN_PROXY');
   \HolyMD\Config\Env::set('HOLYMD_ALLOW_TUN_PROXY', null);
  }
  protected function tearDown(): void {
   putenv('HOLYMD_ALLOW_TUN_PROXY');
   \HolyMD\Config\Env::set('HOLYMD_ALLOW_TUN_PROXY', null);
  }
  public function test_returns_the_public_addresses_that_were_validated_for_the_transport(): void { $addresses=(new EndpointPolicy(static fn(string $host):array=>['8.8.8.8','2606:4700:4700::1111']))->validate('https://provider.test/v1/chat/completions',true); self::assertSame(['8.8.8.8','2606:4700:4700::1111'],$addresses); }
  public function test_accepts_a_bracketed_public_ipv6_literal_without_dns_resolution(): void { $addresses=(new EndpointPolicy())->validate('https://[2606:4700:4700::1111]/v1/chat/completions',true); self::assertSame(['2606:4700:4700::1111'],$addresses); }
  public function test_rejects_the_benchmark_network_without_an_injected_resolver_or_tun_setting(): void {
   $this->expectException(InvalidArgumentException::class);(new EndpointPolicy())->validate('https://198.18.0.1/v1/chat/completions',true);
  }
  public function test_accepts_the_benchmark_network_with_tun_setting(): void {
   putenv('HOLYMD_ALLOW_TUN_PROXY=1');
   \HolyMD\Config\Env::set('HOLYMD_ALLOW_TUN_PROXY', '1');
   $addresses = (new EndpointPolicy())->validate('https://198.18.0.1/v1/chat/completions', true);
   self::assertSame(['198.18.0.1'], $addresses);
  }
  #[DataProvider('blocked')] public function test_rejects_unsafe_endpoint(string $url,array $addresses): void { $this->expectException(InvalidArgumentException::class);(new EndpointPolicy(static fn(string $host):array=>$addresses))->validate($url,true); }
  public static function blocked(): array{return [['http://provider.test/api',['8.8.8.8']],['https://user@provider.test/api',['8.8.8.8']],['https://127.0.0.1/api',['127.0.0.1']],['https://provider.test/api',['10.0.0.1']],['https://provider.test/api',['169.254.1.1']]];}
  #[DataProvider('nonGlobalAddresses')] public function test_rejects_every_non_global_unicast_range(string $address): void { $this->expectException(InvalidArgumentException::class);(new EndpointPolicy(static fn(string $host):array=>[$address]))->validate('https://provider.test/api',true); }
  public static function nonGlobalAddresses(): array { return array_map(static fn(string $ip):array=>[$ip],['0.0.0.0','10.0.0.1','100.64.0.1','127.0.0.1','169.254.1.1','172.16.0.1','192.0.0.1','192.0.2.1','192.168.1.1','198.18.0.1','198.51.100.1','203.0.113.1','224.0.0.1','240.0.0.1','255.255.255.255','::','::1','fc00::1','fe80::1','ff00::1','2001:db8::1','4000::1','::10.0.0.1','::ffff:10.0.0.1','::ffff:192.168.1.1']); }
 }
