<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use KeyAgency\AssetUsage\Jobs\BuildIndex;
use KeyAgency\AssetUsage\Support\Run;
use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\Usage\IndexBuilder;
use KeyAgency\AssetUsage\Usage\IndexStore;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/** A rebuild as the CP follows it: running once, its progress, stopping it, and one that died. */
class IndexRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/photo.jpg']);
    }

    /** With a real queue, so a rebuild waits for a worker instead of running in the request. */
    private function queued(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
    }

    private function state(): array
    {
        return $this->actingAs($this->superUser())->getJson(cp_route('asset-usage.status'))->json('index');
    }

    #[Test]
    public function a_running_rebuild_is_not_started_again()
    {
        $this->queued();
        $user = $this->superUser();

        $this->actingAs($user)->postJson(cp_route('asset-usage.rebuild'))->assertOk();
        $this->actingAs($user)->postJson(cp_route('asset-usage.rebuild'))->assertOk()->assertJsonPath('index.building', true);

        Queue::assertPushed(BuildIndex::class, 1);
    }

    #[Test]
    public function the_progress_is_estimated_from_the_last_build()
    {
        $scanned = (new IndexStore)->meta()['items_scanned'];
        $this->queued();

        $this->actingAs($this->superUser())->postJson(cp_route('asset-usage.rebuild'))
            ->assertJsonPath('index.progress', ['done' => 0, 'total' => $scanned, 'estimated' => true, 'stopping' => false]);
    }

    /** A worker that never came, or a build that died without a word, would otherwise say "checking" forever. */
    #[Test]
    public function a_rebuild_without_a_sign_of_life_no_longer_counts_as_running()
    {
        $this->queued();
        $this->actingAs($this->superUser())->postJson(cp_route('asset-usage.rebuild'));

        $this->travel(Run::STALL_MINUTES + 1)->minutes();

        $state = $this->state();

        $this->assertFalse($state['building']);
        $this->assertTrue($state['stale']);
        $this->assertSame('not_started', $state['failure']['reason']);
    }

    /** How a site stuck on "checking" before this version is left: flagged as building, without a run. */
    #[Test]
    public function a_build_flag_left_without_a_run_reads_as_unfinished()
    {
        (new IndexStore)->markBuilding();

        $state = $this->state();

        $this->assertFalse($state['building']);
        $this->assertSame('stalled', $state['failure']['reason']);
    }

    #[Test]
    public function stopping_a_rebuild_puts_the_index_back()
    {
        $builtAt = (new IndexStore)->meta()['built_at'];
        $this->queued();
        $user = $this->superUser();

        $this->actingAs($user)->postJson(cp_route('asset-usage.rebuild'));

        $state = $this->actingAs($user)->postJson(cp_route('asset-usage.stop'))->assertOk()->json('index');

        $this->assertFalse($state['building']);
        $this->assertFalse($state['stale']);
        $this->assertNull($state['failure']);
        $this->assertSame($builtAt, $state['built_at']);
    }

    /** The build notices on its next beat, puts the index back and ends. */
    #[Test]
    public function a_busy_rebuild_ends_itself_when_asked_to_stop()
    {
        $store = new IndexStore;
        $builtAt = $store->meta()['built_at'];
        $run = IndexBuilder::startRun($store);
        Run::index()->beat($run, 0, force: true);

        $state = $this->actingAs($this->superUser())->postJson(cp_route('asset-usage.stop'))->json('index');

        $this->assertTrue($state['building']);
        $this->assertTrue($state['progress']['stopping']);

        $this->assertNull((new IndexBuilder($store))->build(run: $run));

        $state = $this->state();

        $this->assertFalse($state['building']);
        $this->assertFalse($state['stale']);
        $this->assertSame($builtAt, $state['built_at']);
    }

    #[Test]
    public function stopping_the_first_build_leaves_no_index()
    {
        $store = new IndexStore;
        $store->delete();
        $this->queued();

        IndexBuilder::startRun($store);

        $this->actingAs($this->superUser())->postJson(cp_route('asset-usage.stop'))->assertOk();

        $this->assertFalse($store->exists());
    }

    #[Test]
    public function a_stopped_rebuild_ends_without_writing_its_index()
    {
        $store = new IndexStore;
        $store->delete();
        $run = IndexBuilder::startRun($store);

        Run::index()->stop();
        $store->cancelBuilding();

        (new BuildIndex($run))->handle();

        $this->assertFalse($store->exists());
    }

    #[Test]
    public function a_newer_rebuild_replaces_the_one_before_it()
    {
        $store = new IndexStore;
        $first = IndexBuilder::startRun($store);
        $second = IndexBuilder::startRun($store);

        $this->assertNull((new IndexBuilder($store))->build(run: $first));
        $this->assertNotNull((new IndexBuilder($store))->build(run: $second));
        $this->assertFalse($store->isStale());
    }

    #[Test]
    public function a_failed_rebuild_says_why_and_puts_the_index_back()
    {
        $run = IndexBuilder::startRun(new IndexStore);

        (new BuildIndex($run))->failed(new RuntimeException('Allowed memory size exhausted'));

        $state = $this->state();

        $this->assertFalse($state['building']);
        $this->assertFalse($state['stale']);
        $this->assertSame('error', $state['failure']['reason']);
        $this->assertSame('Allowed memory size exhausted', $state['failure']['message']);
    }

    #[Test]
    public function a_long_error_is_shortened()
    {
        $run = IndexBuilder::startRun(new IndexStore);

        (new BuildIndex($run))->failed(new RuntimeException(str_repeat('SQLSTATE ', 100)));

        $this->assertLessThanOrEqual(Run::MESSAGE_LENGTH + 3, mb_strlen($this->state()['failure']['message']));
    }

    #[Test]
    public function a_finished_rebuild_leaves_nothing_to_report()
    {
        $this->actingAs($this->superUser())->postJson(cp_route('asset-usage.rebuild'));

        $state = $this->state();

        $this->assertFalse($state['building']);
        $this->assertNull($state['progress']);
        $this->assertNull($state['failure']);
        $this->assertFileDoesNotExist(Run::index()->path());
    }

    #[Test]
    public function the_command_replaces_a_running_rebuild()
    {
        $store = new IndexStore;
        $run = IndexBuilder::startRun($store);

        $this->artisan('statamic:asset-usage:index')->assertSuccessful();

        // The command started a run of its own, which replaced the one above.
        $this->assertFalse(Run::index()->isCurrent($run));
        $this->assertFalse($store->isBuilding());
    }
}
