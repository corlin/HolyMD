#!/usr/bin/env php
<?php

declare(strict_types=1);

use HolyMD\Config\PublicationSettings;
use HolyMD\Config\Settings;
use HolyMD\Content\ArticleRepository;
use HolyMD\Database\Connection;
use HolyMD\Geo\CitationProbeClient;
use HolyMD\Geo\CitationProbeConfiguration;
use HolyMD\Geo\CitationProbeRunner;
use HolyMD\Geo\EncryptedApiCredential;

require dirname(__DIR__) . '/vendor/autoload.php';

$argv = $_SERVER['argv'] ?? [];
$usage = "Usage: holymd-probe.php [--limit <count>] [--list]\n\n"
    . "Asks the configured AI search model the FAQ questions of published articles and records\n"
    . "whether its answers cite this site. Least recently probed questions run first.\n"
    . "  --limit <count>  questions to ask in this run (default HOLYMD_PROBE_MAX_PER_RUN, 10)\n"
    . "  --list           show the questions in the order they would run, without calling the API\n";
if (in_array('--help', $argv, true)) {
    fwrite(STDOUT, $usage);
    exit(0);
}
$limitIndex = array_search('--limit', $argv, true);
$limitValue = $limitIndex === false ? null : ($argv[$limitIndex + 1] ?? null);
if ($limitIndex !== false && (!is_string($limitValue) || preg_match('/^[1-9][0-9]{0,2}$/', $limitValue) !== 1)) {
    fwrite(STDERR, $usage);
    exit(64);
}

$root = dirname(__DIR__);
try {
    $pdo = (new Connection(Settings::fromEnvironment($root)))->pdo();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Database unavailable: ' . $exception->getMessage() . "\n");
    exit(1);
}
$configuration = CitationProbeConfiguration::fromEnvironment();
$listOnly = in_array('--list', $argv, true);
if (!$configuration->configured && !$listOnly) {
    fwrite(STDERR, "Citation probes are not configured. Set HOLYMD_PROBE_API_CREDENTIAL and HOLYMD_PROBE_API_KEY (see holymd-admin.php encrypt-probe-key).\n");
    exit(1);
}
$credential = $configuration->configured
    ? EncryptedApiCredential::fromEnvironment(CitationProbeConfiguration::CREDENTIAL_VARIABLE, CitationProbeConfiguration::KEY_VARIABLE)->reveal()
    : '';
$runner = new CitationProbeRunner(
    new ArticleRepository($root . '/content/articles'),
    new CitationProbeClient($credential, $configuration),
    $pdo,
    PublicationSettings::fromEnvironment(),
    $configuration,
);

$limit = $limitValue === null ? $configuration->maxPerRun : (int) $limitValue;
if ($listOnly) {
    foreach (array_slice($runner->dueQuestions(), 0, $limit) as $candidate) {
        fwrite(STDOUT, $candidate['slug'] . "\t" . $candidate['question'] . "\n");
    }
    exit(0);
}

$cited = 0;
$failed = 0;
foreach ($runner->run($limit) as $outcome) {
    if ($outcome['result'] === null) {
        $failed++;
        fwrite(STDOUT, sprintf("ERROR\t%s\t%s\t%s\n", $outcome['slug'], $outcome['question'], $outcome['error']));
        continue;
    }
    $result = $outcome['result'];
    $cited += $result->citedSite ? 1 : 0;
    $status = $result->citedArticle ? 'CITED' : ($result->citedSite ? 'SITE' : ($result->mentioned ? 'MENTIONED' : 'MISSED'));
    fwrite(STDOUT, sprintf("%s\t%s\t%s%s\n", $status, $outcome['slug'], $outcome['question'], $result->citedUrl === null ? '' : "\t" . $result->citedUrl));
}
fwrite(STDOUT, sprintf("Probed with %s: %d cited this site, %d failed.\n", $configuration->model, $cited, $failed));
exit($failed > 0 ? 1 : 0);
