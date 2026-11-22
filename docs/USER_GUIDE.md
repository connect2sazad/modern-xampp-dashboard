# User guide

[Documentation home](../README.md)

## Find and organize projects

The dashboard lists supported direct subfolders of `C:\xampp\htdocs`. Use **Search** or **Ctrl+F** to find a project by folder name. The alphabet rail filters by the first character and **All** clears that letter filter. **Starred projects** shows the favorites you marked with the star on a project card. **List** and **Tiles** switch layouts without navigating and remember the selection in the browser.

Open the gear icon on a card for **Project Settings**. You can rename the folder, add notes, link MySQL databases, configure Git, and set appearance. Enter a **Project group** name to group related cards together. Matching group names share the **Group color**. Enable **Use custom card color** to override a card's accent. Clearing the group name moves the project to **Ungrouped**.

Disk and database sizes load after the page renders. Database size requires a reachable server and configured links. The dashboard may show a cached result for up to five minutes.

| Card action | Use it to |
| --- | --- |
| **Open** | Launch the app using its detected public entry point or a saved launch file |
| **Editor** | Browse and edit project files |
| **Developer** | Inspect the entry point and runtime, copy useful commands, or run project tools |
| **⚙** | Change project settings |
| **···** | Open Explorer, VS Code, or linked databases, create or download archives, or remove the project |

## Create projects

From **New Project**, pick the workflow that matches the source.

| Option | Result |
| --- | --- |
| **Blank Project** | Creates an `index.php` starter and any selected stub files |
| **Blank Git Project** | Creates the same starter, initializes Git on `main` or `master`, and optionally commits and pushes to an empty HTTPS remote |
| **Clone a Git Project** | Clones an HTTPS repository. You can use its default branch or enter a branch or tag. |
| **Blank Framework Project** | Uses Composer to scaffold Laravel, Yii 2 Basic, CakePHP, Symfony, or CodeIgniter 4 |
| **Install CMS or Custom Template** | Fetches the selected package, repository, or official release ZIP and streams the install log |
| **Restore an MXDP backup / Import a ZIP project** | Imports an archive from disk. You can also drop one ZIP or MXDP file on the dashboard. |

New-project forms accept up to 80 letters, numbers, underscores, or hyphens, starting with a letter or number. Use a new folder name. A failed Git or Composer installation can leave a partially created folder. Read the job output and inspect that folder before retrying with the same name.

### Framework entry points

| Framework | Public directory |
| --- | --- |
| Laravel | `public/` |
| Yii 2 Basic | `web/` |
| CakePHP | `webroot/` |
| Symfony | `public/` |
| CodeIgniter 4 | `public/` |

Use the card's **Open** button. The dashboard detects supported index files in the public directory. For newly scaffolded projects with a public entry point and no root `.htaccess`, it can add an Apache redirect from the project root. It preserves a template's own root Apache rules. If the starter contains only a skeleton and no web entry point, complete the framework setup first.

### Built-in CMS catalog

| Source type | Templates |
| --- | --- |
| Official release ZIP | WordPress, Joomla, Grav |
| Composer | Drupal, Drupal CMS, Craft CMS, Kirby Starterkit, Statamic, Concrete CMS, Silverstripe CMS, October CMS, TYPO3 |
| HTTPS Git | ProcessWire, Backdrop CMS |

The selector displays the source. After the files install, use **Open** to continue the CMS's setup, or follow the template's setup instructions if it requires a CLI step. A CMS may require its own PHP extensions, compatible PHP version, database, account, or license. The dashboard does not create a CMS administrator or fill its database configuration for you.

### Custom CMS and framework templates

In **Settings → Manage custom templates**, enter a name, choose **CMS** or **Framework**, and pick a source.

- **Git clone** accepts an HTTPS repository URL and an optional branch or tag. Enable **Install Composer dependencies after Git clone** if the repository has a `composer.json`.
- **Composer** accepts a `vendor/package` starter package and an optional version constraint.
- **Public folder** is relative to the project, such as `public` or `frontend/web`. Leave it blank for the project root.

Save the template and select it under **Install CMS or Custom Template**. Editing or removing a saved template changes future installs. Installed projects keep their recorded source and public folder.

## Work on a project

### Editor and plain PHP or HTML tools

**Editor** lists project files and opens them as Monaco tabs. Create a file or folder by entering a project-relative path, or open a file to edit, save, rename, or delete it. Create a parent folder before creating a file inside it. Unsaved changes remain in their tabs while you switch between files. The editor handles UTF-8 text files up to 2 MiB and prompts before you leave the page with unsaved edits.

On **Developer**, plain projects can set a **Launch file**, such as `pages/home.php`. Clear it to use automatic index detection. The PHP syntax checker runs `php -l` on a selected project-relative PHP file. A passing check verifies syntax. Run the app separately to check runtime behavior.

### Composer

For projects with `composer.json`, **Developer** shows the declared PHP requirement, lockfile and autoloader status, detectable missing Apache PHP extensions, and `.env` presence when `.env.example` exists. It also provides copyable project paths, app URLs, and PowerShell commands.

| Button | What it runs |
| --- | --- |
| Install | Installs from `composer.lock` when present. Without it, Composer resolves versions and can write a lockfile. |
| Update | Resolves dependency versions again and can change both `composer.lock` and installed packages |
| Validate | Checks the Composer manifest and its lockfile consistency |
| Check platform | Checks PHP and extension requirements using the lockfile when available |
| Outdated packages | Lists outdated direct dependencies |
| Audit dependencies | Audits locked packages for known advisories |

The panel displays live output and offers **Copy output**. Use it for details when a job fails. The dashboard runs one Composer job per project at a time. Composer jobs run with the account and environment available to Apache. Use project sources you trust, since Composer can run project scripts and plugins.

### Git and desktop tools

For a folder without Git, select **Initialize Git for this project** in **Project Settings**. The dashboard uses the default branch from Settings. An origin is optional. For an existing Git repository, the modal reads the configured `origin` from Git. An unchanged origin stays in place. Enter an HTTPS URL to replace it, or clear the loaded field to remove it. The dashboard displays HTTP(S) origins without embedded credentials.

**Backups → Project Tools** exposes Git Fetch and fast-forward-only Pull. Use your Git client for branching, merges, conflicts, rebases, and interactive credentials. The Git hosting preference in Settings does not create accounts or configure remotes.

**··· → Open in Explorer** and **Open in VS Code** launch desktop programs from Apache. VS Code needs its `code` command on Apache's PATH. Desktop launches may be unavailable when Apache runs in a background Windows service session.

## Preferences and utilities

- **Settings** offers Light, Dark, Windows XP, Blood Red, Forest, Deep Ocean, Sunrise, and Lavender. Selecting a theme changes the demo immediately. **Save preferences** applies it across the dashboard.
- **Starter file templates** customize the generated README, license, Git ignore, changelog, and security policy for future blank projects. The dashboard substitutes `{{PROJECT_NAME}}` and `{{YEAR}}`.
- The header's **to-do icon** opens a list where you can add, complete, and delete tasks without reloading. The tasks live in the current browser's storage for the current site address.
- **phpMyAdmin** opens the local database UI. A card's **··· → Open in phpMyAdmin** section links directly to the project's connected databases when it has any.
- **PHP Info** shows the PHP environment serving the dashboard, with search, a printable view, and HTML export.

[Backups and databases](BACKUPS_AND_DATABASES.md) · [Setup and troubleshooting](SETUP_AND_TROUBLESHOOTING.md)
