# Release Notes

## Unreleased

First release.

- **"Used in" panel in the asset editor** — every entry, global set, taxonomy term, navigation, user, asset and form submission that references the asset, grouped by site.
- **A usage column and a Used / Unused filter** in the asset browser.
- **An overview under Tools** — filter by container, site, usage and path, sort by name or usage count, expand a row to see where an asset is used, and delete unused assets one by one, per selection, or all of them at once.
- **Reference detection** for every format Statamic understands, plus plain asset URLs typed into text fields (`scan_urls`), anywhere in your content including nested Grid, Replicator, Group and Bard data.
- **Incremental updates** on content saves and deletes, so the usage data stays accurate without a manual rebuild.
- **Out-of-date detection** — changing the containers, `scanned_types`, `scan_urls` or `include_working_copies` marks the usage data stale, and while it is, deleting is blocked.
- **Commands** — `php please asset-usage:index`, `asset-usage:unused` (with `--delete`) and `asset-usage:doctor`.
- **Config** for the asset containers to cover, the content types to scan, plain-URL scanning, working copies, where the CP shows usage, `ignore` patterns and a minimum age before an asset can be reported as unused.
