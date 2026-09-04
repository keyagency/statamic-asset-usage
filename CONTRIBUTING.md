# Contributing

Asset Usage is a Key Agency Statamic addon. The source lives on GitHub so you can report issues, follow development and propose focused fixes. It is not an open-source project you are free to fork and redistribute.

## Reporting bugs & requesting features

[Open an issue](https://github.com/keyagency/statamic-asset-usage/issues) with:

- what you did, what you expected, and what happened instead
- your Statamic and PHP versions, and whether the site runs on flat-file (Stache) or the Eloquent driver
- for detection problems: how the asset is referenced (asset field, Bard, Markdown, a plain URL…) and a small example of the field data
- whether the usage index was current, which the Tools page says, and `php please asset-usage:index` rebuilds it
- for missing or miscounted assets: the output of `php please asset-usage:doctor`

Please don't paste production content or credentials. A minimal reproduction is enough.

Small, well-scoped pull requests for bugs are welcome. For anything larger, open an issue first so we can agree on the approach before you invest time.

## Development

``` bash
composer install
npm install
npm run build
```

To run your working copy inside a Statamic site, add it there as a Composer path repository. From that site's root:

``` bash
composer config repositories.asset-usage path ../../Projects/statamic-asset-usage
composer require keyagency/statamic-asset-usage:@dev
```

The path is relative to that site's `composer.json`; an absolute path works too. `:@dev` is required, because Composer versions a path repository as `dev-main`. The folder is symlinked, so PHP changes are live without reinstalling.

Composer will warn that it could not download `dist.tar.gz` for `dev-main`. That is expected on a branch install and harmless: the dist plugin tolerates it and keeps your local `resources/dist`.

The site keeps its own published `config/statamic/asset-usage.php`, and Laravel merges `scanned_types` as a whole, so a config published before you added a scanned type leaves that type switched off, and nothing of it is scanned. `php please asset-usage:doctor` names the keys a site's config is missing.

### Frontend changes need publishing

Anything under `resources/js` or `resources/css`, every `.vue` file included, is compiled into `resources/dist`, and the site serves its **own copy** from `public/vendor/`. Editing a `.vue` file therefore changes nothing you can see until both steps have run. This catches everyone; if a Control Panel change seems to do nothing, this is why.

Either work against the dev server, in this repo:

``` bash
npm run dev
```

That writes a hot file the site loads from, so changes are live without rebuilding or publishing.

Or build and publish, which is what you need for anything you want to keep:

``` bash
npm run build                                       # in this repo
php please vendor:publish --tag=asset-usage --force  # in the site
```

Repeat both after every frontend change. Publishing copies files, it does not symlink.

Two things about that command are easy to get wrong:

- The tag is the addon's **slug** (`asset-usage`), while the files land in `public/vendor/`**`statamic-asset-usage`**`/`, which is the **package name**. They differ for this addon. `--tag=statamic-asset-usage` silently publishes nothing.
- `--force` is required. Without it Composer skips files that already exist, so you keep looking at the previous build.

Published builds are hashed and accumulate, so old `cp-*.js` and `cp-*.css` files stay behind. Harmless, but `public/vendor/statamic-asset-usage/build/assets` can be emptied and republished whenever it gets noisy.

## Testing

``` bash
vendor/bin/phpunit
vendor/bin/pint --test
```

Tests run against in-memory SQLite via Orchestra Testbench + `Statamic\Testing\AddonTestCase`. CI runs PHPUnit on PHP 8.2–8.5 (including a `--prefer-lowest` job) and Pint. New behaviour needs a test.

## Conventions

- Console output is English, always, written as literals in the command itself. It ends up in bug reports, and a diagnostic in the reporter's language is harder to read, not easier.
- Other user-facing strings live in `lang/en/messages.php` and `lang/nl/messages.php`. Add keys there, don't inline literals, and keep both locales in sync. Related strings are grouped under their own array key (`index`, `filters`, `sort`, `delete`, `errors`, …) rather than prefixed.
- Config is read through `Support\Settings`, not scattered `config()` calls.

Four rules are easy to break; please preserve them:

1. **Content is read through Statamic's repositories, never the filesystem.** That's what makes the addon work on Eloquent-driver sites, which is its reason to exist.
2. **The injected `asset_usage` field stays `visibility: computed`.** That is the only thing keeping it out of every asset's `.meta` file. `UsageFieldTest::updating_an_asset_never_writes_the_field_into_its_data` guards it.
3. **Deletion rails live in `Usage\Unused`**, so the Control Panel and the CLI can't disagree about what may be removed. Add new guards there, not in a controller.
4. **Which assets exist comes from `$container->queryAssets()`, not `$container->files()`.** On the Eloquent driver that file listing only reports the container root, so every asset in a folder would silently disappear. `ContainerPathsTest` guards it.

## Releasing

Compiled assets are not committed. Pushing a `v*` tag triggers the release workflow, which builds the assets and attaches a `dist.tar.gz` to the GitHub release. Consuming projects download it automatically on `composer install` via `pixelfear/composer-dist-plugin` (see `extra.download-dist` in `composer.json`).
