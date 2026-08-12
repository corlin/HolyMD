<?php
declare(strict_types=1);
namespace HolyMD\Geo;
use InvalidArgumentException;
final readonly class EndpointPolicy {
 /** @param null|callable(string):list<string> $resolver */ public function __construct(private mixed $resolver=null) {}
 public function validate(string $url,bool $hasCredential): void {
  $parts=parse_url($url);if(!is_array($parts)||($parts['scheme']??null)!=='https'||isset($parts['user'])||isset($parts['pass'])||!is_string($parts['host']??null))throw new InvalidArgumentException('GEO endpoint must be an HTTPS URL without user information.');
  $host=$parts['host'];$addresses=filter_var($host,FILTER_VALIDATE_IP)?[$host]:($this->resolver!==null?($this->resolver)($host):$this->resolve($host));if($addresses===[])throw new InvalidArgumentException('GEO endpoint DNS resolution failed.');
  foreach($addresses as $address)if(!$this->isGlobalUnicast($address))throw new InvalidArgumentException('GEO endpoint must resolve only to public addresses.');
 }
 private function isGlobalUnicast(string $address): bool { $packed=@inet_pton($address);if($packed===false)return false;if(strlen($packed)===16&&substr($packed,0,12)==="\0\0\0\0\0\0\0\0\0\0\xff\xff")return $this->isGlobalUnicast((string)inet_ntop(substr($packed,12,4)));if(strlen($packed)===16&&substr($packed,0,12)==="\0\0\0\0\0\0\0\0\0\0\0\0")return false;if(strlen($packed)===16&&!$this->inCidr($packed,'2000::/3'))return false;$blockedV4=['0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16','172.16.0.0/12','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24','192.168.0.0/16','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4'];if($this->resolver!==null||getenv('HOLYMD_ALLOW_TUN_PROXY')==='0')$blockedV4[]='198.18.0.0/15';$blocked=strlen($packed)===4?$blockedV4:['2001::/23','2001:db8::/32','2002::/16','3fff::/20'];foreach($blocked as $cidr)if($this->inCidr($packed,$cidr))return false;return true; }
 private function inCidr(string $packed,string $cidr): bool { [$network,$bits]=explode('/',$cidr);$net=inet_pton($network);if($net===false||strlen($net)!==strlen($packed))return false;$bytes=intdiv((int)$bits,8);$remaining=(int)$bits%8;if(substr($packed,0,$bytes)!==substr($net,0,$bytes))return false;if($remaining===0)return true;$mask=(0xff<<(8-$remaining))&0xff;return (ord($packed[$bytes])&$mask)===(ord($net[$bytes])&$mask); }
 /** @return list<string> */ private function resolve(string $host): array { $records=@dns_get_record($host,DNS_A|DNS_AAAA);$result=[];foreach($records?:[] as $record){if(isset($record['ip']))$result[]=$record['ip'];if(isset($record['ipv6']))$result[]=$record['ipv6'];}if($result===[]) {$ips=@gethostbynamel($host);if(is_array($ips)){$result=array_merge($result,$ips);}else{$ip=@gethostbyname($host);if($ip!==''&&$ip!==$host)$result[]=$ip;}}return array_values(array_unique($result)); }
}
