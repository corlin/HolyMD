<?php

declare(strict_types=1);

namespace HolyMD\Geo;

use JsonException;

/**
 * Asks a web-search-backed model a question and collects the URLs it cited.
 * Understands Perplexity (`citations`, `search_results`), OpenAI-style
 * `annotations`, and falls back to links written in the answer text.
 */
final readonly class CitationProbeClient
{
    public function __construct(
        private string $credential,
        private CitationProbeConfiguration $configuration,
        private HttpTransport $transport = new StreamHttpTransport(),
        private EndpointPolicy $endpointPolicy = new EndpointPolicy(),
    ) {
    }

    public function ask(string $question): CitationProbeAnswer
    {
        if ($this->credential === '') {
            throw new GeoAiException('Citation probe credentials are not configured.', false);
        }
        try {
            $addresses = $this->endpointPolicy->validate($this->configuration->endpoint, true);
        } catch (\InvalidArgumentException $exception) {
            throw new GeoAiException($exception->getMessage(), false);
        }

        $body = json_encode([
            'model' => $this->configuration->model,
            'messages' => [
                ['role' => 'system', 'content' => 'Answer the question concisely using web search, and cite the sources you used.'],
                ['role' => 'user', 'content' => $question],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $response = $this->transport->post(
            $this->configuration->endpoint,
            ['Authorization' => 'Bearer ' . $this->credential, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
            $body,
            $this->configuration->timeoutSeconds,
            $this->configuration->maxResponseBytes,
            $addresses,
        );
        if ($response->status < 200 || $response->status >= 300) {
            throw new GeoAiException('Citation probe provider returned HTTP ' . $response->status . '.', in_array($response->status, [408, 409, 425, 429, 500, 502, 503, 504], true));
        }
        try {
            $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new GeoAiException('Citation probe provider returned invalid JSON.', true);
        }
        if (!is_array($payload)) {
            throw new GeoAiException('Citation probe provider returned an unexpected response.', true);
        }

        return self::parse($payload);
    }

    /** @param array<mixed> $payload */
    public static function parse(array $payload): CitationProbeAnswer
    {
        $message = $payload['choices'][0]['message'] ?? [];
        $text = is_array($message) && is_string($message['content'] ?? null) ? $message['content'] : '';

        $urls = [];
        foreach ((array) ($payload['citations'] ?? []) as $citation) {
            $urls[] = is_string($citation) ? $citation : (is_array($citation) ? ($citation['url'] ?? null) : null);
        }
        foreach ((array) ($payload['search_results'] ?? []) as $result) {
            $urls[] = is_array($result) ? ($result['url'] ?? null) : null;
        }
        foreach ((array) (is_array($message) ? ($message['annotations'] ?? []) : []) as $annotation) {
            $urls[] = is_array($annotation) ? ($annotation['url_citation']['url'] ?? $annotation['url'] ?? null) : null;
        }
        preg_match_all('#https?://[^\s<>()\[\]"\'`]+#i', $text, $matches);
        foreach ($matches[0] as $url) {
            $urls[] = rtrim($url, '.,;:!?');
        }

        $citations = [];
        foreach ($urls as $url) {
            if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                $citations[] = $url;
            }
        }

        return new CitationProbeAnswer($text, array_values(array_unique($citations)));
    }
}
