<?php

declare(strict_types=1);

use ShipIt\Contracts\AdapterInterface;
use ShipIt\ShipIt;

/**
 * Custom Adapter for ShipIt.
 * 
 * Implement any of the methods to customize deployment behavior.
 */
class CustomAdapter implements AdapterInterface
{
    public function getTasks(): array { return []; }
    public function getPreHooks(): array { return []; }
    public function getPostHooks(): array { return []; }
    public function getWritablePaths(): array { return []; }
    public function getOwnershipPaths(): array { return []; }
    public function getSymlinks(): array { return []; }
    public function getUpdateIgnore(): array { return []; }
    public function getBackupIgnore(): array { return []; }
    public function getRunOrderRules(): array { return []; }
}
