<?php

declare(strict_types=1);

namespace HolyMD\Tests\Publish;

use HolyMD\Admin\VersionService;
use HolyMD\Config\PublicationSettings;
use HolyMD\Content\ArticleRepository;
use HolyMD\Publish\BuildPublisherFactory;
use PDO;
use PHPUnit\Framework\TestCase;

final class BuildPublisherFactoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/holymd-build-factory-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/articles', 0777, true);
        mkdir($this->root . '/pages', 0777, true);
        mkdir($this->root . '/content', 0777, true);
        mkdir($this->root . '/public/site', 0777, true);
        file_put_contents($this->root . '/public/site/index.html', 'old');
        file_put_contents($this->root . '/public/.holymd-current', "site\n");
        file_put_contents($this->root . '/articles/note.md', "---\ntitle: Note\nslug: note\ndate: 2026-08-17\nstatus: draft\nsummary: This is a sufficiently detailed summary for a queued publication score.\n---\nBody\n");
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function test_runtime_factory_records_a_score_after_successful_publish(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE geo_scores (slug TEXT, score INTEGER, breakdown TEXT, snapshot_trigger TEXT)');
        $articles = new ArticleRepository($this->root . '/articles');
        $versions = new VersionService($this->root . '/versions');
        $version = $versions->capturePublicationInput($articles->read('note'));
        $factory = new BuildPublisherFactory(
            $pdo,
            new PublicationSettings('Test Notes', 'https://example.test', 'Ada', 'About Ada'),
            $this->root,
        );

        $factory->create($articles, new ArticleRepository($this->root . '/pages'), $versions, $this->root . '/public/.holymd-current')->publish('note', $version);

        $row = $pdo->query('SELECT slug, score, snapshot_trigger FROM geo_scores')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('note', $row['slug']);
        self::assertSame(25, (int) $row['score']);
        self::assertSame('publish', $row['snapshot_trigger']);
    }

    private function remove(string $path): void
    {
        if (!is_dir($path) && !is_link($path)) return;
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $child = $path . '/' . $name;
            is_dir($child) && !is_link($child) ? $this->remove($child) : unlink($child);
        }
        rmdir($path);
    }
}
