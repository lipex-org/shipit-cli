<?php

declare(strict_types=1);

namespace ShipIt;

class TerminalUI
{
    private bool $verbose = false;
    private Spinner $spinner;

    public function __construct(?Spinner $spinner = null)
    {
        $this->spinner = $spinner ?? new Spinner();
    }

    public function getSpinner(): Spinner
    {
        return $this->spinner;
    }

    public function setSpinner(Spinner $spinner): void
    {
        $this->spinner = $spinner;
    }

    public function step(string $msg): void
    {
        if ($this->verbose) {
            $this->info($msg);
            return;
        }

        if ($this->spinner->isActive()) {
            $this->spinner->setMessage($msg);
        } else {
            $this->spinner->start($msg);
        }
    }

    public function stepSuccess(string $msg): void
    {
        if ($this->spinner->isActive()) {
            $this->spinner->stop();
        }
        $this->success($msg);
    }

    public function stepError(string $msg): void
    {
        if ($this->spinner->isActive()) {
            $this->spinner->stop();
        }
        $this->error($msg);
    }

    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }

    public function isVerbose(): bool
    {
        return $this->verbose;
    }

    public function color(string $text, string $code): string
    {
        return $code . $text . "\033[0m";
    }

    public function success(string $msg): void
    {
        if (isset($this->spinner) && $this->spinner->isActive()) {
            $this->spinner->stop();
        }
        echo $this->color($msg . "\n", "\033[32m");
    }

    public function error(string $msg): void
    {
        if (isset($this->spinner) && $this->spinner->isActive()) {
            $this->spinner->stop();
        }
        echo $this->color($msg . "\n", "\033[31m");
    }

    public function info(string $msg): void
    {
        if (isset($this->spinner) && $this->spinner->isActive()) {
            $this->spinner->clear();
            echo $this->color($msg . "\n", "\033[36m");
            $this->spinner->render();
            return;
        }
        echo $this->color($msg . "\n", "\033[36m");
    }

    public function warning(string $msg): void
    {
        if (isset($this->spinner) && $this->spinner->isActive()) {
            $this->spinner->clear();
            echo $this->color($msg . "\n", "\033[33m");
            $this->spinner->render();
            return;
        }
        echo $this->color($msg . "\n", "\033[33m");
    }

    public function verbose(string $msg, string $level = 'info'): void
    {
        if (!$this->verbose) {
            return;
        }

        match ($level) {
            'success' => $this->success($msg),
            'error' => $this->error($msg),
            'warning' => $this->warning($msg),
            default => $this->info($msg),
        };
    }

    public function debug(string $msg): void
    {
        if ($this->verbose) {
            $this->info($msg);
        }
    }

    public function verboseOnly(callable $callback): void
    {
        if ($this->verbose) {
            $callback();
        }
    }

    public function summary(string $title, array $details): void
    {
        if (isset($this->spinner) && $this->spinner->isActive()) {
            $this->spinner->clear();
        }
        echo "\n" . $this->color($title, "\033[1;36m") . "\n";
        foreach ($details as $label => $val) {
            $paddedLabel = str_pad($label . ':', 18, ' ', STR_PAD_RIGHT);
            echo "  " . $this->color($paddedLabel, "\033[1m") . " " . $val . "\n";
        }
        echo "\n";
    }

    public function table(array $headers, array $rows): void
    {
        if (isset($this->spinner) && $this->spinner->isActive()) {
            $this->spinner->stop();
        }
        $colWidths = [];
        foreach ($headers as $i => $h) {
            $colWidths[$i] = mb_strlen($h);
        }
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $col) {
                if (!isset($colWidths[$i])) {
                    $colWidths[$i] = 0;
                }
                $colWidths[$i] = max($colWidths[$i], mb_strlen((string) $col));
            }
        }

        $sep = function ($widths) {
            $line = "+";
            foreach ($widths as $w) {
                $line .= str_repeat("-", $w + 2) . "+";
            }
            return $line;
        };

        echo $sep($colWidths) . "\n";
        echo "|";
        foreach ($headers as $i => $h) {
            echo " " . $this->mb_str_pad($h, $colWidths[$i]) . " |";
        }
        echo "\n";
        echo $sep($colWidths) . "\n";

        foreach ($rows as $row) {
            echo "|";
            foreach (array_values($row) as $i => $col) {
                echo " " . $this->mb_str_pad((string) $col, $colWidths[$i]) . " |";
            }
            echo "\n";
        }
        echo $sep($colWidths) . "\n";
    }

    public function prompt(string $question, string $default = ''): string
    {
        $prompt = $question;
        if ($default !== '') {
            $prompt .= " [$default]";
        }
        $prompt .= ": ";
        echo $this->color($prompt, "\033[33m");
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        fclose($handle);
        $result = trim((string) $line);
        return $result === '' ? $default : $result;
    }

    private function mb_str_pad(string $string, int $length, string $pad_string = " ", int $pad_type = STR_PAD_RIGHT, ?string $encoding = null): string
    {
        if (!$encoding) {
            $encoding = mb_internal_encoding();
        }
        $pad_len = $length - mb_strlen($string, $encoding);
        if ($pad_len <= 0) {
            return $string;
        }
        $pad_str_len = mb_strlen($pad_string, $encoding);
        $pad_count = floor($pad_len / $pad_str_len);
        $remainder = $pad_len % $pad_str_len;
        $padding = str_repeat($pad_string, (int) $pad_count) . mb_substr($pad_string, 0, $remainder, $encoding);
        if ($pad_type === STR_PAD_RIGHT) {
            return $string . $padding;
        } elseif ($pad_type === STR_PAD_LEFT) {
            return $padding . $string;
        } else {
            $left_padding = mb_substr($padding, 0, (int) floor($pad_len / 2), $encoding);
            $right_padding = mb_substr($padding, mb_strlen($left_padding, $encoding), null, $encoding);
            return $left_padding . $string . $right_padding;
        }
    }
}
