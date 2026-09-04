<?php

namespace KeyAgency\AssetUsage\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use KeyAgency\AssetUsage\Support\Settings;
use KeyAgency\AssetUsage\Usage\Containers;
use KeyAgency\AssetUsage\Usage\IndexStore;
use KeyAgency\AssetUsage\Usage\Item;
use KeyAgency\AssetUsage\Usage\Items;
use KeyAgency\AssetUsage\Usage\ItemUsageUpdater;
use KeyAgency\AssetUsage\Usage\Usage;
use Statamic\Events\AddonSettingsSaved;
use Statamic\Events\AssetDeleted;
use Statamic\Events\AssetReplaced;
use Statamic\Events\AssetSaved;
use Statamic\Events\BlueprintDeleted;
use Statamic\Events\BlueprintSaved;
use Statamic\Events\CollectionDeleted;
use Statamic\Events\CollectionSaved;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;
use Statamic\Events\FieldsetDeleted;
use Statamic\Events\FieldsetSaved;
use Statamic\Events\GlobalVariablesDeleted;
use Statamic\Events\GlobalVariablesSaved;
use Statamic\Events\LocalizedTermSaved;
use Statamic\Events\NavTreeDeleted;
use Statamic\Events\NavTreeSaved;
use Statamic\Events\RevisionDeleted;
use Statamic\Events\SubmissionDeleted;
use Statamic\Events\SubmissionSaved;
use Statamic\Events\Subscriber;
use Statamic\Events\TaxonomyDeleted;
use Statamic\Events\TaxonomySaved;
use Statamic\Events\TermDeleted;
use Statamic\Events\TermSaved;
use Statamic\Events\UserDeleted;
use Statamic\Events\UserSaved;

/**
 * Keeps the usage index in step with content changes, so the asset browser is
 * accurate without a manual rebuild.
 *
 * Queued, like Statamic's own `UpdateAssetReferences`, so a save doesn't wait
 * for it. Under the `sync` connection that means it runs inline, which is the
 * right fallback for a site without a worker.
 */
class UpdateUsageIndex extends Subscriber implements ShouldQueue
{
    protected $listeners = [
        EntrySaved::class => 'handleEntrySaved',
        EntryDeleted::class => 'handleEntryDeleted',
        RevisionDeleted::class => 'handleRevisionDeleted',
        TermSaved::class => 'handleTermSaved',
        LocalizedTermSaved::class => 'handleLocalizedTermSaved',
        TermDeleted::class => 'handleTermDeleted',
        GlobalVariablesSaved::class => 'handleGlobalVariablesSaved',
        GlobalVariablesDeleted::class => 'handleGlobalVariablesDeleted',
        NavTreeSaved::class => 'handleNavTreeSaved',
        NavTreeDeleted::class => 'handleNavTreeDeleted',
        UserSaved::class => 'handleUserSaved',
        UserDeleted::class => 'handleUserDeleted',
        SubmissionSaved::class => 'handleSubmissionSaved',
        SubmissionDeleted::class => 'handleSubmissionDeleted',
        AssetSaved::class => 'handleAssetSaved',
        AssetReplaced::class => 'handleAssetReplaced',
        AssetDeleted::class => 'handleAssetDeleted',
        CollectionSaved::class => 'handleCollectionSaved',
        CollectionDeleted::class => 'handleCollectionDeleted',
        TaxonomySaved::class => 'handleTaxonomySaved',
        TaxonomyDeleted::class => 'handleTaxonomyDeleted',
        AddonSettingsSaved::class => 'handleAddonSettingsSaved',
        BlueprintSaved::class => 'handleBlueprintSaved',
        BlueprintDeleted::class => 'handleBlueprintDeleted',
        FieldsetSaved::class => 'handleFieldsetSaved',
        FieldsetDeleted::class => 'handleFieldsetDeleted',
    ];

    public function handleEntrySaved(EntrySaved $event): void
    {
        if (! Settings::scans('entries')) {
            return;
        }

        $items = [Items::fromEntry($event->entry)];

        if ($draft = Items::fromEntryWorkingCopy($event->entry)) {
            $items[] = $draft;
        } else {
            /*
             * A published draft is gone as a separate source, so whatever it
             * contributed has to go with it.
             */
            $this->forgetKeys(Usage::makeItemKey('entry_draft', $event->entry->id(), $event->entry->locale()));
        }

        $this->update(...$items);
    }

    public function handleEntryDeleted(EntryDeleted $event): void
    {
        $this->forgetKeys(
            Usage::makeItemKey('entry', $event->entry->id(), $event->entry->locale()),
            Usage::makeItemKey('entry_draft', $event->entry->id(), $event->entry->locale()),
        );
    }

    /**
     * Publishing or discarding a draft deletes the working copy, but Statamic
     * saves the entry first, so `handleEntrySaved` still sees a draft that is
     * on its way out. The revision's own deletion is the reliable moment.
     */
    public function handleRevisionDeleted(RevisionDeleted $event): void
    {
        if (! $event->revision->isWorkingCopy()) {
            return;
        }

        // Entry revision keys are `collections/{collection}/{site}/{id}`.
        $parts = explode('/', $event->revision->key());

        if (count($parts) !== 4 || $parts[0] !== 'collections') {
            return;
        }

        $this->forgetKeys(Usage::makeItemKey('entry_draft', $parts[3], $parts[2]));
    }

    public function handleTermSaved(TermSaved $event): void
    {
        if (! Settings::scans('terms')) {
            return;
        }

        $this->update(...$event->term->localizations()
            ->map(fn ($term) => Items::fromTerm($term))
            ->values()
            ->all());
    }

    public function handleLocalizedTermSaved(LocalizedTermSaved $event): void
    {
        if (! Settings::scans('terms')) {
            return;
        }

        $this->update(Items::fromTerm($event->term));
    }

    public function handleTermDeleted(TermDeleted $event): void
    {
        $this->forgetKeys(...$event->term->localizations()
            ->map(fn ($term) => Usage::makeItemKey('term', $term->id(), $term->locale()))
            ->values()
            ->all());
    }

    public function handleGlobalVariablesSaved(GlobalVariablesSaved $event): void
    {
        if (! Settings::scans('globals')) {
            return;
        }

        $this->update(Items::fromGlobalVariables($event->variables));
    }

    public function handleGlobalVariablesDeleted(GlobalVariablesDeleted $event): void
    {
        $this->forgetKeys(Usage::makeItemKey(
            'global',
            $event->variables->globalSet()->handle(),
            $event->variables->locale(),
        ));
    }

    public function handleNavTreeSaved(NavTreeSaved $event): void
    {
        if (! Settings::scans('navs')) {
            return;
        }

        $this->update(Items::fromNavTree($event->tree));
    }

    public function handleNavTreeDeleted(NavTreeDeleted $event): void
    {
        $this->forgetKeys(Usage::makeItemKey('nav', $event->tree->handle(), $event->tree->locale()));
    }

    public function handleUserSaved(UserSaved $event): void
    {
        if (! Settings::scans('users')) {
            return;
        }

        $this->update(Items::fromUser($event->user));
    }

    public function handleUserDeleted(UserDeleted $event): void
    {
        $this->forgetKeys(Usage::makeItemKey('user', $event->user->id(), null));
    }

    public function handleSubmissionSaved(SubmissionSaved $event): void
    {
        if (! Settings::scans('form_submissions')) {
            return;
        }

        $this->update(Items::fromSubmission($event->submission));
    }

    public function handleSubmissionDeleted(SubmissionDeleted $event): void
    {
        $submission = $event->submission;

        $this->forgetKeys(Usage::makeItemKey(
            'form_submission',
            "{$submission->form()->handle()}::{$submission->id()}",
            null,
        ));
    }

    public function handleAssetSaved(AssetSaved $event): void
    {
        $asset = $event->asset;

        /*
         * A rename fires this event with the new path. References in content are
         * rewritten by Statamic's own listener, which fires the content events
         * that patch the index, but the old id's rows would linger until then,
         * and forever if reference updating is switched off.
         */
        if (($original = $asset->getOriginal('path')) && $original !== $asset->path()) {
            $this->forgetAssets("{$asset->container()->handle()}::{$original}");
        }

        if (Settings::scans('assets') && Containers::includes($asset->container()->handle())) {
            $this->update(Items::fromAsset($asset));
        }
    }

    public function handleAssetReplaced(AssetReplaced $event): void
    {
        $original = $event->originalAsset;

        if ($original->id() !== $event->newAsset->id()) {
            $this->forgetAssets($original->id());
            $this->forgetKeys(Usage::makeItemKey('asset', $original->id(), null));
        }

        if (Settings::scans('assets') && Containers::includes($event->newAsset->container()->handle())) {
            $this->update(Items::fromAsset($event->newAsset));
        }
    }

    public function handleAssetDeleted(AssetDeleted $event): void
    {
        $asset = $event->asset;

        $this->forgetAssets($asset->id());
        $this->forgetKeys(Usage::makeItemKey('asset', $asset->id(), null));
    }

    public function handleCollectionSaved(CollectionSaved $event): void
    {
        if (! Settings::scans('collection_cascades')) {
            return;
        }

        $this->update(Items::fromCollection($event->collection));
    }

    /**
     * Statamic deletes a collection's entries and trees but leaves its
     * blueprints on disk, and fires no BlueprintDeleted for them, so their
     * defaults have to go from here or they'd hold assets until the next
     * rebuild dropped them anyway.
     */
    public function handleCollectionDeleted(CollectionDeleted $event): void
    {
        $this->forgetKeys(Usage::makeItemKey('collection', $event->collection->handle(), null));

        $this->forgetPrefixes(Items::blueprintKeyPrefix('collections/'.$event->collection->handle()));
    }

    public function handleTaxonomySaved(TaxonomySaved $event): void
    {
        if (! Settings::scans('taxonomy_cascades')) {
            return;
        }

        $this->update(Items::fromTaxonomy($event->taxonomy));
    }

    /** Term blueprints outlive their taxonomy the same way. */
    public function handleTaxonomyDeleted(TaxonomyDeleted $event): void
    {
        $this->forgetKeys(Usage::makeItemKey('taxonomy', $event->taxonomy->handle(), null));

        $this->forgetPrefixes(Items::blueprintKeyPrefix('taxonomies/'.$event->taxonomy->handle()));
    }

    public function handleAddonSettingsSaved(AddonSettingsSaved $event): void
    {
        if (! Settings::scans('addon_settings')) {
            return;
        }

        $this->update(Items::fromAddonSettings($event->settings->addon(), $event->settings->raw()));
    }

    public function handleBlueprintSaved(BlueprintSaved $event): void
    {
        if (! Settings::scans('blueprints')) {
            return;
        }

        $this->update(Items::fromBlueprint($event->blueprint));
    }

    public function handleBlueprintDeleted(BlueprintDeleted $event): void
    {
        $this->forgetKeys(Usage::makeItemKey('blueprint', Items::blueprintKey($event->blueprint), null));
    }

    public function handleFieldsetSaved(FieldsetSaved $event): void
    {
        if (! Settings::scans('blueprints')) {
            return;
        }

        $this->update(Items::fromFieldset($event->fieldset));
    }

    public function handleFieldsetDeleted(FieldsetDeleted $event): void
    {
        $this->forgetKeys(Usage::makeItemKey('fieldset', $event->fieldset->handle(), null));
    }

    private function update(Item ...$items): void
    {
        if (Settings::autoUpdates()) {
            $this->updater()->update(...$items);
        }
    }

    private function forgetKeys(string ...$itemKeys): void
    {
        if (Settings::autoUpdates()) {
            $this->updater()->forget(...$itemKeys);
        }
    }

    private function forgetPrefixes(string ...$prefixes): void
    {
        if (Settings::autoUpdates()) {
            $this->updater()->forgetPrefixes(...$prefixes);
        }
    }

    private function forgetAssets(string ...$assetIds): void
    {
        if (Settings::autoUpdates()) {
            $this->updater()->forgetAssets(...$assetIds);
        }
    }

    private function updater(): ItemUsageUpdater
    {
        return new ItemUsageUpdater(new IndexStore);
    }
}
