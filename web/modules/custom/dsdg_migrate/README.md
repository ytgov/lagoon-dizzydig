# DSDG migration (Drupal 7 → 11)

Migrates the Drupal 7 Digital Service Delivery Guide into this site with core
Migrate. It reads the D7 database and files directly, so nothing runs on the
D7 site.

**Local DDEV only.** The migration modules are dev requirements
(`composer install --no-dev` leaves them out), and `config_exclude_modules`
keeps them out of `config/sync`, which holds production configuration.

## Setup

1. Get a D7 DSDG production dump: a `.sql` file and a files `.tgz` whose top
   folder is `files/`.

2. Load the D7 database as a second database, `d7`:

   ```bash
   ddev import-db --database=d7 --file=<dump>.sql
   ```

3. Extract the D7 files. D7 doesn't set `file_public_path`, so the D7 file
   source assumes `sites/default/files`:

   ```bash
   mkdir -p private/d7/sites/default
   tar -xzf <dump>-files.tgz -C private/d7/sites/default
   ```

4. Create `web/sites/default/settings.local.php` (gitignored):

   ```php
   <?php
   $databases['migrate']['default'] = [
     'database' => 'd7',
     'username' => 'db',
     'password' => 'db',
     'host' => 'db',
     'port' => 3306,
     'driver' => 'mysql',
     'prefix' => '',
   ];
   $settings['config_exclude_modules'] = [
     'migrate',
     'migrate_drupal',
     'migrate_plus',
     'migrate_tools',
     'dsdg_migrate',
     // Installed by migrate_drupal.
     'phpass',
   ];
   ```

5. Enable the modules, then put back any config they changed (enabling
   modules can import new interface translations):

   ```bash
   ddev drush en -y migrate migrate_drupal migrate_plus migrate_tools dsdg_migrate
   ddev drush cim -y
   ```

## Running

```bash
ddev drush migrate:status --tag=dsdg
ddev drush migrate:import --tag=dsdg --execute-dependencies --continue-on-failure
ddev drush migrate:messages dsdg_content_page
ddev drush migrate:rollback --tag=dsdg
```

`--continue-on-failure` is needed because two D7 files are missing from the
production files archive (`bean/highlight/file/*`), so `dsdg_file` always
reports 2 failures.

## What it migrates

| Migration | From D7 | To D11 |
|---|---|---|
| `dsdg_category` | `wetkit_categories` terms | `categories` terms (D7 term IDs kept) |
| `dsdg_file` | public files | files (D7 file IDs, URIs and UUIDs kept) |
| `dsdg_attachment_media` | files in `field_attachments` | `file` media |
| `dsdg_content_page` | `content_page` nodes in their source language | `content_page` nodes (D7 node IDs and UUIDs kept) |
| `dsdg_content_page_translation` | translation nodes (linked by `tnid`) | translations of the source node |
| `dsdg_url_alias` | page and category aliases | path aliases, unchanged |

Page bodies are rewritten by the `dsdg_body` process plugin:

- `[uuid-link:node:UUID]` becomes the linked page's D7 URL (`/{lang}/{alias}`).
  Links to D7 nodes that no longer exist are left as is, with a migration
  message.
- `[[{"fid":…}]]` media tags become `<img>` (or a link for other files)
  pointing at the migrated file.

Where a D7 translation set has several French nodes, only one is kept (see
`DsdgSkipSupersededTranslation`).

## Not migrated yet

- Authors: every item belongs to user 1. D7 users aren't needed once staff
  sign in with MyYukon SSO.
- Revisions (only the current version) and the workbench moderation state
  (published or unpublished only).
- French category names (D7 translates them through the interface, i18n
  "localize" mode).
- Menus, the homepage block, the two webforms and the one `wetkit_page` node.
