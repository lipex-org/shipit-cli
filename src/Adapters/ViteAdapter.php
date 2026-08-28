<?php

declare(strict_types=1);

namespace ShipIt\Adapters;

use ShipIt\Contracts\AdapterInterface;
use ShipIt\ShipIt;

class ViteAdapter implements AdapterInterface
{
    public function getTasks(): array
    {
        return [];
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
        return [];
    }

    public function getOwnershipPaths(): array
    {
        return [];
    }

    public function getSymlinks(): array
    {
        return [
            ['dist', 'public_html']
        ];
    }

    public function getUpdateIgnore(): array
    {
        return ['node_modules', '.env', 'dist'];
    }

    public function getBackupIgnore(): array
    {
        return ['node_modules', 'dist'];
    }

    public function getRunOrderRules(): array
    {
        return [
            'before' => [
                'symlink' => ['nodejs']
            ]
        ];
    }

    public function getPublicFolder(): string
    {
        return 'dist';
    }
}
