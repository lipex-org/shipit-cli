<?php

declare(strict_types=1);

return [
    'root_symlinks' => function (\ShipIt\ShipIt $shipIt) {
        $publicFolder = 'public';
        foreach ($shipIt->getAdapters() as $adapter) {
            if (method_exists($adapter, 'getPublicFolder')) {
                $publicFolder = $adapter->getPublicFolder();
            } elseif (get_class($adapter) === 'ShipIt\Adapters\LaravelAdapter' || get_class($adapter) === 'ShipIt\Adapters\CI4Adapter') {
                $publicFolder = 'public';
            } elseif (get_class($adapter) === 'ShipIt\Adapters\ViteAdapter') {
                $publicFolder = 'dist';
            }
        }

        return [
            ["current/{$publicFolder}", "public_html"],
            ["current/{$publicFolder}", "private_html"]
        ];
    }
];
