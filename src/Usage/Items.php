<?php

namespace KeyAgency\AssetUsage\Usage;

use Generator;
use KeyAgency\AssetUsage\Support\Defaults;
use KeyAgency\AssetUsage\Support\Settings;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\FormSubmission;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;

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

    public const TYPES = ['entry', 'entry_draft', 'global', 'term', 'nav', 'user', 'asset', 'form_submission'];

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
            title: "{$form->title()} — {$submission->id()}",
            editUrl: null,
            data: $submission->data()->all(),
        );
    }
}
