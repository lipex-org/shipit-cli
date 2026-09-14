<?php

declare(strict_types=1);

namespace ShipIt;

class Spinner
{
    /** @var string[] */
    private array $frames;
    private int $currentFrame = 0;
    private string $message = '';
    private bool $active = false;
    private bool $isTty = false;

    public function __construct(?bool $isTty = null)
    {
        $this->isTty = $isTty ?? (defined('STDOUT') && stream_isatty(STDOUT) && getenv('CI_ENVIRONMENT') !== 'testing');
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $this->frames = $isWindows
            ? ['-', '\\', '|', '/']
            : ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
    }

    public function start(string $message): void
    {
        $this->message = $message;
        $this->currentFrame = 0;
        $this->active = true;
        $this->render();
    }

    public function setMessage(string $message): void
    {
        $this->message = $message;
        if ($this->active) {
            $this->render();
        }
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isTty(): bool
    {
        return $this->isTty;
    }

    public function setIsTty(bool $isTty): void
    {
        $this->isTty = $isTty;
    }

    public function getFrames(): array
    {
        return $this->frames;
    }

    public function tick(): void
    {
        if (!$this->active) {
            return;
        }
        $this->currentFrame = ($this->currentFrame + 1) % count($this->frames);
        $this->render();
    }

    public function render(): void
    {
        if (!$this->isTty) {
            return;
        }
        $frame = $this->frames[$this->currentFrame];
        echo "\r\033[K\033[36m" . $frame . "\033[0m " . $this->message;
    }

    public function clear(): void
    {
        if ($this->active && $this->isTty) {
            echo "\r\033[K";
        }
        $this->active = false;
    }

    public function stop(?string $finalMessage = null): void
    {
        if (!$this->active) {
            return;
        }
        $this->clear();
        if ($finalMessage !== null) {
            echo $finalMessage . "\n";
        }
    }
}
