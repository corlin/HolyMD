<?php
declare(strict_types=1);
namespace HolyMD\Geo;
final readonly class GeoConfiguration {
 public function __construct(public string $endpoint,public string $model,public bool $configured,public int $timeoutSeconds=30,public int $maxResponseBytes=262144) {}
 public static function fromEnvironment(): self { $endpoint=(string)(\HolyMD\Config\Env::get('HOLYMD_GEO_API_ENDPOINT')?:'https://api.deepseek.com/v1/chat/completions');$model=(string)(\HolyMD\Config\Env::get('HOLYMD_GEO_MODEL')?:'deepseek-v4-flash');$configured=(bool)(\HolyMD\Config\Env::get('HOLYMD_GEO_API_CREDENTIAL')&&\HolyMD\Config\Env::get('HOLYMD_GEO_API_KEY'));$timeout=max(1,min(120,(int)(\HolyMD\Config\Env::get('HOLYMD_GEO_TIMEOUT_SECONDS')?:30)));$max=max(1024,min(1048576,(int)(\HolyMD\Config\Env::get('HOLYMD_GEO_MAX_RESPONSE_BYTES')?:262144)));return new self($endpoint,$model,$configured,$timeout,$max); }
 /** @return array{configured:bool,endpointHost:string,model:string,timeoutSeconds:int,maxResponseBytes:int} */ public function status(): array{return ['configured'=>$this->configured,'endpointHost'=>(string)(parse_url($this->endpoint,PHP_URL_HOST)?:'invalid'),'model'=>$this->model,'timeoutSeconds'=>$this->timeoutSeconds,'maxResponseBytes'=>$this->maxResponseBytes];}
}
