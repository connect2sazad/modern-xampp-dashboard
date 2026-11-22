# Backups and databases

[Documentation home](../README.md)

## Link an existing database

1. Create or locate the database in the local MySQL or MariaDB server.
2. Open the project's gear icon and put the names in **Connected MySQL databases**, separated by commas. Save changes.
3. Use **··· → Open in phpMyAdmin** on that card to open a linked database.

Linking stores database names in dashboard metadata. It does not write to the project's `.env`, `wp-config.php`, or other connection configuration. Keep the application's database settings in sync yourself. The dashboard uses one database server configuration for size lookups, exports, imports, and deletion. The phpMyAdmin links open the local phpMyAdmin installation, whose own server configuration may differ.

## Choose a backup type

| Action | Contents | Where it goes |
| --- | --- | --- |
| **Create MXDP backup → Project files and linked databases** | Project files plus SQL dumps of every linked database | Saved to the dashboard's Backups catalog and offered for download |
| **Create MXDP backup → Linked databases only** | SQL dumps of the linked databases | Saved to the catalog and offered for download |
| **Download ZIP + DB** | An unencrypted ZIP containing an inner `project.zip`, `manifest.json`, and numbered SQL dumps for linked databases | Browser download |
| **Remove project → Save an MXDP backup** | The full or database-only backup selected in the remove dialog | Saved in Backups before project removal begins |

New backups exclude Git metadata and filesystem links. Keep a separate Git copy if you need repository history. Review downloaded ZIPs before sharing them because they can contain application configuration and database data.

MXDP is a dashboard-specific encrypted wrapper around a ZIP payload. Set and confirm your password when creating one. You will need that password to restore it. Keep the password separately because the dashboard does not store a recovery copy.

## Manage and restore archives

Open **Backups** to see saved MXDPs, their creation time, size, database inclusion, and password protection. Choose **Download**, **Restore**, or **Delete**. Deleting an archive requires typing its full displayed filename. Older MXDP archives can show **Unknown** for database inclusion if they predate catalog metadata.

To use an archive already on disk, select **New Project → Restore an MXDP backup** or **Import a ZIP project**. You can also drop a single archive anywhere on the dashboard.

1. Provide the project folder name and choose the file. For MXDP, enter the backup password. The show-password control is available.
2. An archive with files requires a **new** project directory. A database-only MXDP can target an existing project or a new empty one.
3. After restoring, select the SQL files to import in the database setup dialog. Skip this step if you only need the project files.
4. Open the project and update its application-level database configuration if the database name or credentials differ.

The dashboard accepts its own ZIP export format and ordinary project ZIPs. For an ordinary ZIP, put the desired project folders and files at the archive root. A ZIP with an extra top-level folder retains that folder after extraction.

## Import and link SQL files

The database setup dialog appears after a Git clone or project archive restore. It offers SQL files found in the project, plus any database dumps staged from a dashboard archive.

- Select one to 20 SQL files and give each a distinct new database name. Existing databases are protected from overwrite.
- Each successful import creates and links its target database. If a later import fails, earlier successful imports remain linked.
- Use SQL that can run in a single selected schema. The dashboard uses a temporary account restricted to that schema. Scripts that need another schema, administrative privileges, or an explicit privileged definer can fail.
- Complete the import while the setup dialog and staging files are available. If staging expires, you can reopen a database-only archive for the existing project. A full archive still requires a new project folder if its files are restored again.

## Remove a project

Use **··· → Remove project**, review the linked databases, and type the exact project name. The options in the dialog control what happens.

- **Delete linked databases** adds database removal to the operation. Leave it unchecked to keep the databases.
- Choose either **Save an MXDP backup of the project and linked databases** or **Save an MXDP backup of linked databases only**. If the backup fails, project removal does not start.
- The dashboard blocks database deletion when another dashboard project is linked to the same name. External apps are outside that check.

Removal is permanent. File removal and database drops are separate operations. If the dashboard reports a partial failure, check which files and databases remain before retrying.

## Limits

| Item | Limit |
| --- | --- |
| MXDP password | 8 to 1,024 bytes |
| MXDP archive | 50 MiB, with lower upload limits possible in PHP or Apache |
| Project ZIP extraction | 10,000 entries and 200 MiB uncompressed |
| Dashboard bundle staging | 250 MiB total for the inner archive and SQL files |
| Databases per backup or import | 20 |

[Database connection settings and troubleshooting](SETUP_AND_TROUBLESHOOTING.md)
