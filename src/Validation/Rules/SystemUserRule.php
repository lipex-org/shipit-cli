<?php

declare(strict_types=1);

namespace ShipIt\Validation\Rules;

use ShipIt\Contracts\ValidationRuleInterface;

class SystemUserRule implements ValidationRuleInterface
{
    public function getName(): string
    {
        return 'System User/Group';
    }

    public function validate(array $config, string $rootDir): ?array
    {
        $user = $config['user'] ?? null;
        $group = $config['group'] ?? null;

        if ($user) {
            if (!$this->userExists($user)) {
                return [
                    'status' => 'error',
                    'message' => "System user '$user' does not exist.",
                    'suggestion' => "Verify the user exists on this Linux system or remove it from config."
                ];
            }
        }

        if ($group) {
            if (!$this->groupExists($group)) {
                return [
                    'status' => 'error',
                    'message' => "System group '$group' does not exist.",
                    'suggestion' => "Verify the group exists on this Linux system or remove it from config."
                ];
            }
        }

        return null;
    }

    private function userExists(string $user): bool
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') return true;
        if (getenv('CI_ENVIRONMENT') === 'testing' || (defined('ENVIRONMENT') && ENVIRONMENT === 'testing')) return true;
        
        $output = [];
        $status = 0;
        @exec("id -u " . escapeshellarg($user) . " 2>&1", $output, $status);
        return $status === 0;
    }

    private function groupExists(string $group): bool
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') return true;
        if (getenv('CI_ENVIRONMENT') === 'testing' || (defined('ENVIRONMENT') && ENVIRONMENT === 'testing')) return true;

        $output = [];
        $status = 0;
        @exec("getent group " . escapeshellarg($group) . " 2>&1", $output, $status);
        return $status === 0;
    }
}
