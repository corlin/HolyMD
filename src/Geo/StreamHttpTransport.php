<?php
declare(strict_types=1);
namespace HolyMD\Geo;
final class StreamHttpTransport implements HttpTransport {
    public function post(string $url, array $headers, string $body, int $timeoutSeconds, int $maxResponseBytes): HttpResponse {
        $lines=[];foreach($headers as $name=>$value)$lines[]=$name.': '.$value;
        $context=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$lines),'content'=>$body,'timeout'=>$timeoutSeconds,'ignore_errors'=>true,'follow_location'=>0]]);
        $stream=@fopen($url,'rb',false,$context); if($stream===false) throw new GeoAiException('GEO provider connection failed or timed out.',true);
        $response=stream_get_contents($stream,$maxResponseBytes+1);$metadata=stream_get_meta_data($stream);fclose($stream);
        if($response===false)throw new GeoAiException('GEO provider response could not be read.',true);
        if(strlen($response)>$maxResponseBytes)throw new GeoAiException('GEO provider response exceeded the configured size limit.',false);
        $statusLine=$metadata['wrapper_data'][0]??'';preg_match('/\s(\d{3})\s/',(string)$statusLine,$matches);return new HttpResponse((int)($matches[1]??0),$response);
    }
}
