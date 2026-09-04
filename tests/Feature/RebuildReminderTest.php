<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use KeyAgency\AssetUsage\Support\Settings;
use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\Usage\IndexBuilder;
use KeyAgency\AssetUsage\Usage\IndexStore;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Tools page suggests a rebuild once the usage data has gone long enough
 * without one. Deliberately separate from `isStale()`: an aged index is still
 * built for the settings in force, it has just had no full pass in a while.
 */
class RebuildReminderTest extends TestCase
{
    private function buildDaysAgo(int $days): IndexStore
    {
        $this->travelTo(now()->subDays($days));

        (new IndexBuilder(new IndexStore))->build();

        $this->travelBack();

        return new IndexStore;
    }

    #[Test]
    public function an_index_built_just_now_is_not_aged()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);

        $this->assertFalse($this->buildDaysAgo(0)->isAged());
    }

    /**
     * With auto_update on the index is patched on every save, so age says
     * little and the reminder waits a lot longer.
     */
    #[Test]
    public function it_waits_thirty_days_while_the_index_updates_itself()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);

        $this->assertFalse($this->buildDaysAgo(29)->isAged());
        $this->assertTrue($this->buildDaysAgo(31)->isAged());
    }

    /**
     * With auto_update off nothing changes the index in between, so its age is
     * exactly how far behind the content it has fallen.
     */
    #[Test]
    public function it_waits_seven_days_when_nothing_updates_the_index()
    {
        config(['statamic.asset-usage.auto_update' => false]);

        $this->makeContainer('assets', ['img/photo.jpg']);

        $this->assertFalse($this->buildDaysAgo(6)->isAged());
        $this->assertTrue($this->buildDaysAgo(8)->isAged());
    }

    #[Test]
    public function zero_days_turns_the_reminder_off()
    {
        config(['statamic.asset-usage.rebuild_reminder_days' => ['auto_update' => 0, 'manual' => 0]]);

        $this->makeContainer('assets', ['img/photo.jpg']);

        $this->assertFalse($this->buildDaysAgo(400)->isAged());
    }

    #[Test]
    public function the_threshold_is_configurable()
    {
        config(['statamic.asset-usage.rebuild_reminder_days' => ['auto_update' => 2, 'manual' => 1]]);

        $this->makeContainer('assets', ['img/photo.jpg']);

        $this->assertTrue($this->buildDaysAgo(3)->isAged());
    }

    /**
     * Laravel merges the key as a whole, the trap `scanned_types` fell into. A
     * config naming only one half must not read the other as 0, which would
     * switch the reminder off without saying so.
     */
    #[Test]
    public function a_config_that_names_only_one_half_keeps_the_shipped_default_for_the_other()
    {
        config(['statamic.asset-usage.rebuild_reminder_days' => ['manual' => 3]]);

        $this->assertSame(30, Settings::rebuildReminderDays());

        config(['statamic.asset-usage.auto_update' => false]);

        $this->assertSame(3, Settings::rebuildReminderDays());
    }

    #[Test]
    public function a_config_without_the_key_at_all_still_gets_the_shipped_defaults()
    {
        config(['statamic.asset-usage.rebuild_reminder_days' => null]);

        $this->assertSame(30, Settings::rebuildReminderDays());

        config(['statamic.asset-usage.auto_update' => false]);

        $this->assertSame(7, Settings::rebuildReminderDays());
    }

    /**
     * An out-of-date index already says so in its own words, and two warnings
     * about the same button read as two problems.
     */
    #[Test]
    public function a_stale_index_is_not_also_reported_as_aged()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);

        $store = $this->buildDaysAgo(400);

        config(['statamic.asset-usage.scan_urls' => false]);

        $this->assertTrue($store->isStale());
        $this->assertFalse($store->isAged());
    }

    #[Test]
    public function an_index_that_was_never_built_is_not_reported_as_aged()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);

        $this->assertFalse((new IndexStore)->isAged());
    }

    /**
     * The page gets its index state from the status endpoint, not the initial
     * render, so that is where the flag has to arrive.
     */
    #[Test]
    public function the_tools_page_is_told_the_rebuild_is_overdue()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->buildDaysAgo(31);

        $this->actingAs($this->superUser())
            ->get(cp_route('asset-usage.status'))
            ->assertOk()
            ->assertJsonPath('index.aged', true)
            ->assertJsonPath('index.stale', false)
            ->assertJsonPath('index.auto_update', true);
    }

    #[Test]
    public function the_tools_page_is_told_when_the_rebuild_is_not_overdue()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->buildDaysAgo(1);

        $this->actingAs($this->superUser())
            ->get(cp_route('asset-usage.status'))
            ->assertOk()
            ->assertJsonPath('index.aged', false);
    }
}
