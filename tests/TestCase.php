<?php

namespace KeyAgency\AssetUsage\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use KeyAgency\AssetUsage\ServiceProvider;
use KeyAgency\AssetUsage\Support\Defaults;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;
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
     * found by the scanner — the file listing is enough for that.
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
