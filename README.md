# 🚀 XAMPP Project Dashboard by connect2sazad

A fully-featured local project manager and file editor for XAMPP `htdocs`.  
Easily create, edit, backup, and preview PHP/HTML projects — all from your browser.

## 🌟 Features

- 📂 **List & Manage Projects**: Detects supported project folders inside `C:/xampp/htdocs`
- ➕ **Create Projects**: One-click creation with Monaco code editor (index.php scaffolded)
- 📝 **Edit Code**: Tab-based editor with syntax highlighting powered by Monaco
- ⚙️ **File Manager**: Create folders and create, rename, or delete files
- 🌐 **Live Preview**: Instantly view HTML or PHP in browser
- 💾 **Backup Projects**: Save a password-protected MXDP backup of project files and linked databases, or databases only
- 🧠 **Git Integration**: Init, Push, Pull with Git CLI commands
- 🎨 **Bootstrap UI**: Clean responsive interface with dark mode toggle
- 🔍 **Smart Search (Ctrl+F)**: Filter existing projects or create new ones instantly
- 📊 **Styled phpinfo()**: PHP environment info styled with Bootstrap, searchable, and exportable

## 📦 Requirements

- XAMPP or any local Apache + PHP environment
- PHP 8.2+ with ZIP, OpenSSL, and PDO MySQL for MXDP and database operations
- Windows (for `explorer` and `code` commands to work)
- [VS Code](https://code.visualstudio.com/) installed & added to system `PATH`
- Git installed (for Git integration)

## 🛠 Setup

1. Clone or download the repository into `C:/xampp/htdocs/dashboard` or similar folder
2. Access via `http://localhost/dashboard/`
3. Create projects, manage files, and start coding!

Open **New Project → Install CMS or Custom Template** to scaffold a CMS. The installer shows its download source and streams progress. If the CMS has a browser installer, use **Open** on the new project card to configure the database and administrator account. Individual templates have their own PHP extension, version, and database requirements.

In **Settings → Manage custom templates**, save a reusable CMS or framework from an HTTPS Git URL or a Composer package. You can choose a branch, tag, or Composer version, set the public folder, and opt into running Composer install after a Git clone.

The dashboard accepts requests from the local machine only. Keep its Apache access rules enabled. The editor supports UTF-8 text files up to 2 MB. MXDP uses a dashboard-specific wrapper around a ZIP payload, encrypted and authenticated with AES-256-GCM. It requires the password chosen at backup creation. New backups exclude Git metadata and links. The MXDP limit is 50 MB, subject to Apache and PHP upload limits. A backup with files restores into a new project folder.

Use **Backups** to view, download, restore, or delete saved MXDP backups. Delete requires typing the full filename. Older archives without catalog metadata show an unknown database status.

Use **New Project → Restore an MXDP backup** to open a backup. Use **Import a ZIP project** for a ZIP file, or drop one ZIP or MXDP file anywhere on the dashboard. **Download ZIP + DB** on a project card exports a standard, unencrypted ZIP containing a project ZIP and the linked SQL dumps. The dashboard can import that bundle or an ordinary project ZIP. Project cards with linked databases also provide a direct link to phpMyAdmin. After a restore or Git clone, choose the SQL files and name the databases to import. The dashboard creates and links new databases. Use SQL scripts that can run within a single database. Scripts that require server-wide privileges, a different schema, or an explicit privileged definer can fail.

Database operations use the MySQL/MariaDB settings in `storage/data/settings.json` (`db_host`, `db_port`, `db_user`, and `db_password`). The defaults are `127.0.0.1:3306` and `root` with an empty password. The configured account needs privileges to dump and create databases and to manage the restricted import account. Removing linked databases additionally requires drop privileges. A requested backup must complete before deletion starts. Backups created during deletion can be downloaded from the dashboard and are stored encrypted under `storage/data`.


> Made with ❤️ by [**connect2sazad**](https://connect2sazad.github.io/)
