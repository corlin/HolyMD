<?php
declare(strict_types=1);
namespace HolyMD\Geo;
use InvalidArgumentException;
final readonly class EndpointPolicy {
 /** @param null|callable(string):list<string> $resolver */ public function __construct(private mixed $resolver=null) {}
 public function validate(string $url,bool $hasCredential): void {
  $parts=parse_url($url);if(!is_array($parts)||($parts['scheme']??null)!=='https'||isset($parts['user'])||isset($parts['pass'])||!is_string($parts['host']??null))throw new InvalidArgumentException('GEO endpoint must be an HTTPS URL without user information.');
  $host=$parts['host'];$addresses=filter_var($host,FILTER_VALIDATE_IP)?[$host]:($this->resolver!==null?($this->resolver)($host):$this->resolve($host));if($addresses===[])throw new InvalidArgumentException('GEO endpoint DNS resolution failed.');
  foreach($addresses as $address)if(filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)throw new InvalidArgumentException('GEO endpoint must resolve only to public addresses.');
 }
 /** @return list<string> */ private function resolve(string $host): array { $records=dns_get_record($host,DNS_A|DNS_AAAA);$result=[];foreach($records?:[] as $record){if(isset($record['ip']))$result[]=$record['ip'];if(isset($record['ipv6']))$result[]=$record['ipv6'];}return array_values(array_unique($result)); }
}
