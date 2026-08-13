<?php

namespace KeyAgency\AssetUsage\Listeners;

use KeyAgency\AssetUsage\Support\Settings;
use KeyAgency\AssetUsage\Usage\Containers;
use Statamic\Events\AssetContainerBlueprintFound;

/**
 * Adds the "Used in" field to every enabled container's blueprint. One field
 * covers both places usage shows up: the asset editor renders it as a panel,
 * and `Blueprint::columns()` turns it into the browser column.
 *
 * `visibility: computed` is what keeps it out of the saved data — see
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

        $event->blueprint->ensureField(self::HANDLE, $this->config());
    }

    private function config(): array
    {
        $panel = Settings::showsEditorPanel();

        return [
            'type' => self::HANDLE,
            /*
             * One `display` drives both the editor field label and the browser
             * column header (`Blueprint::columns()` reads it), so it says "Used"
             * — the column is a tick or a cross. The panel spells out "Used in
             * N places" on its own toggle.
             *
             * Without the panel the field is only here to produce the column,
             * so it renders nothing and hides its label in the editor.
             */
            'display' => $panel ? __('asset-usage::messages.column_label') : '',
            'hide_display' => ! $panel,
            'visibility' => 'computed',
            'listable' => Settings::showsListingColumn(),
            'panel' => $panel,
        ];
    }
}
