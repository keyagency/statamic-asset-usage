# Asset Usage

[![Statamic Marketplace](https://img.shields.io/badge/Statamic-Marketplace-orange.svg)](https://statamic.com/addons/key-agency/asset-usage)
[![Latest Version](https://img.shields.io/github/v/release/keyagency/statamic-asset-usage?label=version&color=blue)](https://github.com/keyagency/statamic-asset-usage/releases)
[![Tests](https://github.com/keyagency/statamic-asset-usage/actions/workflows/tests.yml/badge.svg)](https://github.com/keyagency/statamic-asset-usage/actions/workflows/tests.yml)

Asset Usage is a Statamic 6 Control Panel addon that shows where each asset is used, lists the assets that are not used anywhere, and compresses images that are larger than necessary.

## Features

- **Used in panel** in the asset editor, listing every item that references the asset, grouped by site.
- **Used column and filter** in the asset browser.
- **Overview** under Tools, with filters for container, site, usage and path, and deletion of unused assets.
- **Image compression** with a before and after comparison. The original is kept and can be restored.
- **Log** of every compression and deletion, including the user who performed it.
- **PDF export** of the current list, with thumbnails.
- **Commands** for reporting, deleting and compressing from the command line.
- **Translations** in English, Dutch, German, French, Spanish and Italian.

Content is read through Statamic's repositories, so the addon works on flat-file sites and on sites using the [Eloquent driver](https://statamic.dev/tips/eloquent-driver).

## Installation

```bash
composer require keyagency/statamic-asset-usage
php please asset-usage:index
```

The second command builds the usage index from existing content. If it is skipped, the first content save builds the index instead. From then on, the index is updated whenever content is saved or deleted.

Optional, for image compression:

- [pngquant](https://pngquant.org) on the server. Without it, PNGs are only resized and saved losslessly.
- Laravel's scheduler, which deletes kept originals after `keep_originals_days`.

## What counts as used

Every reference format Statamic uses is recognised, including references nested in Grid, Replicator, Group and Bard fields:

| Where | Format |
|---|---|
| Assets fields | `img/photo.jpg` |
| Link fields, Bard images and links | `asset::main::img/photo.jpg` |
| Markdown and Bard HTML | `statamic://asset::main::img/photo.jpg` |
| Text fields (`scan_urls`) | `/assets/img/photo.jpg` or the full URL |

Scanned are entries (including unpublished working copies), globals, taxonomy terms, navigations, users, other assets, form submissions, collection and taxonomy cascades, addon settings, and default values in blueprints and fieldsets.

On a multisite, an asset counts as unused only when no site uses it.

### Limitations

- References in templates (Antlers, Blade) and PHP files are not detected. Protect those files with the `ignore` setting.
- Glide URLs (`/img/asset/…`) are not detected.
- Data an addon keeps in its own files or database tables is not scanned.
- A path that exists in two containers counts as used in both.

## Usage index

Usage is stored in `storage/statamic/asset-usage/index.json` and updated by a queued listener. On a queue other than `sync`, a worker must be running. Alternatively, set `auto_update` to `false` and rebuild the index on a schedule.

The index is never built during a page load. Until it exists, assets are shown as not checked rather than unused. When a setting the index depends on changes, it is marked out of date. In both cases nothing can be deleted until the index is rebuilt.

## Deleting assets

Unused assets can be deleted from the Tools page or with `asset-usage:unused --delete`. On the Tools page this requires the Delete unused assets permission and Statamic's delete permission for the container. In both cases an asset is only deleted when:

- the usage index exists and is up to date;
- the asset has no usages;
- the asset does not match an `ignore` pattern and is older than `minimum_age_in_days`.

## Image compression

Images are analysed after upload, or on request from the Compression page or with `asset-usage:analyze`. An image that would get smaller gets a View compression button, which opens a before and after comparison. Compress all and Compress selected handle several images at once, without the comparison.

Compressing scales the image down to `max_dimension` and saves it again in the same format, at the same path, so existing references keep working. JPG and WebP are re-encoded at the configured quality, PNGs go through pngquant when it is installed. The original is kept for `keep_originals_days` and can be restored. A compressed image is not offered again.

Compression uses the image driver configured for Glide. GIF, SVG and animated images are skipped.

## Log

The Log page lists every compression and every deleted asset in the enabled containers, with the user, the file size and whether the asset was still in use. Deletions are logged wherever they happen, including Statamic's own asset browser.

## Commands

| Command | Description |
|---|---|
| `asset-usage:index` | Rebuilds the usage index. |
| `asset-usage:unused` | Lists unused assets. Deletes them with `--delete`. |
| `asset-usage:analyze` | Analyses which images can be compressed. |
| `asset-usage:compress` | Compresses every image that can get smaller. |
| `asset-usage:prune-originals` | Deletes originals older than `keep_originals_days`. Runs daily through the scheduler. |
| `asset-usage:log` | Prints the log. |
| `asset-usage:doctor` | Shows what the addon detects, for troubleshooting. |

Run a command with `php please`, and add `--help` for its options.

## Configuration

```bash
php artisan vendor:publish --tag=asset-usage-config
```

Every option is described in `config/statamic/asset-usage.php`.

## Permissions

| Permission | Grants access to |
|---|---|
| View asset usage | The Overview on the Tools page. |
| Delete unused assets | Deleting from the Tools page. |
| Compress images | The Compression page, compressing and restoring. Also requires Statamic's edit and upload permissions for the container. |
| View log | The Log page. |

The Used in panel and the browser column follow Statamic's asset permissions. The Tools pages only show containers the user can view. Commands do not check permissions.

## Support

Found a bug or missing a feature? [Open an issue on GitHub](https://github.com/keyagency/statamic-asset-usage/issues). [CONTRIBUTING.md](CONTRIBUTING.md) lists what to include.

## License

Proprietary. © Key Agency.
