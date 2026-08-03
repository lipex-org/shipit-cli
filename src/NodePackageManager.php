<?php

declare(strict_types=1);

namespace ShipIt;

class NodePackageManager
{
    private string $rootDir;

    public function __construct(string $rootDir)
    {
        $this->rootDir = $rootDir;
    }

    /**
     * Checks if package.json exists in root directory.
     */
    public function hasPackageJson(): bool
    {
        return file_exists($this->rootDir . '/package.json');
    }

    /**
     * Detects the package manager to use.
     * Returns string: 'npm', 'yarn', 'pnpm', 'bun'.
     * Throws an exception if multiple conflicting lockfiles are found.
     */
    public function detect(): string
    {
        $lockFiles = [
            'npm' => 'package-lock.json',
            'yarn' => 'yarn.lock',
            'pnpm' => 'pnpm-lock.yaml',
            'bun' => ['bun.lockb', 'bun.lock']
        ];

        $detected = [];

        foreach ($lockFiles as $pm => $files) {
            $files = (array) $files;
            foreach ($files as $file) {
                if (file_exists($this->rootDir . '/' . $file)) {
                    $detected[] = $pm;
                    break;
                }
            }
        }

        $detected = array_unique($detected);

        if (count($detected) > 1) {
            throw new \RuntimeException(
                sprintf(
                    "Conflicting package manager lockfiles found: %s. Refusing to run to protect the package manager.",
                    implode(', ', $detected)
                )
            );
        }

        if (count($detected) === 1) {
            return $detected[0];
        }

        // Default to npm if package.json exists but no lockfile is present
        return 'npm';
    }

    /**
     * Returns the command to run for installing and building.
     */
    public function getInstallAndBuildCommand(string $pm): string
    {
        switch ($pm) {
            case 'yarn':
                return 'yarn install && yarn run build';
            case 'pnpm':
                return 'pnpm install && pnpm run build';
            case 'bun':
                return 'bun install && bun run build';
            case 'npm':
            default:
                return 'npm install && npm run build';
        }
    }
}
