# Contributing

Asset Usage is a Key Agency Statamic addon. The source lives on GitHub so you can report issues, follow development and propose focused fixes — it is not an open-source project you are free to fork and redistribute.

## Reporting bugs & requesting features

[Open an issue](https://github.com/keyagency/statamic-asset-usage/issues) with:

- what you did, what you expected, and what happened instead
- your Statamic and PHP versions, and whether the site runs on flat-file (Stache) or the Eloquent driver
- for detection problems: how the asset is referenced (asset field, Bard, Markdown, a plain URL…) and a small example of the field data
- whether the usage index was current — the Tools page says so, and `php please asset-usage:index` rebuilds it
- for missing or miscounted assets: the output of `php please asset-usage:doctor`

Please don't paste production content or credentials — a minimal reproduction is enough.

Small, well-scoped pull requests for bugs are welcome. For anything larger, open an issue first so we can agree on the approach before you invest time.

## Development

``` bash
composer install
npm install
npm run build
```

When testing inside a Statamic site through a path repository, `npm run dev` writes a hot file that the site loads from the dev server, so you don't have to rebuild after every change. Without it, run `npm run build` after touching anything under `resources/js` and publish the assets in the site:

``` bash
php please vendor:publish --tag=asset-usage --force
```

## Testing

``` bash
vendor/bin/phpunit
vendor/bin/pint --test
```

Tests run against in-memory SQLite via Orchestra Testbench + `Statamic\Testing\AddonTestCase`. CI runs PHPUnit on PHP 8.2–8.5 (including a `--prefer-lowest` job) and Pint. New behaviour needs a test.

## Conventions

- User-facing strings live in `lang/en/messages.php` and `lang/nl/messages.php` — add keys there, don't inline literals, and keep both locales in sync. Related strings are grouped under their own array key (`index`, `filters`, `sort`, `delete`, `errors`, …) rather than prefixed.
- Config is read through `Support\Settings`, not scattered `config()` calls.

Four rules are easy to break; please preserve them:

1. **Content is read through Statamic's repositories, never the filesystem.** That's what makes the addon work on Eloquent-driver sites, which is its reason to exist.
2. **The injected `asset_usage` field stays `visibility: computed`.** That is the only thing keeping it out of every asset's `.meta` file. `UsageFieldTest::updating_an_asset_never_writes_the_field_into_its_data` guards it.
3. **Deletion rails live in `Usage\Unused`**, so the Control Panel and the CLI can't disagree about what may be removed. Add new guards there, not in a controller.
4. **Which assets exist comes from `$container->queryAssets()`, not `$container->files()`.** On the Eloquent driver that file listing only reports the container root, so every asset in a folder would silently disappear. `ContainerPathsTest` guards it.

## Releasing

Compiled assets are not committed. Pushing a `v*` tag triggers the release workflow, which builds the assets and attaches a `dist.tar.gz` to the GitHub release. Consuming projects download it automatically on `composer install` via `pixelfear/composer-dist-plugin` (see `extra.download-dist` in `composer.json`).
