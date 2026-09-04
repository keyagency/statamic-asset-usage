<?php

namespace KeyAgency\AssetUsage\Usage;

use Generator;
use KeyAgency\AssetUsage\Support\Defaults;
use KeyAgency\AssetUsage\Support\Settings;
use Statamic\Facades\Addon;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Form;
use Statamic\Facades\FormSubmission;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\Support\Str;
use Throwable;

/**
 * Turns Statamic content into `Item`s to scan. Everything goes through the
 * repository facades, never the filesystem, so a site using the eloquent driver
 * is scanned exactly like a flat-file one.
 */
final class Items
{
    /**
     * Keys we never want to carry into the index. Password hashes can't match
     * an asset path, but there's no reason to read them either.
     */
    private const STRIPPED_USER_KEYS = ['password', 'password_hash', 'remember_token'];

    public const TYPES = ['entry', 'entry_draft', 'global', 'term', 'nav', 'user', 'asset', 'form_submission', 'collection', 'taxonomy', 'addon_settings', 'blueprint', 'fieldset'];

    public function __construct(private readonly Containers $containers) {}

    /**
     * @return Generator<Item>
     */
    public function all(): Generator
    {
        yield from $this->entries();
        yield from $this->globals();
        yield from $this->terms();
        yield from $this->navs();
        yield from $this->users();
        yield from $this->assets();
        yield from $this->formSubmissions();
        yield from $this->collections();
        yield from $this->taxonomies();
        yield from $this->addonSettings();
        yield from $this->blueprints();
        yield from $this->fieldsets();
    }

    /**
     * @return Generator<Item>
     */
    public function entries(): Generator
    {
        if (! Settings::scans('entries')) {
            return;
        }

        foreach (Entry::query()->lazy(Defaults::$scanChunkSize) as $entry) {
            yield self::fromEntry($entry);

            if ($draft = self::fromEntryWorkingCopy($entry)) {
                yield $draft;
            }
        }
    }

    public static function fromEntry($entry): Item
    {
        return new Item(
            type: 'entry',
            key: $entry->id(),
            site: $entry->locale(),
            title: $entry->get('title') ?: $entry->slug() ?: $entry->id(),
            editUrl: $entry->editUrl(),
            data: $entry->data()->all(),
        );
    }

    /**
     * An asset that only appears in an unpublished draft still counts as used,
     * so cleaning up never breaks work in progress. Guarded by
     * `revisionsEnabled()` because `workingCopy()` is a lookup per entry.
     */
    public static function fromEntryWorkingCopy($entry): ?Item
    {
        if (! Settings::includesWorkingCopies() || ! $entry->revisionsEnabled()) {
            return null;
        }

        if (! $revision = $entry->workingCopy()) {
            return null;
        }

        $draft = $entry->makeFromRevision($revision);

        return new Item(
            type: 'entry_draft',
            key: $entry->id(),
            site: $entry->locale(),
            title: $draft->get('title') ?: $entry->slug() ?: $entry->id(),
            editUrl: $entry->editUrl(),
            data: $draft->data()->all(),
        );
    }

    /**
     * @return Generator<Item>
     */
    public function globals(): Generator
    {
        if (! Settings::scans('globals')) {
            return;
        }

        foreach (GlobalSet::all() as $set) {
            foreach ($set->localizations() as $variables) {
                yield self::fromGlobalVariables($variables);
            }
        }
    }

    public static function fromGlobalVariables($variables): Item
    {
        return new Item(
            type: 'global',
            key: $variables->globalSet()->handle(),
            site: $variables->locale(),
            title: $variables->globalSet()->title(),
            editUrl: $variables->editUrl(),
            data: $variables->data()->all(),
        );
    }

    /**
     * @return Generator<Item>
     */
    public function terms(): Generator
    {
        if (! Settings::scans('terms')) {
            return;
        }

        foreach (Taxonomy::all() as $taxonomy) {
            foreach ($taxonomy->sites() as $site) {
                $terms = Term::query()
                    ->where('taxonomy', $taxonomy->handle())
                    ->where('site', $site)
                    ->lazy(Defaults::$scanChunkSize);

                foreach ($terms as $term) {
                    yield self::fromTerm($term);
                }
            }
        }
    }

    public static function fromTerm($term): Item
    {
        return new Item(
            type: 'term',
            key: $term->id(),
            site: $term->locale(),
            title: $term->title(),
            editUrl: $term->editUrl(),
            data: $term->data()->all(),
        );
    }

    /**
     * @return Generator<Item>
     */
    public function navs(): Generator
    {
        if (! Settings::scans('navs')) {
            return;
        }

        foreach (Nav::all() as $nav) {
            foreach ($nav->trees() as $tree) {
                yield self::fromNavTree($tree);
            }
        }
    }

    public static function fromNavTree($tree): Item
    {
        return new Item(
            type: 'nav',
            key: $tree->handle(),
            site: $tree->locale(),
            title: $tree->structure()->title(),
            editUrl: $tree->editUrl(),
            /*
             * Tree nodes are plain data: titles, urls and any custom node
             * fields, which is exactly where an asset reference could sit.
             */
            data: $tree->tree(),
        );
    }

    /**
     * @return Generator<Item>
     */
    public function users(): Generator
    {
        if (! Settings::scans('users')) {
            return;
        }

        foreach (User::query()->lazy(Defaults::$scanChunkSize) as $user) {
            yield self::fromUser($user);
        }
    }

    public static function fromUser($user): Item
    {
        return new Item(
            type: 'user',
            key: $user->id(),
            site: null,
            title: $user->name() ?: $user->email(),
            editUrl: $user->editUrl(),
            data: collect($user->data()->all())->except(self::STRIPPED_USER_KEYS)->all(),
        );
    }

    /**
     * Assets have blueprints of their own, so one asset can reference another.
     *
     * @return Generator<Item>
     */
    public function assets(): Generator
    {
        if (! Settings::scans('assets')) {
            return;
        }

        foreach ($this->containers->all() as $container) {
            foreach ($container->queryAssets()->lazy(Defaults::$scanChunkSize) as $asset) {
                yield self::fromAsset($asset);
            }
        }
    }

    public static function fromAsset($asset): Item
    {
        return new Item(
            type: 'asset',
            key: $asset->id(),
            site: null,
            title: $asset->path(),
            editUrl: $asset->editUrl(),
            data: $asset->data()->all(),
        );
    }

    /**
     * @return Generator<Item>
     */
    public function formSubmissions(): Generator
    {
        if (! Settings::scans('form_submissions')) {
            return;
        }

        foreach (Form::all() as $form) {
            /*
             * Chunked rather than $form->submissions(): a busy form holds tens
             * of thousands, and loading them at once exhausts memory.
             */
            $submissions = FormSubmission::query()
                ->where('form', $form->handle())
                ->lazy(Defaults::$scanChunkSize);

            foreach ($submissions as $submission) {
                yield self::fromSubmission($submission, $form);
            }
        }
    }

    public static function fromSubmission($submission, $form = null): Item
    {
        $form ??= $submission->form();

        return new Item(
            type: 'form_submission',
            key: "{$form->handle()}::{$submission->id()}",
            site: null,
            title: "{$form->title()}: {$submission->id()}",
            editUrl: null,
            data: $submission->data()->all(),
        );
    }

    /**
     * A collection's cascade holds the values that fall through to every entry
     * in it, so an asset set there is used by the whole collection without
     * appearing on a single entry.
     *
     * @return Generator<Item>
     */
    public function collections(): Generator
    {
        if (! Settings::scans('collection_cascades')) {
            return;
        }

        foreach (Collection::all() as $collection) {
            yield self::fromCollection($collection);
        }
    }

    public static function fromCollection($collection): Item
    {
        return new Item(
            type: 'collection',
            key: $collection->handle(),
            site: null,
            title: $collection->title(),
            editUrl: $collection->editUrl(),
            data: $collection->cascade()->all(),
        );
    }

    /**
     * A taxonomy's cascade is the term-level counterpart of a collection's.
     *
     * @return Generator<Item>
     */
    public function taxonomies(): Generator
    {
        if (! Settings::scans('taxonomy_cascades')) {
            return;
        }

        foreach (Taxonomy::all() as $taxonomy) {
            yield self::fromTaxonomy($taxonomy);
        }
    }

    public static function fromTaxonomy($taxonomy): Item
    {
        return new Item(
            type: 'taxonomy',
            key: $taxonomy->handle(),
            site: null,
            title: $taxonomy->title(),
            editUrl: $taxonomy->editUrl(),
            data: $taxonomy->cascade()->all(),
        );
    }

    /**
     * Addon settings, stored in `resources/addons/{slug}.yaml`. Read through
     * the addon API rather than per addon, so any addon keeping an asset in
     * its settings is covered without knowing anything about it.
     *
     * @return Generator<Item>
     */
    public function addonSettings(): Generator
    {
        if (! Settings::scans('addon_settings')) {
            return;
        }

        foreach (Addon::all() as $addon) {
            /*
             * Statamic saves addon settings under the addon's slug but reads
             * them back under its package name, so an addon that overrides its
             * slug can have a settings file it cannot resolve. One addon in
             * that state shouldn't take the whole scan down with it.
             */
            try {
                $settings = $addon->settings()->raw();
            } catch (Throwable) {
                continue;
            }

            if ($settings) {
                yield self::fromAddonSettings($addon, $settings);
            }
        }
    }

    /**
     * The raw settings rather than the resolved ones: an Antlers expression in
     * a setting is content, and what it renders to on this request is not the
     * reference the site stores.
     */
    public static function fromAddonSettings($addon, array $settings): Item
    {
        return new Item(
            type: 'addon_settings',
            key: $addon->id(),
            site: null,
            title: $addon->name(),
            editUrl: $addon->settingsUrl(),
            data: $settings,
        );
    }

    /**
     * Blueprints, for the `default` values their fields hold. Reached through
     * the things that own a blueprint rather than by listing files, so an
     * addon-registered namespace is covered as long as its owner is.
     *
     * @return Generator<Item>
     */
    public function blueprints(): Generator
    {
        if (! Settings::scans('blueprints')) {
            return;
        }

        foreach (Collection::all() as $collection) {
            foreach ($collection->entryBlueprints() as $blueprint) {
                yield self::fromBlueprint($blueprint);
            }
        }

        foreach (Taxonomy::all() as $taxonomy) {
            foreach ($taxonomy->termBlueprints() as $blueprint) {
                yield self::fromBlueprint($blueprint);
            }
        }

        /*
         * Concatenated rather than spread: these repositories key their results
         * by handle, and a global set and a navigation can share one.
         */
        $owners = collect()
            ->concat(GlobalSet::all())
            ->concat(Nav::all())
            ->concat(Form::all())
            ->concat(AssetContainer::all());

        /*
         * A blueprint is null when nothing has ever been saved for its owner,
         * which is the normal state for a site that never customised it.
         */
        foreach ($owners as $owner) {
            if ($blueprint = $owner->blueprint()) {
                yield self::fromBlueprint($blueprint);
            }
        }

        if ($blueprint = User::blueprint()) {
            yield self::fromBlueprint($blueprint);
        }
    }

    public static function fromBlueprint($blueprint): Item
    {
        return new Item(
            type: 'blueprint',
            key: self::blueprintKey($blueprint),
            site: null,
            title: $blueprint->title(),
            editUrl: self::blueprintEditUrl($blueprint),
            data: BlueprintDefaults::in($blueprint->contents()),
        );
    }

    public static function blueprintKey($blueprint): string
    {
        return trim(str_replace('/', '.', (string) $blueprint->namespace()).'.'.$blueprint->handle(), '.');
    }

    /**
     * The item key every blueprint in one namespace starts with. The trailing
     * dot is what keeps `collections/pages` from also matching the blueprints
     * of `collections/pages-archive`.
     */
    public static function blueprintKeyPrefix(string $namespace): string
    {
        return Usage::makeItemKeyPrefix('blueprint', str_replace('/', '.', $namespace).'.');
    }

    /**
     * Where a blueprint is edited is decided by whatever owns it, and a saved
     * blueprint doesn't know its owner. Derived from the namespace instead, so
     * a full scan and an incremental update produce the same link.
     */
    private static function blueprintEditUrl($blueprint): ?string
    {
        // Namespaces reach us dotted from some repositories and slashed from others.
        $namespace = str_replace('/', '.', (string) $blueprint->namespace());
        $handle = $blueprint->handle();

        return match (true) {
            $namespace === '' && $handle === 'user' => cp_route('blueprints.users.edit'),
            str_starts_with($namespace, 'collections.') => Collection::findByHandle(Str::after($namespace, '.'))?->editBlueprintUrl($blueprint),
            str_starts_with($namespace, 'taxonomies.') => Taxonomy::findByHandle(Str::after($namespace, '.'))?->editBlueprintUrl($blueprint),
            $namespace === 'globals' => GlobalSet::findByHandle($handle)?->editBlueprintUrl(),
            $namespace === 'navigation' => Nav::findByHandle($handle)?->editBlueprintUrl(),
            $namespace === 'forms' => Form::find($handle)?->editBlueprintUrl(),
            $namespace === 'assets' => AssetContainer::findByHandle($handle)?->editBlueprintUrl(),
            default => null,
        };
    }

    /**
     * Fieldsets are scanned in their own right rather than through the
     * blueprints that import them, so a default in one is found even while no
     * blueprint uses it yet.
     *
     * @return Generator<Item>
     */
    public function fieldsets(): Generator
    {
        if (! Settings::scans('blueprints')) {
            return;
        }

        foreach (Fieldset::all() as $fieldset) {
            yield self::fromFieldset($fieldset);
        }
    }

    public static function fromFieldset($fieldset): Item
    {
        return new Item(
            type: 'fieldset',
            key: $fieldset->handle(),
            site: null,
            title: $fieldset->title(),
            editUrl: $fieldset->editUrl(),
            data: BlueprintDefaults::in($fieldset->contents()),
        );
    }
}
