=== Windrose Anchors - Network Media ===
Contributors: patricia70
Tags: multisite, media library, network media, shared media
Requires at least: 7.2
Tested up to: 7.2
Requires PHP: 8.4
Stable tag: 0.6.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Network media for multisite: the main site's media library (or that of other media hubs) shared with a Network tab and Network sources in the block inserter on every allowed site.

== Description ==

The main site, and any other site made a media hub, shares media items with the network ("Share with the network" in the attachment details, or one by one and in bulk in the media list, which also says which sites use each item). On every site a network administrator allows, the media dialog gets a **Network** tab next to "Upload files" and "This site", and Media gets a **Network media** page listing every shared item with "Add to this site". In the Media Library, network items are marked "From the network" and can be filtered.

Picking a network item adds it to that site as a normal media item with its own title, alternative text, caption and description, so each language site can describe the same image in its own language. The file stays on the main site and is never copied; sizes and dimensions always come from the main site.

Safety:

* Deleting a network item on a site ("Delete from this site") never deletes the media hub's file.
* A media hub item that other sites use cannot be deleted, trashed or edited in the image editor; WordPress says which sites use it.
* Network items cannot be edited in the image editor; cropping one in the block editor creates a local copy.
* Other sites never see private media. Media attached to a post that is not published is shown only to the people who can read that post on the media hub, marked "Not published yet". Once such an item is added to a site, it is a media item of that site like any other.

Works on any multisite network, with or without Multisite Language Switcher, and on mapped domains (the media dialog only talks to its own site).

Every media type is treated the same. In the block editor, the inserter's Media tab gets **Network images**, **Network videos** and **Network audio** sources (in the "Media source" menu, for the types the main site shares): inserting an item there, by click or drag, gives the block the site's own network item, never a copy. Documents and other files, which the inserter does not list, come from the media dialog's Network tab, in the File block for example; the Video and Audio blocks and plugins that open WordPress's media dialog get the same tab.

For network administrators:

* **Usage**: every shared item, the sites that use it, and what to look at: items changed locally, network items not found in any content (found the way WordPress's coming media library finds unused media), duplicates, usage records out of date (with a repair button). Network items not found in any content can be deleted from their site there.
* **Leave safely**: before deactivating Windrose Anchors, or exporting one site alone, it turns network items into normal media items of their site. The file and its sizes are copied into that site's uploads folder and the site's content points to the copies. A dry run comes first; it runs for one site or for every site. The main site's files are not changed.

Windrose Anchors is part of the Windrose Suite, a free set of plugins and themes for WordPress networks: https://www.webpresencecare.com/windrose/ . Each Windrose plugin and theme works on its own.

Source code, issues and build steps: https://github.com/webpresencecare/windrose-anchors

Security: please report vulnerabilities privately through GitHub: https://github.com/webpresencecare/windrose-anchors/security/advisories/new . Never in a public support thread.

== External services ==

None. Windrose Anchors only reads the media hubs of the same network, on the server. The bundled update library contacts the GitHub API (api.github.com) to check for and download updates of Windrose plugins: sent are the repository name and a "Windrose" User-Agent. GitHub terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service, privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

== Privacy ==

Windrose Anchors collects nothing about visitors and sets no cookies. Suggested privacy policy text is added under Settings, Privacy, Policy guide.

== Uninstall ==

Uninstalling removes the list of allowed sites and the media hub settings. It keeps the information that links network items to their media hub: without the plugin, network items have no file of their own. Before deactivating on a network that uses network media, run Leave safely (Network admin, Windrose Anchors, Leave safely); the network Plugins screen reminds you while sites use network items.

== Frequently Asked Questions ==

= Is this plugin written with AI? =

Yes. I write the Windrose plugins and themes with Claude Code, Anthropic's AI coding assistant: I design each feature, test it on real sites and review every release, and every commit written with Claude says so. Independent reviews are welcome on GitHub.

== Changelog ==

= 0.6.1 =
* Windrose Common 1.3.0: updates come from the public GitHub repositories, without a token (WordPress 7.2 RC no longer has the Secrets API, where the token was kept).

= 0.6.0 =
* A shared media item attached to a post that is not published yet on its media hub is now offered on other sites to the people who can read that post there (its author, the hub's editors), so both languages of a post can be prepared as drafts. It is marked "Not published yet". Everyone else sees what they saw before.
* Fixed: shared items created in the same second could be repeated on one page of the Network tab and missing from the next.
* Tests for Replace in the Image block, galleries, more pages and search in the Network tab.

= 0.5.3 =
* Fixed: the block editor no longer stops with an error when the media dialog opens on the Network tab (the tab WordPress remembered from last time).
* Fixed: WordPress now remembers the Network tab. Its ID had a hyphen, which WordPress removes when it saves the setting, so the dialog came back empty with no tab selected. A dialog that remembers the old ID opens on "This site".

= 0.5.2 =
* Usage: content is read with WordPress's block processor, without building the whole block tree; same results.
* Notices use WordPress's own notice function (`wp_admin_notice()`), also the warning on the Plugins screen.
* Code: all WordPress packages on their newest releases (DataViews 22).

= 0.5.1 =
* A site whose media items other sites use can no longer be deleted (WordPress would delete its uploads folder, and with them the files those sites show): run Leave safely for those sites first. Network Admin, Sites still says "deleted", but the site stays; WP-CLI shows the reason.
* A language group's main site that stopped being a hub (Windrose Sabir deactivated, or the group changed) is no longer forgotten: deleting a site that used its items frees them, and edits to its items reach the other sites at once.
* Leave safely reads each site's posts once per network item instead of twice per file address, so it no longer runs out of time on large sites.
* The Usage report loads the hub items in two queries instead of one per item.
* Windrose Common 1.2.0.

= 0.5.0 =
* With Windrose Sabir: copying a draft to another language can use network items for its images, even before the post is published on the media hub (only for people who may read that draft). Such items are still listed to no other site.

= 0.4.0 =
* Several media hubs: any site can be a media hub, sharing items of its own media library; each site uses one hub (the main site by default). Network admin, Windrose Anchors, Sites: Make media hub, Stop being a media hub, Choose media hub, and a Media hub column.
* With Windrose Sabir: Follow language groups, so each site of a language group uses its group's main site as media hub.
* Network items keep the hub they come from, so changing a site's hub never breaks them. Usage and Leave safely work for every hub.
* Hub items are cached per hub (two hubs never share a cache entry).

= 0.3.0 =
* Every media type the same: Network videos and Network audio sources in the block inserter (next to Network images); the media dialog's Network tab checked in the Video, Audio and File blocks and in dialogs opened by other plugins.
* Leave safely: clearer wording of the first option ("First remove the Network tab from...").
* Usage: "Not found in content" finds media the way WordPress's coming media library will (all media blocks, classic markup, gallery shortcodes, addresses, featured images, logo, icon); network items not found in content can be deleted from their site.
* WordPress's media REST route (/wp/v2/media) tells which items are shared, used or from the network, and filters on it, for core screens and the coming DataViews media library.
* On the main site, items other sites use count as used for WordPress's coming "Unused" media filter.

= 0.2.0 =
* Block inserter: a "Network" media source; inserted items become the site's own network item (also when dragged), and content saved with a placeholder left in it is fixed on save.
* Network admin, Usage: shared items, sites using them, local changes, items not found in content, duplicates, usage records out of date (repair).
* Network admin, Leave safely: dry run, then network items become normal media items of their site (files copied, content updated), for one site or every site.
* Network Plugins screen: links to Usage and Leave safely, and a warning while sites use network items.

= 0.1.0 =
* First version: allowed sites, sharing on the main site (also in bulk), Network tab in the media dialog, Media, Network media page, Network column, badges and filters in the Media Library, network items with local title, alternative text, caption and description, Reset to network values, deletion and editing guards.
