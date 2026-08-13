<?php

declare(strict_types=1);

namespace HolyMD\Tests\Geo;

use HolyMD\Geo\GeoAiException;
use HolyMD\Geo\StreamHttpTransport;
use PHPUnit\Framework\TestCase;

final class StreamHttpTransportTest extends TestCase
{
    public function test_pins_the_https_connection_to_the_addresses_validated_by_endpoint_policy(): void
    {
        $options = [];
        $transport = new StreamHttpTransport(
            /** @param array<int, mixed> $curlOptions @return array{succeeded:bool,status:int,error:string,errno:int} */
            static function (array $curlOptions) use (&$options): array {
                $options = $curlOptions;
                $curlOptions[CURLOPT_WRITEFUNCTION](null, '{"ok":true}');
                return ['succeeded' => true, 'status' => 200, 'error' => '', 'errno' => 0];
            },
        );

        try {
            $response = $transport->post(
                'https://provider.test/v1/chat/completions',
                ['Authorization' => 'Bearer redacted'],
                '{}',
                10,
                2048,
                ['8.8.8.8', '2606:4700:4700::1111'],
            );
        } catch (GeoAiException $exception) {
            self::fail('Transport did not use the validated address contract: ' . $exception->getMessage());
        }

        self::assertSame(200, $response->status);
        self::assertSame('{"ok":true}', $response->body);
        self::assertSame(
            ['provider.test:443:8.8.8.8,[2606:4700:4700::1111]'],
            $options[CURLOPT_RESOLVE],
        );
        self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame('', $options[CURLOPT_PROXY]);
        self::assertSame('*', $options[CURLOPT_NOPROXY]);
    }

    public function test_rejects_a_response_that_crosses_the_byte_limit(): void
    {
        $transport = new StreamHttpTransport(
            static function (array $options): array {
                $options[CURLOPT_WRITEFUNCTION](null, '12345');
                return ['succeeded' => false, 'status' => 200, 'error' => 'write aborted', 'errno' => CURLE_WRITE_ERROR];
            },
        );

        $this->expectException(GeoAiException::class);
        $this->expectExceptionMessage('exceeded the configured size limit');
        $transport->post('https://provider.test/api', [], '{}', 10, 4, ['8.8.8.8']);
    }

    public function test_refuses_to_connect_without_validated_addresses(): void
    {
        $this->expectException(GeoAiException::class);
        $this->expectExceptionMessage('validated public address');
        (new StreamHttpTransport())->post('https://provider.test/api', [], '{}', 10, 2048, []);
    }
}
