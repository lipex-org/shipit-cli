<?php

declare(strict_types=1);

namespace ShipIt\Backup;

use ShipIt\Cloud\CloudStorageManager;
use ShipIt\Config\EnvLoader;
use ShipIt\Filesystem;
use ShipIt\TerminalUI;

class BackupManager
{
    private string $rootDir;
    private array $config;
    private EnvLoader $env;
    private TerminalUI $ui;
    private Filesystem $fs;
    private CloudStorageManager $cloudManager;
    private Encrypter $encrypter;
    private DatabaseDumper $dumper;

    public function __construct(
        string $rootDir,
        array $config,
        EnvLoader $env,
        TerminalUI $ui,
        Filesystem $fs
    ) {
        $this->rootDir = $rootDir;
        $this->config = $config;
        $this->env = $env;
        $this->ui = $ui;
        $this->fs = $fs;
        $this->cloudManager = new CloudStorageManager($env);
        $this->encrypter = new Encrypter();
        $this->dumper = new DatabaseDumper($env, $ui);
    }

    public function getEncrypter(): Encrypter
    {
        return $this->encrypter;
    }

    public function getDumper(): DatabaseDumper
    {
        return $this->dumper;
    }

    public function getCloudManager(): CloudStorageManager
    {
        return $this->cloudManager;
    }

    /**
     * Performs a complete cloud backup:
     * 1. Creates a staging directory
     * 2. Dumps database (if enabled/detected)
     * 3. Copies included folders/files
     * 4. Packs into .tar.gz archive
     * 5. Encrypts with AES-256-GCM (.tar.gz.enc)
     * 6. Uploads to Cloudflare R2
     * 7. Prunes old backups based on retention_count
     */
    public function createCloudBackup(?string $customKey = null): string
    {
        $backupConfig = $this->config['backup']['cloud'] ?? $this->config['cloud_backup'] ?? [];
        $projectName = $this->config['name'] ?? basename($this->rootDir);
        $timestamp = date('Ymd_His');

        $tempDir = sys_get_temp_dir() . '/shipit_backup_' . uniqid($projectName . '_');
        mkdir($tempDir, 0777, true);
        $stagingDir = $tempDir . '/payload';
        mkdir($stagingDir, 0777, true);

        try {
            // 1. Dump Database
            $dbEnabled = $this->config['backup']['database']['enabled'] ?? true;
            if ($dbEnabled) {
                $dbConfig = $this->dumper->detectDatabaseConfig($this->rootDir);
                if ($dbConfig && !empty($dbConfig['database'])) {
                    $this->ui->step("Dumping database ({$dbConfig['database']})...");
                    $ext = $dbConfig['driver'] === 'sqlite' ? 'sqlite' : 'sql';
                    $dumpPath = $stagingDir . '/database_dump.' . $ext;
                    $this->dumper->dump($dbConfig, $dumpPath);

                    // Save db metadata for seamless restore
                    file_put_contents($stagingDir . '/database_meta.json', json_encode([
                        'driver' => $dbConfig['driver'],
                        'database' => $dbConfig['database'],
                        'created_at' => date('c'),
                    ], JSON_PRETTY_PRINT));
                }
            }

            // 2. Collect Included Folders / Files
            $includes = $this->config['backup']['include'] ?? [
                'writable/uploads',
                'storage/app/public',
                '.env',
            ];

            $filesDir = $stagingDir . '/files';
            mkdir($filesDir, 0777, true);

            foreach ($includes as $item) {
                $item = trim($item, '/\\');
                $sourcePath = $this->rootDir . '/' . $item;
                if (!file_exists($sourcePath)) {
                    continue;
                }

                $targetPath = $filesDir . '/' . $item;
                $targetParent = dirname($targetPath);
                if (!is_dir($targetParent)) {
                    mkdir($targetParent, 0777, true);
                }

                if (is_dir($sourcePath)) {
                    $this->fs->copyFolder($sourcePath, $targetPath);
                } else {
                    copy($sourcePath, $targetPath);
                }
            }

            // Add backup manifest
            file_put_contents($stagingDir . '/manifest.json', json_encode([
                'project' => $projectName,
                'version' => '1.0',
                'created_at' => date('c'),
                'strategy' => $this->config['strategy'] ?? 'copy',
                'branch' => $this->config['branch'] ?? 'main',
            ], JSON_PRETTY_PRINT));

            // 3. Compress into .tar.gz
            $tarGzFile = $tempDir . '/backup.tar.gz';
            $this->ui->step("Compressing backup archive...");
            $this->createTarGz($stagingDir, $tarGzFile);

            // 4. Encrypt with AES-256-GCM
            $encryptionKey = $customKey ?: $this->env->get('SHIPIT_BACKUP_KEY');
            if (!$encryptionKey) {
                throw new \InvalidArgumentException(
                    "No encryption key found. Please set SHIPIT_BACKUP_KEY in your .shipit.env or generate one with 'shipit env:init'."
                );
            }

            $encryptedFile = $tempDir . "/{$projectName}_backup_{$timestamp}.tar.gz.enc";
            $this->ui->step("Encrypting backup with AES-256-GCM...");
            $this->encrypter->encryptFile($tarGzFile, $encryptedFile, $encryptionKey);

            // 5. Upload to Cloudflare R2
            $this->ui->step("Uploading backup to Cloudflare R2...");
            $driver = $this->cloudManager->createDriver('r2', $backupConfig);

            $prefix = trim($backupConfig['prefix'] ?? "{$projectName}/", '/');
            $remoteKey = ($prefix !== '' ? "{$prefix}/" : '') . basename($encryptedFile);

            $driver->upload($encryptedFile, $remoteKey);
            $this->ui->verbose("Cloud backup uploaded to: {$remoteKey}", 'success');

            // 6. Prune Older Cloud Backups
            $retentionCount = (int) ($backupConfig['retention_count'] ?? $this->config['backup_retention'] ?? 10);
            if ($retentionCount > 0) {
                $this->pruneCloudBackups($driver, $prefix, $retentionCount);
            }

            return $remoteKey;
        } finally {
            // Clean up temporary workspace
            $this->fs->removeFolder($tempDir);
        }
    }

    /**
     * Lists cloud backups available for this project.
     */
    public function listCloudBackups(): array
    {
        $backupConfig = $this->config['backup']['cloud'] ?? $this->config['cloud_backup'] ?? [];
        $projectName = $this->config['name'] ?? basename($this->rootDir);
        $prefix = trim($backupConfig['prefix'] ?? "{$projectName}/", '/');

        $driver = $this->cloudManager->createDriver('r2', $backupConfig);
        return $driver->listObjects($prefix);
    }

    /**
     * Restores project from a cloud backup archive.
     */
    public function restoreFromCloud(?string $specificKey = null, ?string $customKey = null, bool $restoreDatabase = true, bool $restoreFiles = true): void
    {
        $backupConfig = $this->config['backup']['cloud'] ?? $this->config['cloud_backup'] ?? [];
        $projectName = $this->config['name'] ?? basename($this->rootDir);
        $driver = $this->cloudManager->createDriver('r2', $backupConfig);

        $encryptionKey = $customKey ?: $this->env->get('SHIPIT_BACKUP_KEY');
        if (!$encryptionKey) {
            throw new \InvalidArgumentException(
                "Missing encryption key. Please set SHIPIT_BACKUP_KEY in .shipit.env or provide --key argument."
            );
        }

        // Determine remote key to restore
        $remoteKey = $specificKey;
        if (!$remoteKey || $remoteKey === 'latest') {
            $prefix = trim($backupConfig['prefix'] ?? "{$projectName}/", '/');
            $objects = $driver->listObjects($prefix);
            if (empty($objects)) {
                throw new \RuntimeException("No backups found in Cloudflare R2 under prefix '{$prefix}'");
            }
            $remoteKey = $objects[0]['key'];
        }

        $tempDir = sys_get_temp_dir() . '/shipit_restore_' . uniqid();
        mkdir($tempDir, 0777, true);

        try {
            // 1. Download from R2
            $this->ui->step("Downloading cloud backup: {$remoteKey}...");
            $encryptedFile = $tempDir . '/backup.tar.gz.enc';
            $driver->download($remoteKey, $encryptedFile);

            // 2. Decrypt
            $this->ui->step("Decrypting backup with AES-256-GCM...");
            $tarGzFile = $tempDir . '/backup.tar.gz';
            $this->encrypter->decryptFile($encryptedFile, $tarGzFile, $encryptionKey);

            // 3. Extract Archive
            $this->ui->step("Extracting archive...");
            $payloadDir = $tempDir . '/payload';
            mkdir($payloadDir, 0777, true);
            $this->extractTarGz($tarGzFile, $payloadDir);

            // 4. Restore Files
            if ($restoreFiles && is_dir($payloadDir . '/files')) {
                $this->ui->step("Restoring project files...");
                $this->fs->copyFolder($payloadDir . '/files', $this->rootDir);
            }

            // 5. Restore Database
            if ($restoreDatabase) {
                $dbMetaFile = $payloadDir . '/database_meta.json';
                if (file_exists($dbMetaFile)) {
                    $meta = json_decode(file_get_contents($dbMetaFile), true);
                    $dbConfig = $this->dumper->detectDatabaseConfig($this->rootDir);

                    if ($dbConfig && !empty($dbConfig['database'])) {
                        $ext = ($meta['driver'] ?? 'mysql') === 'sqlite' ? 'sqlite' : 'sql';
                        $dumpFile = $payloadDir . '/database_dump.' . $ext;

                        if (file_exists($dumpFile)) {
                            $this->ui->step("Restoring database ({$dbConfig['database']})...");
                            $this->dumper->restore($dbConfig, $dumpFile);
                        }
                    }
                }
            }

            $this->ui->success("Disaster recovery restore completed successfully from: {$remoteKey}");
        } finally {
            $this->fs->removeFolder($tempDir);
        }
    }

    private function pruneCloudBackups($driver, string $prefix, int $retentionCount): void
    {
        $objects = $driver->listObjects($prefix);
        if (count($objects) > $retentionCount) {
            $toDelete = array_slice($objects, $retentionCount);
            foreach ($toDelete as $obj) {
                $driver->delete($obj['key']);
                $this->ui->verbose("Pruned old cloud backup: {$obj['key']}");
            }
        }
    }

    private function createTarGz(string $sourceDir, string $destinationArchive): void
    {
        $phar = new \PharData(substr($destinationArchive, 0, -3)); // .tar
        $phar->buildFromDirectory($sourceDir);
        $phar->compress(\Phar::GZ);
        @unlink(substr($destinationArchive, 0, -3)); // remove uncompressed .tar
    }

    private function extractTarGz(string $archivePath, string $destinationDir): void
    {
        $phar = new \PharData($archivePath);
        $phar->extractTo($destinationDir, null, true);
    }
}
