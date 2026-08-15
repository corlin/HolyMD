<?php
declare(strict_types=1);
namespace HolyMD\Geo;

use Closure;

final class StreamHttpTransport implements HttpTransport {
    /** @var Closure(array<int,mixed>):array{succeeded:bool,status:int,error:string,errno:int} */
    private Closure $execute;

    /** @param null|callable(array<int,mixed>):array{succeeded:bool,status:int,error:string,errno:int} $execute */
    public function __construct(?callable $execute = null) {
        $this->execute=Closure::fromCallable($execute??static function(array $options):array {
            $handle=curl_init();if($handle===false)throw new GeoAiException('GEO provider transport could not be initialized.',false);
            try { curl_setopt_array($handle,$options);$succeeded=curl_exec($handle)!==false;return ['succeeded'=>$succeeded,'status'=>(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE),'error'=>curl_error($handle),'errno'=>curl_errno($handle)]; }
            finally { if (\PHP_VERSION_ID < 80500) @curl_close($handle); }
        });
    }

    public function post(string $url,array $headers,string $body,int $timeoutSeconds,int $maxResponseBytes,array $resolvedAddresses=[]): HttpResponse {
        $parts=parse_url($url);$host=is_array($parts)&&is_string($parts['host']??null)?trim($parts['host'],'[]'):'';$port=(int)($parts['port']??443);
        if(($parts['scheme']??null)!=='https'||$host===''||$port<1||$port>65535)throw new GeoAiException('GEO endpoint is invalid for the HTTPS transport.',false);
        if($resolvedAddresses===[])throw new GeoAiException('GEO provider transport requires a validated public address.',false);
        $pinned=[];foreach($resolvedAddresses as $address){if(!is_string($address)||inet_pton($address)===false)throw new GeoAiException('GEO provider transport received an invalid validated address.',false);$pinned[]=str_contains($address,':')?'['.$address.']':$address;}
        $lines=[];foreach($headers as $name=>$value)$lines[]=$name.': '.$value;
        $response='';$tooLarge=false;
        $write=static function(mixed $handle,string $chunk)use(&$response,&$tooLarge,$maxResponseBytes):int{$length=strlen($chunk);if(strlen($response)+$length>$maxResponseBytes){$tooLarge=true;return 0;}$response.=$chunk;return $length;};
        $options=[CURLOPT_URL=>$url,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$lines,CURLOPT_TIMEOUT=>$timeoutSeconds,CURLOPT_CONNECTTIMEOUT=>min($timeoutSeconds,10),CURLOPT_RETURNTRANSFER=>false,CURLOPT_HEADER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_NOSIGNAL=>true,CURLOPT_RESOLVE=>[$host.':'.$port.':'.implode(',',$pinned)],CURLOPT_WRITEFUNCTION=>$write];
        $result=($this->execute)($options);
        if($tooLarge)throw new GeoAiException('GEO provider response exceeded the configured size limit.',false);
        if(!$result['succeeded']||$result['status']===0)throw new GeoAiException('GEO provider connection failed or timed out.',true);
        return new HttpResponse($result['status'],$response);
    }
}
