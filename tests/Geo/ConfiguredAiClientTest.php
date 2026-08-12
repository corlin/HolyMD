<?php
declare(strict_types=1);
namespace HolyMD\Tests\Geo;
use HolyMD\Geo\ConfiguredAiClient;
use HolyMD\Geo\GeoAiException;
use HolyMD\Geo\HttpResponse;
use HolyMD\Geo\HttpTransport;
use HolyMD\Geo\EndpointPolicy;
use PHPUnit\Framework\TestCase;

final class ConfiguredAiClientTest extends TestCase
{
    public function test_sends_openai_compatible_analysis_request_and_extracts_json_content(): void
    {
        $transport = new RecordingTransport(new HttpResponse(200, json_encode(['choices' => [['message' => ['content' => '{"proposals":[],"findings":[]}']]]], JSON_THROW_ON_ERROR)));
        $client = new ConfiguredAiClient('secret', 'https://provider.test/v1/chat/completions', 'geo-model', $transport, 12, 4096, new EndpointPolicy(static fn (string $host): array => ['8.8.8.8']));

        $response = $client->analyze('Never write prose.', '# Saved body');

        self::assertSame('{"proposals":[],"findings":[]}', $response->json);
        self::assertSame(12, $transport->timeout);
        self::assertSame(4096, $transport->maxBytes);
        self::assertSame('Bearer secret', $transport->headers['Authorization']);
        $payload = json_decode($transport->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('geo-model', $payload['model']);
        self::assertSame('system', $payload['messages'][0]['role']);
        self::assertSame('user', $payload['messages'][1]['role']);
        self::assertSame('# Saved body', $payload['messages'][1]['content']);
        self::assertSame('json_object', $payload['response_format']['type']);
    }

    public function test_marks_rate_limits_retryable_and_redacts_provider_body(): void
    {
        $client = new ConfiguredAiClient('secret', 'https://provider.test/v1/chat/completions', 'geo-model', new RecordingTransport(new HttpResponse(429, '{"error":{"message":"quota detail secret"}}')), 10, 2048, new EndpointPolicy(static fn (string $host): array => ['8.8.8.8']));
        try { $client->analyze('prompt', 'body'); self::fail('Expected exception.'); }
        catch (GeoAiException $exception) { self::assertTrue($exception->retryable); self::assertStringContainsString('HTTP 429', $exception->getMessage()); self::assertStringNotContainsString('secret', $exception->getMessage()); }
    }
}

final class RecordingTransport implements HttpTransport
{
    public array $headers = []; public string $body = ''; public int $timeout = 0; public int $maxBytes = 0;
    public function __construct(private readonly HttpResponse $response) {}
    public function post(string $url, array $headers, string $body, int $timeoutSeconds, int $maxResponseBytes): HttpResponse { $this->headers=$headers;$this->body=$body;$this->timeout=$timeoutSeconds;$this->maxBytes=$maxResponseBytes;return $this->response; }
}
