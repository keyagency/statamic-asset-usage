<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use KeyAgency\AssetUsage\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ConfigPublishingTest extends TestCase
{
    /**
     * Statamic's AddonServiceProvider publishes an addon's config on its own,
     * under the same tag this addon uses. Left on, a single publish wrote the
     * file to two places.
     */
    #[Test]
    public function the_config_is_published_to_one_place()
    {
        $paths = ServiceProvider::pathsToPublish(null, 'asset-usage-config');

        $this->assertSame(
            [config_path('statamic/asset-usage.php')],
            array_values($paths),
        );
    }

    #[Test]
    public function the_config_is_read_under_the_statamic_key()
    {
        $this->assertSame('*', config('statamic.asset-usage.containers'));
        $this->assertNull(config('asset-usage.containers'));
    }
}
