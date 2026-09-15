<?php

declare(strict_types=1);

namespace ShipIt\Tests;

use PHPUnit\Framework\TestCase;
use ShipIt\Spinner;
use ShipIt\TerminalUI;

class SpinnerTest extends TestCase
{
    public function testSpinnerStateInitial(): void
    {
        $spinner = new Spinner(false);
        $this->assertFalse($spinner->isActive());
        $this->assertFalse($spinner->isTty());
        $this->assertSame('', $spinner->getMessage());
        $this->assertNotEmpty($spinner->getFrames());
    }

    public function testSpinnerStartAndMessage(): void
    {
        $spinner = new Spinner(false);
        $spinner->start("Deploying...");
        $this->assertTrue($spinner->isActive());
        $this->assertSame("Deploying...", $spinner->getMessage());

        $spinner->setMessage("Updating...");
        $this->assertSame("Updating...", $spinner->getMessage());

        $spinner->clear();
        $this->assertFalse($spinner->isActive());
    }

    public function testSpinnerTickingCyclesFrames(): void
    {
        $spinner = new Spinner(true);
        $frames = $spinner->getFrames();
        $this->assertGreaterThan(1, count($frames));

        ob_start();
        $spinner->start("Step 1");
        $initialRender = ob_get_clean();
        $this->assertStringContainsString($frames[0], $initialRender);
        $this->assertStringContainsString("Step 1", $initialRender);

        ob_start();
        $spinner->tick();
        $secondRender = ob_get_clean();
        $this->assertStringContainsString($frames[1], $secondRender);

        // Tick through all remaining frames to verify cycle
        ob_start();
        for ($i = 2; $i < count($frames); $i++) {
            $spinner->tick();
        }
        ob_get_clean();

        // Next tick wraps around to frame 0
        ob_start();
        $spinner->tick();
        $wrapRender = ob_get_clean();
        $this->assertStringContainsString($frames[0], $wrapRender);

        $spinner->clear();
    }

    public function testSpinnerRendersAnsiWhenTty(): void
    {
        $spinner = new Spinner(true);

        ob_start();
        $spinner->start("In progress");
        $output = ob_get_clean();

        // Must contain carriage return \r and erase line \033[K
        $this->assertStringContainsString("\r\033[K", $output);
        $this->assertStringContainsString("In progress", $output);

        ob_start();
        $spinner->stop("Done!");
        $stopOutput = ob_get_clean();

        $this->assertStringContainsString("\r\033[K", $stopOutput);
        $this->assertStringContainsString("Done!\n", $stopOutput);
        $this->assertFalse($spinner->isActive());
    }

    public function testSpinnerSilentWhenNotTty(): void
    {
        $spinner = new Spinner(false);

        ob_start();
        $spinner->start("Silent task");
        $spinner->tick();
        $spinner->setMessage("New message");
        $spinner->clear();
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    public function testTerminalUIStepStartsAndUpdatesSpinner(): void
    {
        $spinner = new Spinner(false);
        $ui = new TerminalUI($spinner);

        $this->assertFalse($spinner->isActive());

        $ui->step("First step");
        $this->assertTrue($spinner->isActive());
        $this->assertSame("First step", $spinner->getMessage());

        $ui->step("Second step");
        $this->assertTrue($spinner->isActive());
        $this->assertSame("Second step", $spinner->getMessage());

        ob_start();
        $ui->stepSuccess("All done");
        $output = ob_get_clean();

        $this->assertFalse($spinner->isActive());
        $this->assertStringContainsString("All done", $output);
    }

    public function testTerminalUIStepInVerboseMode(): void
    {
        $spinner = new Spinner(false);
        $ui = new TerminalUI($spinner);
        $ui->setVerbose(true);

        ob_start();
        $ui->step("Verbose step");
        $output = ob_get_clean();

        // When verbose, it should not activate spinner, but output an info line directly
        $this->assertFalse($spinner->isActive());
        $this->assertStringContainsString("Verbose step", $output);
    }

    public function testTerminalUISuccessStopsActiveSpinner(): void
    {
        $spinner = new Spinner(true);
        $ui = new TerminalUI($spinner);

        ob_start();
        $ui->step("Processing...");
        $this->assertTrue($spinner->isActive());

        $ui->success("Finished!");
        $output = ob_get_clean();

        $this->assertFalse($spinner->isActive());
        $this->assertStringContainsString("Finished!", $output);
    }

    public function testTerminalUIErrorStopsActiveSpinner(): void
    {
        $spinner = new Spinner(true);
        $ui = new TerminalUI($spinner);

        ob_start();
        $ui->step("Processing...");
        $this->assertTrue($spinner->isActive());

        $ui->stepError("Failed!");
        $output = ob_get_clean();

        $this->assertFalse($spinner->isActive());
        $this->assertStringContainsString("Failed!", $output);
    }
}
