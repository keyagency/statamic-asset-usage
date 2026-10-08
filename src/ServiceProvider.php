<?php

namespace KeyAgency\AssetUsage;

use Illuminate\Console\Scheduling\Schedule;
use KeyAgency\AssetUsage\Console\Commands\AnalyzeCommand;
use KeyAgency\AssetUsage\Console\Commands\CompressCommand;
use KeyAgency\AssetUsage\Console\Commands\DoctorCommand;
use KeyAgency\AssetUsage\Console\Commands\IndexCommand;
use KeyAgency\AssetUsage\Console\Commands\LogCommand;
use KeyAgency\AssetUsage\Console\Commands\PruneOriginalsCommand;
use KeyAgency\AssetUsage\Console\Commands\UnusedCommand;
use KeyAgency\AssetUsage\Fieldtypes\AssetUsage;
use KeyAgency\AssetUsage\Listeners\AnalyzeUploadedImage;
use KeyAgency\AssetUsage\Listeners\InjectUsageField;
use KeyAgency\AssetUsage\Listeners\KeepCompressionDataWithAssets;
use KeyAgency\AssetUsage\Listeners\LogAssetDeletion;
use KeyAgency\AssetUsage\Listeners\UpdateUsageIndex;
use KeyAgency\AssetUsage\Query\Scopes\Filters\AssetUsage as UsageFilter;
use KeyAgency\AssetUsage\Support\NavIcon;
use KeyAgency\AssetUsage\Support\Settings;
use Statamic\Events\AssetContainerBlueprintFound;
use Statamic\Events\AssetReuploaded;
use Statamic\Events\AssetUploaded;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    public const PERMISSION_VIEW = 'view asset usage';

    public const PERMISSION_DELETE = 'delete unused assets';

    public const PERMISSION_COMPRESS = 'compress assets';

    public const PERMISSION_LOG = 'view asset log';

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

    // Statamic would use the package name; the views follow the slug like the lang files do.
    protected $viewNamespace = 'asset-usage';

    protected $fieldtypes = [
        AssetUsage::class,
    ];

    protected $scopes = [
        UsageFilter::class,
    ];

    protected $commands = [
        AnalyzeCommand::class,
        CompressCommand::class,
        DoctorCommand::class,
        IndexCommand::class,
        LogCommand::class,
        PruneOriginalsCommand::class,
        UnusedCommand::class,
    ];

    protected $listen = [
        AssetContainerBlueprintFound::class => [
            InjectUsageField::class,
        ],
        AssetUploaded::class => [
            AnalyzeUploadedImage::class,
        ],
        AssetReuploaded::class => [
            AnalyzeUploadedImage::class,
        ],
    ];

    protected $subscribe = [
        UpdateUsageIndex::class,
        LogAssetDeletion::class,
        KeepCompressionDataWithAssets::class,
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
        $this->registerSchedule();
    }

    protected function registerPermissions(): void
    {
        Permission::group('asset_usage', __('asset-usage::messages.permissions.group'), function () {
            Permission::register(self::PERMISSION_VIEW)
                ->label(__('asset-usage::messages.permissions.view'))
                ->children([
                    Permission::make(self::PERMISSION_DELETE)
                        ->label(__('asset-usage::messages.permissions.delete')),
                    Permission::make(self::PERMISSION_COMPRESS)
                        ->label(__('asset-usage::messages.permissions.compress')),
                    Permission::make(self::PERMISSION_LOG)
                        ->label(__('asset-usage::messages.permissions.log')),
                ]);
        });
    }

    protected function registerNav(): void
    {
        Nav::extend(function ($nav) {
            $item = $nav->tools(__('asset-usage::messages.nav_title'))
                ->icon(NavIcon::svg())
                ->route('asset-usage.index')
                ->can(self::PERMISSION_VIEW);

            $item->children(array_values(array_filter([
                $nav->item(__('asset-usage::messages.nav.usage'))->route('asset-usage.index')->can(self::PERMISSION_VIEW),
                Settings::compressionEnabled()
                    ? $nav->item(__('asset-usage::messages.nav.compression'))->route('asset-usage.compression')->can(self::PERMISSION_COMPRESS)
                    : null,
                $nav->item(__('asset-usage::messages.nav.log'))->route('asset-usage.log')->can(self::PERMISSION_LOG),
            ])));
        });
    }

    /** Runs with the site's own scheduler; without one, originals are kept until pruned by hand. */
    protected function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(PruneOriginalsCommand::class)->daily();
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
