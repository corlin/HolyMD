<?php

declare(strict_types=1);

namespace HolyMD\Publish;

use RuntimeException;

/** Switches a static release on the same filesystem, keeping a recoverable backup until success. */
final class AtomicPublicTree
{
    public function swap(string $temporaryRoot, string $liveRoot): void
    {
        if (!is_dir($temporaryRoot)) {
            throw new RuntimeException('Temporary build tree does not exist.');
        }
        if (dirname($temporaryRoot) !== dirname($liveRoot)) {
            throw new RuntimeException('Temporary and live public trees must share a parent directory.');
        }

        $backup = dirname($liveRoot) . '/.' . basename($liveRoot) . '-previous-' . bin2hex(random_bytes(6));
        $hadLiveTree = file_exists($liveRoot) || is_link($liveRoot);
        if ($hadLiveTree && !rename($liveRoot, $backup)) {
            throw new RuntimeException('Unable to preserve the current public tree.');
        }
        if (!rename($temporaryRoot, $liveRoot)) {
            if ($hadLiveTree) rename($backup, $liveRoot);
            throw new RuntimeException('Unable to activate the new public tree.');
        }
        if ($hadLiveTree) $this->remove($backup);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $this->remove($path . '/' . $entry);
        }
        rmdir($path);
    }
}
