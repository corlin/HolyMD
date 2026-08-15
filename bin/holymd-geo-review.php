#!/usr/bin/env php
<?php
declare(strict_types=1);

use HolyMD\Bootstrap;
use HolyMD\Content\ArticleRepository;
use HolyMD\Geo\AiClient;
use HolyMD\Geo\GeoReviewService;

require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
$articleOption = array_search('--article', $argv, true);
$reviewOption = array_search('--review-id', $argv, true);
$slug = $articleOption === false ? null : ($argv[$articleOption + 1] ?? null);
$reviewId = $reviewOption === false ? null : ($argv[$reviewOption + 1] ?? null);
if (!is_string($slug) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || !is_string($reviewId) || preg_match('/^[1-9][0-9]*$/', $reviewId) !== 1) { fwrite(STDERR, "A safe article slug and review ID are required.\n"); exit(64); }

$container = Bootstrap::createContainer($root);
$pdo = $container->get(\PDO::class);
$binding = $pdo->prepare('SELECT geo_reviews.input_checksum, article_versions.snapshot_path FROM geo_reviews INNER JOIN articles ON articles.id = geo_reviews.article_id INNER JOIN article_versions ON article_versions.id = geo_reviews.article_version_id AND article_versions.article_id = articles.id WHERE geo_reviews.id = ? AND articles.slug = ?');
$binding->execute([(int) $reviewId, $slug]);
$row = $binding->fetch(\PDO::FETCH_ASSOC);
if (!is_array($row) || !is_string($row['input_checksum'] ?? null) || !is_string($row['snapshot_path'] ?? null)) {
    fwrite(STDERR, "GEO review is not bound to a valid immutable review input.\n");
    exit(64);
}
$expectedChecksum = (string) $row['input_checksum'];
$snapshotPath = (string) $row['snapshot_path'];
$versionId = (string) basename($snapshotPath, '.md');
$versionService = new \HolyMD\Admin\VersionService($root . '/content/versions');
try {
    $document = str_starts_with($snapshotPath, 'review-inputs/')
        ? $versionService->restoreReviewInput($versionId, $slug)
        : $versionService->restore($versionId, $slug);
} catch (\Throwable) {
    $document = (new ArticleRepository($root . '/content/articles'))->read($slug);
}
if (!hash_equals($expectedChecksum, hash('sha256', $document->serialize()))) {
    throw new RuntimeException('GEO review input does not match its saved article checksum.');
}
try { $review = (new GeoReviewService($container->get(AiClient::class)))->review($document); }
catch (\HolyMD\Geo\GeoAiException $error) { fwrite(STDERR, ($error->retryable ? 'RETRYABLE: ' : 'PERMANENT: ') . $error->getMessage() . "\n"); exit($error->retryable ? 75 : 78); }
$pdo->beginTransaction();
try {
    $insert = $pdo->prepare('INSERT INTO geo_proposals (geo_review_id, proposal_type, proposed_metadata, proposal_key, status) VALUES (?, ?, ?, ?, \'pending\') ON DUPLICATE KEY UPDATE id=id');
    foreach ($review->proposals as $proposal) {
        $json = json_encode($proposal->value, JSON_THROW_ON_ERROR);
        $insert->execute([(int) $reviewId, $proposal->type, $json, hash('sha256', $reviewId . ':' . $proposal->type . ':' . $json)]);
    }
    $pdo->commit();
} catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
fwrite(STDOUT, "Completed GEO review {$reviewId} for {$slug} with " . count($review->proposals) . " proposal(s).\n");
