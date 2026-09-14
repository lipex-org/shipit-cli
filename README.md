# ShipIt
The missing bridge between Git and Shared Hosting/VPS.

ShipIt is a lightweight, zero-dependency PHP deployment orchestrator designed for developers who want professional CI/CD workflows (hooks, backups, rollbacks, and environment management) on servers managed by DirectAdmin, cPanel, or raw VPS.

## Why use ShipIt?
Zero-Downtime Mentality: Automatic backups before every update.

Environment Protection: Never accidentally overwrite your .env or user-uploaded content again.

Framework Aware: Built-in adapters for CodeIgniter 4, Laravel, and React.

Permission Fixer: Automatically handles chown and chmod for webserver users.

Dead Simple: No Docker, no Kubernetes, no complex YAML—just PHP and Git.

## Server Requirements
ShipIt is designed to run natively on Linux-based environments (VPS, Dedicated, or Shared Hosting with SSH access like DirectAdmin / cPanel). 

Your server must have:
- **PHP 8.1+** installed and accessible via CLI.
- **Git** installed and authenticated (e.g., SSH keys added so `git clone` can run without interactive password prompts).
- **Composer** and **NPM** installed (if your deployment hooks require them).
- **SSH / Terminal access** to run the deployment script.

## Installation

### Project-Specific (Recommended)
Install ShipIt via Composer in your project:
```bash
composer require gilads-otiannoh254/shipit
```
You can then run it via `vendor/bin/shipit`.

### Global Installation (For ease of use)
Install ShipIt globally to use it anywhere on your server:
```bash
composer global require gilads-otiannoh254/shipit
```
Ensure your global composer bin directory is in your `$PATH`. You can then simply run `shipit` in any project directory.

## Configuration Management

ShipIt supports hierarchical configuration: **Project Config** (`.deploy/config.json`) overrides **Global Config** (`~/.shipit/config.json`) which overrides **Defaults**.

Use the `config` command to manage settings easily:

```bash
# View current project config
shipit config

# Set a project-specific setting
shipit config user deploy_user

# Set a global default for all projects
shipit config --global user vps_admin
shipit config --global backup_path /var/backups/shipit
```

## Running on a Server

Navigate to your project root (where your `.deploy` folder lives) and run:

```bash
shipit
```

### Available Commands and Options

- `shipit` - Run the full deployment sequence. (Automatically skips backup if the project is empty).
- `shipit rollback` - Clears the current project (preserving `.deploy` and `.git`), restores the last backup, and runs post-deployment tasks (composer, npm, etc.).
- `shipit config` - Manage project or global configuration.
- `shipit list` - Display all available deployment tasks.
- `shipit --verbose` or `shipit -v` - Display detailed execution output, ASCII banner, skipped tasks, and command logs (by default, ShipIt runs in concise mode with minimal output).
- `shipit --dry-run` - Simulate the deployment/rollback process.
- `shipit --log` - Show detailed file copy operations.

### Setting up Auto-Deployments (Webhooks / Cron)

To trigger deployments automatically when you push to Git:

1. **Option 1: Using a webhook listener.** You can create a simple PHP script exposed publicly (e.g., `deploy.php`) that runs `shell_exec('cd /path/to/project && vendor/bin/shipit > deploy.log 2>&1');` when a payload is received from GitHub/GitLab. Make sure to secure this endpoint with a secret token!
2. **Option 2: Cron Job.** If you prefer periodic polling, set up a cron job on your server to run `vendor/bin/shipit` on a schedule. Because ShipIt checks if Git cloning is needed, however, a webhook is highly recommended for efficiency.

When you run `shipit`:
1. **Backup**: Your current directory is copied to your `backup_path`. If your project only contains `.deploy` or `.git` files, the backup is skipped (first-run optimization).
2. **Clone**: Your configured Git repository branch is cloned.
3. **Merge**: Files are copied over, excluding anything in `.deployignore` or standard ignores.
4. **Build**: Composer and NPM hooks run.
5. **Permissions**: Ownership and permissions are enforced.

## Rollback Logic
When you run `shipit rollback`:
1. **Clear**: The current project directory is cleared, but `.deploy` and `.git` are preserved to keep configuration and repository metadata.
2. **Restore**: The contents of the most recent backup are copied back into the project.
3. **Rebuild**: Post-deployment tasks like `composer install` and `npm build` are triggered to ensure the environment is fully functional.


## The `.deployignore` File

By default, ShipIt already ignores common files during updates (e.g., `.env`, `vendor`, `node_modules`, `.git`, `public_html`). 

If you have specific files or folders in your Git repository that should **not** be copied to your live server during a deployment, you can place a `.deployignore` file in any directory. 

The syntax is similar to `.gitignore`. Each line represents a pattern to exclude:

```text
# Ignore specific files
docker-compose.yml
phpunit.xml

# Ignore entire directories
tests/
dev-tools/

# Ignore files matching a pattern
*.log
*.sqlite
```

ShipIt traverses your folders and recursively applies any `.deployignore` files it finds during the update process. Use the `vendor/bin/shipit --dry-run` flag to safely verify that your ignore patterns are working correctly before doing a live deployment!

## Security Best Practices

- **Never expose `.deploy/` to the web:** Best practice is to keep your root directory (where `composer.json` and `.deploy` live) *above* your public document root (e.g. `/home/user/domains/domain.com/` while the web root is `/home/user/domains/domain.com/public_html`). 
- **Protect Webhooks:** If you use a webhook PHP script to trigger deployments, secure the endpoint using a secret token verify from GitHub/GitLab. Do not leave the webhook URL easily guessable.
- **SSH Keys vs Passwords:** Always authenticate the server against the Git provider using Deploy Keys or SSH keys instead of hardcoding passwords or tokens in URLs.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

