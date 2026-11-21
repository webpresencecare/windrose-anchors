# Windrose Anchors - Network Media

Network media for WordPress multisite networks, part of the Windrose Suite. The main site's media library is the hub: the items it shares appear in a **Network** tab of the media dialog on every allowed site. Picking one creates a local media item on that site, with its own title, alternative text, caption and description, while the file stays on the main site. No file is ever copied.

Requires WordPress 7.2 and PHP 8.4. Network-activated only. Works with or without Multisite Language Switcher.

Version 0.5.0 lets Windrose Sabir's Copy make network items for images of a draft not published yet on the hub (`Proxy::create( $hub_id, $parent_id, true )`, only for people who may read that draft there; since 0.6.0 this needs no third argument and such items are listed to those people on other sites, see below). Version 0.4.0 adds **several media hubs**: the main site is still the hub of every site by default, but other sites can be hubs too, for example the main site of each language project, each with its own shared media. With Windrose Sabir, hubs can follow the language groups. Version 0.3.0 added the inserter sources, Usage, Leave safely and every media type the same.

Tested on single-network installs only; each network of a multi-network install would have its own hubs, but this is not tested yet.

## What it does

- **Network admin, Windrose Anchors, Sites**: a **Media hubs** card (the hubs, each linked to its Media Library, and, when Windrose Sabir is active, **Follow language groups**), then the sites (DataViews table with search): Allow and Stop allowing (also bulk), **Choose media hub** (also bulk), **Make media hub**, **Stop being a media hub**, and a Media hub column. The main site is always a hub and the default hub of every site; hubs are always allowed. A site that is no longer allowed loses the Network tab; the network media it already uses keeps working.
- **Several hubs**: every site takes network media from one hub: itself when it is a hub, else its language group's main site (while groups are followed; filter `windrose_anchors_group_hubs`, answered by Windrose Sabir), else the hub chosen for it, else the main site. Each network item keeps the hub it was made from (`_msls_linked['blog']`), so changing a site's hub, or making it a hub, never breaks the items it holds; new ones come from the new hub. A hub stops being one only while no other site uses its items (Leave safely first); following language groups stops only under the same condition for group hubs. Hubs serve sites of their own network only.
- **Main site, sharing**: "Share with the network" in the attachment details (media dialog, Media Library grid, edit screen), "Share with the network" / "Stop sharing" as row actions and bulk actions in the list view, a **Network** column naming the sites that use each item (linked to their Media Library), and filters "Shared with the network", "Used by other sites", "Not shared" (list view menu next to the dates, grid view type menu). A "Shared" badge on shared tiles. Files uploaded while the Network tab of the media dialog is open are shared at once (classic uploader and the REST uploads of 7.2's block editor). Stopping is refused while sites use the item.
- **Every allowed site, media dialog**: tabs "Upload files", "This site" (core's "Media Library") and "Network", in every dialog that browses a library (image block, gallery, featured image...). Network items carry negative IDs (minus the hub ID); picking one asks the site for its network item (created once, then reused) and puts that local item in the selection. Search and media type filters run on the server; pages of 40.
- **Media, Network media** on every allowed site except the main site: the main site's shared items (DataViews grid or table, search, filter "On this site" / "Not on this site yet"), each with **Add to this site** (also as a bulk action) or "On this site" and a **Show** link.
- **Media Library on other sites**: a **Network** column ("From the network", and the fields changed here), a "From the network" badge on tiles, filters "From the network" / "Not from the network"; "Delete from this site" instead of "Delete permanently" on network items, with a confirmation saying the file stays on the main site.
- **Network items** ("proxies") show "From the network" and the fields changed on the site in their attachment details, with **Reset to network values**. They have no "Edit Image": cropping one in the block editor creates a normal local copy.
- **Guards**:
  - deleting a network item never deletes a hub file: its path is a virtual one inside the site's own uploads (`windrose-anchors/<hub ID>/<file name>`, never created), and no file is deleted at all while it is being deleted (from `delete_attachment` until the `clean_post_cache` that follows `deleted_post`). The same guard covers attachments linked to another site's attachment (`_msls_linked`);
  - a main site item that sites use cannot be deleted, trashed or edited in the image editor, by anyone (super admins included). The REST API answers 409 with the list of sites;
  - deleting a site removes it from every item's usage and from the allowed sites.
- Other sites never see private media. **Media attached to an unpublished post** of the hub (not in the trash) is listed only to the people who may read that post on the hub (`read_post`: its author, the hub's editors), in the Network tab, the inserter and Media, Network media, marked "Not published yet" with the reason (`windroseAnchors.unpublished`); they alone can make its network item (`Proxy::create()`, 404 for everyone else). The readable posts are resolved first (`Hub::readable_unpublished_parents()`, the 200 newest) and passed to the query as IDs: `read_post` cannot be asked in SQL, and filtering after the query would make the totals and pages wrong. **Afterwards the network item is like any other**: it is a media item of its site, seen by that site's users, and it stays when the hub post is never published or goes to the trash; the hub item cannot be deleted or stop being shared while a site uses it (as for every item). The hub's own messages say so: the attachment's Network field, and "Hidden from other sites (private, or shown only to readers of its draft)" on the Usage screen.
- **Block inserter, Media tab** (allowed sites): **Network images**, **Network videos**, **Network audio** in 7.2's "Media source" menu (only for the types the main site shares: the inserter takes no other type), with search and pages. Documents and other files come from the media dialog's Network tab (File block, and every block or plugin that opens WordPress's media dialog). Items carry a placeholder ID (minus the hub ID): the editor inserts an item with an ID as is (one without would be downloaded and uploaded as a copy). Right after insertion, by click or drag, `src/inserter` swaps the placeholder for the site's network item (`proxies`, created once, then reused; image, video, audio and file blocks); the editor's request for the placeholder's media details waits for the swap instead of failing; if the swap fails, the block is removed with a notice. On save, any placeholder left in content (`"id":-<n>` of image, video, audio and file blocks, `wp-image--<n>`) becomes the site's network item, or is removed where none can be made (site not allowed, no `upload_files`, item not shared).
- **Network admin, Usage** (DataViews, two tabs): **Shared items** (sites using each, number of network items, what to look at) and **Network items on sites** (site, main site item, fields changed here, where it is used, notes). Notes: changed locally, not found in content (found the way WordPress's coming media usage detection finds media, Gutenberg pull request 84300: media blocks' ID attributes, `wp-image-`/`wp-attachment-` markers, `[gallery ids]`, the item's address, featured images, site logo, icon and classic logo; filter `windrose_anchors_media_id_attributes` for other blocks), duplicates (two network items of one hub item on a site), deleted on the main site, no longer shared, hidden from other sites, usage record out of date. **Repair usage records** rewrites `_windrose_anchors_usage` from what the sites hold. **Delete from its site** (also in bulk, with a confirmation) for network items not found in content: checked again on the server, rights checked on each site, the main site's file kept. On the main site, items other sites use count as used for the coming core detection (filter `gutenberg_used_attachment_ids`).
- **Network admin, Leave safely**: a dry run of every site's network items (new file, files and size to copy, content to update, problems), for every site or one; with a backup confirmed, **Convert** (batches of 20 per request). Each network item: the hub file, its original and every size are copied into the site's uploads, in the same year/month folder (a name that exists there gets a number: `canola-1.jpg`, `canola-1-300x225.jpg`); the item gets its own `_wp_attached_file` and `_wp_attachment_metadata` and loses `_windrose_anchors_hub`, `_windrose_anchors_overridden` and the matching `_msls_linked` (same ID, title, alternative text, caption, description); the site's content (all post types, revisions included, also JSON-escaped URLs) points to the copies, on the site's own address; the hub item's usage loses the site. A failed copy leaves the item as it was. By default the site (or every site) stops being allowed first. Main site files are never changed.
- **Network Plugins screen**: links to Usage and Leave safely, and a warning under the plugin while other sites use network items.

## Data

| Where | Key | Holds |
|---|---|---|
| Network option | `windrose_anchors_sites` | IDs of the allowed sites (hubs are not stored: always allowed) |
| Network option | `windrose_anchors_hubs` | IDs of the sites marked as media hubs (the main site is not stored: always a hub) |
| Network option | `windrose_anchors_site_hubs` | Site ID => hub site ID, for sites whose hub is not the main site |
| Network option | `windrose_anchors_follow_groups` | `1` while each site of a language group uses its group's main site as hub |
| Main site attachment | `_windrose_anchors_shared` | `1` when shared |
| Main site attachment | `_windrose_anchors_usage` | IDs of the sites holding a network item of it |
| Network item | `_windrose_anchors_hub` | ID of the main site attachment it shows |
| Network item | `_msls_linked` | `['blog' => <main site ID>, 'post' => <hub ID>]`, Multisite Language Switcher's format |
| Network item | `_windrose_anchors_overridden` | Fields changed on the site: `title`, `alt`, `caption`, `description` |

A network item never stores `_wp_attached_file`, `_wp_attachment_metadata` or `_wp_attachment_backup_sizes` (writes are refused). At read time, `wp_get_attachment_url`, `get_post_metadata` (for `_wp_attachment_metadata`), `wp_calculate_image_srcset`, `get_attached_file` and `load_image_to_edit_path` give the hub's URL, sizes and dimensions, `srcset` and real file for editing. Main site URLs are built on the main site's own address (inside `switch_to_blog()`, `WP_CONTENT_URL` still belongs to the requested site, which breaks with domain mapping). So regenerating thumbnails on the main site updates every site.

Hub items are cached in the global object cache group `windrose_anchors`, one entry per hub and item (`item:<hub site>:<ID>`, so two hubs, or two networks, never share an entry), cleared when the item or its meta changes. Without a persistent object cache, each item is read once per request.

**Uninstall** removes `windrose_anchors_sites`, `windrose_anchors_hubs`, `windrose_anchors_site_hubs`, `windrose_anchors_follow_groups` and the cached update check. It keeps the meta above: without the plugin, network items have no file of their own, so run Leave safely before deactivating on a network that uses them.

REST routes (`windrose-anchors/v1`, always on the current site, which reads the main site server side):

| Route | Methods | Permission (current site) |
|---|---|---|
| `network` | GET (`page`, `per_page`, `search`, `type`, `local` = yes or no) | `upload_files`, site allowed |
| `proxies` | POST (`hub`, `post`) | `upload_files`, site allowed, not the main site; `edit_post` on `post` |
| `proxies/<id>/reset` | POST | `edit_post` on the network item |
| `sites` | GET (`page`, `per_page`, `search`; each site with `status`, `hub`, `hubRole`), POST (`sites`, `allowed`) | `manage_network_options` |
| `hubs` | GET (hubs with `role` main, marked or group; `follow`; `groupsAvailable`), POST (`site`, `hub`) | `manage_network_options` |
| `hubs/choose` | POST (`sites`, `hub`) | `manage_network_options` |
| `hubs/follow` | POST (`follow`) | `manage_network_options` |
| `usage` | GET (the whole report) | `manage_network_options` |
| `usage/repair` | POST | `manage_network_options` |
| `usage/delete` | POST (`items`: `site`, `id`, at most 50) | `manage_network_options`; on each item's site `delete_post` |
| `leave` | GET (`site`, 0 for every site: dry run), POST (`site`, `ids`, at most 20) | `manage_network_options`; on each item's site `upload_files` and `edit_post` |

Core's `/wp/v2/media` also gets a `windrose_anchors` field (people who may upload files: on the main site `shared` and `usage`, elsewhere `network`, `hub`, `overridden`) and a `windrose_anchors` parameter (`shared`, `used`, `not-shared` on the main site; `network`, `local` elsewhere), for core screens and the DataViews Media Library WordPress is building on that route.

Windrose Common (the shared update library) is bundled in `common/`, copied from Windrose Helm with `windrose-helm/bin/sync-common.sh`; never edit it here.

## Releasing

1. Set the version in the plugin header and the `WINDROSE_ANCHORS_VERSION` constant (and in `stubs/constants.php`, `package.json`, `readme.txt`); `npm run build`; run the checks; commit.
2. Wait for the green CI run of that commit, then tag `vX.Y.Z` and push the tag. `.github/workflows/release.yml` builds `windrose-anchors.zip` with `bin/build-zip.sh` and publishes the release.

## Development

JavaScript in `src/` (built into `build/`, committed): `src/network` (network admin screens Sites, Usage, Leave safely: one bundle) and `src/library` (Media, Network media), React with WordPress components and DataViews (bundled: WordPress does not provide it); `src/media` (the media dialog and Media Library grid, Backbone); `src/inserter` (the block inserter's Network source).

```
npm install
npm run build
npx wp-scripts lint-js src
npx wp-scripts lint-style "src/**/*.scss"
```

PHP checks: `composer install`, then `vendor/bin/phpcs --standard=phpcs.xml.dist` and `vendor/bin/phpstan analyse -c phpstan.neon.dist` (PHP 8.4). PHPUnit runs in multisite mode against the WordPress test library (`bin/install-wp-tests.sh <folder>` installs it with a test database; `WP_TESTS_DIR` points to it), never on real data. The Playwright tests (`tests/e2e`) run on a WordPress multisite test network with WP-CLI (main site, `/fr/`, `/ar/`), reached at `WINDROSE_E2E_HOST` with the administrator `WINDROSE_E2E_USER` and password `WINDROSE_PASS`; they delete everything they create.

On GitHub, `.github/workflows/ci.yml` runs on every push to `main` and every pull request: `php -l` on PHP 8.4 and 8.5, PHPCS, PHPStan (PHP 8.4), JavaScript and style lint with a fresh build equal to the committed `build/`, PHPUnit multisite on PHP 8.4 and 8.5 against the WordPress nightly.

## Licence

GPL-2.0-or-later.
