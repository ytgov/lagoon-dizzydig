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
ddev drush migrate:messages dsdg_content_page_revision
ddev drush migrate:rollback --tag=dsdg
```

`--continue-on-failure` is needed because two D7 files are missing from the
production files archive (`bean/highlight/file/*`), so `dsdg_file` always
reports 2 failures.

## What it migrates

| Migration | From D7 | To D11 |
|---|---|---|
| `dsdg_user` | staff accounts (see below) | users with no password (D7 user IDs kept) |
| `dsdg_homepage`, `dsdg_homepage_translation` | custom block 1, "Homepage Block" (en, fr via i18n_block) | `content_page` node 1, the front page (`system.site` `page.front: /node/1`) |
| `dsdg_category` | `wetkit_categories` terms | `categories` terms (D7 term IDs kept) |
| `dsdg_category_translation` | French term names (i18n "localize" mode) | category translations |
| `dsdg_file` | public files | files (D7 file IDs, URIs and UUIDs kept) |
| `dsdg_attachment_media` | files in `field_attachments` | `file` media |
| `dsdg_content_page_revision` | every revision of `content_page` nodes and their translation nodes (linked by `tnid`), with workbench moderation states | `content_page` nodes with their revision history and translations (D7 node IDs of source nodes, revision IDs and UUIDs kept) |
| `dsdg_url_alias` | page and category aliases | path aliases, unchanged |
| `dsdg_menu_link` | `main-menu` and `menu-wet-footer` links (English) | `main` and `footer` menu links |
| `dsdg_menu_link_translation` | their French links | translations of the English links |
| `dsdg_redirect` | D7's `/search` → `/search/content` (search_facetapi is D7's default search page) | a redirect (redirect module), in every language |

Page bodies are rewritten by the `dsdg_body` process plugin:

- `[uuid-link:node:UUID]` becomes the linked page's D7 URL (`/{lang}/{alias}`).
  As D7 renders these links in the current language, a French body links to
  the page's published French translation when there is one. A link to a D7
  node that no longer exists is removed and its text kept, as D7's uuid_link
  filter did when it rendered the page (with a migration message).
- `[[{"fid":…}]]` media tags become `<img>` (or a link for other files)
  pointing at the migrated file.

Where a D7 translation set has several French nodes, only one is kept (see
`SupersededTranslationsTrait`).

## Revision history

D7 translations are separate nodes, each with its own revisions; D11 keeps
one revision history per page. The `dsdg_content_page_revision` source
returns the revisions of source nodes and translations together in vid order,
which is the order D7 saved them. The destination of the same name saves each
one the way core's edit form would:

- A page's first revision creates it (always in the source language: no D7
  translation node has revisions older than its source node).
- A later revision starts from the latest revision of its language and is
  merged with the other translations of the default revision
  (`createRevision()`), so a French draft doesn't carry a pending English one.
- It's the default revision if its state is published or archived, or if the
  page isn't published yet: content moderation's rule, which it doesn't apply
  to syncing saves, so the destination applies it.
- Translations don't change untranslatable fields (attachments, date adopted).

Workbench states map draft → draft, needs_review → ready_for_review,
published → published, by each revision's last state. A live revision that
was unpublished (D7's "Unpublish" moves it back to draft) becomes archived
(nodes 132 and 152, and an earlier revision of 460).

Workbench saves a copy of the live revision whenever a draft is saved on a
published node, so that `node.vid` stays on live content. These 787 copies
are left out: they only repeat live content, and replayed in vid order one
would replace a draft that D7 later published. Without them, every published
D7 node's live content (title, body, description, categories, attachments,
date adopted) is the same as before. So D11's default revision ID differs
from `node.vid` on 13 pages, where `node.vid` is such a copy.

Result: 3,240 revisions (4,033 minus the copies and the 6 revisions of
superseded French nodes). Live pages match D7, and the 13 drafts and reviews
pending on published pages are pending revisions. So is D7 648, an unpublished
French draft of 634, which is a new French translation that exists only in a
pending revision.

Each revision keeps its D7 author, date and log message. The 34 by accounts
that weren't migrated (33 anonymous, 1 by a vendor) are credited to the admin
account (uid 1), like pages.

The homepage uses vid 1, below D7's revision IDs (361 and up).

Rolling back a page's default revision deletes the page; rolling back another
revision deletes just that revision.

D7 URLs of translation nodes (`/fr/node/N`) keep working: core's
`NodeTranslationExceptionSubscriber` redirects them to the page's translation
using the `node_translation_redirect` key-value store, which core only fills
for `translations: true` migrations. The `TranslationRedirects` event
subscriber fills it after `dsdg_content_page_revision` is imported, for all
203 D7 translation nodes (superseded ones go to the kept translation), and
empties it on rollback.

Menus (source plugin `MenuLink`): D7 keeps separate English and French links,
so a French link becomes a translation of its English pair. A link to a page
pairs with the English link to that page's source node; the footer's external
links pair through `pairs` in both migration files. Then:

- French links with no English pair (to the 11 French-only pages, which
  would show in English menus too) and links to superseded French pages are
  skipped. The French-only pages are migrated, because French content links to
  them; editors can give them English pages or delete them.
- Page links are `entity:node/N` in both languages, so translations share
  the English link's URL. Where the French URL differs (the footer's yukon.ca/fr
  pages), it goes in `link_override`, the translatable field from the base's
  contrib `translatable_menu_link_uri` module (core's menu link URL isn't
  translatable).

Accounts (`dsdg_user`, source plugin `StaffUser`):

- Only YG staff (@yukon.ca or @gov.yk.ca). Vendor accounts aren't migrated.
- Active (enabled, signed in since `active_since`, 2025-01-01) → active.
  Inactive but owning a page or authoring a revision → blocked, so pages
  and their revision history keep their authors.
  Inactive with neither → not migrated.
- `@gov.yk.ca` becomes `@yukon.ca`.
- No passwords. Staff sign in with MyYukon, which links to the account by
  email (`openid_connect` `connect_existing_users`).
- Roles map by name: administrator, editor, publisher, translator.
- Pages and revisions by an author who wasn't migrated go to the admin
  account (uid 1).

## Not migrated yet

- D7's "Navigation" (31 stray page links), "Sidebar", "User menu" and
  "Management" menus.
- The two webforms and the one `wetkit_page` node.
