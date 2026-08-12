<?php
declare(strict_types=1);
namespace HolyMD\Geo;
use JsonException;
final readonly class ConfiguredAiClient implements AiClient {
 public function __construct(private string $credential,private string $endpoint,private string $model,private HttpTransport $transport=new StreamHttpTransport(),private int $timeoutSeconds=30,private int $maxResponseBytes=262144,private EndpointPolicy $endpointPolicy=new EndpointPolicy()) {}
 public function analyze(string $systemPrompt,string $articleMarkdown): AiResponse {
  if($this->credential==='')throw new GeoAiException('GEO AI credentials are not configured.',false);
  try{$this->endpointPolicy->validate($this->endpoint,true);}catch(\InvalidArgumentException $e){throw new GeoAiException($e->getMessage(),false);}
  $schema=['type'=>'object','additionalProperties'=>false,'required'=>['proposals','findings'],'properties'=>['proposals'=>['type'=>'array','items'=>['type'=>'object','additionalProperties'=>false,'required'=>['type','value_json'],'properties'=>['type'=>['type'=>'string','enum'=>GeoReview::TYPES],'value_json'=>['type'=>'string']]]],'findings'=>['type'=>'array','items'=>['type'=>'string']]]];
  $body=json_encode(['model'=>$this->model,'messages'=>[['role'=>'system','content'=>$systemPrompt],['role'=>'user','content'=>$articleMarkdown]],'response_format'=>['type'=>'json_schema','json_schema'=>['name'=>'geo_review','strict'=>true,'schema'=>$schema]]],JSON_THROW_ON_ERROR);
  $response=$this->transport->post($this->endpoint,['Authorization'=>'Bearer '.$this->credential,'Content-Type'=>'application/json','Accept'=>'application/json'],$body,$this->timeoutSeconds,$this->maxResponseBytes);
  if($response->status<200||$response->status>=300)throw new GeoAiException('GEO provider returned HTTP '.$response->status.'.',in_array($response->status,[408,409,425,429,500,502,503,504],true));
  try{$payload=json_decode($response->body,true,512,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new GeoAiException('GEO provider returned invalid JSON.',true);}
  $content=$payload['choices'][0]['message']['content']??null;if(!is_string($content)||$content==='')throw new GeoAiException('GEO provider response did not contain structured review content.',false);return new AiResponse($content);
 }
}
