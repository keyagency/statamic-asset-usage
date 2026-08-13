<?php

namespace KeyAgency\AssetUsage;

use KeyAgency\AssetUsage\Console\Commands\DoctorCommand;
use KeyAgency\AssetUsage\Console\Commands\IndexCommand;
use KeyAgency\AssetUsage\Console\Commands\UnusedCommand;
use KeyAgency\AssetUsage\Fieldtypes\AssetUsage;
use KeyAgency\AssetUsage\Listeners\InjectUsageField;
use KeyAgency\AssetUsage\Listeners\UpdateUsageIndex;
use KeyAgency\AssetUsage\Query\Scopes\Filters\AssetUsage as UsageFilter;
use KeyAgency\AssetUsage\Support\NavIcon;
use Statamic\Events\AssetContainerBlueprintFound;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    public const PERMISSION_VIEW = 'view asset usage';

    public const PERMISSION_DELETE = 'delete unused assets';

    // Statamic would publish a second copy to config/asset-usage.php; we merge and publish our own below.
    protected $config = false;

    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $fieldtypes = [
        AssetUsage::class,
    ];

    protected $scopes = [
        UsageFilter::class,
    ];

    protected $commands = [
        DoctorCommand::class,
        IndexCommand::class,
        UnusedCommand::class,
    ];

    protected $listen = [
        AssetContainerBlueprintFound::class => [
            InjectUsageField::class,
        ],
    ];

    protected $subscribe = [
        UpdateUsageIndex::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(
            __DIR__.'/../config/asset-usage.php',
            'statamic.asset-usage'
        );
    }

    public function bootAddon(): void
    {
        $this->registerPermissions();
        $this->registerNav();
        $this->registerPublishables();
    }

    protected function registerPermissions(): void
    {
        Permission::group('asset_usage', __('asset-usage::messages.permissions.group'), function () {
            Permission::register(self::PERMISSION_VIEW)
                ->label(__('asset-usage::messages.permissions.view'))
                ->children([
                    Permission::make(self::PERMISSION_DELETE)
                        ->label(__('asset-usage::messages.permissions.delete')),
                ]);
        });
    }

    protected function registerNav(): void
    {
        Nav::extend(function ($nav) {
            $nav->tools(__('asset-usage::messages.nav_title'))
                ->icon(NavIcon::svg())
                ->route('asset-usage.index')
                ->can(self::PERMISSION_VIEW);
        });
    }

    protected function registerPublishables(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // php artisan vendor:publish --tag=asset-usage-config
        $this->publishes([
            __DIR__.'/../config/asset-usage.php' => config_path('statamic/asset-usage.php'),
        ], 'asset-usage-config');
    }
}
