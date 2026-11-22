# Modern XAMPP Dashboard

Manage local PHP and HTML projects in XAMPP from one browser tab. Create or clone projects, install CMS and framework templates, edit files, run Composer, and make backups with their linked databases.

## Quick start

1. Place the dashboard in `C:\xampp\htdocs\dashboard`. Keep its `.htaccess` files.
2. Start **Apache** in the XAMPP Control Panel. Start **MySQL** if you need databases.
3. Open [the dashboard](http://localhost/dashboard/). Existing supported folders under `C:\xampp\htdocs` appear automatically.
4. Use **New Project** to scaffold, clone, install, or import a project. Use **Open** on its card to launch the app.

This build uses the Windows XAMPP layout and PHP 8.2 or newer. Git, Composer, VS Code, and PHP extensions are needed only for the features that use them. Bootstrap and Monaco load from a CDN. Run the dashboard on a trusted local machine.

## Documentation

Open **Docs** in the dashboard header to read the HTML guides in XAMPP. The Markdown sources are also available below.

| Guide | What it covers |
| --- | --- |
| [User guide](docs/USER_GUIDE.md) | Navigation, project creation, CMS and frameworks, editors, Git, Composer, project groups, themes, and to-dos |
| [Backups and databases](docs/BACKUPS_AND_DATABASES.md) | Linking databases, MXDP and ZIP, SQL imports, deletion, limits, and recovery |
| [Setup and troubleshooting](docs/SETUP_AND_TROUBLESHOOTING.md) | Prerequisites, PHP and Apache configuration, and what to check when a feature fails |
| [Maintainer reference](docs/MAINTAINER_REFERENCE.md) | Code layout, endpoints, persistent data, and verification |
| [Upgrade guide](UPGRADE.md) | Preserving projects, preferences, templates, and backups while updating |

Created by [connect2sazad](https://connect2sazad.github.io/).
