<?php

namespace KeyAgency\AssetUsage\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use KeyAgency\AssetUsage\ServiceProvider;
use KeyAgency\AssetUsage\Support\Defaults;
use Statamic\Addons\AddonRepository;
use Statamic\Addons\Manifest;
use Statamic\Facades\Addon;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Facades\YAML;
use Statamic\Support\Str;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function setUp(): void
    {
        parent::setUp();

        Defaults::reset();

        File::deleteDirectory(storage_path('statamic/asset-usage'));

        /*
         * Blueprints, fieldsets and addon settings are written to disk, so
         * without this one test's would leak into the next.
         */
        foreach (['addons', 'blueprints', 'fieldsets'] as $directory) {
            File::deleteDirectory(resource_path($directory));
        }

        File::delete(config_path('statamic/asset-usage.php'));
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // Multiple users and roles are a Pro feature, and the permission tests need both.
        $app['config']->set('statamic.editions.pro', true);
    }

    /**
     * A container backed by a faked disk, so `files()` returns real paths and
     * `url()` gives us something to scan for.
     *
     * @param  string[]  $files
     */
    protected function makeContainer(string $handle = 'assets', array $files = [], string $url = '/assets'): \Statamic\Contracts\Assets\AssetContainer
    {
        Storage::fake($handle, ['url' => $url]);

        foreach ($files as $path) {
            Storage::disk($handle)->put($path, 'fake-file-contents');
        }

        return tap(AssetContainer::make($handle)->disk($handle)->title(ucfirst($handle)))->save();
    }

    /**
     * Register an asset so it has meta/data of its own. Not needed just to be
     * found by the scanner, because the file listing is enough for that.
     */
    protected function makeAsset(string $container, string $path, array $data = [])
    {
        return tap(AssetContainer::findByHandle($container)->makeAsset($path)->data($data))->save();
    }

    protected function makeEntry(string $slug, array $data, string $collection = 'pages', ?string $site = null)
    {
        if (! Collection::findByHandle($collection)) {
            Collection::make($collection)
                ->title(ucfirst($collection))
                ->sites(Site::all()->map->handle()->all())
                ->save();
        }

        $entry = Entry::make()->collection($collection)->slug($slug)->data($data);

        if ($site) {
            $entry->locale($site);
        }

        return tap($entry)->save();
    }

    /**
     * Register an addon with saved settings. Written to
     * `resources/addons/{slug}.yaml` directly rather than through `save()`,
     * which needs a booted addon.
     *
     * @param  string  $id  vendor/package; the slug is the package half, as it
     *                      is for every addon that doesn't override it
     */
    protected function fakeAddon(string $id, array $settings = []): void
    {
        $slug = Str::after($id, '/');

        $manifest = app(Manifest::class);
        $manifest->manifest = array_merge($manifest->addons()->all(), [
            $id => [
                'id' => $id,
                'slug' => $slug,
                'version' => '1.0.0',
                'namespace' => Str::studly(Str::before($id, '/')).'\\'.Str::studly($slug),
                'autoload' => 'src/',
                'provider' => ServiceProvider::class,
            ],
        ]);

        // The repository memoises the addon list, so it has to be rebuilt.
        app()->forgetInstance(AddonRepository::class);
        app()->instance(AddonRepository::class, new AddonRepository);
        Addon::clearResolvedInstances();

        File::ensureDirectoryExists(resource_path('addons'));
        File::put(resource_path("addons/{$slug}.yaml"), YAML::dump($settings));
    }

    /**
     * A blueprint whose fields are assets fields with a `default`.
     *
     * @param  array<string, string>  $defaults  field handle => default path
     */
    protected function makeBlueprint(?string $namespace, string $handle, array $defaults): void
    {
        $fields = [];

        foreach ($defaults as $field => $default) {
            $fields[] = [
                'handle' => $field,
                'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1, 'default' => $default],
            ];
        }

        $blueprint = Blueprint::make($handle)->setContents(['tabs' => ['main' => ['fields' => $fields]]]);

        if ($namespace) {
            $blueprint->setNamespace($namespace);
        }

        $blueprint->save();
    }

    protected function setSites(array $sites): void
    {
        Site::setSites($sites);
    }

    protected function superUser()
    {
        return User::findByEmail('super@example.com')
            ?? tap(User::make()->email('super@example.com')->makeSuper())->save();
    }
}
