<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use KeyAgency\AssetUsage\Compression\AnalysisStore;
use KeyAgency\AssetUsage\Compression\Analyzer;
use KeyAgency\AssetUsage\Jobs\AnalyzeAllCompression;
use KeyAgency\AssetUsage\Jobs\AnalyzeCompression;
use KeyAgency\AssetUsage\Support\Run;
use KeyAgency\AssetUsage\Tests\Support\Images;
use KeyAgency\AssetUsage\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Statamic\Facades\Asset;

/** A full analysis as the CP follows it: its progress, stopping it, and one that died. */
class AnalysisRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.asset-usage.compression.max_dimension' => 200]);

        $this->makeContainer('assets');
        Storage::disk('assets')->put('img/heavy.jpg', Images::jpeg(600, 600, dpi: 300, quality: 98));
        Storage::disk('assets')->put('img/small.jpg', Images::jpeg(100, 100, quality: 30));

        // A real queue, so the batches wait for a worker and each step can be run by hand.
        config(['queue.default' => 'database']);
        Queue::fake();
    }

    private function compressionStatus(): array
    {
        return $this->actingAs($this->superUser())->getJson(cp_route('asset-usage.compress.status'))->json('compression');
    }

    /** Starts a run from the CP and lets the "worker" split it into batches. */
    private function startRun(): string
    {
        $this->actingAs($this->superUser())->postJson(cp_route('asset-usage.compress.analyze'))->assertOk();

        Queue::assertPushed(AnalyzeAllCompression::class, function (AnalyzeAllCompression $job) use (&$run) {
            $run = $job->run;
            $job->handle();

            return true;
        });

        return $run;
    }

    /** @return AnalyzeCompression[] */
    private function batches(): array
    {
        return Queue::pushed(AnalyzeCompression::class)->all();
    }

    #[Test]
    public function the_progress_counts_the_images_of_every_batch()
    {
        $this->startRun();

        $this->assertSame(['done' => 0, 'total' => 2, 'estimated' => false, 'stopping' => false], $this->compressionStatus()['progress']);

        foreach ($this->batches() as $batch) {
            $batch->handle();
        }

        $status = $this->compressionStatus();

        $this->assertFalse($status['analyzing']);
        $this->assertNull($status['progress']);
        $this->assertNull($status['failure']);
        $this->assertNotNull($status['analyzed_at']);
    }

    #[Test]
    public function each_batch_adds_its_images_to_the_count()
    {
        $ids = ['assets::img/heavy.jpg', 'assets::img/small.jpg'];
        $run = Run::analysis()->start();

        // A second batch still to come keeps the run going.
        (new AnalysisStore)->markAnalyzing(['one' => $ids, 'two' => ['assets::img/other.jpg']], 'fingerprint', $run);
        (new AnalyzeCompression($ids, 'one', run: $run))->handle();

        $this->assertSame(['done' => 2, 'total' => 3, 'estimated' => false, 'stopping' => false], Run::analysis()->progress());
    }

    /**
     * A busy run is asked to stop rather than cut off, so it can save what it
     * has first. The page keeps checking until the run has ended itself.
     */
    #[Test]
    public function a_busy_run_ends_itself_when_asked_to_stop()
    {
        $this->startRun();

        $response = $this->actingAs($this->superUser())->postJson(cp_route('asset-usage.compress.analyze.stop'))->assertOk();

        $this->assertTrue($response->json('compression.analyzing'));
        $this->assertTrue($response->json('compression.progress.stopping'));

        foreach ($this->batches() as $batch) {
            $batch->handle();
        }

        $status = $this->compressionStatus();

        $this->assertFalse($status['analyzing']);
        $this->assertNull($status['failure']);
        $this->assertNull($status['analyzed_at']);
        $this->assertSame([], (new AnalysisStore)->records());
        $this->assertFileDoesNotExist(Run::analysis()->path());
    }

    #[Test]
    public function a_run_no_worker_picked_up_yet_stops_at_once()
    {
        $user = $this->superUser();
        $this->actingAs($user)->postJson(cp_route('asset-usage.compress.analyze'));

        $response = $this->actingAs($user)->postJson(cp_route('asset-usage.compress.analyze.stop'))->assertOk();

        $this->assertFalse($response->json('compression.analyzing'));
        $this->assertNull($response->json('compression.failure'));
    }

    /** One that doesn't answer (the worker died just then) counts as stopped after a short while. */
    #[Test]
    public function a_run_that_does_not_answer_a_stop_counts_as_stopped()
    {
        $this->startRun();
        $this->actingAs($this->superUser())->postJson(cp_route('asset-usage.compress.analyze.stop'));

        $this->travel(Run::ACTIVE_SECONDS + 1)->seconds();

        $status = $this->compressionStatus();

        $this->assertFalse($status['analyzing']);
        $this->assertNull($status['failure']);
    }

    /** The callback returns false once the run is stopped, which a batch notices between two images. */
    #[Test]
    public function a_batch_stopped_halfway_keeps_what_it_analysed()
    {
        $ids = ['assets::img/heavy.jpg', 'assets::img/small.jpg'];
        $store = new AnalysisStore;
        $store->markAnalyzing(['batch' => $ids], 'fingerprint');

        Analyzer::make()->analyze(collect($ids)->map(fn (string $id) => Asset::find($id)), batch: 'batch', progress: fn () => false);

        $this->assertSame(['assets::img/heavy.jpg'], array_keys($store->records()));
        $this->assertSame(1, $store->meta()['pending']);
    }

    /** A worker that never came, or a run that died without a word, would otherwise say "analysing" forever. */
    #[Test]
    public function a_run_without_a_sign_of_life_no_longer_counts_as_analysing()
    {
        $this->actingAs($this->superUser())->postJson(cp_route('asset-usage.compress.analyze'));

        $this->travel(Run::STALL_MINUTES + 1)->minutes();

        $status = $this->compressionStatus();

        $this->assertFalse($status['analyzing']);
        $this->assertSame('not_started', $status['failure']['reason']);
    }

    #[Test]
    public function a_failed_run_says_why()
    {
        $this->actingAs($this->superUser())->postJson(cp_route('asset-usage.compress.analyze'));

        Queue::assertPushed(AnalyzeAllCompression::class, function (AnalyzeAllCompression $job) {
            $job->failed(new RuntimeException('Worker died'));

            return true;
        });

        $status = $this->compressionStatus();

        $this->assertFalse($status['analyzing']);
        $this->assertSame('Worker died', $status['failure']['message']);
    }

    #[Test]
    public function a_running_analysis_is_not_started_again()
    {
        $user = $this->superUser();

        $this->actingAs($user)->postJson(cp_route('asset-usage.compress.analyze'));
        $this->actingAs($user)->postJson(cp_route('asset-usage.compress.analyze'))->assertJsonPath('compression.analyzing', true);

        Queue::assertPushed(AnalyzeAllCompression::class, 1);
    }
}
