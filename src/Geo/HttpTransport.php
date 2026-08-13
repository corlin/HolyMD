<?php
declare(strict_types=1);
namespace HolyMD\Geo;
interface HttpTransport {
    /** @param array<string,string> $headers @param list<string> $resolvedAddresses */
    public function post(string $url, array $headers, string $body, int $timeoutSeconds, int $maxResponseBytes, array $resolvedAddresses = []): HttpResponse;
}
