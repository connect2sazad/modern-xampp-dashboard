# XAMPP Dashboard upgrade

1. Back up your current dashboard directory and projects.
2. Extract this archive into `C:\xampp\htdocs\dashboard`, preserving your existing `storage/logo.png` and any custom helper files.
3. Ensure Apache can write to `storage/data` and `storage/broilers`.
4. Open `http://localhost/dashboard/`, then click **Settings** to edit project stubs and preferences.
5. Use **New Project** for optional README, .gitignore, LICENSE, CHANGELOG, and SECURITY files. Use **Tools** for clone, Composer framework creation, Git fetch/pull, project metadata and MXDP restore.
6. Enable the PHP ZIP, OpenSSL, and PDO MySQL extensions for MXDP archives and database operations. Install Git for Windows and Composer to use Git/Composer features. The Git/Composer commands run under Apache's account, so configure credentials for that account.

Data is stored in `storage/data/settings.json` and `storage/data/projects.json`. Encrypted MXDP backups are under `storage/data`. Existing snapshots remain under `storage/data/snapshots`. No MySQL database is used by dashboard metadata; the dashboard can use linked database names to look up sizes when MySQL is reachable.

**Security:** Intended for trusted, local XAMPP use only. Do not expose this dashboard to the public Internet. `storage/data` and `storage/broilers` contain `.htaccess` rules denying HTTP access on Apache installations honoring `.htaccess`.

**MXDP:** New backups use password-based encryption. Restore them from the New Project menu. Keep the password with your backup records.

**Limitations:** Framework creation and remote Git operations depend on local tools and credentials; they have not been end-to-end tested on Windows. Git pull uses fast-forward only. Backups exclude Git metadata and linked files. A backup that contains project files restores into a new directory. A database-only backup can be imported into a new or existing project.
