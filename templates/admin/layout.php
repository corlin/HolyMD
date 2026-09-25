<?php
declare(strict_types=1);
/** @var string $title */ /** @var string $content */ /** @var string $basePath */
$scriptCatalog = \HolyMD\I18n\Translator::scriptCatalog();
?>
<!doctype html><html lang="<?= htmlspecialchars(\HolyMD\I18n\Translator::locale(), ENT_QUOTES, 'UTF-8') ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · HolyMD</title><link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/assets/admin.css"></head><body><?= $content ?><?php if ($scriptCatalog !== []): ?><script type="application/json" id="holymd-i18n"><?= json_encode($scriptCatalog, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></script><?php endif; ?><script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/assets/admin.js" defer></script></body></html>
