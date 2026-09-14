<?php

declare(strict_types=1);

namespace ShipIt\Tests;

use PHPUnit\Framework\TestCase;
use ShipIt\ShipIt;
use ShipIt\TerminalUI;

class YamlAndStepsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shipit_yaml_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeFolder($this->tempDir);
        }
    }

    private function removeFolder(string $dir): void
    {
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

    public function testLoadConfigFromShipitYaml(): void
    {
        $yamlContent = <<<YAML
name: my-jengo-app
adapter: ci4
branch: develop
strategy: copy
steps:
  - name: "Clear Cache"
    run: "echo cache cleared"
  - name: "Initial Data Setup"
    run: "echo data seeded"
    once: true
YAML;

        file_put_contents($this->tempDir . '/.shipit.yml', $yamlContent);

        $shipIt = new ShipIt($this->tempDir);
        $shipIt->loadConfig();
        $config = $shipIt->getConfig();

        $this->assertSame('my-jengo-app', $config['name']);
        $this->assertSame('ci4', $config['adapter']);
        $this->assertSame('develop', $config['branch']);
        $this->assertSame('copy', $config['strategy']);
        $this->assertCount(2, $config['steps']);
        $this->assertSame('Clear Cache', $config['steps'][0]['name']);
        $this->assertTrue($config['steps'][1]['once']);
    }

    public function testCustomStepsExecutionAndOneTimeTracking(): void
    {
        $markerFile = $this->tempDir . '/recurring.txt';
        $onceFile = $this->tempDir . '/once.txt';

        $yamlContent = <<<YAML
gitRepoUrl: "https://example.com/repo.git"
backup_path: "{$this->tempDir}/backups"
steps:
  - name: "Recurring Task"
    run: "touch {$markerFile}"
  - name: "One-Time Task"
    run: "touch {$onceFile}"
    once: true
    id: "init_seed_v1"
YAML;

        file_put_contents($this->tempDir . '/.shipit.yml', $yamlContent);

        $shipIt = new ShipIt($this->tempDir);
        $shipIt->loadConfig();

        $this->assertFileDoesNotExist($markerFile);
        $this->assertFileDoesNotExist($onceFile);
        $this->assertFalse($shipIt->isOnceExecuted('init_seed_v1'));

        // First execution: custom steps should run both
        $reflector = new \ReflectionClass(ShipIt::class);
        $runCustomStepsMethod = $reflector->getMethod('runCustomSteps');
        $runCustomStepsMethod->invoke($shipIt);

        $this->assertFileExists($markerFile);
        $this->assertFileExists($onceFile);
        $this->assertTrue($shipIt->isOnceExecuted('init_seed_v1'));

        // Remove both files to simulate a subsequent deployment
        unlink($markerFile);
        unlink($onceFile);

        // Second execution: Recurring task runs again, but One-Time task is skipped
        $runCustomStepsMethod->invoke($shipIt);

        $this->assertFileExists($markerFile);
        $this->assertFileDoesNotExist($onceFile); // Skipped because it was already executed once!
    }

    public function testResetOnceExecuted(): void
    {
        $shipIt = new ShipIt($this->tempDir);
        $shipIt->recordOnceExecuted('task_1', 'Task 1', 'echo 1');
        $shipIt->recordOnceExecuted('task_2', 'Task 2', 'echo 2');

        $this->assertTrue($shipIt->isOnceExecuted('task_1'));
        $this->assertTrue($shipIt->isOnceExecuted('task_2'));

        // Reset single task
        $shipIt->resetOnceExecuted('task_1');
        $this->assertFalse($shipIt->isOnceExecuted('task_1'));
        $this->assertTrue($shipIt->isOnceExecuted('task_2'));

        // Reset all
        $shipIt->resetOnceExecuted('--all');
        $this->assertFalse($shipIt->isOnceExecuted('task_2'));
    }

    public function testOnceListOutput(): void
    {
        $shipIt = new ShipIt($this->tempDir);
        $shipIt->recordOnceExecuted('seed_users', 'Seed Users', 'php spark db:seed UsersSeeder');

        $reflector = new \ReflectionClass(ShipIt::class);
        $doOnceListMethod = $reflector->getMethod('doOnceList');

        ob_start();
        $doOnceListMethod->invoke($shipIt);
        $output = ob_get_clean();

        $this->assertStringContainsString('seed_users', $output);
        $this->assertStringContainsString('Seed Users', $output);
        $this->assertStringContainsString('UsersSeeder', $output);
    }
}
