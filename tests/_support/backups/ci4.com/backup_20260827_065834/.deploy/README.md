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
