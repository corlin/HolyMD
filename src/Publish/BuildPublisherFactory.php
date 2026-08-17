<?php

declare(strict_types=1);

namespace HolyMD\Publish;

use HolyMD\Admin\VersionService;
use HolyMD\Config\PublicationSettings;
use HolyMD\Content\ArticleRepository;
use HolyMD\Geo\GeoScoreCalculator;
use HolyMD\Render\StaticBuilder;
use PDO;

final readonly class BuildPublisherFactory
{
    public function __construct(
        private PDO $pdo,
        private PublicationSettings $settings,
        private string $projectRoot,
    ) {
    }

    public function create(ArticleRepository $articles, ArticleRepository $pages, VersionService $versions, string $liveRoot): PublishService
    {
        return new PublishService(
            $articles,
            new StaticBuilder(),
            new AtomicPublicTree(),
            $liveRoot,
            $this->settings,
            $this->projectRoot . '/content/audit',
            null,
            $this->projectRoot . '/content/holymd-publish.lock',
            $versions,
            $pages,
            $this->pdo,
            new GeoScoreCalculator(),
        );
    }
}
