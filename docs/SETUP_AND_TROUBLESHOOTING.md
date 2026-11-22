# Setup and troubleshooting

[Documentation home](../README.md)

## Install on a local Apache server

1. Put the dashboard in a folder named `dashboard` inside your Apache document root. For example, on a standard Windows XAMPP installation use `C:\xampp\htdocs\dashboard`. On macOS or Linux, use the document root from your Apache or XAMPP configuration. The dashboard discovers projects in its parent directory by default.
2. Keep the root `.htaccess` and the access rules under `storage/data` and `storage/broilers`. Apache must honor the files and allow local requests.
3. Start Apache and open [localhost/dashboard](http://localhost/dashboard/). Start the XAMPP MySQL or MariaDB service if you need database features.
4. Give the Apache account write access to `storage/data` and `storage/broilers`. Creating, editing, and removing projects also require write access to the relevant project directories or `htdocs`.

The dashboard assumes a trusted local user and can write project files, run tools, and operate on databases. Keep it bound to the local machine and preserve the Apache protections on its data directories. Bootstrap and Monaco load from a CDN, so browser access to the CDN is needed for the styled interface and Monaco editor.

| Requirement | Feature |
| --- | --- |
| PHP 8.2 or newer | Dashboard and editor |
| PHP ZIP and OpenSSL | MXDP backup and restore |
| PHP ZIP | ZIP import/export and CMS release extraction |
| PHP cURL | Downloading official CMS release archives |
| PDO MySQL and XAMPP MySQL client binaries | Database estimates, dumps, SQL imports, and database deletion |
| Git on Apache's PATH | Git initialization, cloning, origin changes, fetch/pull, and Git templates |
| Composer on Apache's PATH | Composer projects, framework/CMS installs, and dependency commands |
| VS Code on Apache's PATH | Open in VS Code |

Use **PHP Info** to check the loaded Apache `php.ini`, extensions, and active limits. The bundled PHP CLI can use a different configuration from Apache. Run the following in PowerShell to inspect the CLI and tool availability.

```powershell
& C:\xampp\php\php.exe --ini
& C:\xampp\php\php.exe -m
git --version
composer --version
```

On macOS or Linux, use `php --ini` and `php -m` from the PHP installation you intend to run. If you change the server account's PATH, restart Apache so it can pick up the change. Terminal availability alone does not confirm that Apache can launch the same command.

### Optional server environment settings

Set these as environment variables visible to the dashboard's PHP process when the default installation layout does not match your setup.

| Setting | Use |
| --- | --- |
| `DASHBOARD_PROJECTS_ROOT` | Absolute project directory on disk. Apache must also serve those project folders as URL siblings of the dashboard for **Open** and **Preview** to work. The environment variable does not create an Apache alias. |
| `DASHBOARD_PHP_CLI` | Absolute path to the PHP CLI executable for syntax checks and the Windows Composer PHAR launcher |
| `DASHBOARD_PHPMYADMIN_URL` | Base URL of your phpMyAdmin installation, such as `/phpmyadmin/`, without a query string |

**Open project folder** uses Explorer on Windows, `open` on macOS, and `xdg-open` on Linux. These launch on the machine running Apache. They require a desktop session available to its account. Browser editing and project tools remain available on a local development server without a desktop session.

## Configure database access

The dashboard reads these optional fields from `storage/data/settings.json`. Merge the keys into your existing JSON document to preserve themes, defaults, and custom templates.

```json
{
  "db_host": "127.0.0.1",
  "db_port": 3306,
  "db_user": "root",
  "db_password": ""
}
```

These are the fallback values used for a default local XAMPP setup. Use your actual local credentials. Keep the settings file private. The dashboard uses the configured account for schema inspection, SQL dumps, and administrative actions. SQL import requires permission to create a schema and create, grant privileges to, and remove a temporary import account. The delete-database option also requires permission to drop linked schemas.

## Diagnose common problems

| Symptom | What to check |
| --- | --- |
| Dashboard is unavailable | Check Apache, the URL and port, and the error log configured by Apache's `ErrorLog` directive. On Windows XAMPP the default is `C:\xampp\apache\logs\error.log`. |
| Page appears unstyled or editor does not start | Check whether the browser can load the Bootstrap and Monaco CDN resources. Reload after connectivity returns. |
| Apache displays a folder listing instead of the app | Open the app from the project card. Check **Developer → Web entry point**. A framework typically serves `public/`, `web/`, or `webroot/`. If Apache ignores the generated redirect, check `.htaccess` handling and `mod_rewrite`. |
| **Open** is missing | Review the detected entry point in **Developer**. For a plain project, set a PHP or HTML **Launch file**. For a template, make sure installation finished and the public folder contains an index file. |
| Git origin is blank | Check whether the repository has a remote named `origin`. In that project's PowerShell prompt, run `git remote -v` and compare it with Project Settings. |
| Git or Composer is unavailable in the dashboard | Confirm Apache can find the executable and PHP can launch processes. Use **Backups → Project Tools** for the dashboard's environment indicators. |
| Clone or push reports an authentication error | Configure Git authentication for the Windows account running Apache. Use an HTTPS URL without credentials in the URL. Test interactive authentication in your Git client with that account. |
| Composer or CMS installation fails | Read the live job output for the first dependency, PHP version, extension, network, or credential error. Use **Developer → Check platform** when a lockfile exists. Inspect a partially created folder before retrying. |
| MySQL size shows **Unavailable** | Confirm the database service is running, the link names are correct, and the configured account can read schema metadata. Results may be cached for five minutes. |
| SQL import fails or reports a privilege-table error | Read the import error and confirm the configured account can create a database and manage the temporary import account. A server error referring to a MySQL system table needs database administration outside the dashboard. |
| Upload fails before the archive opens | Check `upload_max_filesize` and `post_max_size` in the Apache PHP configuration. The POST limit needs room for the archive plus form fields. Restart Apache after changing it. The dashboard also enforces its own archive limits. |
| MXDP password rejected | Enter the exact password used when making the archive. A wrong password or damaged file can give the same error. |
| Settings do not save | Check write permissions on `storage/data`, the dashboard session, and Apache's error log. Reload if the form reports an expired session. |
| Explorer or VS Code does not open | Check Apache's PATH and whether its Windows session can show desktop windows. As a fallback, copy the project path from **Developer**. |

[Backups and databases](BACKUPS_AND_DATABASES.md) · [Upgrade guide](../UPGRADE.md)
