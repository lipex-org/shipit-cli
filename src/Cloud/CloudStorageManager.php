<?php

declare(strict_types=1);

namespace ShipIt\Cloud;

use ShipIt\Config\EnvLoader;
use ShipIt\Contracts\CloudStorageInterface;

class CloudStorageManager
{
    private EnvLoader $env;
    private array $customDrivers = [];

    public function __construct(EnvLoader $env)
    {
        $this->env = $env;
    }

    /**
     * Creates and returns a cloud storage driver instance.
     */
    public function createDriver(?string $driverName = null, array $config = []): CloudStorageInterface
    {
        $driver = $driverName ?: $config['driver'] ?? $this->env->get('SHIPIT_CLOUD_DRIVER', 'r2');

        if (isset($this->customDrivers[$driver])) {
            return ($this->customDrivers[$driver])($this->env, $config);
        }

        if ($driver === 'r2') {
            $accountId = $config['account_id'] ?? $this->env->get('SHIPIT_R2_ACCOUNT_ID');
            $accessKeyId = $config['access_key_id'] ?? $this->env->get('SHIPIT_R2_ACCESS_KEY_ID');
            $secretAccessKey = $config['secret_access_key'] ?? $this->env->get('SHIPIT_R2_SECRET_ACCESS_KEY');
            $bucket = $config['bucket'] ?? $this->env->get('SHIPIT_R2_BUCKET');
            $region = $config['region'] ?? $this->env->get('SHIPIT_R2_REGION', 'auto');
            $endpoint = $config['endpoint'] ?? $this->env->get('SHIPIT_R2_ENDPOINT');

            if (!$accountId || !$accessKeyId || !$secretAccessKey || !$bucket) {
                throw new \InvalidArgumentException(
                    "Missing Cloudflare R2 credentials. Please set SHIPIT_R2_ACCOUNT_ID, SHIPIT_R2_ACCESS_KEY_ID, SHIPIT_R2_SECRET_ACCESS_KEY, and SHIPIT_R2_BUCKET in your .shipit.env"
                );
            }

            return new CloudflareR2Client(
                (string) $accountId,
                (string) $accessKeyId,
                (string) $secretAccessKey,
                (string) $bucket,
                (string) $region,
                $endpoint ? (string) $endpoint : null
            );
        }

        throw new \InvalidArgumentException("Unsupported cloud storage driver: '{$driver}'. Only 'r2' is currently supported.");
    }

    /**
     * Registers a custom driver factory for future extensibility.
     */
    public function extend(string $driver, callable $factory): void
    {
        $this->customDrivers[$driver] = $factory;
    }
}
