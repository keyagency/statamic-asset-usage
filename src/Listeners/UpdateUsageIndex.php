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
use Statamic\Events\AssetDeleted;
use Statamic\Events\AssetReplaced;
use Statamic\Events\AssetSaved;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;
use Statamic\Events\GlobalVariablesDeleted;
use Statamic\Events\GlobalVariablesSaved;
use Statamic\Events\LocalizedTermSaved;
use Statamic\Events\NavTreeDeleted;
use Statamic\Events\NavTreeSaved;
use Statamic\Events\SubmissionDeleted;
use Statamic\Events\SubmissionSaved;
use Statamic\Events\Subscriber;
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
         * that patch the index — but the old id's rows would linger until then,
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
