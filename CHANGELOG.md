# Release Notes

## 1.3.1 (2026-10-06)

### What's new
- Compress all: a button on the Compression page that compresses every image that can get smaller, after a warning, with a progress bar.
- New permission: View log. The Log page now needs it; give it to roles that should keep seeing the log.

### What's improved
- The Compression page is only there for users with the Compress images permission. Without it the page had nothing to do; the Saving column on the Overview stays.

### What's fixed
- The asset editor shows the Compression part for every image, also when compressing wouldn't help or the image wasn't analysed yet, instead of nothing.

## 1.3.0 (2026-10-06)

### What's new
- Compress images from the Tools page or the asset editor. Images that can get smaller get a View compression button, which compares the original and the result first, with a slider or side by side. The original is kept for 30 days and can be restored.
- The Tools page says when the image driver's PHP extension, a format or pngquant is missing on the server.
- New `compression` settings in the config, added to a published config by the update script.
- New permission: Compress images.
- A Compression page with the images that can get smaller and the ones the addon compressed.
- A log of every compression and every deleted asset, wherever it was deleted, with who did it and whether a deleted asset was still in use.
- New commands: `asset-usage:analyze`, `asset-usage:prune-originals` and `asset-usage:log`.
- Sort the asset browser by the "Used" column (flat-file sites).
- In the asset editor, usage and compression sit in a section of their own, headed with the addon's name.
- The Tools page lists assets in columns: name, size, resolution, DPI, saving, last modified and usage. Click a column heading to sort by it, and click again to reverse the order.
- Resolution for images, and DPI for JPEG and PNG files that declare one.

### What's improved
- "Used in X places" opens the details itself, instead of a separate Details button.
- `asset-usage:index` and `asset-usage:unused` show a progress bar.

## 1.2.0 (2026-09-23)

### What's new
- Sort the Tools page by date: newest first or oldest first.
- German, French, Spanish and Italian translations.

### What's fixed
- The first content save on a fresh install builds the usage data, instead of being ignored until it was built by hand.
- The Tools page no longer shows assets as unused before the usage data has been built.

### What's improved
- Faster asset browser and Tools page on sites with many assets.

## 1.1.3 (2026-09-23)

### What's fixed
- Fixed the timezone and locale formatting of "Last updated" on the Tools page.

### What's improved
- Removed unused code.

## 1.1.2 (2026-09-16)

### What's fixed
- **The Tools page respects the asset permissions per container.** A user with "View asset usage" saw every container's files and where they were used, including containers whose assets they aren't allowed to view. The overview, its container filter and "delete all unused" now only cover containers the user can view in the asset browser.

### What's improved
- The Composer package no longer ships tests, CI workflows and build tooling, cutting the download from roughly 120 KB to 70 KB

## 1.1.1 (2026-09-04)

### What's new
- **The Tools page says when the usage data is due a rebuild.** How long that takes depends on `auto_update`: with it on the index is patched on every save, so the reminder waits 30 days and is only about what fires no event, such as files put on the disk directly. With it off nothing updates the index in between, so it waits 7. Both numbers live in `rebuild_reminder_days`, and either at 0 turns the reminder off.

### What's fixed
- **Nothing can be deleted before the first build.** Without usage data every asset looked unreferenced, so the Tools page offered deleting and put a count on "delete all unused" covering the whole library. The delete endpoints refused, but only after the fact. Assets are now blocked one by one with the reason, and the unused count is 0 until there is data behind it.
- A user with neither a name nor an email no longer takes down the whole scan.
- An update script adds `rebuild_reminder_days` to a published config. The addon falls back to its own defaults without it, so this is about the file showing everything there is to configure.
- Console output is English throughout. A few messages were reaching for a translation key meant for the Control Panel, so `asset-usage:unused` mixed the site's language into an otherwise English report.

## 1.1.0 (2026-09-04)

### What's new
- **Cascade, addon settings and blueprint defaults are scanned.** An asset can be referenced without ever being saved onto a single entry: in a collection's or taxonomy's cascade (`inject`), in what an addon saves under `resources/addons/`, or as a field's `default` in a blueprint or fieldset. All four are now indexed and kept in sync, so assets referenced that way are no longer reported as unused.
- `asset-usage:doctor` lists the content types being scanned and warns about any your published config doesn't mention.
- The Tools page and `asset-usage:unused` now say what an "unused" verdict does not cover (templates, Glide URLs, and data an addon keeps in its own store), so the caveat is where you act on the list, not only in the README.

### Upgrading
`scanned_types` gained `collection_cascades`, `taxonomy_cascades`, `addon_settings` and `blueprints`. Laravel merges that config key as a whole, so a config you published earlier would leave the new sources switched off. An update script adds the four keys to your published config on `composer update`. Only the ones that are missing; nothing else in the file is touched. Set any of them to `false` if you'd rather leave that source out, then refresh the usage data with `php please asset-usage:index`.

If the script can't find the `scanned_types` array (a config rewritten by hand), it says so and leaves the file alone; `php please asset-usage:doctor` names the keys to add.

## 1.0.1 (2026-08-13)

### What's fixed
- Publishing the config wrote the file to `config/asset-usage.php` as well as `config/statamic/asset-usage.php`. Only the latter is read; the stray copy is safe to delete.

## 1.0.0 (2026-08-13)

First release.

- **"Used in" panel in the asset editor**: every entry, global set, taxonomy term, navigation, user, asset and form submission that references the asset, grouped by site.
- **A usage column and a Used / Unused filter** in the asset browser.
- **An overview under Tools**: filter by container, site, usage and path, sort by name or usage count, expand a row to see where an asset is used, and delete unused assets one by one, per selection, or all of them at once.
- **Reference detection** for every format Statamic understands, plus plain asset URLs typed into text fields (`scan_urls`), anywhere in your content including nested Grid, Replicator, Group and Bard data.
- **Incremental updates** on content saves and deletes, so the usage data stays accurate without a manual rebuild.
- **Out-of-date detection**: changing the containers, `scanned_types`, `scan_urls` or `include_working_copies` marks the usage data stale, and while it is, deleting is blocked.
- **Commands**: `php please asset-usage:index`, `asset-usage:unused` (with `--delete`) and `asset-usage:doctor`.
- **Config** for the asset containers to cover, the content types to scan, plain-URL scanning, working copies, where the CP shows usage, `ignore` patterns and a minimum age before an asset can be reported as unused.
