<?php

declare(strict_types=1);

namespace ShipIt\Adapters;

use ShipIt\Contracts\AdapterInterface;
use ShipIt\ShipIt;

class LaravelAdapter implements AdapterInterface
{
    public function getTasks(): array
    {
        return [
            'migrate' => function (ShipIt $shipIt) {
                $shipIt->runCommand('Laravel Migrate', 'php artisan migrate --force', true);
            },
            'optimize' => function (ShipIt $shipIt) {
                $shipIt->runCommand('Laravel Optimize', 'php artisan optimize', true);
            }
        ];
    }

    public function getPreHooks(): array
    {
        return [];
    }

    public function getPostHooks(): array
    {
        return [];
    }

    public function getWritablePaths(): array
    {
        return ['storage', 'bootstrap/cache'];
    }

    public function getOwnershipPaths(): array
    {
        return [];
    }

    public function getSymlinks(): array
    {
        return [];
    }

    public function getUpdateIgnore(): array
    {
        return ['storage', '.env'];
    }

    public function getBackupIgnore(): array
    {
        return ['storage/framework/cache', 'storage/framework/sessions', 'storage/logs'];
    }

    public function getRunOrderRules(): array
    {
        return [
            'after' => [
                'update' => ['migrate'],
                'composer' => ['optimize']
            ]
        ];
    }

    public function rollback(ShipIt $shipIt): void
    {
        $shipIt->runCommand('Laravel Migrate Rollback', 'php artisan migrate:rollback --force', true);
    }
}
