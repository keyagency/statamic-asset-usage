# AGENTS.md

This file provides guidance to AI coding tools when working with code in this repository.

## What this is

A Statamic 6 Control Panel addon (`keyagency/statamic-asset-usage`) that shows where every asset is used and which assets nothing references. PHP 8.2+, single free edition. Content is read through Statamic's repositories, never by scanning `content/`, which is what makes it work on eloquent-driver sites. Don't replace that with filesystem reads.

## Commands

```bash
vendor/bin/phpunit                      # run all tests
vendor/bin/phpunit --filter testName    # run a single test
vendor/bin/phpunit tests/Unit/ReferenceExtractorTest.php
vendor/bin/pint                         # apply code style (laravel preset)
vendor/bin/pint --test                  # check code style (CI gate)
npm run build                           # build CP assets (vite → resources/dist)
npm run dev                             # vite dev server
```

Tests run on an in-memory sqlite via Orchestra Testbench + `Statamic\Testing\AddonTestCase`. `TestCase` turns Statamic Pro on because the permission tests need more than one user.

Blueprints, fieldsets and addon settings are written to real files under `resource_path()`, which Testbench does not reset between tests, so `TestCase::setUp()` deletes `resources/{addons,blueprints,fieldsets}`. Don't drop that: a blueprint left behind by one test silently replaces another test's, and the failure surfaces somewhere unrelated. `makeBlueprint()` and `fakeAddon()` are the helpers for creating them; `fakeAddon()` registers an addon through the `Manifest` because `Addon::all()` is memoised. Note that `entryBlueprints()` is blinked per collection, so create a blueprint before the first entry that would resolve it. CI runs PHPUnit on PHP 8.2–8.5 (plus a `--prefer-lowest` job) and Pint once against current Pint.

## Architecture

The pipeline is **extract → index → display**.

- **`Usage\ReferenceExtractor`** walks one item's data array and returns `assetId => [dotted field paths]`. It is deliberately **generic, not blueprint-driven**: every format Statamic uses is recognisable from the string itself (`asset::container::path`, `statamic://asset::…`), and the one exception, the bare container-relative path an `assets` field stores, is resolved against the list of paths that actually exist. That's why nav trees and form submissions, which have no useful blueprint, work with the same code. See `src/Fieldtypes/UpdatesReferences.php` in core for the formats.
- **`Usage\Containers`** holds the two lookup tables the extractor needs: every known asset path (`path => [container handles]`, from `$container->queryAssets()->pluck('path')`) and the URL prefixes that point at a container. Building it lists every asset in every container, so build it once per scan and pass it around. **Don't switch this back to `$container->files()`:** on the eloquent driver that listing returns the container root only, because its recursive lookup is `folder LIKE '/%'` while nested assets store `folder` without a leading slash, so every asset in a folder would disappear from the overview. `ContainerPathsTest` guards it.
- **`Usage\Items`** turns everything scannable into `Item`s, through the repository facades only. Besides content that is collection and taxonomy cascades, addon settings and the `default` values in blueprints and fieldsets, which are the places an asset is referenced without ever being saved onto a single item. Static `from*()` factories are shared with the event listener so a full build and an incremental patch describe an item identically.
- **`Usage\BlueprintDefaults`** pulls the `default` values out of a blueprint or fieldset, keyed by dotted field handle. It walks the raw `contents()` rather than the `Fields` objects on purpose: a default can sit inside a grid, a group or a replicator set, and each of those stores its fields in a shape only that fieldtype knows about.
- **`Usage\UsageIndex`** keeps `assetId => Usage[]` **and** a reverse `itemKey => assetIds` map. The reverse map is the whole reason an incremental update is possible; don't drop it.
- **`Usage\IndexStore`** persists to `storage/statamic/asset-usage/index.json`, outside site content, so it is driver-agnostic and stays out of git-integration commits. Writes go through a `Cache::lock` and a temp-file rename. `isStale()` compares every setting an index depends on (the container set, `scanned_types`, `scan_urls` and `include_working_copies`) against config, so a config change surfaces as an out-of-date index instead of quietly wrong results. Add the setting to `settingsMeta()` when you introduce a new one that changes what gets indexed.
- **`Usage\Unused`** owns the safety rails (zero usages, `ignore` patterns, `minimum_age_in_days`). The CP controller and the CLI both go through it so they can't drift on what they're willing to delete.

### How the CP panel and the column work

One `asset_usage` field, injected into every enabled container's blueprint by `Listeners\InjectUsageField` on `AssetContainerBlueprintFound`, covers both places.

**Invariant: the field is `visibility: computed`, and that is what keeps it out of saved data.** `Fields::values()` (`src/Fields/Fields.php`) rejects computed fields unless `withComputedValues()` was called, and `AssetsController::update()` saves via `$fields->process()->values()`, which never calls it. `preProcess()` does call it, so the editor still sees the value. Change that visibility and every asset's `.meta` file gains an `asset_usage` key, and `UsageFieldTest::updating_an_asset_never_writes_the_field_into_its_data` is the guard.

The asset is read from `$this->field->parent()`: the editor sets it via `AssetContainer::blueprint($asset)`, the listing via `FolderAsset::values()`. Handle a non-Asset parent gracefully, because `setColumns()` resolves the blueprint with the *container* as parent.

`Field::isSortable()` returns false for computed fields, so the column can't be sorted. That's expected: the query knows nothing about the index.

### Frontend

Vite + Vue in `resources/js/`. `cp.js` registers the Inertia page (`asset-usage::AssetUsage`) and the two fieldtype components, which **must** be named `asset_usage-fieldtype` and `asset_usage-fieldtype-index`, because the listing resolves the index component as `` `${fieldtype}-fieldtype-index` `` and silently falls back to plain text otherwise. Components come from `@statamic/cms/ui`; those imports resolve at runtime through the `__STATAMIC__` global, so a wrong export name builds fine and breaks in the browser. Check `vendor/statamic/cms/resources/dist-package/src/ui.js` for the real export list.

## Deliberate trade-offs

Documented in the README as well; don't "fix" them without a reason:

- A bare path present in two enabled containers counts as used in **both**. Over-reporting beats deleting a file that was in use.
- Glide URLs (`/img/asset/…`) and template references are out of scope.
- The index is never built implicitly during a page render. Not-indexed and unused are shown as different states on purpose.

## Conventions

- Namespace `KeyAgency\AssetUsage\` → `src/`. Config is merged/published under the `statamic.asset-usage` key (`config/asset-usage.php`), read through `Support\Settings` rather than `config()` calls scattered around.
- User-facing strings live in `lang/{en,nl}/messages.php`. Add keys there, don't inline literals. Related strings are grouped under their own array key (`permissions`, `index`, `filters`, `sort`, `delete`, `item_type`, `errors`) rather than prefixed; singletons stay at the top level. Statamic flattens the file with `Arr::dot()` for the JS side, so `__('asset-usage::messages.errors.asset_missing')` works the same in Vue as in PHP.
- `scanned_types` is read as the complete set (`Settings::scans()`): a type that isn't listed is off. Laravel merges that config key as a whole, so a published config with one type means one type, and a config published before you added a type leaves that type off in that site. `DoctorCommand` names the keys a site's config is missing. Adding a scanned type means four things together: `config/asset-usage.php`, `Settings::SCANNABLE_TYPES`, an `item_type` label in both lang files, and an update script in `src/UpdateScripts/` (autoloaded by `AddonServiceProvider::bootUpdateScripts()`, no registration needed) that adds the key to already-published configs. Those scripts list the types they introduce explicitly rather than reading `SCANNABLE_TYPES`, so a type a site left out on purpose is never silently re-added.
- Statamic saves addon settings under the addon's slug but reads them back under its package name (`FileSettings::path()` vs `FileSettingsRepository::find()`). For an addon that overrides its slug those differ, which cuts both ways: its real settings file is never found, so `addon_settings` misses it entirely, and a file that happens to sit on the package-name path resolves to a null addon and throws. `Items::addonSettings()` catches the throw per addon so one broken addon can't take the whole scan down; `an_addon_whose_settings_cannot_be_read_does_not_break_the_scan` guards it. The miss is Statamic's to fix, so don't work around it by reading `resources/addons/` directly.
- Comments use `/** */` for multi-line, and only where something needs explaining.
