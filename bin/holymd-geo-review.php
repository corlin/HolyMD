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
$document = (new ArticleRepository($root . '/content/articles'))->read($slug);
$binding = $pdo->prepare('SELECT geo_reviews.input_checksum FROM geo_reviews INNER JOIN articles ON articles.id = geo_reviews.article_id INNER JOIN article_versions ON article_versions.id = geo_reviews.article_version_id AND article_versions.article_id = articles.id WHERE geo_reviews.id = ? AND articles.slug = ?');
$binding->execute([(int) $reviewId, $slug]);
$expectedChecksum = $binding->fetchColumn();
if (!is_string($expectedChecksum) || !hash_equals($expectedChecksum, hash('sha256', $document->bodyMarkdown))) throw new RuntimeException('GEO review is not bound to the current article version checksum.');
$review = (new GeoReviewService($container->get(AiClient::class)))->review($document);
$pdo->beginTransaction();
try {
    $insert = $pdo->prepare('INSERT INTO geo_proposals (geo_review_id, proposal_type, proposed_metadata, proposal_key, status) VALUES (?, ?, ?, ?, \'pending\') ON DUPLICATE KEY UPDATE id=id');
    foreach ($review->proposals as $proposal) {
        $metadata = is_array($proposal->value) ? $proposal->value : ['value' => $proposal->value];
        $json = json_encode($metadata, JSON_THROW_ON_ERROR);
        $insert->execute([(int) $reviewId, $proposal->type, $json, hash('sha256', $reviewId . ':' . $proposal->type . ':' . $json)]);
    }
    $pdo->commit();
} catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
fwrite(STDOUT, "Completed GEO review {$reviewId} for {$slug} with " . count($review->proposals) . " proposal(s).\n");
