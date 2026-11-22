# Maintainer reference

[Documentation home](../README.md)

## Application layout

The dashboard is a PHP application served by XAMPP Apache. It uses JSON files for dashboard state and a PHP session for form tokens, notifications, and pending database setup. MySQL or MariaDB is accessed for the projects' databases.

| Area | Files and responsibilities |
| --- | --- |
| Project dashboard | `index.php` handles discovery, filtering, grouping, settings, blank scaffolding, and removal. `project-cards.js` manages layout and loads the real Git origin into the settings modal. |
| Shared PHP | `dashboard_lib.php` handles sessions, project and file path validation, metadata, themes, Apache public paths, archive primitives, and desktop helpers. `project_preferences.php` covers Git origin and color handling. |
| Project creation | `project_job.php` orchestrates Git clone, framework creation, and template installs. `template_lib.php` defines the CMS catalog and source adapters. `templates.php` manages custom entries. |
| Developer tools | `developer.php` renders project diagnostics and plain-project launch and lint controls. `composer_job.php` and `composer-tools.js` implement Composer jobs. `job_runner.php` streams Git and Composer output. |
| Editing | `editor.php` contains the Monaco UI. `file_api.php` implements list, read, save, create, rename, and delete. |
| Backups and restore | `backup.php` creates and downloads MXDP backups. `backups.php` catalogs and removes saved archives. `project_zip.php` exports portable ZIP bundles. `restore.php` validates and stages archive imports. `mxdp_lib.php` implements encryption, archive manifests, database tooling, and SQL discovery. |
| Database setup | `database_setup.php` applies selected SQL imports and links successful results. `project_stats.php` returns cached file and database sizes for visible projects. |
| Preferences and client code | `settings.php` edits dashboard preferences and starter stubs. `style.css` and `themes.css` style the interface. `todo.js` stores browser-local tasks. `archive-ui.js` handles password visibility and archive drop. |
| Utilities | `tools.php` provides Git fetch/pull, project metadata, and links to setup and backup flows. `phpinfo.php` displays the runtime configuration. `explorer.php` and `vscode.php` launch desktop helpers. |

## Request behavior

These routes support the dashboard UI. Treat their request fields and formats as internal interfaces.

| Route | Request and result |
| --- | --- |
| `index.php` | GET applies `q`, `letter`, `starred`, and `page`. POST handles project changes with a session token and redirects after handling them. |
| `file_api.php` | GET supports list and read. JSON POST supports save, delete, rename, create file, and create folder with the session token. |
| `project_stats.php` | JSON POST accepts up to 12 project names and returns file and database display values keyed by project. |
| `project_origin.php` | GET returns the repository's displayed origin on demand. It uses Git configuration and removes embedded HTTP(S) credentials from the displayed URL. |
| `project_job.php`, `composer_job.php` | Form POST with the session token. Responses use newline-delimited JSON with stage, output, completion, or error events. Use the final event to determine success. |
| `restore.php`, `database_setup.php`, `project_zip.php` | Form POST with the session token for archive restore, SQL setup, and ZIP exports. |
| `explorer.php`, `vscode.php` | Form POST with the session token and a validated project name. |

## Data and backups

Paths below are relative to the dashboard directory.

| Path or store | Contents |
| --- | --- |
| `storage/data/settings.json` | Theme, default Git branch, hosting preference, custom templates, and optional database connection settings |
| `storage/data/projects.json` | Project metadata keyed by folder name. Values can include notes, database links, stars, groups and colors, launch files, and template records. |
| `storage/data/project_stats_cache.json` | Rebuildable file and database size estimates |
| `storage/data/backup_<id>.mxdp` | Saved encrypted archive |
| `storage/data/backup_<id>.mxdp.json` | Catalog metadata such as displayed filename, creation time, and included database count |
| `storage/data/mxdp_*` | Temporary protected workspaces for archives and staged SQL |
| `storage/data/snapshots` | Legacy storage location. The current Backups catalog lists saved MXDPs. |
| `storage/broilers/*.stub` | Editable starter templates, including `.gitignore.stub` |
| Browser local storage | To-do list and preferred List or Tiles layout |
| PHP session store | Form token, notifications, and current database import selection |

Back up both JSON preference files, the starter stubs, and saved MXDPs with their matching catalog files when migrating the dashboard. Treat settings and archives as private. The dashboard's MXDP wraps a ZIP with AES-256-GCM and derives the key from the entered password using PBKDF2. The ZIP export uses a manifest and one SQL dump per linked database. It is unencrypted. Neither archive format includes Git history.

## Verification after changing the dashboard

1. Lint PHP and JavaScript from PowerShell in the dashboard folder. The JavaScript command requires Node.js.
2. Open the Dashboard, Settings, Backups, and Developer pages in XAMPP. Validate actual menus and rendered controls. Checking that the page returns HTTP 200 does not establish that a control is present or functional.
3. Use a disposable project for create/edit/rename and backup/restore checks. Check any SQL workflow against a disposable database or isolated server before running it on a project you use.
4. Verify Apache blocks direct requests to `storage/data` and `storage/broilers`. Use an actual Git repo to verify origin reads. Use a disposable project for commands that write dependency files.
5. Check new installs and framework links through Apache. A successful Composer invocation alone does not establish that the app's public URL works.

```powershell
Get-ChildItem -File -Filter '*.php' | ForEach-Object {
    & C:\xampp\php\php.exe -l $_.FullName
    if ($LASTEXITCODE -ne 0) { throw "PHP lint failed for $($_.Name)" }
}
Get-ChildItem -File -Filter '*.js' | ForEach-Object {
    node --check $_.FullName
    if ($LASTEXITCODE -ne 0) { throw "JavaScript syntax check failed for $($_.Name)" }
}
git diff --check
```

[Setup and troubleshooting](SETUP_AND_TROUBLESHOOTING.md) · [Backups and databases](BACKUPS_AND_DATABASES.md)

## Publish documentation changes

Edit the Markdown sources listed in `docs/pages.json`. Run the following from the repository root with PowerShell 7 to refresh the HTML fragments served by the dashboard's **Docs** page.

```powershell
pwsh -File docs/build.ps1
```

Include the updated Markdown and generated `docs/html/` files together. Apache serves the generated HTML through `docs.php` without requiring PowerShell or a Markdown renderer at runtime.
