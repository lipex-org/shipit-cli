<?php

declare(strict_types=1);

namespace ShipIt\Backup;

use ShipIt\Config\EnvLoader;
use ShipIt\TerminalUI;

class DatabaseDumper
{
    private EnvLoader $env;
    private TerminalUI $ui;

    public function __construct(EnvLoader $env, TerminalUI $ui)
    {
        $this->env = $env;
        $this->ui = $ui;
    }

    /**
     * Attempts to detect DB config from .shipit.env overrides or application .env
     */
    public function detectDatabaseConfig(string $projectDir): ?array
    {
        // 1. Direct .shipit.env overrides
        $driver = $this->env->get('SHIPIT_DB_CONNECTION') ?: $this->env->get('SHIPIT_DB_DRIVER');
        $database = $this->env->get('SHIPIT_DB_DATABASE');

        if ($driver || $database) {
            return [
                'driver' => strtolower($driver ?: 'mysql'),
                'host' => $this->env->get('SHIPIT_DB_HOST', '127.0.0.1'),
                'port' => (int) $this->env->get('SHIPIT_DB_PORT', '3306'),
                'database' => (string) $database,
                'username' => $this->env->get('SHIPIT_DB_USERNAME', 'root'),
                'password' => $this->env->get('SHIPIT_DB_PASSWORD', ''),
            ];
        }

        // 2. Read from application .env file if present
        $appEnvCandidates = [
            $projectDir . '/.env',
            $projectDir . '/env',
        ];

        foreach ($appEnvCandidates as $envFile) {
            if (file_exists($envFile) && is_readable($envFile)) {
                $parsed = $this->env->parseEnvFile($envFile);

                // Laravel / standard format
                $dbConnection = $parsed['DB_CONNECTION'] ?? null;
                $dbDatabase = $parsed['DB_DATABASE'] ?? null;

                // CI4 format (database.default.database)
                if (!$dbDatabase) {
                    $dbDatabase = $parsed['database.default.database'] ?? null;
                    $dbConnection = $parsed['database.default.DBDriver'] ?? $dbConnection;
                }

                if ($dbDatabase) {
                    $dbHost = $parsed['DB_HOST'] ?? $parsed['database.default.hostname'] ?? '127.0.0.1';
                    $dbPort = (int) ($parsed['DB_PORT'] ?? $parsed['database.default.port'] ?? 3306);
                    $dbUser = $parsed['DB_USERNAME'] ?? $parsed['database.default.username'] ?? 'root';
                    $dbPass = $parsed['DB_PASSWORD'] ?? $parsed['database.default.password'] ?? '';
                    $driver = $dbConnection ?: 'mysql';

                    // Map SQLite or MySQL names
                    if (str_contains(strtolower($driver), 'sqlite') || str_ends_with($dbDatabase, '.sqlite') || str_ends_with($dbDatabase, '.db')) {
                        $driver = 'sqlite';
                    } elseif (str_contains(strtolower($driver), 'pg') || str_contains(strtolower($driver), 'postgre')) {
                        $driver = 'pgsql';
                    } else {
                        $driver = 'mysql';
                    }

                    return [
                        'driver' => $driver,
                        'host' => $dbHost,
                        'port' => $dbPort,
                        'database' => $dbDatabase,
                        'username' => $dbUser,
                        'password' => $dbPass,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Dumps database to destination SQL/dump file.
     */
    public function dump(array $dbConfig, string $destinationFile): void
    {
        $driver = strtolower($dbConfig['driver'] ?? 'mysql');
        $targetDir = dirname($destinationFile);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        if ($driver === 'sqlite') {
            $dbPath = $dbConfig['database'];
            if (!file_exists($dbPath)) {
                throw new \RuntimeException("SQLite database file not found at: {$dbPath}");
            }
            if (!copy($dbPath, $destinationFile)) {
                throw new \RuntimeException("Failed to backup SQLite database to {$destinationFile}");
            }
            return;
        }

        if ($driver === 'pgsql' || $driver === 'postgres' || $driver === 'postgresql') {
            $this->dumpPostgres($dbConfig, $destinationFile);
            return;
        }

        // Default: MySQL / MariaDB
        $this->dumpMysql($dbConfig, $destinationFile);
    }

    /**
     * Restores database from a SQL/dump file.
     */
    public function restore(array $dbConfig, string $sourceDumpFile): void
    {
        if (!file_exists($sourceDumpFile) || !is_readable($sourceDumpFile)) {
            throw new \RuntimeException("Dump file not found: {$sourceDumpFile}");
        }

        $driver = strtolower($dbConfig['driver'] ?? 'mysql');

        if ($driver === 'sqlite') {
            $dbPath = $dbConfig['database'];
            if (!copy($sourceDumpFile, $dbPath)) {
                throw new \RuntimeException("Failed to restore SQLite database to {$dbPath}");
            }
            return;
        }

        if ($driver === 'pgsql' || $driver === 'postgres' || $driver === 'postgresql') {
            $this->restorePostgres($dbConfig, $sourceDumpFile);
            return;
        }

        $this->restoreMysql($dbConfig, $sourceDumpFile);
    }

    private function dumpMysql(array $config, string $destinationFile): void
    {
        $host = escapeshellarg($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 3306);
        $user = escapeshellarg($config['username'] ?? 'root');
        $pass = $config['password'] ?? '';
        $database = escapeshellarg($config['database']);

        $passArg = $pass !== '' ? '-p' . escapeshellarg($pass) : '';

        // Check if mysqldump exists
        $dumpBinary = $this->findBinary('mysqldump') ?: 'mysqldump';

        $cmd = "{$dumpBinary} --host={$host} --port={$port} --user={$user} {$passArg} --single-transaction --quick --routines --triggers {$database} > " . escapeshellarg($destinationFile);

        $this->executeCommand($cmd, "mysqldump for database '{$config['database']}'");
    }

    private function restoreMysql(array $config, string $sourceFile): void
    {
        $host = escapeshellarg($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 3306);
        $user = escapeshellarg($config['username'] ?? 'root');
        $pass = $config['password'] ?? '';
        $database = escapeshellarg($config['database']);

        $passArg = $pass !== '' ? '-p' . escapeshellarg($pass) : '';
        $mysqlBinary = $this->findBinary('mysql') ?: 'mysql';

        $cmd = "{$mysqlBinary} --host={$host} --port={$port} --user={$user} {$passArg} {$database} < " . escapeshellarg($sourceFile);

        $this->executeCommand($cmd, "mysql restore for database '{$config['database']}'");
    }

    private function dumpPostgres(array $config, string $destinationFile): void
    {
        $host = escapeshellarg($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 5432);
        $user = escapeshellarg($config['username'] ?? 'postgres');
        $pass = $config['password'] ?? '';
        $database = escapeshellarg($config['database']);

        $pgDump = $this->findBinary('pg_dump') ?: 'pg_dump';
        $envPrefix = $pass !== '' ? "PGPASSWORD=" . escapeshellarg($pass) . " " : "";

        $cmd = "{$envPrefix}{$pgDump} -h {$host} -p {$port} -U {$user} -F p -b -v {$database} > " . escapeshellarg($destinationFile);

        $this->executeCommand($cmd, "pg_dump for database '{$config['database']}'");
    }

    private function restorePostgres(array $config, string $sourceFile): void
    {
        $host = escapeshellarg($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 5432);
        $user = escapeshellarg($config['username'] ?? 'postgres');
        $pass = $config['password'] ?? '';
        $database = escapeshellarg($config['database']);

        $psql = $this->findBinary('psql') ?: 'psql';
        $envPrefix = $pass !== '' ? "PGPASSWORD=" . escapeshellarg($pass) . " " : "";

        $cmd = "{$envPrefix}{$psql} -h {$host} -p {$port} -U {$user} -d {$database} < " . escapeshellarg($sourceFile);

        $this->executeCommand($cmd, "psql restore for database '{$config['database']}'");
    }

    private function executeCommand(string $cmd, string $label): void
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException("Failed to spawn process for {$label}");
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $err = trim((string) $stderr) ?: trim((string) $stdout);
            throw new \RuntimeException("{$label} failed with exit code {$exitCode}: {$err}");
        }
    }

    private function findBinary(string $binary): ?string
    {
        $output = [];
        $exitCode = 0;
        @exec("which " . escapeshellarg($binary) . " 2>/dev/null", $output, $exitCode);
        if ($exitCode === 0 && !empty($output[0])) {
            return trim($output[0]);
        }
        return null;
    }
}
