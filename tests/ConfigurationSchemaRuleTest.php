<?php

declare(strict_types=1);

namespace ShipIt\Tests;

use PHPUnit\Framework\TestCase;
use ShipIt\Validation\Rules\ConfigurationSchemaRule;

class ConfigurationSchemaRuleTest extends TestCase
{
    private ConfigurationSchemaRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ConfigurationSchemaRule();
    }

    public function testGetName(): void
    {
        $this->assertSame('Configuration Schema', $this->rule->getName());
    }

    public function testValidConfigReturnsNull(): void
    {
        $config = [
            'adapter' => 'laravel',
            'server' => 'directadmin',
            'gitRepoUrl' => 'git@github.com:foo/bar.git',
            'branch' => 'main',
            'user' => 'deployer',
            'group' => 'deployer',
            'ownership' => ['public'],
            'symlinks' => [['public', 'public_html']],
            'writable' => ['storage'],
            'backup_path' => '/tmp/backups',
            'backup_retention' => 5,
            'hooks' => ['pre-update' => 'echo 1'],
            'update_ignore' => ['node_modules'],
            'backup_ignore' => ['node_modules'],
            'strategy' => 'symlink',
            'keep_releases' => 3,
            'shared_files' => ['.env'],
            'shared_dirs' => ['storage'],
            'last_shipped_at' => '2026-08-03' // ignored key
        ];

        $this->assertNull($this->rule->validate($config, ''));
    }

    public function testUnknownConfigKeyEmitsWarning(): void
    {
        $config = [
            'some_unknown_key' => 'value'
        ];

        $result = $this->rule->validate($config, '');
        $this->assertNotNull($result);
        $this->assertSame('warning', $result['status']);
        $this->assertStringContainsString('Unrecognized configuration key(s)', $result['message']);
    }

    public function testInvalidTypeEmitsError(): void
    {
        $config = [
            'backup_retention' => 'not-an-integer'
        ];

        $result = $this->rule->validate($config, '');
        $this->assertNotNull($result);
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString("Configuration option 'backup_retention' must be of type integer", $result['message']);
    }

    public function testInvalidStrategyEmitsError(): void
    {
        $config = [
            'strategy' => 'invalid_strategy_name'
        ];

        $result = $this->rule->validate($config, '');
        $this->assertNotNull($result);
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString("Strategy must be either 'copy' or 'symlink'", $result['message']);
    }
}
