<?php

declare(strict_types=1);

namespace ShipIt\Tests;

use PHPUnit\Framework\TestCase;
use ShipIt\NodePackageManager;

class NodePackageManagerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shipit_npm_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeFolder($this->tempDir);
    }

    private function removeFolder(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeFolder($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testHasPackageJson(): void
    {
        $nodePM = new NodePackageManager($this->tempDir);
        $this->assertFalse($nodePM->hasPackageJson());

        file_put_contents($this->tempDir . '/package.json', '{}');
        $this->assertTrue($nodePM->hasPackageJson());
    }

    public function testDetectDefaultsToNpm(): void
    {
        $nodePM = new NodePackageManager($this->tempDir);
        $this->assertSame('npm', $nodePM->detect());
    }

    public function testDetectSingleLockfiles(): void
    {
        $nodePM = new NodePackageManager($this->tempDir);

        // Yarn
        file_put_contents($this->tempDir . '/yarn.lock', '');
        $this->assertSame('yarn', $nodePM->detect());
        unlink($this->tempDir . '/yarn.lock');

        // Pnpm
        file_put_contents($this->tempDir . '/pnpm-lock.yaml', '');
        $this->assertSame('pnpm', $nodePM->detect());
        unlink($this->tempDir . '/pnpm-lock.yaml');

        // Bun
        file_put_contents($this->tempDir . '/bun.lockb', '');
        $this->assertSame('bun', $nodePM->detect());
        unlink($this->tempDir . '/bun.lockb');

        file_put_contents($this->tempDir . '/bun.lock', '');
        $this->assertSame('bun', $nodePM->detect());
        unlink($this->tempDir . '/bun.lock');
    }

    public function testDetectConflictingLockfilesThrowsException(): void
    {
        $nodePM = new NodePackageManager($this->tempDir);
        file_put_contents($this->tempDir . '/yarn.lock', '');
        file_put_contents($this->tempDir . '/pnpm-lock.yaml', '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Conflicting package manager lockfiles found');
        $nodePM->detect();
    }

    public function testGetInstallAndBuildCommand(): void
    {
        $nodePM = new NodePackageManager($this->tempDir);
        $this->assertSame('npm install && npm run build', $nodePM->getInstallAndBuildCommand('npm'));
        $this->assertSame('yarn install && yarn run build', $nodePM->getInstallAndBuildCommand('yarn'));
        $this->assertSame('pnpm install && pnpm run build', $nodePM->getInstallAndBuildCommand('pnpm'));
        $this->assertSame('bun install && bun run build', $nodePM->getInstallAndBuildCommand('bun'));
    }
}
