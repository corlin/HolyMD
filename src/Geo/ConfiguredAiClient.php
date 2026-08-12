<?php
declare(strict_types=1);
namespace HolyMD\Geo;
use RuntimeException;
final readonly class ConfiguredAiClient implements AiClient {
 public function __construct(private ?EncryptedApiCredential $credential, private string $endpoint) {}
 public function analyze(string $systemPrompt, string $articleMarkdown): AiResponse { if($this->credential===null) throw new RuntimeException('GEO AI credentials are not configured.'); $context=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nAuthorization: Bearer ".$this->credential->reveal()."\r\n",'content'=>json_encode(['system'=>$systemPrompt,'article_markdown'=>$articleMarkdown],JSON_THROW_ON_ERROR),'timeout'=>30,'ignore_errors'=>true]]); $response=file_get_contents($this->endpoint,false,$context); if($response===false||!str_contains($http_response_header[0]??'',' 2')) throw new RuntimeException('GEO AI request failed.'); return new AiResponse($response); }
}
