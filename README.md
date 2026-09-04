# Asset Usage

> See where every asset is used, and find the ones that aren't used at all.

A Statamic 6 Control Panel addon for cleaning up your asset containers. It shows you where an asset is used, so you can delete the files nothing needs without guessing.

- **"Used in" panel in the asset editor**: the entries, globals, terms, navs, users and form submissions that reference this asset, as links, grouped by site.
- **A "Used" column in the asset browser**: a tick or a cross, so one glance tells you which files are orphans.
- **A Used / Unused filter** in the browser.
- **An overview under Tools**: filter by container, site, usage and path, sort by name or by how much an asset is used, expand any asset to see where it's used, and delete the ones nothing needs.
- **`please` commands** for reporting and cleaning up from the CLI.

## Works on flat-file and database sites alike

Content is read exclusively through Statamic's repositories (`Entry::query()`, `Term::query()`, `GlobalSet::all()`, …), never by scanning the `content/` directory. So on a site using [the eloquent driver](https://statamic.dev/tips/eloquent-driver), where entries live in the database rather than on disk, everything is found just the same. And rather than only telling you *whether* an asset is used, it tells you *where*.

## Installation

```bash
composer require keyagency/statamic-asset-usage
php please asset-usage:index
```

The second command builds the usage index. After that the addon keeps itself up to date as you edit content.

## What counts as "used"

Every reference format Statamic itself understands, found anywhere in your content, at the top level or nested inside Grid, Replicator, Group and Bard sets:

| Where | What it looks like |
|---|---|
| Asset / file fields | `img/photo.jpg` (a path relative to the container) |
| Link fields, Bard images, Bard link marks | `asset::main::img/photo.jpg` |
| Markdown and Bard HTML | `statamic://asset::main::img/photo.jpg` |
| Any text, textarea, markdown or HTML field | `/assets/img/photo.jpg`, `https://example.com/assets/img/photo.jpg` |

That last row is off in Statamic's own reference tracking but on by default here (`scan_urls`), because a hand-typed URL is exactly the case where you'd otherwise delete a file that was in use.

Scanned content types: entries (including unpublished working copies), global sets, taxonomy terms, navigations, users, other assets' own fields, and form submissions.

An asset can also be referenced without ever being saved onto a single item, so these are scanned too:

| Where | What it covers |
|---|---|
| Collection and taxonomy cascades | The `inject` values that fall through to every entry or term, where an addon such as an SEO one keeps its per-section defaults |
| Addon settings | What addons save in `resources/addons/{slug}.yaml`, which is where a site-wide default image usually lives |
| Blueprint and fieldset defaults | A field's `default` holds an asset until something is saved over it, and on a field nobody touches it holds it for good |

`scanned_types` is the complete set, so listing only the types you want is enough. Anything left out is off. That also means a config you published before a type existed would leave that type switched off, so updates add new keys to your published config for you. `php please asset-usage:doctor` names any your config is still missing.

### What it doesn't see

- References in **Antlers/Blade templates** or in PHP config files. This is a content scanner, not a codebase scanner. Protect those files with the `ignore` config.
- Data an addon keeps in **its own store**: its own YAML files, or its own database tables. Addon settings and blueprint defaults are covered; a private store is not.
- Settings of an addon that **overrides its slug**. Statamic writes those under the slug but looks them up under the package name, so the file can't be resolved and nothing in it is scanned.
- **Glide-transformed URLs** (`/img/asset/…`), which live in templates rather than content.
- A bare path that exists in **two enabled containers** is counted as used in both. Over-reporting is deliberate: you should never lose a file because the addon guessed wrong.

### Where the column appears

The column arrives through the container's blueprint, and Statamic renders blueprint columns before its own File / Size / Last Modified, and there's no hook to change that. If you'd rather have it at the end, use **Customize Columns** in the browser and drag it there; Statamic remembers the order per user.

The column deliberately shows only a tick or a cross. A count or a list of sites made rows wide enough to push the filename off screen; the numbers live in the editor panel and on the Tools page.

## Multisite

Every usage records the site of the item it was found in, so an asset used only in your Dutch entries shows `Nederlands` and nothing else. An asset counts as unused only when no site uses it. Items that have no site of their own (users, other assets, form submissions) are grouped under "All sites".

## The index

Scanning a whole site per page load isn't viable, so usage lives in an index at `storage/statamic/asset-usage/index.json`. It is:

- built by `php please asset-usage:index`, or from the **Refresh usage data** button on the Tools page;
- patched incrementally whenever content is saved or deleted, so it stays accurate on its own;
- marked out of date automatically when you change a setting it was built with: the enabled containers, `scanned_types`, `scan_urls` or `include_working_copies`. While it's out of date the CP says so, the browser filter hides itself, and nothing can be deleted;
- never built implicitly while rendering a page. Before the first build the column and the panel say "not checked yet" rather than pretending everything is unused.

Which assets exist is read through the container's asset query, the same source the asset browser uses, not through the container's file listing, which on the eloquent driver reports the container root only.

The incremental updates run through a queued listener (like Statamic's own reference updating). On the `sync` queue that means inline; with a real queue connection you need a worker running, or set `auto_update` to `false` and rebuild on a schedule instead.

## Commands

```bash
# Rebuild the whole index
php please asset-usage:index
php please asset-usage:index --queue    # hand it to a worker

# Diagnose what the addon sees per container
php please asset-usage:doctor
php please asset-usage:doctor --folders

# Report unused assets
php please asset-usage:unused
php please asset-usage:unused --container=main --json
php please asset-usage:unused --older-than=30 --ignore='*.pdf'

# Clean up
php please asset-usage:unused --delete           # asks first
php please asset-usage:unused --delete --force   # doesn't
php please asset-usage:unused --fresh --delete   # rebuild the index first
```

`--delete` refuses to run against an out-of-date index, and re-checks every asset immediately before removing it.

`asset-usage:doctor` is for when the numbers look wrong. Per container it prints what the filesystem holds (split into root and subfolders), what the asset query finds, what Statamic's own file listing reports, how many paths resolve to an actual asset and how many are recorded as used. It also flags files that exist on disk but aren't known as assets: on the eloquent driver those have no row in the `assets` table, so nothing can report on them until `php please eloquent:import-assets` brings them in.

Above the per-container output it lists the content types actually being scanned, and warns about any that your published config doesn't mention, because those are switched off, which is the usual reason a whole class of references seems to go unnoticed.

## Deleting

Deleting is available from the Tools page (per row, for a checkbox selection, and as **Delete all unused**, which covers every unused asset the active filters match rather than only the page you're looking at, up to 500 per click, so a big cleanup takes a few) and from the CLI. Everything goes through the same rails:

- the `delete unused assets` permission, on top of Statamic's own per-container asset permissions;
- the index must be current;
- the asset must have zero usages, so a used asset can't be deleted from here at all;
- `ignore` patterns and `minimum_age_in_days` are enforced server-side, not just in the UI.

## Configuration

```bash
php artisan vendor:publish --tag=asset-usage-config
```

```php
return [
    // '*' for all containers, or ['main', 'documents']
    'containers' => '*',

    // The complete set: a type you leave out is not scanned
    'scanned_types' => [
        'entries' => true,
        'globals' => true,
        'terms' => true,
        'navs' => true,
        'users' => true,
        'assets' => true,
        'form_submissions' => true,
        'collection_cascades' => true,
        'taxonomy_cascades' => true,
        'addon_settings' => true,
        'blueprints' => true,
    ],

    // Also count plain /assets/… URLs typed into text fields
    'scan_urls' => true,

    // An asset in an unpublished draft counts as used
    'include_working_copies' => true,

    // Keep the index in sync on content saves
    'auto_update' => true,

    'editor_panel' => true,
    'listing_column' => true,

    // Never reported as unused, e.g. ['*.pdf', 'downloads/*']
    'ignore' => [],

    // Assets younger than this are never reported as unused
    'minimum_age_in_days' => 0,
];
```

## Permissions

- **View asset usage**: the Tools page.
- **Delete unused assets**: the delete buttons and the destroy endpoint.

The "Used in" panel and the browser column follow Statamic's normal asset permissions; if you can see the asset, you can see its usage.

## License

Proprietary. © Key Agency.
