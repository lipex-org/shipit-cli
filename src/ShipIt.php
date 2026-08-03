<?php

declare(strict_types=1);

namespace ShipIt;

use ShipIt\Contracts\AdapterInterface;
use ShipIt\Adapters\CI4Adapter;
use ShipIt\Adapters\LaravelAdapter;
use ShipIt\Adapters\ViteAdapter;
use ShipIt\Validation\Validator;
use ShipIt\Validation\Rules\RequiredConfigRule;
use ShipIt\Validation\Rules\GitUrlRule;
use ShipIt\Validation\Rules\BackupRetentionRule;
use ShipIt\Validation\Rules\BackupPathRule;
use ShipIt\Validation\Rules\HookCommandRule;
use ShipIt\Validation\Rules\SymlinkRule;
use ShipIt\Validation\Rules\SystemUserRule;
use ShipIt\Validation\Rules\AdapterExistsRule;
use ShipIt\Validation\Rules\GlobalRegistryRule;

class ShipIt
{
    public const VERSION = '0.0.3-alpha';

    private TerminalUI $ui;
    private TaskRunner $runner;
    private Filesystem $fs;
    public Validator $validator;

    private string $rootDir;
    private string $deployDir;
    private string $configFile;
    private string $globalConfigFile;

    private array $config = [];
    private bool $dryRun = false;
    private bool $log = false;
    private array $ignoreList = [];
    private array $onlyList = [];
    private bool $ignoreAll = false;
    private bool $updateSelf = false;
    private ?string $logId = null;
    private ?string $currentCmd = null;
    private ?string $user = null;

    private array $updateIgnoreList = [
        '.env',
        '.deploy',
        'logs',
        'public_html',
        'private_html',
        'public_ftp',
        'vendor',
        'node_modules',
        'stats',
        '.git',
        '.deployignore'
    ];
    private array $backupIgnoreList = [
        'vendor',
        'node_modules',
        '.git',
        '.vscode',
        '.DS_Store',
        '__temp_update_clone'
    ];
    private array $adapterRunOrderRules = [];

    public function __construct(string $rootDir = '')
    {
        $this->ui = new TerminalUI();
        $this->runner = new TaskRunner($this->ui);
        $this->validator = new Validator($this->ui);

        $this->rootDir = $rootDir ?: getcwd() ?: __DIR__;
        $this->initPaths();

        $home = $this->getHomeDir();
        $this->globalConfigFile = $home ? $home . DIRECTORY_SEPARATOR . '.shipit' . DIRECTORY_SEPARATOR . 'config.json' : '';

        $this->setupValidator();
        $this->setupTasks();
    }

    private function initPaths(): void
    {
        $this->deployDir = $this->rootDir . '/.deploy';
        $this->configFile = $this->deployDir . '/config.json';
    }

    private function setupValidator(): void
    {
        $this->validator->addRule(new RequiredConfigRule());
        $this->validator->addRule(new GitUrlRule());
        $this->validator->addRule(new BackupRetentionRule());
        $this->validator->addRule(new BackupPathRule());
        $this->validator->addRule(new HookCommandRule());
        $this->validator->addRule(new SymlinkRule());
        $this->validator->addRule(new SystemUserRule());
        $this->validator->addRule(new AdapterExistsRule());
        $this->validator->addRule(new GlobalRegistryRule($this->globalConfigFile));
    }

    /**
     * Sets the root directory for running deployment
     * @param string $dir
     * @param bool $allowMissing Allow directory to be missing (useful for init)
     * @throws \InvalidArgumentException
     * @return void
     */
    public function setRoot(string $dir, bool $allowMissing = false): void
    {
        if (!$allowMissing && !is_dir($dir)) {
            throw new \InvalidArgumentException("Invalid root directory: $dir");
        }

        $this->rootDir = $dir;
        $this->initPaths();
    }

    public function run(array $argv): void
    {
        $this->parseArgs($argv);
        $this->fs = new Filesystem($this->ui, $this->dryRun);

        $cmd = 'deploy';
        foreach (array_slice($argv, 1) as $arg) {
            if (!str_starts_with($arg, '--')) {
                $cmd = $arg;
                break;
            }
        }
        $this->currentCmd = $cmd;

        if ($cmd !== 'config' && $cmd !== 'version' && !in_array('--version', $argv, true) && !in_array('-v', $argv, true)) {
            $this->printLogo();
        }

        if ($cmd === 'init') {
            $this->doInit($argv);
            return;
        }

        if ($cmd === 'doctor') {
            if (file_exists($this->configFile)) {
                $this->loadConfig();
            }
            $this->doDoctor();
            return;
        }

        if ($cmd === 'version') {
            $this->showVersion();
            return;
        }

        if ($cmd === 'config' || $cmd === 'help' || in_array('--help', $argv, true)) {
            $this->loadConfig($cmd === 'config' && in_array('--global', $argv, true));
            if ($cmd === 'config') {
                $this->doConfig($argv);
                return;
            }
            $this->showHelp();
            return;
        }

        if (!is_dir($this->deployDir) && !$this->dryRun) {
            mkdir($this->deployDir, 0777, true);
        }

        $this->loadConfig();

        $this->logExecution($cmd);

        $this->applyConfigHooks();
        $this->applyAdapter();
        $this->applyServerProfile();

        if ($cmd === 'validate') {
            $results = $this->validator->validate($this->config, $this->rootDir);
            $this->validator->displayResults($results);
            return;
        }

        if ($cmd === 'registry:prune') {
            $this->pruneGlobalRegistry();
            return;
        }

        if ($cmd === 'rollback') {
            $this->doRollback($argv);
            return;
        }
        if ($cmd === 'backups') {
            $this->doBackups();
            return;
        }
        if ($cmd === 'list') {
            $this->listTasks();
            return;
        }
        if ($cmd === 'status') {
            $this->doStatus();
            return;
        }

        // Default command is 'deploy'
        $results = $this->validator->validate($this->config, $this->rootDir);
        $isValid = $this->validator->displayResults($results);

        if (!$isValid) {
            $this->ui->error("\nAborting deployment due to configuration errors.");
            exit(1);
        }

        $this->doDeploy();
    }

    private function doDeploy(): void
    {
        $runOrder = ['backup', 'update', 'composer', 'nodejs', 'symlink', 'perms'];
        if (!empty($this->adapterRunOrderRules)) {
            $runOrder = $this->runner->mergeRunOrder($runOrder, $this->adapterRunOrderRules);
        }

        if ($this->dryRun) {
            $this->ui->info("DRY RUN MODE ENABLED. No files will be modified.");
        }

        $this->runner->run($runOrder, $this->ignoreList, $this->onlyList, $this->ignoreAll, $this);

        if (!$this->dryRun) {
            $this->config['last_shipped_at'] = date('Y-m-d H:i:s');
            file_put_contents($this->configFile, json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->updateGlobalRegistry('success');
        }

        $this->ui->success("\n✅ Deployment completed successfully.");
    }

    public function runCommand(string $label, string $cmd, bool $ignoreError = false): void
    {
        if ($this->dryRun) {
            $this->ui->info("[Dry Run] Would run: $label ($cmd)");
            return;
        }
        $escaped = escapeshellarg($this->rootDir);
        $fullCmd = "cd $escaped && $cmd 2>&1";
        $this->ui->info("⚙️  Running $label...");
        $output = shell_exec($fullCmd);
        if ($output === null && !$ignoreError) {
            $this->ui->error("$label failed");
        } else {
            $this->ui->success("$label done");
        }
    }

    private function parseArgs(array $argv): void
    {
        $this->dryRun = in_array('--dry-run', $argv, true);
        $this->log = in_array('--log', $argv, true);
        $this->updateSelf = in_array('--self', $argv, true);

        // Parse --only and --ignore
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--only=')) {
                $this->onlyList = explode(',', substr($arg, 7));
            } elseif (str_starts_with($arg, '--ignore=')) {
                $this->ignoreList = explode(',', substr($arg, 9));
            } elseif ($arg === '--ignore-all') {
                $this->ignoreAll = true;
            } elseif ($arg === '--help') {
                $this->showHelp();
                exit(0);
            } elseif ($arg === '--version' || $arg === '-v') {
                $this->showVersion();
                exit(0);
            } elseif (str_starts_with($arg, '--log-id=')) {
                $this->logId = substr($arg, 9);
            } elseif (str_starts_with($arg, '--user=')) {
                $this->user = substr($arg, 7);
            }
        }
    }

    public function loadConfig(bool $globalOnly = false): void
    {
        $defaultConfig = [
            'adapter' => null,
            'server' => null,
            'gitRepoUrl' => null,
            'branch' => 'main',
            'user' => 'admin',
            'group' => 'admin',
            'ownership' => ['public', 'public_html', 'private_html'],
            'symlinks' => [
                ['public', 'public_html'],
                ['public_html', 'private_html']
            ],
            'writable' => ['storage', 'bootstrap/cache', 'writable'],
            'backup_path' => (dirname($this->rootDir, 2) === '/' || !is_writable(dirname($this->rootDir, 2) ?: '/'))
                ? dirname($this->rootDir) . '/domain_backups/' . basename($this->rootDir)
                : dirname($this->rootDir, 2) . '/domain_backups/' . basename($this->rootDir),
            'backup_retention' => 5,
            'hooks' => [
                'pre-update' => 'echo "Entering maintenance mode..."',
                'post-update' => 'echo "Leaving maintenance mode..."',
            ],
        ];

        // 1. Load global config
        $globalConfig = [];
        if (!empty($this->globalConfigFile) && file_exists($this->globalConfigFile)) {
            $globalData = json_decode(file_get_contents($this->globalConfigFile), true) ?: [];
            $globalConfig = $globalData['defaults'] ?? [];
        }

        if ($globalOnly) {
            $this->config = array_merge($defaultConfig, $globalConfig);
            return;
        }

        // 2. Load project config
        $projectConfig = [];
        if (file_exists($this->configFile)) {
            $projectConfig = json_decode(file_get_contents($this->configFile), true) ?: [];
        }

        // 3. Merge: Default < Global Defaults < Project Config
        $this->config = array_merge($defaultConfig, $globalConfig, $projectConfig);
    }

    private function doInit(array $argv): void
    {
        $force = in_array('--force', $argv, true);
        $gitUrl = null;
        $branch = 'main';
        $user = $this->user ?: 'admin';

        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--git-url=')) {
                $gitUrl = substr($arg, 10);
            } elseif (str_starts_with($arg, '--branch=')) {
                $branch = substr($arg, 9);
            }
        }

        // Create root directory if it doesn't exist
        if (!is_dir($this->rootDir)) {
            mkdir($this->rootDir, 0777, true);
        }

        if (!is_dir($this->deployDir)) {
            mkdir($this->deployDir, 0777, true);
        }

        $this->initConfigFile($force, $gitUrl, $branch, $user);
        $this->initReadmeFile($force);
        $this->initAdapterFile($force);
        $this->initDeployIgnoreFile($force);

        // Attempt to create the backup directory if it doesn't exist
        $this->loadConfig();
        $backupRoot = $this->config['backup_path'] ?? null;
        if ($backupRoot && !is_dir($backupRoot) && !$this->dryRun) {
            $this->ui->info("Attempting to create backup directory: $backupRoot");
            if (!@mkdir($backupRoot, 0777, true) && !is_dir($backupRoot)) {
                $this->ui->warning("⚠️  Could not create backup directory at $backupRoot. You may need to create it manually or check permissions.");
            } else {
                $this->ui->success("Created backup directory: $backupRoot");
            }
        }

        $this->ui->success("\n✅ ShipIt initialized successfully in " . $this->deployDir);
        $this->updateGlobalRegistry();
    }

    private function writeFile(string $path, string $content, bool $force = false): void
    {
        $filename = basename($path);
        if (file_exists($path) && !$force) {
            $overwrite = $this->ui->prompt("File '$filename' already exists. Overwrite? (y/n)", "n");
            if (strtolower($overwrite) !== 'y' && strtolower($overwrite) !== 'yes') {
                $this->ui->info("Skipped creating '$filename'.");
                return;
            }
        }

        if ($this->dryRun) {
            $this->ui->info("[Dry Run] Would write to $path:\n$content\n");
            return;
        }

        if (file_put_contents($path, $content) !== false) {
            $this->ui->success("Created: $path");
        } else {
            $this->ui->error("Failed to write to $path");
        }
    }

    private function initConfigFile(bool $force, ?string $gitUrl = null, string $branch = 'main', string $user = 'admin'): void
    {
        $this->ui->info("Creating standard config.json...");
        $defaultConfig = [
            'adapter' => 'laravel',
            'server' => 'directadmin',
            'gitRepoUrl' => $gitUrl ?: 'git@github.com:username/repository.git',
            'branch' => $branch,
            'user' => $user,
            'group' => $user === 'admin' ? 'admin' : $user,
            'ownership' => ['public', 'public_html', 'private_html'],
            'symlinks' => [
                ['public', 'public_html'],
                ['public_html', 'private_html']
            ],
            'writable' => ['storage', 'bootstrap/cache', 'writable'],
            'backup_path' => (dirname($this->rootDir, 2) === '/' || !is_writable(dirname($this->rootDir, 2) ?: '/'))
                ? dirname($this->rootDir) . '/domain_backups/' . basename($this->rootDir)
                : dirname($this->rootDir, 2) . '/domain_backups/' . basename($this->rootDir),
            'backup_retention' => 5,
            'hooks' => [
                'pre-update' => 'echo "Entering maintenance mode..."',
                'post-update' => 'echo "Leaving maintenance mode..."',
            ],
        ];

        $content = json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $this->writeFile($this->configFile, $content, $force);
    }

    private function initReadmeFile(bool $force): void
    {
        $content = <<<'MARKDOWN'
# ShipIt Deployment Configuration Directory

This directory contains the deployment configuration files and custom extensions for ShipIt.

## Files

### 1. config.json
The main configuration file. It overrides the global configuration.
Key settings:
- `gitRepoUrl`: The SSH URL of the git repository to deploy from.
- `branch`: The git branch to clone and deploy (default: "main").
- `adapter`: Optional framework adapter (e.g. "ci4", "laravel", "vite", "react", or "custom").
- `server`: Optional server profile (e.g. "directadmin", "cpanel", or "custom").
- `user` / `group`: The webserver user and group ownership to apply.
- `backup_path`: Destination directory where backups will be stored before deployment.
- `ownership`: Array of directories to apply user/group ownership to.
- `writable`: Array of directories to make writable (chmod 775).
- `symlinks`: A list of source-to-target pairs for symlinking (e.g., [["public", "public_html"]]).
- `hooks`: Script commands to run before or after tasks (e.g., "pre-update", "post-composer").

### 2. custom.adapter.php (Optional)
A custom adapter class. To use it, set "adapter": "custom" in config.json.
You can implement tasks, hooks, writable paths, symlinks, and run order specific to your framework.

### 3. custom.server.php (Optional)
A custom server profile returning an array. To use it, set "server": "custom" in config.json.
You can override directories, add hooks, or run specific tasks suitable for the server environment.
MARKDOWN;

        $this->writeFile($this->deployDir . '/README.md', $content . PHP_EOL, $force);
    }

    private function initAdapterFile(bool $force): void
    {
        $content = <<<'PHP'
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
PHP;

        $this->writeFile($this->deployDir . '/custom.adapter.php', $content . PHP_EOL, $force);
    }

    private function initDeployIgnoreFile(bool $force): void
    {
        $content = implode(PHP_EOL, $this->updateIgnoreList) . PHP_EOL;
        $this->writeFile($this->rootDir . '/.deployignore', $content, $force);
    }

    private function setupTasks(): void
    {
        $this->runner->addTask('backup', fn() => $this->doBackup());
        $this->runner->addTask('update', fn() => $this->doUpdate());
        $this->runner->addTask('composer', fn() => $this->runCommand('Composer Install', 'composer install --no-dev --optimize-autoloader', true));
        $this->runner->addTask('nodejs', fn() => $this->runNodePackageManager());
        $this->runner->addTask('perms', fn() => $this->fixPermissions());
        $this->runner->addTask('symlink', fn() => $this->createSymlinks());

        $this->runner->addPreHook('update', fn() => $this->ui->info("🔒 Entering maintenance mode..."));
        $this->runner->addPostHook('update', fn() => $this->ui->info("🔓 Leaving maintenance mode..."));
        $this->runner->addPostHook('composer', fn() => $this->ui->success("🚀 Composer done, autoloader optimized."));
    }

    private function applyConfigHooks(): void
    {
        $hooks = $this->config['hooks'] ?? [];
        foreach ($hooks as $key => $command) {
            if (str_starts_with($key, 'pre-')) {
                $task = substr($key, 4);
                $this->runner->addPreHook($task, fn() => $this->runCommand("Pre-hook for $task", $command, true));
            } elseif (str_starts_with($key, 'post-')) {
                $task = substr($key, 5);
                $this->runner->addPostHook($task, fn() => $this->runCommand("Post-hook for $task", $command, true));
            }
        }
    }

    private function applyAdapter(): void
    {
        $adaptersToLoad = [];
        $adapterName = null;

        // 1. Load primary framework adapter if configured
        if (!empty($this->config['adapter'])) {
            $adapterName = strtolower($this->config['adapter']);
            $adapterClass = null;

            if ($adapterName === 'ci4') {
                $adapterClass = new CI4Adapter();
            } elseif ($adapterName === 'laravel') {
                $adapterClass = new LaravelAdapter();
            } elseif ($adapterName === 'vite' || $adapterName === 'react') {
                $adapterClass = new ViteAdapter();
            } else {
                $adapterFile = $this->deployDir . '/' . $adapterName . '.adapter.php';
                if (file_exists($adapterFile)) {
                    require_once $adapterFile;
                    $className = ucfirst($adapterName) . 'Adapter';
                    if (class_exists($className)) {
                        $adapterClass = new $className();
                    }
                }
            }

            if ($adapterClass) {
                $adaptersToLoad[] = $adapterClass;
            }
        }

        // 2. Load local custom.adapter.php if present (and wasn't already loaded as primary)
        if ($adapterName !== 'custom') {
            $customAdapterFile = $this->deployDir . '/custom.adapter.php';
            if (file_exists($customAdapterFile)) {
                require_once $customAdapterFile;
                if (class_exists('CustomAdapter')) {
                    $adaptersToLoad[] = new \CustomAdapter();
                }
            }
        }

        // 3. Process tasks, hooks, permissions, ignore lists, and order rules from all loaded adapters
        foreach ($adaptersToLoad as $adapterClass) {
            if ($adapterClass instanceof AdapterInterface) {
                foreach ($adapterClass->getTasks() as $name => $task) {
                    $this->runner->addTask($name, $task);
                }
                foreach ($adapterClass->getPreHooks() as $task => $hooks) {
                    foreach ($hooks as $hook) {
                        $this->runner->addPreHook($task, $hook);
                    }
                }
                foreach ($adapterClass->getPostHooks() as $task => $hooks) {
                    foreach ($hooks as $hook) {
                        $this->runner->addPostHook($task, $hook);
                    }
                }

                $this->config['writable'] = array_merge($this->config['writable'], $adapterClass->getWritablePaths());
                $this->config['ownership'] = array_merge($this->config['ownership'], $adapterClass->getOwnershipPaths());
                $this->config['symlinks'] = array_merge($this->config['symlinks'], $adapterClass->getSymlinks());

                $this->updateIgnoreList = array_unique(array_merge($this->updateIgnoreList, $adapterClass->getUpdateIgnore()));
                $this->backupIgnoreList = array_unique(array_merge($this->backupIgnoreList, $adapterClass->getBackupIgnore()));

                if (method_exists($adapterClass, 'getRunOrderRules')) {
                    $this->adapterRunOrderRules = $this->mergeAdapterRules($this->adapterRunOrderRules, $adapterClass->getRunOrderRules());
                }
            }
        }
    }

    private function runNodePackageManager()
    {
        $nodePM = new NodePackageManager($this->rootDir);
        if (!$nodePM->hasPackageJson()) {
            $this->ui->info("Skipping node package installation & build (no package.json found).");
            return;
        }

        try {
            $pm = $nodePM->detect();
        } catch (\RuntimeException $e) {
            $this->ui->error($e->getMessage());
            throw $e;
        }

        $binaryPath = $this->findBinary($pm);
        if ($binaryPath === null) {
            $errorMessage = "Required package manager '$pm' is not installed or not in the PATH. Hook \"nodejs\" task will fail.";
            $this->ui->error($errorMessage);
            throw new \RuntimeException($errorMessage);
        }

        $label = strtoupper($pm) . ' Install & Build';
        $cmd = $nodePM->getInstallAndBuildCommand($pm);
        $this->runCommand($label, $cmd);
    }


    private function mergeAdapterRules(array $rules1, array $rules2): array
    {
        $merged = $rules1;
        foreach ($rules2 as $type => $targets) {
            if (!isset($merged[$type])) {
                $merged[$type] = $targets;
                continue;
            }
            if ($type === 'prepend' || $type === 'append') {
                $merged[$type] = array_merge($merged[$type], $targets);
            } else { // 'before' or 'after'
                foreach ($targets as $target => $inserts) {
                    if (isset($merged[$type][$target])) {
                        $merged[$type][$target] = array_values(array_unique(array_merge($merged[$type][$target], $inserts)));
                    } else {
                        $merged[$type][$target] = $inserts;
                    }
                }
            }
        }
        return $merged;
    }

    private function applyServerProfile(): void
    {
        if (empty($this->config['server'])) {
            return;
        }

        $serverName = strtolower($this->config['server']);
        $profileFile = $this->deployDir . '/' . $serverName . '.server.php';

        if (file_exists($profileFile)) {
            $profile = include $profileFile;

            if (is_array($profile)) {
                // Handle arbitrary hooks/tasks/configs from a simple array return
                if (isset($profile['tasks'])) {
                    foreach ($profile['tasks'] as $name => $task) {
                        $this->runner->addTask($name, $task);
                    }
                }

                foreach (['preHooks', 'postHooks'] as $type) {
                    if (isset($profile[$type])) {
                        foreach ($profile[$type] as $task => $hooks) {
                            foreach ((array) $hooks as $hook) {
                                $method = $type === 'preHooks' ? 'addPreHook' : 'addPostHook';
                                $this->runner->{$method}($task, $hook);
                            }
                        }
                    }
                }

                foreach (['writable', 'ownership', 'symlinks'] as $key) {
                    if (isset($profile[$key])) {
                        $this->config[$key] = array_merge($this->config[$key] ?? [], $profile[$key]);
                    }
                }

                if (isset($profile['ignoreLists']['update'])) {
                    $this->updateIgnoreList = array_unique(array_merge($this->updateIgnoreList, $profile['ignoreLists']['update']));
                }
                if (isset($profile['ignoreLists']['backup'])) {
                    $this->backupIgnoreList = array_unique(array_merge($this->backupIgnoreList, $profile['ignoreLists']['backup']));
                }
            }
        }
    }

    private function doBackup(): void
    {
        if ($this->isFirstRun()) {
            $this->ui->info("⏩ Skipping backup: project directory appears to be empty or contains only deployment files.");
            return;
        }

        $backupRoot = $this->config['backup_path'];
        $timestamp = date('Ymd_His');
        $backupFolder = "$backupRoot/backup_$timestamp";

        if (!$this->dryRun && !is_dir($backupRoot)) {
            $this->ui->info("Creating backup directory: $backupRoot");
            if (!@mkdir($backupRoot, 0777, true) && !is_dir($backupRoot)) {
                $this->ui->error("❌ Failed to create backup directory: $backupRoot. Please check permissions.");
                return;
            }
        }

        if (!$this->dryRun && !is_dir($backupFolder)) {
            if (!@mkdir($backupFolder, 0777, true) && !is_dir($backupFolder)) {
                $this->ui->error("❌ Failed to create specific backup folder: $backupFolder. Please check permissions.");
                return;
            }
        }

        $this->ui->info("📁 Backup started to $backupRoot ...");
        $ignoreList = $this->backupIgnoreList;
        if (isset($this->config['backup_env']) && $this->config['backup_env'] === false) {
            $ignoreList = array_unique(array_merge($ignoreList, ['.env']));
        }

        $realRoot = realpath($this->rootDir);
        $realBackupRoot = realpath($backupRoot);
        if ($realRoot && $realBackupRoot && str_starts_with($realBackupRoot, $realRoot)) {
            $relBackupRoot = ltrim(substr($realBackupRoot, strlen($realRoot)), '/\\');
            if ($relBackupRoot !== '') {
                $ignoreList[] = $relBackupRoot;
            }
        }

        $this->fs->copyFolder($this->rootDir, $backupFolder, $ignoreList, '', $this->log);
        $this->ui->success("Backup saved to $backupFolder");

        $this->rotateBackups();
    }

    private function doUpdate(): void
    {
        $gitRepoUrl = $this->config['gitRepoUrl'] ?? null;
        $branch = $this->config['branch'] ?? 'main';
        $cloneFolder = $this->rootDir . "/__temp_update_clone";

        if (!$gitRepoUrl) {
            $this->ui->error("No gitRepoUrl set in config.json or via arguments.");
            exit(1);
        }

        if (is_dir($cloneFolder) && !$this->dryRun) {
            $this->fs->removeFolder($cloneFolder);
        }

        $this->ui->info("📥 Cloning $gitRepoUrl (branch: $branch)");
        if (!$this->dryRun) {
            exec("git clone -b " . escapeshellarg($branch) . " " . escapeshellarg($gitRepoUrl) . " " . escapeshellarg($cloneFolder), $out, $status);
            if ($status !== 0) {
                $this->ui->error("Git clone failed.");
                exit(1);
            }
        }

        $this->ui->info("🔄 Updating project...");
        $isFirstRun = $this->isFirstRun();
        if ($isFirstRun) {
            $this->ui->info("✨ First run detected: copying all files from repository.");
        }

        $this->fs->copyFolder($cloneFolder, $this->rootDir, $this->updateIgnoreList, '', $this->log, $isFirstRun);
        if (!$this->dryRun) {
            $this->fs->removeFolder($cloneFolder);
        }
        $this->ui->success("Update completed");
    }

    private function doRollback(array $argv = []): void
    {
        $backupRoot = $this->config['backup_path'];
        if (!is_dir($backupRoot)) {
            $this->ui->error("No backup directory found at $backupRoot.");
            return;
        }

        // Check if a specific backup target is specified
        $target = null;
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg !== 'rollback' && !str_starts_with($arg, '--')) {
                $target = $arg;
                break;
            }
        }

        if (!$target) {
            // Find most recent backup
            $backups = glob("$backupRoot/backup_*");
            if (empty($backups)) {
                $this->ui->error("No backups found in $backupRoot.");
                return;
            }
            rsort($backups);
            $target = $backups[0];
        } else {
            // If target is just a timestamp, prepend prefix
            if (!str_starts_with($target, 'backup_') && !str_contains($target, '/')) {
                $target = "backup_$target";
            }
            // If it's a relative path, make it absolute against backupRoot
            if (!str_contains($target, '/')) {
                $target = "$backupRoot/$target";
            }
        }

        if (!is_dir($target)) {
            $this->ui->error("Rollback target not found: $target");
            return;
        }

        $this->ui->info("⏪ Rolling back to $target ...");
        $this->fs->copyFolder($target, $this->rootDir, [], '', $this->log, true);
        $this->ui->success("Rollback completed successfully.");
        $this->updateGlobalRegistry('success');
    }

    private function doBackups(): void
    {
        $backupRoot = $this->config['backup_path'];
        if (!is_dir($backupRoot)) {
            $this->ui->info("Backup directory does not exist yet.");
            return;
        }

        $backups = glob("$backupRoot/backup_*");
        if (empty($backups)) {
            $this->ui->info("No backups found.");
            return;
        }

        rsort($backups);
        $this->ui->info("Available Backups (most recent first):");
        foreach ($backups as $b) {
            $ts = substr(basename($b), 7);
            echo "  - $ts (" . basename($b) . ")\n";
        }
    }

    private function rotateBackups(): void
    {
        $backupRoot = $this->config['backup_path'];
        $retention = (int) ($this->config['backup_retention'] ?? 5);

        if ($retention <= 0)
            return;

        $backups = glob("$backupRoot/backup_*");
        if (count($backups) <= $retention)
            return;

        sort($backups); // Oldest first
        $toDeleteCount = count($backups) - $retention;

        for ($i = 0; $i < $toDeleteCount; $i++) {
            $this->ui->info("🗑️ Rotating old backup: " . basename($backups[$i]));
            $this->fs->removeFolder($backups[$i]);
        }
    }

    private function fixPermissions(): void
    {
        $user = $this->config['user'] ?? null;
        $group = $this->config['group'] ?? null;

        // 1. Chown
        if ($user || $group) {
            $ownership = (array) ($this->config['ownership'] ?? []);
            foreach ($ownership as $path) {
                $fullPath = $this->rootDir . '/' . $path;
                if (file_exists($fullPath)) {
                    $cmd = "chown -R ";
                    if ($user)
                        $cmd .= escapeshellarg($user);
                    if ($group)
                        $cmd .= ":" . escapeshellarg($group);
                    $cmd .= " " . escapeshellarg($fullPath);
                    $this->runCommand("Apply Ownership ($path)", $cmd, true);
                }
            }
        }

        // 2. Chmod writable
        $writable = (array) ($this->config['writable'] ?? []);
        foreach ($writable as $path) {
            $fullPath = $this->rootDir . '/' . $path;
            if (file_exists($fullPath)) {
                $this->runCommand("Apply Writable Perms ($path)", "chmod -R 775 " . escapeshellarg($fullPath), true);
            }
        }
    }

    private function createSymlinks(): void
    {
        $symlinks = (array) ($this->config['symlinks'] ?? []);
        foreach ($symlinks as $pair) {
            if (!is_array($pair) || count($pair) !== 2)
                continue;
            [$src, $dest] = $pair;

            $fullSrc = $this->rootDir . '/' . $src;
            $fullDest = $this->rootDir . '/' . $dest;

            if (!file_exists($fullSrc)) {
                $this->ui->warning("Symlink source not found: $src (Skipping)");
                continue;
            }

            if (file_exists($fullDest) || is_link($fullDest)) {
                $this->runCommand("Remove existing target ($dest)", "rm -rf " . escapeshellarg($fullDest), true);
            }

            $this->runCommand("Create Symlink ($src -> $dest)", "ln -s " . escapeshellarg($fullSrc) . " " . escapeshellarg($fullDest), true);
        }
    }

    private function doStatus(): void
    {
        $this->ui->info("ShipIt Project Status");
        $this->ui->info("Root: " . $this->rootDir);

        $this->ui->table(
            ["Config Key", "Value"],
            [
                ["Repository", $this->config['gitRepoUrl']],
                ["Branch", $this->config['branch']],
                ["Adapter", $this->config['adapter'] ?? 'none'],
                ["Last Shipped", $this->config['last_shipped_at'] ?? 'Never'],
            ]
        );

        $backupRoot = $this->config['backup_path'];
        $backups = is_dir($backupRoot) ? glob("$backupRoot/backup_*") : [];
        $this->ui->info("\nBackups: " . count($backups) . " stored in $backupRoot");

        $registeredTasks = array_keys($this->runner->getTasks());
        $preHooks = $this->runner->getPreHooks();
        $postHooks = $this->runner->getPostHooks();

        $this->ui->info("\nDeployment Tasks in Run Order:");
        $runOrder = ['backup', 'update', 'composer', 'nodejs', 'symlink', 'perms'];
        if (!empty($this->adapterRunOrderRules)) {
            $runOrder = $this->runner->mergeRunOrder($runOrder, $this->adapterRunOrderRules);
        }

        foreach ($runOrder as $index => $taskName) {
            $details = [];
            if (isset($preHooks[$taskName]) && count($preHooks[$taskName]) > 0) {
                $details[] = count($preHooks[$taskName]) . " pre-hook(s)";
            }
            if (isset($postHooks[$taskName]) && count($postHooks[$taskName]) > 0) {
                $details[] = count($postHooks[$taskName]) . " post-hook(s)";
            }

            $suffix = !empty($details) ? " (" . implode(', ', $details) . ")" : "";
            echo "  " . ($index + 1) . ". " . $taskName . $suffix . "\n";
        }

        $diff = array_diff($registeredTasks, $runOrder);
        if (!empty($diff)) {
            $this->ui->info("\nOther Registered Tasks (Not in run order):");
            foreach ($diff as $taskName) {
                echo "  - $taskName\n";
            }
        }
    }

    private function doConfig(array $argv): void
    {
        $isGlobal = in_array('--global', $argv, true);
        $file = $isGlobal ? $this->globalConfigFile : $this->configFile;

        if ($isGlobal && empty($file)) {
            $this->ui->error("Could not determine global config path.");
            return;
        }

        // Extract key/value from arguments (skip flags and command)
        $cleanArgs = [];
        $skipNext = false;
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === 'config' || $arg === '--global')
                continue;
            if (str_starts_with($arg, '--'))
                continue;
            $cleanArgs[] = $arg;
        }

        if (empty($cleanArgs)) {
            $this->ui->info("Config file: " . ($file ?: 'none'));
            if ($file && file_exists($file)) {
                echo file_get_contents($file) . "\n";
            } else {
                $this->ui->info("Config file does not exist.");
            }
            return;
        }

        $key = $cleanArgs[0];
        $value = $cleanArgs[1] ?? null;

        $config = [];
        if ($file && file_exists($file)) {
            $config = json_decode(file_get_contents($file), true) ?: [];
        }

        if ($value === null) {
            if (isset($config[$key])) {
                $out = is_scalar($config[$key]) ? (string) $config[$key] : json_encode($config[$key]);
                echo $out . "\n";
            } else {
                $this->ui->error("Key '$key' not found in " . ($isGlobal ? "global" : "project") . " config.");
            }
            return;
        }

        // Type detection
        if ($value === 'true')
            $value = true;
        elseif ($value === 'false')
            $value = false;
        elseif (is_numeric($value))
            $value = strpos($value, '.') !== false ? (float) $value : (int) $value;
        elseif (str_starts_with($value, '[') || str_starts_with($value, '{')) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE)
                $value = $decoded;
        }

        $config[$key] = $value;

        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if (file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            $this->ui->error("Failed to write to $file");
        } else {
            $this->ui->success("Updated " . ($isGlobal ? "global" : "project") . " config: $key = " . json_encode($value));
        }
    }

    private function logExecution(string $cmd): void
    {
        if (empty($this->globalConfigFile))
            return;

        $logDir = dirname($this->globalConfigFile);
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'history.log';

        if (!is_dir($logDir) && !$this->dryRun) {
            mkdir($logDir, 0777, true);
        }

        $user = get_current_user();
        $host = gethostname();
        $date = date('Y-m-d H:i:s');
        $project = basename($this->rootDir);

        $entry = "[$date] User: $user@$host | Project: $project | Command: $cmd" . PHP_EOL;

        if ($this->dryRun) {
            $this->ui->info("[Dry Run] Would log execution: " . trim($entry));
            return;
        }

        file_put_contents($logFile, $entry, FILE_APPEND);
    }

    private function isFirstRun(): bool
    {
        // Check if directory is empty (ignoring management files)
        $items = array_diff(scandir($this->rootDir) ?: [], ['.', '..', '.deploy', '.git', 'shipit', 'config.json', 'vendor', '__temp_update_clone']);
        return empty($items);
    }

    public function getHomeDir(): ?string
    {
        $home = getenv('SHIPIT_HOME') ?: getenv('HOME') ?: getenv('USERPROFILE');
        return $home ? rtrim($home, DIRECTORY_SEPARATOR) : null;
    }

    public function updateGlobalRegistry(?string $outcome = null): void
    {
        if (empty($this->globalConfigFile)) {
            return;
        }

        if ($this->dryRun) {
            return;
        }

        // Determine project config values
        $gitRepoUrl = null;
        $branch = 'main';
        $user = $this->user ?: 'admin';

        if (file_exists($this->configFile)) {
            $projectConfig = json_decode(file_get_contents($this->configFile), true) ?: [];
            $gitRepoUrl = $projectConfig['gitRepoUrl'] ?? null;
            $branch = $projectConfig['branch'] ?? 'main';
            $user = $projectConfig['user'] ?? $user;
        }

        $globalConfigDir = dirname($this->globalConfigFile);
        if (!is_dir($globalConfigDir)) {
            @mkdir($globalConfigDir, 0777, true);
        }

        $fp = fopen($this->globalConfigFile, 'c+');
        if (!$fp) {
            return;
        }

        if (flock($fp, LOCK_EX)) {
            clearstatcache(true, $this->globalConfigFile);
            $fileSize = filesize($this->globalConfigFile);
            $content = '';
            if ($fileSize > 0) {
                rewind($fp);
                $content = fread($fp, $fileSize);
            }
            $registry = json_decode($content, true) ?: [];

            if (!isset($registry['projects']) || !is_array($registry['projects'])) {
                $registry['projects'] = [];
            }

            $path = realpath($this->rootDir) ?: $this->rootDir;
            $existingEntry = $registry['projects'][$path] ?? [];

            $webhookToken = $existingEntry['webhook_token'] ?? null;
            if (empty($webhookToken)) {
                $webhookToken = bin2hex(random_bytes(16));
            }

            $lastShippedAt = $existingEntry['last_shipped_at'] ?? null;
            $latestOutcome = $existingEntry['latest_outcome'] ?? null;
            $history = $existingEntry['history'] ?? [];

            if ($outcome === 'success') {
                $lastShippedAt = date('Y-m-d H:i:s');
                $latestOutcome = 'success';
            } elseif ($outcome === 'failed') {
                $latestOutcome = 'failed';
            }

            // Append to history if a command and outcome are provided
            if ($outcome && $this->currentCmd) {
                array_unshift($history, [
                    'timestamp' => date('Y-m-d H:i:s'),
                    'command' => $this->currentCmd,
                    'outcome' => $outcome,
                    'log_id' => $this->logId
                ]);
                // Keep only latest 15
                $history = array_slice($history, 0, 15);
            }

            $registry['projects'][$path] = [
                'path' => $path,
                'gitRepoUrl' => $gitRepoUrl,
                'branch' => $branch,
                'user' => $user,
                'last_shipped_at' => $lastShippedAt,
                'latest_outcome' => $latestOutcome,
                'webhook_token' => $webhookToken,
                'history' => $history
            ];

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    /**
     * Persists an integration token for a specific user and provider.
     */
    public function setUserIntegrationToken(string $username, string $provider, string $token): void
    {
        if (empty($this->globalConfigFile))
            return;

        $fp = fopen($this->globalConfigFile, 'c+');
        if (!$fp)
            return;

        if (flock($fp, LOCK_EX)) {
            clearstatcache(true, $this->globalConfigFile);
            $fileSize = filesize($this->globalConfigFile);
            $content = '';
            if ($fileSize > 0) {
                rewind($fp);
                $content = fread($fp, $fileSize);
            }
            $registry = json_decode($content, true) ?: [];

            if (!isset($registry['integrations'])) {
                $registry['integrations'] = [];
            }
            if (!isset($registry['integrations'][$provider])) {
                $registry['integrations'][$provider] = [];
            }
            if (!isset($registry['integrations'][$provider]['users'])) {
                $registry['integrations'][$provider]['users'] = [];
            }

            $registry['integrations'][$provider]['users'][$username] = [
                'token' => $token,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    /**
     * Retrieves an integration token for a specific user and provider.
     */
    public function getUserIntegrationToken(string $username, string $provider): ?string
    {
        if (empty($this->globalConfigFile) || !file_exists($this->globalConfigFile)) {
            return null;
        }

        $registry = json_decode(file_get_contents($this->globalConfigFile), true) ?: [];
        return $registry['integrations'][$provider]['users'][$username]['token'] ?? null;
    }

    private function listTasks(): void
    {
        $runOrder = ['backup', 'update', 'composer', 'nodejs', 'symlink', 'perms'];
        if (!empty($this->adapterRunOrderRules)) {
            $runOrder = $this->runner->mergeRunOrder($runOrder, $this->adapterRunOrderRules);
        }

        $registeredTasks = array_keys($this->runner->getTasks());
        $preHooks = $this->runner->getPreHooks();
        $postHooks = $this->runner->getPostHooks();

        $this->ui->info("\nDeployment Tasks in Run Order:");
        foreach ($runOrder as $index => $taskName) {
            $details = [];
            if (isset($preHooks[$taskName]) && count($preHooks[$taskName]) > 0) {
                $details[] = count($preHooks[$taskName]) . " pre-hook(s)";
            }
            if (isset($postHooks[$taskName]) && count($postHooks[$taskName]) > 0) {
                $details[] = count($postHooks[$taskName]) . " post-hook(s)";
            }

            $suffix = !empty($details) ? " (" . implode(', ', $details) . ")" : "";
            echo "  " . ($index + 1) . ". " . $taskName . $suffix . "\n";
        }

        $diff = array_diff($registeredTasks, $runOrder);
        if (!empty($diff)) {
            $this->ui->info("\nOther Registered Tasks (Not in run order):");
            foreach ($diff as $taskName) {
                echo "  - $taskName\n";
            }
        }
    }

    private function pruneGlobalRegistry(): void
    {
        if (empty($this->globalConfigFile) || !file_exists($this->globalConfigFile)) {
            $this->ui->info("Global registry file not found.");
            return;
        }

        $this->ui->info("Checking global registry for dead project paths...");

        $fp = fopen($this->globalConfigFile, 'c+');
        if (!$fp) {
            $this->ui->error("Could not open global registry file for pruning.");
            return;
        }

        if (flock($fp, LOCK_EX)) {
            clearstatcache(true, $this->globalConfigFile);
            $fileSize = filesize($this->globalConfigFile);
            $content = '';
            if ($fileSize > 0) {
                rewind($fp);
                $content = fread($fp, $fileSize);
            }

            $registry = json_decode($content, true) ?: [];

            if (!isset($registry['projects']) || !is_array($registry['projects'])) {
                $this->ui->warning("Registry is empty or invalid.");
                flock($fp, LOCK_UN);
                fclose($fp);
                return;
            }

            $prunedPaths = [];
            foreach ($registry['projects'] as $path => $data) {
                if (!is_dir($path)) {
                    $prunedPaths[] = $path;
                    unset($registry['projects'][$path]);
                }
            }

            if (!empty($prunedPaths)) {
                foreach ($prunedPaths as $p) {
                    $this->ui->warning("🗑️  Pruning non-existent project: $p");
                }
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $this->ui->success("Successfully pruned " . count($prunedPaths) . " dead project(s).");
            } else {
                $this->ui->success("Registry is clean. No dead paths found.");
            }

            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    private function showHelp(): void
    {
        $this->ui->info("ShipIt - PHP Deployment Orchestrator\n");
        $this->ui->info("Usage:");
        $this->ui->info("  shipit [command] [options]\n");
        $this->ui->info("Commands:");
        $this->ui->info("  deploy           Run the full deployment lifecycle (default)");
        $this->ui->info("  rollback [id]    Revert to the most recent or specified backup");
        $this->ui->info("  validate         Run configuration and environment validation");
        $this->ui->info("  registry:prune   Remove non-existent projects from the global registry");
        $this->ui->info("  init             Initialize ShipIt in the current directory");
        $this->ui->info("  doctor           Check system prerequisites and setup");
        $this->ui->info("  status           Show project configuration and task order");
        $this->ui->info("  backups          List available backups");
        $this->ui->info("  list             List all tasks and hooks in run order");
        $this->ui->info("  config           View or update configuration keys");
        $this->ui->info("  version          Show current ShipIt version\n");
        $this->ui->info("Options:");
        $this->ui->info("  --dry-run        Show what would be done without making changes");
        $this->ui->info("  --only=task1,task2 Only run specific tasks");
        $this->ui->info("  --ignore=task1   Skip specific tasks");
        $this->ui->info("  --ignore-all     Skip all built-in tasks (useful for only hooks)");
        $this->ui->info("  --log            Enable detailed logging for the current run");
        $this->ui->info("  --global         Apply config command to the global registry\n");
    }

    private function doDoctor(): void
    {
        $this->ui->info("ShipIt Environment Doctor");
        $this->ui->info("Checking server environment and prerequisites...\n");

        $checks = [];
        $allPassed = true;

        // 1. PHP Version
        $phpVersion = PHP_VERSION;
        $phpPassed = version_compare($phpVersion, '8.1.0', '>=');
        $checks[] = [
            'Prerequisite',
            'PHP Version',
            $phpPassed ? 'SUCCESS' : 'FAILURE',
            "Required: >= 8.1. Current: $phpVersion"
        ];
        if (!$phpPassed)
            $allPassed = false;

        // 2. Disabled exec functions
        $requiredFuncs = ['exec', 'shell_exec', 'passthru'];
        $disabledFuncs = array_filter($requiredFuncs, function ($f) {
            return !function_exists($f) || in_array($f, explode(',', ini_get('disable_functions')), true);
        });
        $funcsPassed = empty($disabledFuncs);
        $checks[] = [
            'Prerequisite',
            'System Exec Functions',
            $funcsPassed ? 'SUCCESS' : 'WARNING',
            $funcsPassed ? 'Required functions are enabled' : 'Disabled: ' . implode(', ', $disabledFuncs) . '. Deployment commands may fail.'
        ];
        if (!$funcsPassed)
            $allPassed = false;

        // 3. Git binary
        $gitPath = $this->findBinary('git');
        $gitPassed = $gitPath !== null;
        $checks[] = [
            'Prerequisite',
            'Git Command',
            $gitPassed ? 'SUCCESS' : 'FAILURE',
            $gitPassed ? "Found at: $gitPath" : 'Not found in path. Install Git to enable cloning.'
        ];
        if (!$gitPassed)
            $allPassed = false;

        // 4. Composer binary
        $composerPath = $this->findBinary('composer');
        $composerPassed = $composerPath !== null;
        $checks[] = [
            'Prerequisite',
            'Composer Command',
            $composerPassed ? 'SUCCESS' : 'WARNING',
            $composerPassed ? "Found at: $composerPath" : 'Not found in path. Hook "composer" task will fail if not resolved.'
        ];

        // 5. Node Package Manager binary
        $nodePM = new NodePackageManager($this->rootDir);
        if (!$nodePM->hasPackageJson()) {
            $checks[] = [
                'Prerequisite',
                'Node Package Manager',
                'SUCCESS',
                'Not required (no package.json found)'
            ];
        } else {
            try {
                $pm = $nodePM->detect();
                $pmPath = $this->findBinary($pm);
                $pmPassed = $pmPath !== null;
                $checks[] = [
                    'Prerequisite',
                    strtoupper($pm) . ' Command',
                    $pmPassed ? 'SUCCESS' : 'WARNING',
                    $pmPassed ? "Found at: $pmPath" : "Not found in path. Hook \"nodejs\" task will fail if not resolved."
                ];
            } catch (\RuntimeException $e) {
                $allPassed = false;
                $checks[] = [
                    'Prerequisite',
                    'Node Package Manager',
                    'FAILURE',
                    $e->getMessage()
                ];
            }
        }

        // 6. Configuration Check
        $configExists = file_exists($this->configFile);
        $checks[] = [
            'Configuration',
            'Project Config',
            $configExists ? 'SUCCESS' : 'FAILURE',
            $configExists ? 'Found .deploy/config.json' : 'Missing .deploy/config.json. Run "shipit init".'
        ];
        if (!$configExists)
            $allPassed = false;

        // 7. DeployIgnore Check
        $ignoreExists = file_exists($this->rootDir . '/.deployignore');
        $checks[] = [
            'Configuration',
            'DeployIgnore File',
            $ignoreExists ? 'SUCCESS' : 'WARNING',
            $ignoreExists ? 'Found .deployignore' : 'Missing .deployignore. All files will be copied.'
        ];

        // 8. Repository Connection Check
        if ($configExists && !empty($this->config['gitRepoUrl'])) {
            $repoUrl = $this->config['gitRepoUrl'];
            $this->ui->info("Testing connection to Git repository: $repoUrl ...");

            $connectionCmd = "GIT_TERMINAL_PROMPT=0 GIT_SSH_COMMAND=\"ssh -o BatchMode=yes\" git ls-remote -h " . escapeshellarg($repoUrl) . " 2>&1";
            exec($connectionCmd, $output, $status);

            $repoPassed = ($status === 0);
            $checks[] = [
                'Configuration',
                'Git Repo Connection',
                $repoPassed ? 'SUCCESS' : 'FAILURE',
                $repoPassed ? 'Authentication successful' : 'Connection failed. Verify SSH keys, repository URL, or access permissions.'
            ];
            if (!$repoPassed)
                $allPassed = false;
        }

        $this->ui->table(
            ['Category', 'Check', 'Result', 'Notes'],
            $checks
        );

        if ($allPassed) {
            $this->ui->success("All critical checks passed! Your environment is ready to deploy.");
        } else {
            $this->ui->error("Some checks failed or generated warnings. Please review the table above.");
        }
    }

    private function findBinary(string $binary): ?string
    {
        $command = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'where' : 'which';
        $output = [];
        $status = 0;
        @exec("$command " . escapeshellarg($binary) . " 2>&1", $output, $status);
        if ($status === 0 && !empty($output)) {
            return trim($output[0]);
        }
        return null;
    }

    private function printLogo(): void
    {
        $logoFile = __DIR__ . '/assets/ascii-logo.txt';
        if (file_exists($logoFile)) {
            $logo = file_get_contents($logoFile);
            echo $this->ui->color($logo, "\033[1;35m"); // Bold Magenta/Purple
        }
    }

    private function showVersion(): void
    {
        $this->ui->info("ShipIt version " . self::VERSION);
    }

    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    public function getConfig(): array
    {
        return $this->config;
    }
}
