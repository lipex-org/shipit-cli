<?php

declare(strict_types=1);

namespace ShipIt\Tests;

use PHPUnit\Framework\TestCase;
use ShipIt\ShipIt;
use ShipIt\TerminalUI;
use ShipIt\Filesystem;
use ReflectionClass;

class BackupRotationTest extends TestCase
{
    private string $tempDir;
    private string $backupDir;
    private ShipIt $shipIt;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shipit_rotation_test_root_' . uniqid();
        $this->backupDir = sys_get_temp_dir() . '/shipit_rotation_test_backups_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        mkdir($this->backupDir, 0777, true);

        $ui = $this->createStub(TerminalUI::class);
        $this->fs = new Filesystem($ui, false);

        $this->shipIt = new ShipIt();

        // Inject variables using Reflection
        $reflector = new ReflectionClass(ShipIt::class);

        $configProp = $reflector->getProperty('config');
        $configProp->setValue($this->shipIt, [
            'backup_path' => $this->backupDir,
            'backup_retention' => 3
        ]);

        $rootDirProp = $reflector->getProperty('rootDir');
        $rootDirProp->setValue($this->shipIt, $this->tempDir);

        $fsProp = $reflector->getProperty('fs');
        $fsProp->setValue($this->shipIt, $this->fs);

        $uiProp = $reflector->getProperty('ui');
        $uiProp->setValue($this->shipIt, $ui);

        $ignoreListProp = $reflector->getProperty('ignoreList');
        $ignoreListProp->setValue($this->shipIt, ['composer', 'nodejs', 'perms', 'symlink']);
    }

    protected function tearDown(): void
    {
        $this->fs->removeFolder($this->tempDir);
        $this->fs->removeFolder($this->backupDir);
    }

    public function testRotateBackupsRetentionLimit(): void
    {
        // Create 5 mock backup folders with different timestamps (and modification times)
        $folders = [
            $this->backupDir . '/backup_20260601_120000',
            $this->backupDir . '/backup_20260602_120000',
            $this->backupDir . '/backup_20260603_120000',
            $this->backupDir . '/backup_20260604_120000',
            $this->backupDir . '/backup_20260605_120000',
        ];

        foreach ($folders as $idx => $folder) {
            mkdir($folder, 0777, true);
            file_put_contents($folder . '/test.txt', 'data');
            // Set modification times in sequence (05 is newest, 01 is oldest)
            touch($folder, time() - (5 - $idx) * 3600);
        }

        // Call the private rotateBackups method via Reflection
        $reflector = new ReflectionClass(ShipIt::class);
        $method = $reflector->getMethod('rotateBackups');
        $method->invoke($this->shipIt);

        // Retention limit is 3, so:
        // backup_20260605_120000 (newest) -> Keep
        // backup_20260604_120000 -> Keep
        // backup_20260603_120000 -> Keep
        // backup_20260602_120000 -> Delete
        // backup_20260601_120000 -> Delete

        $this->assertDirectoryExists($this->backupDir . '/backup_20260605_120000');
        $this->assertDirectoryExists($this->backupDir . '/backup_20260604_120000');
        $this->assertDirectoryExists($this->backupDir . '/backup_20260603_120000');
        $this->assertDirectoryDoesNotExist($this->backupDir . '/backup_20260602_120000');
        $this->assertDirectoryDoesNotExist($this->backupDir . '/backup_20260601_120000');
    }

    public function testTargetedRollback(): void
    {
        $backup1 = $this->backupDir . '/backup_20260601_120000';
        $backup2 = $this->backupDir . '/backup_20260602_120000';

        mkdir($backup1, 0777, true);
        mkdir($backup2, 0777, true);

        file_put_contents($backup1 . '/file1.txt', 'one');
        file_put_contents($backup2 . '/file1.txt', 'two');

        // Set mtimes so backup2 is newer
        touch($backup1, time() - 3600);
        touch($backup2, time());

        // Invoke rollback with specific target via Reflection
        $reflector = new ReflectionClass(ShipIt::class);
        $method = $reflector->getMethod('doRollback');

        // 1. Specific Target (backup1)
        $method->invoke($this->shipIt, ['bin/shipit', 'rollback', 'backup_20260601_120000']);
        $this->assertFileExists($this->tempDir . '/file1.txt');
        $this->assertSame('one', file_get_contents($this->tempDir . '/file1.txt'));

        // Clear target file
        if (file_exists($this->tempDir . '/file1.txt')) {
            unlink($this->tempDir . '/file1.txt');
        }

        // 2. Default Target (should select backup2 as it is the latest)
        $method->invoke($this->shipIt, ['bin/shipit', 'rollback']);
        $this->assertFileExists($this->tempDir . '/file1.txt');
        $this->assertSame('two', file_get_contents($this->tempDir . '/file1.txt'));
    }

    public function testTargetedRollbackChoice(): void
    {
        $backup1 = $this->backupDir . '/backup_20260601_120000';
        $backup2 = $this->backupDir . '/backup_20260602_120000';

        mkdir($backup1, 0777, true);
        mkdir($backup2, 0777, true);

        file_put_contents($backup1 . '/file1.txt', 'one');
        file_put_contents($backup2 . '/file1.txt', 'two');

        touch($backup1, time() - 3600);
        touch($backup2, time());

        $reflector = new ReflectionClass(ShipIt::class);
        $method = $reflector->getMethod('doRollback');
        $method->invoke($this->shipIt, ['bin/shipit', 'rollback', basename($backup1)]);

        $this->assertFileExists($this->tempDir . '/file1.txt');
        $this->assertSame('one', file_get_contents($this->tempDir . '/file1.txt'));
    }

    public function testBackupEnvOption(): void
    {
        // 1. Setup config with backup_env => false
        $reflector = new ReflectionClass(ShipIt::class);
        $configProp = $reflector->getProperty('config');
        $configProp->setValue($this->shipIt, [
            'backup_path' => $this->backupDir,
            'backup_retention' => 3,
            'backup_env' => false
        ]);

        // Create a mock .env file in rootDir ($this->tempDir)
        file_put_contents($this->tempDir . '/.env', 'DATABASE_URL=mysql://...');
        file_put_contents($this->tempDir . '/app.php', '<?php');

        // Trigger backup
        $backupMethod = $reflector->getMethod('doBackup');
        $backupMethod->invoke($this->shipIt);

        // Find the created backup directory
        $backups = glob($this->backupDir . '/backup_*');
        $this->assertCount(1, $backups);
        $backupFolder = $backups[0];

        // Assert that app.php was backed up but .env was NOT
        $this->assertFileExists($backupFolder . '/app.php');
        $this->assertFileDoesNotExist($backupFolder . '/.env');

        // 2. Modify local .env to simulate dynamic production change
        file_put_contents($this->tempDir . '/.env', 'DATABASE_URL=postgres://...');

        // Trigger rollback
        $rollbackMethod = $reflector->getMethod('doRollback');
        $rollbackMethod->invoke($this->shipIt, ['bin/shipit', 'rollback']);

        // Assert that local .env was PRESERVED during clear & rollback, and contains the updated config
        $this->assertFileExists($this->tempDir . '/.env');
        $this->assertSame('DATABASE_URL=postgres://...', file_get_contents($this->tempDir . '/.env'));
    }
}
