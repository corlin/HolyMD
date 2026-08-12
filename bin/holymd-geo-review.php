#!/usr/bin/env php
<?php
declare(strict_types=1);

use HolyMD\Bootstrap;
use HolyMD\Content\ArticleRepository;
use HolyMD\Geo\AiClient;
use HolyMD\Geo\FileGeoReviewStore;
use HolyMD\Geo\GeoReviewService;

require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
$articleOption = array_search('--article', $argv, true);
$reviewOption = array_search('--review-id', $argv, true);
$slug = $articleOption === false ? null : ($argv[$articleOption + 1] ?? null);
$reviewId = $reviewOption === false ? null : ($argv[$reviewOption + 1] ?? null);
if (!is_string($slug) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || !is_string($reviewId) || preg_match('/^[1-9][0-9]*$/', $reviewId) !== 1) { fwrite(STDERR, "A safe article slug and review ID are required.\n"); exit(64); }

$container = Bootstrap::createContainer($root);
$store = new FileGeoReviewStore($root . '/content/geo');
$review = (new GeoReviewService($container->get(AiClient::class), $store))->review((new ArticleRepository($root . '/content/articles'))->read($slug));
$pdo = $container->get(\PDO::class);
$insert = $pdo->prepare('INSERT INTO geo_proposals (geo_review_id, proposal_type, proposed_metadata, status) VALUES (?, ?, ?, \'pending\')');
foreach ($review->proposals as $proposal) {
    $store->save($proposal);
    $metadata = is_array($proposal->value) ? $proposal->value : ['value' => $proposal->value];
    $insert->execute([(int) $reviewId, $proposal->type, json_encode($metadata, JSON_THROW_ON_ERROR)]);
}
fwrite(STDOUT, "Completed GEO review {$reviewId} for {$slug} with " . count($review->proposals) . " proposal(s).\n");
