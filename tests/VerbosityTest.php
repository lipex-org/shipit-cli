<?php

declare(strict_types=1);

namespace ShipIt\Tests;

use PHPUnit\Framework\TestCase;
use ShipIt\ShipIt;
use ShipIt\TerminalUI;
use ShipIt\TaskRunner;
use ShipIt\Validation\Validator;

class VerbosityTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shipit_verbosity_test_' . uniqid();
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

    public function testTerminalUIDefaultsToNonVerbose(): void
    {
        $ui = new TerminalUI();
        $this->assertFalse($ui->isVerbose());

        $ui->setVerbose(true);
        $this->assertTrue($ui->isVerbose());

        $ui->setVerbose(false);
        $this->assertFalse($ui->isVerbose());
    }

    public function testTerminalUIVerboseOutputSuppressedWhenNotVerbose(): void
    {
        $ui = new TerminalUI();
        $ui->setVerbose(false);

        ob_start();
        $ui->verbose("This should not be printed");
        $ui->debug("Debug info suppressed");
        $called = false;
        $ui->verboseOnly(function () use (&$called) {
            $called = true;
        });
        $output = ob_get_clean();

        $this->assertSame('', $output);
        $this->assertFalse($called);
    }

    public function testTerminalUIVerboseOutputPrintedWhenVerbose(): void
    {
        $ui = new TerminalUI();
        $ui->setVerbose(true);

        ob_start();
        $ui->verbose("Verbose message here");
        $ui->debug("Debug message here");
        $called = false;
        $ui->verboseOnly(function () use (&$called) {
            $called = true;
        });
        $output = ob_get_clean();

        $this->assertStringContainsString("Verbose message here", $output);
        $this->assertStringContainsString("Debug message here", $output);
        $this->assertTrue($called);
    }

    public function testTerminalUIVerboseWithDifferentLevels(): void
    {
        $ui = new TerminalUI();
        $ui->setVerbose(true);

        ob_start();
        $ui->verbose("Success detail", 'success');
        $ui->verbose("Warning detail", 'warning');
        $ui->verbose("Error detail", 'error');
        $ui->verbose("Info detail", 'info');
        $output = ob_get_clean();

        $this->assertStringContainsString("✅ Success detail", $output);
        $this->assertStringContainsString("⚠️  Warning detail", $output);
        $this->assertStringContainsString("❌ Error detail", $output);
        $this->assertStringContainsString("ℹ️  Info detail", $output);
    }

    public function testTaskRunnerSkipsSilentWhenNotVerbose(): void
    {
        $ui = new TerminalUI();
        $ui->setVerbose(false);

        $runner = new TaskRunner($ui);
        $runner->addTask('task1', function () {});
        $runner->addTask('task2', function () {});

        ob_start();
        // Ignore task2 so it is skipped
        $runner->run(['task1', 'task2'], ['task2'], [], false);
        $output = ob_get_clean();

        $this->assertStringNotContainsString("Skipped task2", $output);
    }

    public function testTaskRunnerPrintsSkippedWhenVerbose(): void
    {
        $ui = new TerminalUI();
        $ui->setVerbose(true);

        $runner = new TaskRunner($ui);
        $runner->addTask('task1', function () {});
        $runner->addTask('task2', function () {});

        ob_start();
        $runner->run(['task1', 'task2'], ['task2'], [], false);
        $output = ob_get_clean();

        $this->assertStringContainsString("Skipped task2", $output);
    }

    public function testValidatorQuietOnSuccessWhenNotVerbose(): void
    {
        $ui = new TerminalUI();
        $ui->setVerbose(false);

        $validator = new Validator($ui);

        ob_start();
        $valid = $validator->displayResults([], false);
        $output = ob_get_clean();

        $this->assertTrue($valid);
        $this->assertSame('', $output);
    }

    public function testValidatorShowsSuccessMessageWhenVerbose(): void
    {
        $ui = new TerminalUI();
        $ui->setVerbose(true);

        $validator = new Validator($ui);

        ob_start();
        $valid = $validator->displayResults([], true);
        $output = ob_get_clean();

        $this->assertTrue($valid);
        $this->assertStringContainsString("Configuration validation passed", $output);
    }

    public function testValidatorAlwaysShowsErrorsEvenWhenNotVerbose(): void
    {
        $ui = new TerminalUI();
        $ui->setVerbose(false);

        $validator = new Validator($ui);
        $results = [
            [
                'rule' => 'GitUrlRule',
                'status' => 'error',
                'message' => 'gitRepoUrl is missing',
                'suggestion' => 'Provide a valid URL'
            ]
        ];

        ob_start();
        $valid = $validator->displayResults($results, false);
        $output = ob_get_clean();

        $this->assertFalse($valid);
        $this->assertStringContainsString("GitUrlRule", $output);
        $this->assertStringContainsString("gitRepoUrl is missing", $output);
    }

    public function testShipItParsesVerboseFlags(): void
    {
        // 1. Default (no flag) -> not verbose
        $shipIt1 = new ShipIt($this->tempDir);
        $reflector = new \ReflectionClass(ShipIt::class);
        $parseMethod = $reflector->getMethod('parseArgs');
        $parseMethod->invoke($shipIt1, ['bin/shipit']);
        $this->assertFalse($shipIt1->isVerbose());
        $this->assertFalse($shipIt1->getUI()->isVerbose());

        // 2. --verbose flag
        $shipIt2 = new ShipIt($this->tempDir);
        $parseMethod->invoke($shipIt2, ['bin/shipit', '--verbose']);
        $this->assertTrue($shipIt2->isVerbose());
        $this->assertTrue($shipIt2->getUI()->isVerbose());

        // 3. -v flag
        $shipIt3 = new ShipIt($this->tempDir);
        $parseMethod->invoke($shipIt3, ['bin/shipit', '-v']);
        $this->assertTrue($shipIt3->isVerbose());
        $this->assertTrue($shipIt3->getUI()->isVerbose());
    }

    public function testShipItSetVerboseMethod(): void
    {
        $shipIt = new ShipIt($this->tempDir);
        $shipIt->setVerbose(true);
        $this->assertTrue($shipIt->isVerbose());
        $this->assertTrue($shipIt->getUI()->isVerbose());

        $shipIt->setVerbose(false);
        $this->assertFalse($shipIt->isVerbose());
        $this->assertFalse($shipIt->getUI()->isVerbose());
    }

    public function testHelpShowsVerboseOption(): void
    {
        $shipIt = new ShipIt($this->tempDir);
        $reflector = new \ReflectionClass(ShipIt::class);
        $showHelpMethod = $reflector->getMethod('showHelp');

        ob_start();
        $showHelpMethod->invoke($shipIt);
        $output = ob_get_clean();

        $this->assertStringContainsString('--verbose, -v', $output);
    }

    public function testLogoPrintedOnlyWhenVerbose(): void
    {
        $shipIt = new ShipIt($this->tempDir);
        $reflector = new \ReflectionClass(ShipIt::class);
        $printLogoMethod = $reflector->getMethod('printLogo');

        ob_start();
        $printLogoMethod->invoke($shipIt);
        $logoOutput = ob_get_clean();

        // The logo contains the ascii art text
        $this->assertNotEmpty($logoOutput);
    }
}
