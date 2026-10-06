<?php

namespace KeyAgency\AssetUsage\Listeners;

use KeyAgency\AssetUsage\Support\Settings;
use KeyAgency\AssetUsage\Usage\Containers;
use KeyAgency\AssetUsage\Usage\SortIndex;
use Statamic\Events\AssetContainerBlueprintFound;
use Statamic\Http\Controllers\CP\Assets\BrowserController;

/**
 * Adds the "Used in" field to every enabled container's blueprint. One field
 * covers both places usage shows up: the asset editor renders it as a panel,
 * and `Blueprint::columns()` turns it into the browser column.
 *
 * `visibility: computed` is what keeps it out of the saved data. See
 * Fieldtypes\AssetUsage.
 */
class InjectUsageField
{
    public const HANDLE = 'asset_usage';

    public function handle(AssetContainerBlueprintFound $event): void
    {
        if (! Settings::showsEditorPanel() && ! Settings::showsListingColumn()) {
            return;
        }

        $container = $event->container ?? $event->asset?->container();

        if (! $container || ! Containers::includes($container->handle())) {
            return;
        }

        $blueprint = $event->blueprint;

        /*
         * In a section of its own, headed with the addon's name, so it reads as
         * part of the addon rather than one of the container's own fields. Not
         * without the panel, where the field only exists for the column and
         * renders nothing, and not when a site placed the field in its
         * blueprint itself, or this blueprint already has it: ensureField()
         * keeps that position and merges the config.
         */
        if (! Settings::showsEditorPanel() || $blueprint->hasField(self::HANDLE)) {
            $blueprint->ensureField(self::HANDLE, $this->config());

            return;
        }

        $contents = $blueprint->contents();
        $tab = array_key_first($contents['tabs'] ?? []) ?? 'main';

        $contents['tabs'][$tab]['sections'][] = [
            'display' => __('asset-usage::messages.nav_title'),
            'fields' => [['handle' => self::HANDLE, 'field' => $this->config()]],
        ];

        $blueprint->setContents($contents);
    }

    /**
     * Computed fields can't be sorted, so only in the asset browser's listing
     * requests the field is read-only instead, which makes the column
     * sortable. Saving an asset is always another request, where it stays
     * computed and so out of the saved data.
     */
    private function sortsHere(): bool
    {
        $route = request()->route();

        return Settings::showsListingColumn()
            && SortIndex::supported()
            && $route
            && $route->getControllerClass() === BrowserController::class
            && in_array($route->getActionMethod(), ['folder', 'search'], true);
    }

    private function config(): array
    {
        $panel = Settings::showsEditorPanel();

        return [
            'type' => self::HANDLE,
            /*
             * One `display` drives both the editor field label and the browser
             * column header (`Blueprint::columns()` reads it), so it says "Used",
             * because the column is a tick or a cross. The panel spells out "Used in
             * N places" on its own toggle.
             *
             * Without the panel the field is only here to produce the column,
             * so it renders nothing and hides its label in the editor.
             */
            'display' => $panel ? __('asset-usage::messages.column_label') : '',
            'hide_display' => ! $panel,
            'visibility' => $this->sortsHere() ? 'read_only' : 'computed',
            'sortable' => $this->sortsHere(),
            'listable' => Settings::showsListingColumn(),
            'panel' => $panel,
        ];
    }
}
