<?php
declare(strict_types=1);
/**
 * @var string $status
 */
$statusBadgeIcons = ['draft' => 'edit_note', 'published' => 'check_circle', 'withdrawn' => 'visibility_off'];
?>
<span class="status status-<?= htmlspecialchars($status) ?>"><span class="icon" aria-hidden="true"><?= $statusBadgeIcons[$status] ?? 'circle' ?></span><?= htmlspecialchars(ucfirst($status)) ?></span>
