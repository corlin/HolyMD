<?php

declare(strict_types=1);

namespace HolyMD\Tests\Render;

use HolyMD\Config\PublicationSettings;
use HolyMD\Content\ArticleDocument;
use HolyMD\Content\FrontMatter;
use HolyMD\Render\BuildInput;
use HolyMD\Render\StaticBuilder;
use PHPUnit\Framework\TestCase;

final class BrandPageTest extends TestCase
{
    private string $outputRoot;

    protected function setUp(): void
    {
        $this->outputRoot = sys_get_temp_dir() . '/holymd-brand-' . bin2hex(random_bytes(6));
        mkdir($this->outputRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->outputRoot, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->outputRoot);
    }

    public function test_standard_page_renders_without_brand_components(): void
    {
        $page = new ArticleDocument('contact', 'Contact Us', "Get in touch with us.\n", new FrontMatter([
            'title' => 'Contact Us',
            'slug' => 'contact',
            'date' => '2026-10-02',
            'status' => 'published',
        ]), '/pages/contact.md');

        $builder = new StaticBuilder();
        $input = new BuildInput([], new PublicationSettings('Test Site', 'https://example.test', 'Jane Author', 'About Jane'), pages: [$page]);
        $builder->build($input, $this->outputRoot);

        $html = (string) file_get_contents($this->outputRoot . '/contact/index.html');
        self::assertStringContainsString('class="shell page-intro"', $html);
        self::assertStringNotContainsString('class="brand-page"', $html);
        self::assertStringNotContainsString('brand-hero', $html);
        self::assertStringNotContainsString('brand-stats-strip', $html);
    }

    public function test_brand_page_renders_all_neobrutalism_modules_and_enriches_schema(): void
    {
        $brandPage = new ArticleDocument('about', '关于我', "## 我的故事\n\n这是一段深度自白。", new FrontMatter([
            'title' => '关于我',
            'slug' => 'about',
            'date' => '2026-10-02',
            'status' => 'published',
            'template' => 'brand',
            'tagline' => '让经历变成[[杠杆]]，把自己活成((品牌))。',
            'hero_eyebrow' => "CREATOR'S NOTE · 2026",
            'hero_sub' => '连续创业13年。现在我陪有经历的人放大表达。',
            'hero_stance' => [
                '我不是技术表演派，我站在真实用户这边。',
                'AI 是放大人格与商业的杠杆。',
            ],
            'stats' => [
                ['num' => '13年', 'label' => '连续创业', 'highlight' => true],
                ['num' => '八位数', 'label' => '业务闭环'],
            ],
            'offer_title' => '我能提供什么',
            'offer_lede' => '陪你把散落的经验重新梳理成资产。',
            'offers' => [
                ['icon' => '✦', 'title' => '经验整理', 'desc' => '把你过去做过的事沉淀为资产。'],
                ['icon' => '↗', 'title' => '表达放大', 'desc' => '让经历替你工作。'],
            ],
            'track_title' => '这些不是{{履历}}，是我判断的来源。',
            'track_lede' => '经历让我看懂了什么。',
            'tracks' => [
                ['title' => '13 年 连续创业', 'insight' => '风口会变，但一个人的判断力会一直跟着她。'],
            ],
            'identity_title' => '一个完整的我',
            'identities' => [
                ['tag' => '01 BUILDER', 'title' => '创业者', 'desc' => '懂怎么穿越周期。'],
                ['tag' => '02 AI', 'title' => 'AI实践派', 'desc' => '用AI放大生产力。'],
            ],
            'identity_close' => '我是一个把自己活过的每一段都重新变成财富的人。',
            'contact' => [
                'eyebrow' => '靠近我',
                'title' => '我们可以聊聊',
                'email' => 'author@example.test',
                'cta' => '聊聊合作',
                'links' => [
                    ['label' => '即刻', 'url' => 'https://okjk.me'],
                ],
            ],
        ]), '/pages/about.md');

        $builder = new StaticBuilder();
        $input = new BuildInput([], new PublicationSettings('Brand Site', 'https://example.test', '文子', 'About Wenzi'), pages: [$brandPage]);
        $builder->build($input, $this->outputRoot);

        $html = (string) file_get_contents($this->outputRoot . '/about/index.html');

        // 1. Verify Brand Page container and classes
        self::assertStringContainsString('class="brand-page"', $html);

        // 2. Hero & Highlights
        self::assertStringContainsString('class="brand-hero"', $html);
        self::assertStringContainsString('CREATOR&#039;S NOTE · 2026', $html);
        self::assertStringContainsString('<span class="hl">杠杆</span>', $html);
        self::assertStringContainsString('<span class="hl-lilac">品牌</span>', $html);
        self::assertStringContainsString('brand-arrow-doodle', $html);
        self::assertStringContainsString('连续创业13年', $html);

        // 3. Stats Strip
        self::assertStringContainsString('class="brand-stats-strip"', $html);
        self::assertStringContainsString('is-highlight', $html);
        self::assertStringContainsString('13年', $html);
        self::assertStringContainsString('八位数', $html);

        // 4. Offer Matrix
        self::assertStringContainsString('class="brand-offer-card"', $html);
        self::assertStringContainsString('经验整理', $html);
        self::assertStringContainsString('表达放大', $html);

        // 5. Track & Insights
        self::assertStringContainsString('class="brand-track-item"', $html);
        self::assertStringContainsString('<span class="pill-hl">履历</span>', $html);
        self::assertStringContainsString('风口会变，但一个人的判断力会一直跟着她。', $html);

        // 6. Identities Card Wall
        self::assertStringContainsString('class="brand-identity-card', $html);
        self::assertStringContainsString('01 BUILDER', $html);
        self::assertStringContainsString('创业者', $html);
        self::assertStringContainsString('02 AI', $html);
        self::assertStringContainsString('我是一个把自己活过的每一段都重新变成财富的人。', $html);

        // 7. Markdown Editorial Content
        self::assertStringContainsString('class="prose brand-editorial-prose"', $html);
        self::assertStringContainsString('我的故事', $html);

        // 8. Contact & CTA
        self::assertStringContainsString('brand-contact-section', $html);
        self::assertStringContainsString('mailto:author@example.test', $html);
        self::assertStringContainsString('聊聊合作', $html);
        self::assertStringContainsString('https://okjk.me', $html);

        // 9. Schema.org enrichment (Person description from tagline, knowsAbout from identities)
        self::assertStringContainsString('"@type":"AboutPage"', $html);
        self::assertStringContainsString('"knowsAbout":["创业者","AI实践派"]', $html);
    }
}
