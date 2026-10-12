<?php

namespace KeyAgency\AssetUsage\Widgets;

use KeyAgency\AssetUsage\Http\Controllers\Concerns\AuthorizesAssetUsage;
use KeyAgency\AssetUsage\ServiceProvider;
use KeyAgency\AssetUsage\Support\NavIcon;
use KeyAgency\AssetUsage\Support\Settings;
use Statamic\Widgets\VueComponent;
use Statamic\Widgets\Widget;

/**
 * The dashboard widget. Its numbers come from the summary endpoint once the
 * dashboard has loaded: counting them lists every asset in every container,
 * which would hold up the whole dashboard on a large library.
 */
class AssetUsage extends Widget
{
    use AuthorizesAssetUsage;

    public function component()
    {
        // Without a component Statamic leaves the widget off the dashboard.
        if (! $this->hasPermission(ServiceProvider::PERMISSION_VIEW)) {
            return null;
        }

        return VueComponent::render('asset-usage-widget', [
            'title' => $this->config('title', __('asset-usage::messages.nav_title')),
            'icon' => NavIcon::svg(),
            'summaryUrl' => cp_route('asset-usage.summary'),
            'overviewUrl' => cp_route('asset-usage.index'),
            'unusedUrl' => cp_route('asset-usage.index', ['usage' => 'unused']),
            // Null for the pages the user can't open, which leaves those links out.
            'compressibleUrl' => Settings::compressionEnabled() && $this->canCompress()
                ? cp_route('asset-usage.compression', ['compression' => 'compressible'])
                : null,
            'logUrl' => $this->canViewLog() ? cp_route('asset-usage.log') : null,
        ]);
    }
}
