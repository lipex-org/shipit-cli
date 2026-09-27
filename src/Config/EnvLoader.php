<?php

declare(strict_types=1);

namespace ShipIt\Config;

class EnvLoader
{
    private array $env = [];

    public function __construct(array $initialEnv = [])
    {
        $this->env = $initialEnv;
    }

    /**
     * Loads .shipit.env files in priority order:
     * 1. Direct environment variables (getenv)
     * 2. Project-level .shipit.env / .deploy/.shipit.env
     * 3. Global ~/.shipit/.env
     */
    public function load(string $projectDir, ?string $globalDir = null): array
    {
        $loaded = [];

        // 1. Global ~/.shipit/.env
        if ($globalDir && file_exists($globalDir . '/.env')) {
            $loaded = array_merge($loaded, $this->parseEnvFile($globalDir . '/.env'));
        }

        // 2. Project level .shipit.env candidates
        $projectCandidates = [
            $projectDir . '/.shipit.env',
            $projectDir . '/.deploy/.shipit.env',
        ];

        foreach ($projectCandidates as $candidate) {
            if (file_exists($candidate) && is_file($candidate)) {
                $loaded = array_merge($loaded, $this->parseEnvFile($candidate));
                break;
            }
        }

        // 3. Process environment variables (SHIPIT_* take highest priority)
        foreach ($_ENV as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'SHIPIT_')) {
                $loaded[$key] = $value;
            }
        }
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'SHIPIT_')) {
                $loaded[$key] = $value;
            }
        }

        $this->env = array_merge($this->env, $loaded);
        return $this->env;
    }

    public function parseEnvFile(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return [];
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $data = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip surrounding quotes
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            // Unescape common characters in double-quoted strings
            $value = str_replace(['\\n', '\\r', '\\t'], ["\n", "\r", "\t"], $value);

            $data[$key] = $value;
        }

        return $data;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (isset($this->env[$key])) {
            return $this->env[$key];
        }

        $val = getenv($key);
        if ($val !== false) {
            return $val;
        }

        return $default;
    }

    public function all(): array
    {
        return $this->env;
    }

    public function set(string $key, string $value): void
    {
        $this->env[$key] = $value;
    }
}
