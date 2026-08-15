<?php

declare(strict_types=1);

namespace HolyMD\Config;

final readonly class PublicationSettings
{
    public string $basePath;

    public function __construct(
        public string $siteName,
        public string $siteUrl,
        public string $authorName,
        public string $about,
        public bool $generateLlmsTxt = false,
        public string $siteLanguage = 'zh-CN',
        string $basePath = '',
    ) {
        $normalized = '/' . trim($basePath, '/');
        $this->basePath = $normalized === '/' ? '' : $normalized;
    }

    public static function fromEnvironment(): self
    {
        return new self(
            (string) (Env::get('HOLYMD_SITE_NAME') ?: 'HolyMD'),
            (string) (Env::get('HOLYMD_SITE_URL') ?: 'https://example.invalid'),
            (string) (Env::get('HOLYMD_AUTHOR_NAME') ?: 'Author'),
            (string) (Env::get('HOLYMD_ABOUT') ?: ''),
            Env::get('HOLYMD_LLMS_TXT') === '1',
            (string) (Env::get('HOLYMD_SITE_LANGUAGE') ?: 'zh-CN'),
            (string) (Env::get('HOLYMD_BASE_PATH') ?: ''),
        );
    }

    /** @return list<string> */
    public function validationErrors(): array
    {
        $errors = [];
        $host = strtolower((string) parse_url($this->siteUrl, PHP_URL_HOST));
        if ($this->siteUrl === '' || $host === '' || str_contains(strtolower($this->siteUrl), 'replace_with_') || $host === 'example.com' || str_ends_with($host, '.example.com') || str_contains($host, 'example.invalid')) $errors[] = 'The public site URL must be configured and cannot use a placeholder domain.';
        if (trim($this->siteName) === '' || str_starts_with(strtolower(trim($this->siteName)), 'replace_with_') || in_array(strtolower(trim($this->siteName)), ['holymd', 'site', 'your publication'], true)) $errors[] = 'The public site name must be configured and cannot use a placeholder value.';
        if (trim($this->authorName) === '' || str_starts_with(strtolower(trim($this->authorName)), 'replace_with_') || in_array(strtolower(trim($this->authorName)), ['author', 'your name'], true)) $errors[] = 'The public author name must be configured and cannot use a placeholder value.';
        if (preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $this->siteLanguage) !== 1) $errors[] = 'The public site language must be a valid BCP 47 language tag.';
        return $errors;
    }

    /** @return array{site_name:string,site_url:string,author_name:string,about:string,site_language:string} */
    public function adminValues(): array
    {
        return [
            'site_name' => $this->siteName,
            'site_url' => $this->siteUrl,
            'author_name' => $this->authorName,
            'about' => $this->about,
            'site_language' => $this->siteLanguage,
        ];
    }
}
