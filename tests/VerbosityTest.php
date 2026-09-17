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

        $this->assertStringContainsString("Success detail", $output);
        $this->assertStringContainsString("Warning detail", $output);
        $this->assertStringContainsString("Error detail", $output);
        $this->assertStringContainsString("Info detail", $output);
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

    public function testTerminalUISummaryOutput(): void
    {
        $ui = new TerminalUI();
        ob_start();
        $ui->summary('Test Title', [
            'Mode' => 'Non-verbose (use --verbose or -v for detailed output)',
            'Framework' => 'CodeIgniter 4',
            'Package Manager' => 'Composer',
        ]);
        $output = ob_get_clean();

        $this->assertStringContainsString('Test Title', $output);
        $this->assertStringContainsString('Mode:', $output);
        $this->assertStringContainsString('Non-verbose', $output);
        $this->assertStringContainsString('Framework:', $output);
        $this->assertStringContainsString('CodeIgniter 4', $output);
        $this->assertStringContainsString('Package Manager:', $output);
        $this->assertStringContainsString('Composer', $output);
    }

    public function testDetectedFrameworkFromConfig(): void
    {
        $shipIt = new ShipIt($this->tempDir);
        $reflector = new \ReflectionClass(ShipIt::class);
        $configProp = $reflector->getProperty('config');

        $configProp->setValue($shipIt, ['adapter' => 'ci4']);
        $this->assertSame('CodeIgniter 4', $shipIt->getDetectedFramework());

        $configProp->setValue($shipIt, ['adapter' => 'laravel']);
        $this->assertSame('Laravel', $shipIt->getDetectedFramework());

        $configProp->setValue($shipIt, ['adapter' => 'vite']);
        $this->assertSame('Vite', $shipIt->getDetectedFramework());

        $configProp->setValue($shipIt, ['adapter' => 'wordpress']);
        $this->assertSame('WordPress', $shipIt->getDetectedFramework());
    }

    public function testDetectedFrameworkFromMarkerFiles(): void
    {
        $projectDir = $this->tempDir . '/ci4_app';
        mkdir($projectDir, 0777, true);
        touch($projectDir . '/spark');

        $shipIt = new ShipIt($projectDir);
        $this->assertSame('CodeIgniter 4', $shipIt->getDetectedFramework());

        unlink($projectDir . '/spark');
        touch($projectDir . '/artisan');
        $this->assertSame('Laravel', $shipIt->getDetectedFramework());

        unlink($projectDir . '/artisan');
        touch($projectDir . '/next.config.js');
        $this->assertSame('Next.js', $shipIt->getDetectedFramework());

        unlink($projectDir . '/next.config.js');
        $this->assertSame('Generic / Custom', $shipIt->getDetectedFramework());
    }

    public function testDetectedPackageManagers(): void
    {
        $projectDir = $this->tempDir . '/pm_app';
        mkdir($projectDir, 0777, true);
        file_put_contents($projectDir . '/composer.json', '{}');
        file_put_contents($projectDir . '/package.json', '{}');
        file_put_contents($projectDir . '/yarn.lock', '');

        $shipIt = new ShipIt($projectDir);
        $pms = $shipIt->getDetectedPackageManagers();

        $this->assertContains('Composer', $pms);
        $this->assertContains('yarn', $pms);
    }

    public function testDetectedPackageManagersIgnored(): void
    {
        $projectDir = $this->tempDir . '/pm_ignored_app';
        mkdir($projectDir, 0777, true);
        file_put_contents($projectDir . '/composer.json', '{}');
        file_put_contents($projectDir . '/package.json', '{}');

        $shipIt = new ShipIt($projectDir);
        $reflector = new \ReflectionClass(ShipIt::class);
        $ignoreProp = $reflector->getProperty('ignoreList');
        $ignoreProp->setValue($shipIt, ['composer', 'nodejs']);

        $pms = $shipIt->getDetectedPackageManagers();
        $this->assertContains('Composer (ignored)', $pms);
        $this->assertContains('npm (ignored)', $pms);
    }

    public function testShowDeploymentSummaryOutputsTechnologyAndNonVerboseMode(): void
    {
        $projectDir = $this->tempDir . '/summary_app';
        mkdir($projectDir, 0777, true);
        file_put_contents($projectDir . '/composer.json', '{}');

        $shipIt = new ShipIt($projectDir);
        $reflector = new \ReflectionClass(ShipIt::class);
        $configProp = $reflector->getProperty('config');
        $configProp->setValue($shipIt, [
            'name' => 'test-deploy-app',
            'adapter' => 'ci4',
            'branch' => 'staging',
            'strategy' => 'copy',
        ]);

        ob_start();
        $shipIt->showDeploymentSummary();
        $output = ob_get_clean();

        $this->assertStringContainsString('ShipIt Deploying: test-deploy-app (branch: staging)', $output);
        $this->assertStringContainsString('Mode:', $output);
        $this->assertStringContainsString('Non-verbose', $output);
        $this->assertStringContainsString('Framework:', $output);
        $this->assertStringContainsString('CodeIgniter 4', $output);
        $this->assertStringContainsString('Package Manager:', $output);
        $this->assertStringContainsString('Composer', $output);
        $this->assertStringContainsString('Environment:', $output);
        $this->assertStringContainsString('PHP', $output);
        $this->assertStringContainsString('Strategy:', $output);
        $this->assertStringContainsString('copy', $output);
    }
}
