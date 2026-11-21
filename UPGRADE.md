# XAMPP Dashboard upgrade

1. Back up your current dashboard directory and projects.
2. Extract this archive into `C:\xampp\htdocs\dashboard`, preserving your existing `storage/logo.png` and any custom helper files.
3. Ensure Apache can write to `storage/data` and `storage/broilers`.
4. Open `http://localhost/dashboard/`, then click **Settings** to edit project stubs and preferences.
5. Use **New Project** for optional README, .gitignore, LICENSE, CHANGELOG, and SECURITY files. Use **Tools** for clone, Composer framework creation, Git fetch/pull, snapshots, project metadata and ZIP/MXD restore.
6. Enable PHP `zip` extension for archives. Install Git for Windows and Composer to use Git/Composer features. The Git/Composer commands run under Apache's account, so configure credentials for that account.

Data is stored in `storage/data/settings.json` and `storage/data/projects.json`. Local snapshots are under `storage/data/snapshots`. No MySQL database is used by dashboard metadata; the database-name field is informational only.

**Security:** Intended for trusted, local XAMPP use only. Do not expose this dashboard to the public Internet. `storage/data` and `storage/broilers` contain `.htaccess` rules denying HTTP access on Apache installations honoring `.htaccess`.

**MXD:** `.mxd` backups are ZIP-compatible archives with a different filename extension. They are not encrypted or a proprietary binary format.

**Limitations:** Framework creation and remote Git operations depend on local tools and credentials; they have not been end-to-end tested on Windows. Git pull uses fast-forward only. Snapshots exclude Git metadata. Restore always creates a **new** project directory; it never overwrites an existing project. Automatic snapshot rollback is not provided.
