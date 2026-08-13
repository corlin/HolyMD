<?php

declare(strict_types=1);

namespace HolyMD\Publish;

use RuntimeException;

/**
 * Activates immutable versioned releases through an atomically replaced
 * pointer FILE (no symlink support required — shared hosts disable symlink()).
 * The pointer contains the release directory path relative to the pointer's
 * parent directory; public/index.php resolves it for static serving.
 */
final class AtomicPublicTree
{
    public function swap(string $temporaryRoot, string $pointerPath): void
    {
        if (!is_dir($temporaryRoot)) {
            throw new RuntimeException('Temporary build tree does not exist.');
        }
        if (dirname($temporaryRoot) !== dirname($pointerPath)) {
            throw new RuntimeException('Temporary and live public trees must share a parent directory.');
        }

        $parent = dirname($pointerPath);
        $releases = $parent . '/.' . basename($pointerPath) . '-releases';
        if (!is_dir($releases) && !mkdir($releases, 0775, true) && !is_dir($releases)) throw new RuntimeException('Unable to create static release directory.');
        $release = $releases . '/release-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
        if (!rename($temporaryRoot, $release)) throw new RuntimeException('Unable to store the completed static release.');

        if (is_dir($pointerPath)) { $this->remove($release); throw new RuntimeException('The static release pointer must be prepared before publishing.'); }
        $pointer = $parent . '/.' . basename($pointerPath) . '-next-' . bin2hex(random_bytes(6));
        $target = basename($releases) . '/' . basename($release);
        if (file_put_contents($pointer, $target . "\n", LOCK_EX) === false) { $this->remove($release); throw new RuntimeException('Unable to write the static release pointer.'); }
        if (!rename($pointer, $pointerPath)) { unlink($pointer); $this->remove($release); throw new RuntimeException('Unable to atomically activate the static release pointer.'); }
    }

    /** Prepare a stable pointer without moving or hiding the legacy public/site tree. */
    public function prepare(string $pointer, string $legacyTree): void
    {
        if (is_link($pointer)) {
            $parent = dirname($pointer);
            $resolved = realpath($pointer);
            $parentPrefix = rtrim((string) realpath($parent), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if ($resolved === false || !is_dir($resolved) || !str_starts_with($resolved, $parentPrefix)) {
                throw new RuntimeException('Legacy static release symlink must resolve inside its public directory.');
            }
            $this->installPointerFile($pointer, substr($resolved, strlen($parentPrefix)));
            return;
        }
        if (file_exists($pointer)) return;
        if (!is_dir($legacyTree)) throw new RuntimeException('Legacy static tree does not exist.');
        $this->installPointerFile($pointer, basename($legacyTree));
    }

    private function installPointerFile(string $pointer, string $target): void
    {
        $probe = dirname($pointer) . '/.holymd-pointer-probe-' . bin2hex(random_bytes(4));
        if (file_put_contents($probe, $target . "\n", LOCK_EX) === false) throw new RuntimeException('Unable to write the static release pointer.');
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
