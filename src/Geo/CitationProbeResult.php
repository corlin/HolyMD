<?php

declare(strict_types=1);

namespace HolyMD\Geo;

/** Whether one AI answer cited the site, cited the specific article, or named the site. */
final readonly class CitationProbeResult
{
    /** @param list<string> $citations */
    public function __construct(
        public bool $citedSite,
        public bool $citedArticle,
        public bool $mentioned,
        public ?string $citedUrl,
        public array $citations,
    ) {
    }

    public static function evaluate(CitationProbeAnswer $answer, string $siteUrl, string $siteName, string $articleUrl): self
    {
        $siteHost = self::host($siteUrl);
        $articlePath = rtrim((string) parse_url($articleUrl, PHP_URL_PATH), '/');
        $siteCitation = null;
        $articleCitation = null;
        foreach ($answer->citations as $citation) {
            if ($siteHost === '' || self::host($citation) !== $siteHost) {
                continue;
            }
            $siteCitation ??= $citation;
            if ($articleCitation === null && rtrim((string) parse_url($citation, PHP_URL_PATH), '/') === $articlePath) {
                $articleCitation = $citation;
            }
        }

        $name = trim($siteName);
        $mentioned = ($siteHost !== '' && mb_stripos($answer->text, $siteHost) !== false)
            || (mb_strlen($name) >= 4 && mb_stripos($answer->text, $name) !== false);

        return new self($siteCitation !== null, $articleCitation !== null, $mentioned, $articleCitation ?? $siteCitation, $answer->citations);
    }

    private static function host(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
