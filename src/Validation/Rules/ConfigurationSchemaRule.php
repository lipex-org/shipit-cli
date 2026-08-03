<?php

declare(strict_types=1);

namespace ShipIt\Validation\Rules;

use ShipIt\Contracts\ValidationRuleInterface;

class ConfigurationSchemaRule implements ValidationRuleInterface
{
    public function getName(): string
    {
        return 'Configuration Schema';
    }

    public function validate(array $config, string $rootDir): ?array
    {
        $allowedKeys = [
            'adapter', 'server', 'gitRepoUrl', 'branch', 'user', 'group',
            'ownership', 'symlinks', 'writable', 'backup_path', 'backup_retention',
            'hooks', 'update_ignore', 'backup_ignore', 'strategy', 'keep_releases',
            'shared_files', 'shared_dirs'
        ];

        // Check for unrecognized keys
        $unknownKeys = array_diff(array_keys($config), $allowedKeys);
        // exclude last_shipped_at since it is populated dynamically
        $unknownKeys = array_diff($unknownKeys, ['last_shipped_at']);
        if (!empty($unknownKeys)) {
            return [
                'status' => 'warning',
                'message' => sprintf("Unrecognized configuration key(s): %s", implode(', ', $unknownKeys)),
                'suggestion' => "Verify spelling in config.json. Allowed keys: " . implode(', ', $allowedKeys)
            ];
        }

        // Validate types
        $typeChecks = [
            'adapter' => ['string', 'null'],
            'server' => ['string', 'null'],
            'gitRepoUrl' => ['string', 'null'],
            'branch' => ['string'],
            'user' => ['string'],
            'group' => ['string'],
            'ownership' => ['array'],
            'symlinks' => ['array'],
            'writable' => ['array'],
            'backup_path' => ['string'],
            'backup_retention' => ['integer'],
            'hooks' => ['array'],
            'update_ignore' => ['array'],
            'backup_ignore' => ['array'],
            'strategy' => ['string'],
            'keep_releases' => ['integer'],
            'shared_files' => ['array'],
            'shared_dirs' => ['array'],
        ];

        foreach ($typeChecks as $key => $types) {
            if (isset($config[$key])) {
                $val = $config[$key];
                $matched = false;
                foreach ($types as $type) {
                    if ($type === 'null' && is_null($val)) {
                        $matched = true;
                    } elseif ($type === 'string' && is_string($val)) {
                        $matched = true;
                    } elseif ($type === 'integer' && is_int($val)) {
                        $matched = true;
                    } elseif ($type === 'array' && is_array($val)) {
                        $matched = true;
                    }
                }
                if (!$matched) {
                    return [
                        'status' => 'error',
                        'message' => sprintf("Configuration option '%s' must be of type %s.", $key, implode(' or ', $types)),
                        'suggestion' => "Correct the type of '$key' in config.json"
                    ];
                }
            }
        }

        // Validate strategy value
        if (isset($config['strategy']) && !in_array($config['strategy'], ['copy', 'symlink'], true)) {
            return [
                'status' => 'error',
                'message' => sprintf("Strategy must be either 'copy' or 'symlink'. Current: '%s'", $config['strategy']),
                'suggestion' => "Set strategy to 'copy' or 'symlink' in config.json"
            ];
        }

        return null;
    }
}
