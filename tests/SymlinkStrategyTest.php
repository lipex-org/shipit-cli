<?php

declare(strict_types=1);

namespace ShipIt\Tests;

use PHPUnit\Framework\TestCase;
use ShipIt\ShipIt;
use ShipIt\Filesystem;
use ShipIt\TerminalUI;

class SymlinkStrategyTest extends TestCase
{
    private string $tempDir;
    private string $rootDir;
    private ShipIt $shipIt;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shipit_symlink_test_' . uniqid();
        $this->rootDir = $this->tempDir . '/app';
        mkdir($this->rootDir, 0777, true);

        $ui = $this->createStub(TerminalUI::class);
        $this->fs = new Filesystem($ui, false);

        $this->shipIt = new ShipIt($this->rootDir);
        
        $reflector = new \ReflectionClass(ShipIt::class);
        $fsProp = $reflector->getProperty('fs');
        $fsProp->setValue($this->shipIt, $this->fs);
    }

    protected function tearDown(): void
    {
        $this->fs->removeFolder($this->tempDir);
    }

    public function testSymlinkSwap(): void
    {
        $releaseFolder = $this->rootDir . '/releases/release_12345';
        mkdir($releaseFolder, 0777, true);
        file_put_contents($releaseFolder . '/index.php', 'hello');

        $reflector = new \ReflectionClass(ShipIt::class);
        $activeDirProp = $reflector->getProperty('activeDir');
        $activeDirProp->setValue($this->shipIt, $releaseFolder);

        $releasesDirProp = $reflector->getProperty('releasesDir');
        $releasesDirProp->setValue($this->shipIt, $this->rootDir . '/releases');

        $currentSymlinkProp = $reflector->getProperty('currentSymlink');
        $currentSymlinkProp->setValue($this->shipIt, $this->rootDir . '/current');

        // Execute private performSymlinkSwap method
        $method = $reflector->getMethod('performSymlinkSwap');
        $method->invoke($this->shipIt);

        $currentLink = $this->rootDir . '/current';
        $this->assertTrue(is_link($currentLink));
        $this->assertSame($releaseFolder, readlink($currentLink));
    }

    public function testPruneReleases(): void
    {
        $releasesDir = $this->rootDir . '/releases';
        mkdir($releasesDir, 0777, true);

        // Create 6 release directories
        for ($i = 1; $i <= 6; $i++) {
            mkdir($releasesDir . '/release_20260803_12000' . $i, 0777, true);
        }

        $reflector = new \ReflectionClass(ShipIt::class);
        $releasesDirProp = $reflector->getProperty('releasesDir');
        $releasesDirProp->setValue($this->shipIt, $releasesDir);

        // Mock keep_releases config value to 3
        $configProp = $reflector->getProperty('config');
        $config = $configProp->getValue($this->shipIt);
        $config['keep_releases'] = 3;
        $configProp->setValue($this->shipIt, $config);

        // Execute pruneReleases
        $method = $reflector->getMethod('pruneReleases');
        $method->invoke($this->shipIt);

        $remaining = glob($releasesDir . '/release_*');
        $this->assertCount(3, $remaining);
        
        // Assert that the kept releases are the newest ones
        $basenames = array_map('basename', $remaining);
        $this->assertContains('release_20260803_120004', $basenames);
        $this->assertContains('release_20260803_120005', $basenames);
        $this->assertContains('release_20260803_120006', $basenames);
    }
}
