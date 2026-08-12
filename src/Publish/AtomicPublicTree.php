<?php

declare(strict_types=1);

namespace HolyMD\Publish;

use RuntimeException;

/** Activates immutable versioned releases through an atomically renamed symlink pointer. */
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

        $parent = dirname($liveRoot);
        $releases = $parent . '/.' . basename($liveRoot) . '-releases';
        if (!is_dir($releases) && !mkdir($releases, 0775, true) && !is_dir($releases)) throw new RuntimeException('Unable to create static release directory.');
        $release = $releases . '/release-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
        if (!rename($temporaryRoot, $release)) throw new RuntimeException('Unable to store the completed static release.');

        if (file_exists($liveRoot) && !is_link($liveRoot)) { $this->remove($release); throw new RuntimeException('The static release pointer must be prepared before publishing.'); }
        $pointer = $parent . '/.' . basename($liveRoot) . '-next-' . bin2hex(random_bytes(6));
        if (!symlink($release, $pointer)) { $this->remove($release); throw new RuntimeException('Unable to create static release pointer.'); }
        if (!rename($pointer, $liveRoot)) { unlink($pointer); $this->remove($release); throw new RuntimeException('Unable to atomically activate the static release pointer.'); }
    }

    /** Prepare a stable pointer without moving or hiding the legacy public/site tree. */
    public function prepare(string $pointer, string $legacyTree): void
    {
        if (file_exists($pointer) || is_link($pointer)) return;
        if (!is_dir($legacyTree)) throw new RuntimeException('Legacy static tree does not exist.');
        $probe = dirname($pointer) . '/.holymd-symlink-probe-' . bin2hex(random_bytes(4));
        if (!symlink($legacyTree, $probe)) throw new RuntimeException('This host does not support static release symlinks.');
        if (!rename($probe, $pointer)) { unlink($probe); throw new RuntimeException('Unable to install the static release pointer.'); }
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
