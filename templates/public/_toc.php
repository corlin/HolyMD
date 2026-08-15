<?php
declare(strict_types=1);
/** @var list<array{level: int, title: string, id: string}> $toc */
?>
<ol>
  <?php foreach ($toc as $item): ?>
    <li class="toc-level-<?= (int) $item['level'] ?>"><a href="#<?= htmlspecialchars($item['id']) ?>"><?= htmlspecialchars($item['title']) ?></a></li>
  <?php endforeach; ?>
</ol>
