# Upgrade Modern XAMPP Dashboard

[Documentation home](README.md)

## Before upgrading

1. Back up `C:\xampp\htdocs\dashboard` and any project folders and databases you need to recover. Store the copy outside `htdocs`.
2. Keep a copy of `storage/data/settings.json`, `storage/data/projects.json`, saved `.mxdp` files and matching `.mxdp.json` catalog files, custom stubs under `storage/broilers`, and any custom branding or local helpers.
3. Keep backup passwords in a separate password store. Browser-local to-dos and view preferences stay in the browser profile for the current dashboard address.

## Update and verify

1. Update the dashboard files in `C:\xampp\htdocs\dashboard`. When copying an archive over an existing installation, preserve the local data listed above and keep the updated Apache access rules.
2. Check that Apache can write to `storage/data`, `storage/broilers`, and project folders you want to manage. Review the [setup requirements](docs/SETUP_AND_TROUBLESHOOTING.md) if you are enabling Git, Composer, database, or CMS tools.
3. Open the dashboard and verify that the project list, Settings, and Backups catalog load. Use a disposable project to verify the features you use.
4. If you roll back dashboard files, restore the matching saved metadata and templates if needed. Rolling back the dashboard application does not reverse changes to project folders or databases.

Older MXDPs without catalog metadata may show **Unknown** for database inclusion. Preserve legacy snapshots separately. The current importer accepts MXDP and ZIP files and the Backups table catalogs saved MXDP files.

[Archive recovery and database import](docs/BACKUPS_AND_DATABASES.md)
