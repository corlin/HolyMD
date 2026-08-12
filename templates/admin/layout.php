<?php
declare(strict_types=1);
/** @var string $title */ /** @var string $content */
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · HolyMD</title><link rel="stylesheet" href="/assets/admin.css"></head><body><?= $content ?><script src="/assets/admin.js" defer></script></body></html>
