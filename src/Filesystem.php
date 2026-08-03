<?php

declare(strict_types=1);

namespace ShipIt;

class Filesystem
{
    private TerminalUI $ui;
    private bool $dryRun;
    private array $deployIgnoreCache = [];

    public function __construct(TerminalUI $ui, bool $dryRun = false)
    {
        $this->ui = $ui;
        $this->dryRun = $dryRun;
    }

    public function parseDeployIgnore(string $dir): array
    {
        $ignoreFile = rtrim($dir, '/') . '/.deployignore';

        if (isset($this->deployIgnoreCache[$ignoreFile])) {
            return $this->deployIgnoreCache[$ignoreFile];
        }

        $ignores = [];
        if (file_exists($ignoreFile)) {
            $lines = file($ignoreFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '' && !str_starts_with($line, '#')) {
                    $ignores[] = $line;
                }
            }
        }
        $this->deployIgnoreCache[$ignoreFile] = $ignores;
        return $ignores;
    }

    private function isIgnored(string $relPath, array $ignoreList): bool
    {
        foreach ($ignoreList as $pattern) {
            if ($pattern === $relPath)
                return true;
            if (str_starts_with($pattern, '/') && $pattern === '/' . $relPath)
                return true;
            if (str_ends_with($pattern, '/') && str_starts_with($relPath . '/', ltrim($pattern, '/')))
                return true;
            if (fnmatch($pattern, $relPath))
                return true;
        }
        return false;
    }

    private function isRsyncAvailable(): bool
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return false;
        }
        $output = [];
        $status = 0;
        @exec('which rsync 2>&1', $output, $status);
        return $status === 0;
    }

    public function copyFolder(string $source, string $destination, array $ignoreList = [], string $relativeBase = '', bool $log = false, bool $bypassIgnores = false): void
    {
        if (!is_dir($source))
            return;

        $fullIgnoreList = $ignoreList;
        if (!$bypassIgnores) {
            $sourceIgnore = $this->parseDeployIgnore($source);
            $fullIgnoreList = array_unique(array_merge($ignoreList, $sourceIgnore));
        }

        // Use rsync for efficient system-level copy if available at the top level
        if ($relativeBase === '' && $this->isRsyncAvailable()) {
            if ($this->dryRun) {
                $this->ui->info("[Dry Run] Would rsync from $source to $destination");
                return;
            }
            if (!is_dir($destination)) {
                @mkdir($destination, 0777, true);
            }
            $cmd = "rsync -a";
            foreach ($fullIgnoreList as $pattern) {
                $cmd .= " --exclude=" . escapeshellarg($pattern);
            }
            $srcDir = rtrim($source, '/') . '/';
            $destDir = rtrim($destination, '/') . '/';
            $cmd .= " " . escapeshellarg($srcDir) . " " . escapeshellarg($destDir) . " 2>&1";

            $output = [];
            $status = 0;
            @exec($cmd, $output, $status);
            if ($status === 0) {
                if ($log) {
                    $this->ui->success("Copied folder using rsync: $source -> $destination");
                }
                return;
            }
            // If rsync fails, seamlessly fall back to PHP-native copy loop
        }

        if (!$this->dryRun && !is_dir($destination)) {
            @mkdir($destination, 0777, true);
        } elseif ($this->dryRun && $relativeBase === '') {
            $this->ui->info("[Dry Run] Would create root directory: $destination");
        }

        $items = array_diff(scandir($source) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $srcPath = $source . '/' . $item;
            $destPath = $destination . '/' . $item;
            $relPath = ltrim($relativeBase . '/' . $item, '/');

            if (!$bypassIgnores && $this->isIgnored($relPath, $fullIgnoreList)) {
                continue;
            }

            if (is_dir($srcPath)) {
                $this->copyFolder($srcPath, $destPath, $fullIgnoreList, $relPath, $log, $bypassIgnores);
            } else {
                if ($this->dryRun) {
                    if ($log) {
                        $this->ui->success("[Dry Run] Copied: $relPath");
                    } else {
                        $this->ui->info("[Dry Run] Would copy: $relPath");
                    }
                } else {
                    if (!copy($srcPath, $destPath)) {
                        $this->ui->error("Failed to copy: $relPath");
                    } elseif ($log) {
                        $this->ui->success("Copied: $relPath");
                    }
                }
            }
        }
    }

    public function removeFolder(string $dir): void
    {
        if ($this->dryRun) {
            $this->ui->info("[Dry Run] Would remove: $dir");
            return;
        }
        if (!is_dir($dir))
            return;

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Speed up Windows deletion by removing read-only once and using native RD
            @exec("attrib -R " . escapeshellarg($dir) . " /S /D");
            @exec("rd /s /q " . escapeshellarg($dir));
            if (!is_dir($dir))
                return;
        }

        $items = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeFolder($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function clearDirectory(string $dir, array $keep = []): void
    {
        if (!is_dir($dir))
            return;

        $items = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($items as $item) {
            if (in_array($item, $keep, true))
                continue;

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeFolder($path);
            } else {
                if ($this->dryRun) {
                    $this->ui->info("[Dry Run] Would delete: $item");
                } else {
                    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                        @exec("attrib -R " . escapeshellarg($path));
                    }
                    @unlink($path);
                }
            }
        }
    }
}
