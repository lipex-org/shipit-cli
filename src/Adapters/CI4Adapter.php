<?php

declare(strict_types=1);

namespace ShipIt\Adapters;

use ShipIt\Contracts\AdapterInterface;
use ShipIt\ShipIt;

class CI4Adapter implements AdapterInterface
{
    public function getTasks(): array
    {
        return [
            'migrate' => function (ShipIt $shipIt) {
                $shipIt->runCommand('CI4 Migration', 'php spark migrate --all', true);
            },
            'optimize' => function (Shipit $shipit) {
                $shipit->runCommand('CI4 optimize', 'php spark optimize');
            }
        ];
    }

    public function getPreHooks(): array
    {
        return [];
    }

    public function getPostHooks(): array
    {
        $writablePaths = $this->getWritablePaths();
        return [
            'update' => [
                function (ShipIt $shipIt) use ($writablePaths) {
                    $rootDir = $shipIt->getRootDir();
                    foreach ($writablePaths as $dir) {
                        $fullPath = $rootDir . '/' . $dir;
                        if (!is_dir($fullPath)) {
                            $shipIt->runCommand("Init CI4 Writable Folder ($dir)", "mkdir -p " . escapeshellarg($fullPath), true);
                        }
                    }
                }
            ]
        ];
    }

    public function getWritablePaths(): array
    {
        return ['writable', 'writable/cache', 'writable/logs', 'writable/session', 'writable/uploads'];
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
        return ['writable'];
    }

    public function getBackupIgnore(): array
    {
        return ['writable/cache', 'writable/session', 'writable/logs'];
    }

    public function getRunOrderRules(): array
    {
        return ['after' => ['update' => ['migrate']]];
    }

    public function rollback(ShipIt $shipIt): void
    {
        $shipIt->runCommand('CI4 Migrate Rollback', 'php spark migrate:rollback', true);
    }

    public function getPublicFolder(): string
    {
        return 'public';
    }
}
