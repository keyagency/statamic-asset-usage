# Asset Usage

[![Statamic Marketplace](https://img.shields.io/badge/Statamic-Marketplace-orange.svg)](https://statamic.com/addons/key-agency/asset-usage)
[![Latest Version](https://img.shields.io/github/v/release/keyagency/statamic-asset-usage?label=version&color=blue)](https://github.com/keyagency/statamic-asset-usage/releases)
[![Tests](https://github.com/keyagency/statamic-asset-usage/actions/workflows/tests.yml/badge.svg)](https://github.com/keyagency/statamic-asset-usage/actions/workflows/tests.yml)

> See where every asset is used, and find the ones that aren't used at all.

A Statamic 6 Control Panel addon for cleaning up your asset containers. It shows you where an asset is used, so you can delete the files nothing needs without guessing, and it shrinks the images that are far heavier than they need to be.

- **"Used in" panel in the asset editor**, in a section of its own: the entries, globals, terms, navs, users and form submissions that reference this asset, as links, grouped by site.
- **A "Used" column in the asset browser**: a tick or a cross, so one glance tells you which files are orphans.
- **A Used / Unused filter** in the browser.
- **An overview under Tools**: filter by container, site, usage and path, sort by any column (name, size, resolution, DPI, saving, date or usage), expand any asset to see where it's used, and delete the ones nothing needs.
- **Image compression**: images that can get smaller get a View compression button. A before and after page shows the result first, and the original is kept so it can be put back. Or compress a selection, or all of them at once, after a warning.
- **A log** of every compression and every deleted asset, with who did it.
- **`please` commands** for reporting, cleaning up and compressing from the CLI.

## Works on flat-file and database sites alike

Content is read exclusively through Statamic's repositories (`Entry::query()`, `Term::query()`, `GlobalSet::all()`, …), never by scanning the `content/` directory. So on a site using [the eloquent driver](https://statamic.dev/tips/eloquent-driver), where entries live in the database rather than on disk, everything is found just the same. And rather than only telling you *whether* an asset is used, it tells you *where*.

## Installation

```bash
composer require keyagency/statamic-asset-usage
php please asset-usage:index
```

The second command builds the usage index from your existing content. Skip it and the first content save builds it instead. After that the addon keeps itself up to date as you edit content.

For image compression two things are worth setting up, though neither is required:

- [pngquant](https://pngquant.org) on the server (`brew install pngquant`, `apt install pngquant`). Without it PNGs are only resized and saved losslessly.
- Laravel's scheduler (a cron entry running `php artisan schedule:run` every minute), which clears the kept originals once they are older than `keep_originals_days`. Without it they stay until you run `php please asset-usage:prune-originals`.

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

Click its heading to sort by how often an asset is used, so the unused ones come first. That works on flat-file sites; on the eloquent driver the column can't be sorted, because the database has no column to order by.

The column deliberately shows only a tick or a cross. A count or a list of sites made rows wide enough to push the filename off screen; the numbers live in the editor panel and on the Tools page.

## Multisite

Every usage records the site of the item it was found in, so an asset used only in your Dutch entries shows `Nederlands` and nothing else. An asset counts as unused only when no site uses it. Items that have no site of their own (users, other assets, form submissions) are grouped under "All sites".

## The index

Scanning a whole site per page load isn't viable, so usage lives in an index at `storage/statamic/asset-usage/index.json`. It is:

- built by `php please asset-usage:index`, or from the **Refresh usage data** button on the Tools page;
- patched incrementally whenever content is saved or deleted, so it stays accurate on its own;
- built automatically by the first content save when there is none yet (with `auto_update` on), so a fresh install doesn't depend on someone running the command first. On the `sync` queue that one save waits for the full scan;
- marked out of date automatically when you change a setting it was built with: the enabled containers, `scanned_types`, `scan_urls` or `include_working_copies`. While it's out of date the CP says so, the browser filter hides itself, and nothing can be deleted;
- never built implicitly while rendering a page. Before the first build the column, the panel and the Tools page say "not checked yet" rather than pretending everything is unused, and nothing can be deleted until there is data behind that verdict;
- flagged on the Tools page once it has gone a while without a full rebuild (`rebuild_reminder_days`).

Which assets exist is read through the container's asset query, the same source the asset browser uses, not through the container's file listing, which on the eloquent driver reports the container root only.

The incremental updates run through a queued listener (like Statamic's own reference updating). On the `sync` queue that means inline; with a real queue connection you need a worker running, or set `auto_update` to `false` and rebuild on a schedule instead.

## Commands

Every command starts with `php please asset-usage:`. Options belong to the command they are listed under; `--force`, for one, means something different for `unused` than for `analyze`.

### `asset-usage:index`

Rebuilds the whole usage index, with a progress bar that counts the items as it scans them.

| Option | |
|---|---|
| `--queue` | Hand the rebuild to a queue worker instead of running it now |

### `asset-usage:unused`

Lists the assets nothing uses, and can delete them.

| Option | |
|---|---|
| `--container=main` | Only one container |
| `--older-than=30` | Only assets last modified more than this many days ago |
| `--ignore='*.pdf'` | Filename patterns to leave out, on top of the `ignore` config; repeat it for more |
| `--json` | The paths as JSON instead of a table, without progress bars |
| `--fresh` | Rebuild the index first |
| `--delete` | Delete the listed assets. Asks first, refuses to run against an out-of-date index, and re-checks every asset just before removing it |
| `--force` | With `--delete`: don't ask for confirmation |

### `asset-usage:doctor`

Diagnoses what the addon sees, for when the numbers look wrong.

| Option | |
|---|---|
| `--folders` | Also list the file count per folder |

Per container it prints what the filesystem holds (split into root and subfolders), what the asset query finds, what Statamic's own file listing reports, how many paths resolve to an actual asset and how many are recorded as used. It also flags files that exist on disk but aren't known as assets: on the eloquent driver those have no row in the `assets` table, so nothing can report on them until `php please eloquent:import-assets` brings them in.

Above the per-container output it lists the content types actually being scanned, and warns about any that your published config doesn't mention, because those are switched off, which is the usual reason a whole class of references seems to go unnoticed. For compression it shows the image driver Glide uses, whether pngquant is installed, `memory_limit` (with GD), when the images were last analysed and how much space the kept originals take.

### `asset-usage:analyze`

Test-compresses every image, for the Saving column and the Compression page, with a progress bar that moves on per image.

| Option | |
|---|---|
| `--container=main` | Only one container. The date and settings of the full analysis stay as they were |
| `--force` | Also analyse images whose result is still current |
| `--queue` | Hand the analysis to a queue worker instead of running it now |

### `asset-usage:compress`

Compresses every image that can get smaller and keeps the originals. It lists them and asks before replacing anything. Images without a current analysis are left out.

| Option | |
|---|---|
| `--container=main` | Only one container |
| `--analyze` | First analyse the images without a current result, so they are included |
| `--dry-run` | Only list the images, without replacing anything |
| `--json` | The result as JSON, without the table and progress bar. Needs `--force`, or `--dry-run` for only the list |
| `--force` | Don't ask for confirmation |

### `asset-usage:prune-originals`

Deletes the originals of compressed images once they are older than `keep_originals_days`. The image stays marked as compressed, so it isn't offered again. Files nothing can restore any more (an original whose details are missing, a copy that was never finished) are deleted after a day, also when originals are kept forever. Runs daily on its own when the site runs Laravel's scheduler.

### `asset-usage:log`

Prints the log: every compression and every deleted asset, newest first.

| Option | |
|---|---|
| `--type=compressed` | Only compressions, or `--type=deleted` for only deletions |
| `--json` | The totals and the entries as JSON |

## Deleting

Deleting is available from the Tools page (per row, for a checkbox selection, and as **Delete all unused**, which covers every unused asset the active filters match rather than only the page you're looking at, up to 500 per click, so a big cleanup takes a few) and from the CLI. Everything goes through the same rails:

- the `delete unused assets` permission, on top of Statamic's own per-container asset permissions;
- the index must be current, and there has to be one at all: before the first build nothing is known to be unused, so nothing is deletable;
- the asset must have zero usages, so a used asset can't be deleted from here at all;
- `ignore` patterns and `minimum_age_in_days` are enforced server-side, not just in the UI.

Every deletion is logged, from here or from anywhere else (see [The log](#the-log)).

## Compressing images

Images that are far heavier than they need to be (photos straight from a camera, 300 DPI exports, PNGs saved without compression) can be compressed from the Tools page, the asset editor or the command line. Each image is scaled down to `max_dimension` (never enlarged), set to 72 DPI, stripped of metadata where the image library allows it, and saved again in the same format, at the same path, so every place it is used keeps working. JPG and WebP are re-encoded at the configured quality; PNGs go through [pngquant](https://pngquant.org) when the server has it, and are otherwise only resized and saved losslessly.

The **Saving** column shows what each image would save. When an image gets any smaller (`threshold_percent`, 1% by default) it becomes a **View compression** button, which opens a page comparing the original and the result: on top of each other with a line you drag (or move with the arrow keys), or side by side, at fit, 100% or 200%. Nothing changes until you press **Compress** there and confirm. The asset editor has the same button under **Compression**, for an image that can get smaller. For every other image it says how it stands: not analysed yet, already compressed, below the threshold, or larger when saved again. Compressing keeps the original in `storage/statamic/asset-usage/originals` for `keep_originals_days`. The image then shows as **Compressed (−25%)**, measured against that original, and links back to the same page, where the original can be put back. It isn't offered again, not with other settings and not once its original is gone, because saving it again would only shave off another percent while losing quality. To compress it with other settings, put the original back first. An original only belongs to the file it was taken from: once that file is replaced or uploaded anew, the old original is no longer offered. It is deleted with its asset and moves along when the asset is renamed or moved.

Under **Tools > Asset Usage**, tabs (and the submenu) lead to a **Compression** page for users with the Compress images permission, the same overview showing the images that can get smaller and the ones the addon compressed (with a filter for either), sorted on the saving: the images that can get smaller first, in either direction, and the largest saving on top, and to the **Log**, for users with View log: every compression the addon made, by whom, before and after, and whether the original was put back, and every deleted asset (see below). The Compression page sums it up: "12 images · 70.6 MB → 13.3 MB (−81.1%) · 5 resized". Restored compressions stay in the log but no longer count.

**Compress all** on the Compression page compresses every image that can get smaller within the current filters, and **Compress selected** the images ticked on the current page, both after a warning that they are replaced without a before and after comparison. The page sends them a few at a time and shows how far it is, so it works on the `sync` queue too and no request runs into the time limit. Keep the page open until it is done; stopping or leaving halfway leaves the images compressed so far compressed and the rest as they were. Images that can't be compressed are skipped and reported.

The Overview keeps the Saving column for everyone, and for users who may compress a notice with a button to the Compression page when there is something to gain; everything else about compression lives on its own page.

### When is it analysed?

The numbers in the Saving column come from a test compression, not from a live check:

- **After an upload or a replace**: new and replaced images are analysed automatically, on the queue. On the `sync` queue that happens right after the upload has been answered, so the upload doesn't wait for it.
- **With the "Analyse compression" button** on the Compression page, or `php please asset-usage:analyze`: for the images that were there before, and after a change in the settings.
- **After a settings change** the old results no longer count, and the Compression page asks you to analyse again.

The Compression page always says when the last analysis ran.

### Good to know

- The memory Glide needs depends on the number of pixels only, not on the DPI or the file size. Scaling down to `max_dimension` is what helps Glide; setting 72 DPI does no harm but changes nothing for it.
- Compression uses the image driver Glide is configured with (`statamic.assets.image_manipulation.driver`), including a custom one.
- An image that won't fit in `memory_limit` is marked **Too large** instead of being processed. That is checked from the file's header, before the file is read. GD decodes into PHP's own memory, so it reaches that limit much sooner than Imagick, which decodes outside it.
- GD drops embedded colour profiles. When that happens, the before and after page says so.
- Statamic works with Intervention Image v3 and v4, and so does compression. Version 3 has no option to strip metadata, so with v3 and the Imagick driver an image keeps its EXIF data; GD drops it either way.
- Re-saving an image that is already compressed harder than the configured quality would make it bigger. Those images get no button; the Saving column says No saving.
- GIFs, SVGs and animated images are left alone.
- Whether pngquant is found is part of the settings a result is made with. It is looked for in the PATH and in `/opt/homebrew/bin`, `/usr/local/bin` and `/usr/bin`, because the web server and the command line (or a queue worker) often have a different PATH, as with MAMP or PHP-FPM. When it is installed somewhere else and only one of them finds it, the results the other made count as out of date; set `pngquant_binary` to its full path so both use the same one.
- When the PHP extension of the configured driver is missing, the Compression page says so and compression stays off; nothing else breaks. Formats the driver can't handle (GD built without WebP, say) and a missing pngquant are mentioned there as well, and by `asset-usage:doctor`.

## The log

**Tools > Asset Usage > Log** lists what happened to assets in the enabled containers, newest first, filtered by All, Compressed or Deleted. It needs the **View log** permission:

- **Compressions**: who, before and after, whether it was done with `asset-usage:compress`, and whether the original was put back.
- **Deletions**, wherever they happen: the Tools page, `asset-usage:unused --delete`, Statamic's own asset browser, another command or a front-end form. Each entry has who did it, the file size and dimensions, where it happened, and whether the asset was still used at that moment, which makes an accidental deletion easy to trace. The overview shows the total ("8 files deleted · 24.3 MB freed").

Users only see entries for the containers they can view. The log keeps a user's name, not their email address. Without a user, a command or a queued job shows as "Command line"; outside the Control Panel nobody can be named, so that stays empty. It is kept in `storage/statamic/asset-usage/asset-log.jsonl`, outside your content and git. `php please asset-usage:log` prints it, `--json` for scripts.

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

    // Days without a full rebuild before the Tools page suggests one. 0 turns it off.
    'rebuild_reminder_days' => [
        'auto_update' => 30,
        'manual' => 7,
    ],

    'editor_panel' => true,
    'listing_column' => true,

    // Never reported as unused, e.g. ['*.pdf', 'downloads/*']
    'ignore' => [],

    // Assets younger than this are never reported as unused
    'minimum_age_in_days' => 0,

    'compression' => [
        'enabled' => true,
        'threshold_percent' => 1,    // from this saving on, an image is offered (at least 1)
        'max_dimension' => 3840,     // longest side in pixels, never enlarged
        'dpi' => 72,
        'jpg_quality' => 82,
        'webp_quality' => 80,
        'png_quality' => '70-90',    // pngquant min-max
        'pngquant_binary' => null,   // null: look in the PATH and the usual install directories
        'keep_originals_days' => 30, // null: keep originals forever
    ],
];
```

## Permissions

- **View asset usage**: the Overview on the Tools page, Saving column included.
- **View log**: the Log page, and the buttons and tab that lead to it.
- **Delete unused assets**: the delete buttons on the Tools page and the endpoints behind them.
- **Compress images**: the Compression page with its Analyse, Compress selected and Compress all buttons, the before and after page, the Compression part of the asset editor and restoring an original. Replacing the file also needs Statamic's own edit and upload permissions for that container.

The "Used in" panel and the browser column follow Statamic's normal asset permissions; if you can see the asset, you can see its usage. The Tools pages do the same: they only list containers whose assets the user may view, the log and the compression counts included, and deleting also needs Statamic's own delete permission for that container.

The commands don't check permissions: whoever can run `php please` can use them.

## Languages

The Control Panel side is available in English, Dutch, German, French, Spanish and Italian, and follows each user's own CP language. Console output is always English, so it reads the same in a bug report.

## Support

Found a bug or missing a feature? [Open an issue on GitHub](https://github.com/keyagency/statamic-asset-usage/issues). [CONTRIBUTING.md](CONTRIBUTING.md) lists what to include, such as your Statamic and PHP versions, whether the site uses the Eloquent driver, and the output of `php please asset-usage:doctor`.

## License

Proprietary. © Key Agency.
