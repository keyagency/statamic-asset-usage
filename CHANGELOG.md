# Release Notes

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
