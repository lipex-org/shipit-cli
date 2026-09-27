<?php

declare(strict_types=1);

namespace ShipIt\Tests;

use PHPUnit\Framework\TestCase;
use ShipIt\Backup\Encrypter;
use ShipIt\Config\EnvLoader;
use ShipIt\Cloud\CloudflareR2Client;
use ShipIt\Cloud\CloudStorageManager;
use ShipIt\Contracts\CloudStorageInterface;
use ShipIt\Backup\DatabaseDumper;
use ShipIt\Backup\BackupManager;
use ShipIt\Filesystem;
use ShipIt\TerminalUI;
use ShipIt\ShipIt;

class CloudBackupTest extends TestCase
{
    private string $tempDir;
    private Filesystem $fs;
    private TerminalUI $ui;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shipit_cloud_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        $this->ui = new TerminalUI();
        $this->fs = new Filesystem($this->ui, false);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->fs->removeFolder($this->tempDir);
        }
    }

    public function testEnvLoaderPrecedence(): void
    {
        $projectDir = $this->tempDir . '/app';
        $globalDir = $this->tempDir . '/global';
        mkdir($projectDir, 0777, true);
        mkdir($globalDir, 0777, true);

        // Global .env
        file_put_contents($globalDir . '/.env', "SHIPIT_R2_ACCOUNT_ID=\"global_acc\"\nSHIPIT_R2_BUCKET=\"global_bucket\"\n");

        // Project .shipit.env
        file_put_contents($projectDir . '/.shipit.env', "SHIPIT_R2_BUCKET=\"project_bucket\"\nSHIPIT_BACKUP_KEY=\"base64:test\"\n");

        $loader = new EnvLoader();
        $env = $loader->load($projectDir, $globalDir);

        $this->assertSame('global_acc', $env['SHIPIT_R2_ACCOUNT_ID']);
        $this->assertSame('project_bucket', $env['SHIPIT_R2_BUCKET']);
        $this->assertSame('base64:test', $env['SHIPIT_BACKUP_KEY']);
    }

    public function testEncrypterEncryptAndDecrypt(): void
    {
        $encrypter = new Encrypter();
        $key = Encrypter::generateKey();
        $this->assertStringStartsWith('base64:', $key);

        $sourceFile = $this->tempDir . '/secret.txt';
        $encryptedFile = $this->tempDir . '/secret.enc';
        $decryptedFile = $this->tempDir . '/secret.restored.txt';

        $secretContent = "Confidential customer data: " . bin2hex(random_bytes(64));
        file_put_contents($sourceFile, $secretContent);

        $encrypter->encryptFile($sourceFile, $encryptedFile, $key);
        $this->assertFileExists($encryptedFile);
        $this->assertNotSame($secretContent, file_get_contents($encryptedFile));

        $encrypter->decryptFile($encryptedFile, $decryptedFile, $key);
        $this->assertFileExists($decryptedFile);
        $this->assertSame($secretContent, file_get_contents($decryptedFile));
    }

    public function testEncrypterFailsWithWrongKey(): void
    {
        $this->expectException(\RuntimeException::class);

        $encrypter = new Encrypter();
        $key1 = Encrypter::generateKey();
        $key2 = Encrypter::generateKey();

        $sourceFile = $this->tempDir . '/data.txt';
        $encryptedFile = $this->tempDir . '/data.enc';
        $decryptedFile = $this->tempDir . '/data.dec.txt';

        file_put_contents($sourceFile, "important data");
        $encrypter->encryptFile($sourceFile, $encryptedFile, $key1);

        // Attempt decrypt with key2
        $encrypter->decryptFile($encryptedFile, $decryptedFile, $key2);
    }

    public function testCloudStorageManagerSupportsR2AndCustomExtension(): void
    {
        $env = new EnvLoader([
            'SHIPIT_R2_ACCOUNT_ID' => 'acc123',
            'SHIPIT_R2_ACCESS_KEY_ID' => 'key123',
            'SHIPIT_R2_SECRET_ACCESS_KEY' => 'sec123',
            'SHIPIT_R2_BUCKET' => 'my-bucket',
        ]);

        $manager = new CloudStorageManager($env);
        $driver = $manager->createDriver('r2');
        $this->assertInstanceOf(CloudflareR2Client::class, $driver);
        $this->assertSame('r2', $driver->getDriverName());

        // Test extensibility
        $mockCustomDriver = $this->createStub(CloudStorageInterface::class);
        $mockCustomDriver->method('getDriverName')->willReturn('s3');

        $manager->extend('s3', fn($e, $cfg) => $mockCustomDriver);
        $custom = $manager->createDriver('s3');
        $this->assertSame('s3', $custom->getDriverName());
    }

    public function testDatabaseDumperDetectsFromAppEnv(): void
    {
        $projectDir = $this->tempDir . '/app';
        mkdir($projectDir, 0777, true);

        // Laravel format .env
        file_put_contents($projectDir . '/.env', "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_DATABASE=prod_db\nDB_USERNAME=dbuser\nDB_PASSWORD=dbpass\n");

        $env = new EnvLoader();
        $dumper = new DatabaseDumper($env, $this->ui);

        $dbConfig = $dumper->detectDatabaseConfig($projectDir);
        $this->assertNotNull($dbConfig);
        $this->assertSame('mysql', $dbConfig['driver']);
        $this->assertSame('prod_db', $dbConfig['database']);
        $this->assertSame('dbuser', $dbConfig['username']);
        $this->assertSame('dbpass', $dbConfig['password']);
    }

    public function testDatabaseDumperSQLiteBackupAndRestore(): void
    {
        $projectDir = $this->tempDir . '/sqlite_app';
        mkdir($projectDir, 0777, true);
        $sqliteDb = $projectDir . '/database.sqlite';
        file_put_contents($sqliteDb, "SQLITE-TEST-DATABASE-CONTENT");

        $env = new EnvLoader();
        $dumper = new DatabaseDumper($env, $this->ui);

        $dbConfig = [
            'driver' => 'sqlite',
            'database' => $sqliteDb,
        ];

        $dumpFile = $projectDir . '/dump.sqlite';
        $dumper->dump($dbConfig, $dumpFile);
        $this->assertFileExists($dumpFile);
        $this->assertSame("SQLITE-TEST-DATABASE-CONTENT", file_get_contents($dumpFile));

        // Restore to fresh target
        $restoreTarget = $projectDir . '/database.restored.sqlite';
        $dbConfig['database'] = $restoreTarget;
        $dumper->restore($dbConfig, $dumpFile);
        $this->assertFileExists($restoreTarget);
        $this->assertSame("SQLITE-TEST-DATABASE-CONTENT", file_get_contents($restoreTarget));
    }

    public function testBackupManagerEndToEndWithMockCloudStorage(): void
    {
        $projectDir = $this->tempDir . '/full_app';
        mkdir($projectDir, 0777, true);
        mkdir($projectDir . '/writable/uploads', 0777, true);
        mkdir($projectDir . '/.deploy', 0777, true);

        file_put_contents($projectDir . '/writable/uploads/avatar.png', 'avatar-content');
        file_put_contents($projectDir . '/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=" . $projectDir . "/db.sqlite\n");
        file_put_contents($projectDir . '/db.sqlite', "SQLITE-APP-DATA");

        $encryptionKey = Encrypter::generateKey();

        $env = new EnvLoader([
            'SHIPIT_CLOUD_DRIVER' => 'mock_r2',
            'SHIPIT_BACKUP_KEY' => $encryptionKey,
        ]);

        $uploadedFiles = [];
        $mockStorage = $this->createStub(CloudStorageInterface::class);
        $mockStorage->method('getDriverName')->willReturn('r2');
        $mockStorage->method('upload')->willReturnCallback(function ($local, $remote) use (&$uploadedFiles) {
            $uploadedFiles[$remote] = file_get_contents($local);
            return true;
        });
        $mockStorage->method('listObjects')->willReturnCallback(function ($prefix) use (&$uploadedFiles) {
            $items = [];
            foreach ($uploadedFiles as $key => $content) {
                if ($prefix === '' || str_starts_with($key, $prefix)) {
                    $items[] = [
                        'key' => $key,
                        'size' => strlen($content),
                        'last_modified' => date('c'),
                    ];
                }
            }
            return $items;
        });
        $mockStorage->method('download')->willReturnCallback(function ($remote, $localDest) use (&$uploadedFiles) {
            if (!isset($uploadedFiles[$remote])) {
                throw new \RuntimeException("Remote file not found: {$remote}");
            }
            file_put_contents($localDest, $uploadedFiles[$remote]);
            return true;
        });

        $config = [
            'name' => 'mock-project',
            'strategy' => 'copy',
            'backup' => [
                'cloud' => [
                    'prefix' => 'mock-project',
                    'retention_count' => 5,
                ],
                'database' => [
                    'enabled' => true,
                ],
                'include' => [
                    'writable/uploads',
                    '.env',
                ],
            ],
        ];

        $manager = new BackupManager($projectDir, $config, $env, $this->ui, $this->fs);
        $manager->getCloudManager()->extend('r2', fn($e, $cfg) => $mockStorage);

        // 1. Create cloud backup
        $remoteKey = $manager->createCloudBackup($encryptionKey);
        $this->assertArrayHasKey($remoteKey, $uploadedFiles);

        // 2. Wipe uploads and database to test disaster recovery
        unlink($projectDir . '/writable/uploads/avatar.png');
        unlink($projectDir . '/db.sqlite');
        $this->assertFileDoesNotExist($projectDir . '/writable/uploads/avatar.png');
        $this->assertFileDoesNotExist($projectDir . '/db.sqlite');

        // 3. Restore from cloud
        ob_start();
        $manager->restoreFromCloud($remoteKey, $encryptionKey);
        ob_get_clean();

        // 4. Verify restored state
        $this->assertFileExists($projectDir . '/writable/uploads/avatar.png');
        $this->assertSame('avatar-content', file_get_contents($projectDir . '/writable/uploads/avatar.png'));

        $this->assertFileExists($projectDir . '/db.sqlite');
        $this->assertSame('SQLITE-APP-DATA', file_get_contents($projectDir . '/db.sqlite'));
    }

    public function testShipItEnvInitCommand(): void
    {
        $projectDir = $this->tempDir . '/init_app';
        mkdir($projectDir, 0777, true);
        touch($projectDir . '/.gitignore');

        $shipIt = new ShipIt($projectDir);
        ob_start();
        $shipIt->run(['bin/shipit', 'env:init']);
        ob_get_clean();

        $envFile = $projectDir . '/.shipit.env';
        $this->assertFileExists($envFile);

        $content = file_get_contents($envFile);
        $this->assertStringContainsString('SHIPIT_R2_ACCOUNT_ID', $content);
        $this->assertStringContainsString('SHIPIT_BACKUP_KEY="base64:', $content);

        // Check gitignore contains .shipit.env
        $gitignore = file_get_contents($projectDir . '/.gitignore');
        $this->assertStringContainsString('.shipit.env', $gitignore);
    }
}
