<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\Usage\IndexBuilder;
use KeyAgency\AssetUsage\Usage\IndexStore;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;

class CommandTest extends TestCase
{
    private function build(): void
    {
        (new IndexBuilder(new IndexStore))->build();
    }

    #[Test]
    public function the_index_command_builds_the_index()
    {
        $this->makeContainer('assets', ['img/used.jpg', 'img/unused.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/used.jpg']);

        $this->artisan('statamic:asset-usage:index')
            ->expectsOutputToContain('Usage index rebuilt.')
            ->assertExitCode(0);

        $index = (new IndexStore)->indexOrEmpty();

        $this->assertTrue($index->isUsed('assets::img/used.jpg'));
        $this->assertFalse($index->isUsed('assets::img/unused.jpg'));
    }

    #[Test]
    public function the_index_command_complains_when_no_container_is_enabled()
    {
        config(['statamic.asset-usage.containers' => ['nope']]);

        $this->artisan('statamic:asset-usage:index')->assertExitCode(1);
    }

    #[Test]
    public function the_unused_command_lists_unused_assets()
    {
        $this->makeContainer('assets', ['img/used.jpg', 'img/unused.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/used.jpg']);
        $this->build();

        $this->artisan('statamic:asset-usage:unused')
            ->expectsOutputToContain('img/unused.jpg')
            ->doesntExpectOutputToContain('img/used.jpg')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_unused_command_refuses_to_run_without_an_index()
    {
        $this->makeContainer('assets', ['img/unused.jpg']);

        $this->artisan('statamic:asset-usage:unused')
            ->expectsOutputToContain('No usage index yet')
            ->assertExitCode(1);
    }

    #[Test]
    public function the_unused_command_can_build_the_index_itself()
    {
        $this->makeContainer('assets', ['img/unused.jpg']);

        $this->artisan('statamic:asset-usage:unused --fresh')
            ->expectsOutputToContain('img/unused.jpg')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_unused_command_reports_a_fully_used_library()
    {
        $this->makeContainer('assets', ['img/used.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/used.jpg']);
        $this->build();

        $this->artisan('statamic:asset-usage:unused')
            ->expectsOutputToContain('Every asset is used somewhere.')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_unused_command_can_output_json()
    {
        $this->makeContainer('assets', ['img/unused.jpg']);
        $this->build();

        $this->artisan('statamic:asset-usage:unused --json')
            ->expectsOutput('["assets::img\/unused.jpg"]')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_unused_command_deletes_with_force()
    {
        $this->makeContainer('assets', ['img/used.jpg', 'img/unused.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/used.jpg']);
        $this->build();

        $this->artisan('statamic:asset-usage:unused --delete --force')->assertExitCode(0);

        $this->assertNull(Asset::find('assets::img/unused.jpg'));
        $this->assertNotNull(Asset::find('assets::img/used.jpg'));
    }

    #[Test]
    public function the_unused_command_leaves_everything_alone_when_the_confirmation_is_declined()
    {
        $this->makeContainer('assets', ['img/unused.jpg']);
        $this->build();

        $this->artisan('statamic:asset-usage:unused --delete')
            ->expectsConfirmation('Permanently delete these 1 files?', 'no')
            ->assertExitCode(0);

        $this->assertNotNull(Asset::find('assets::img/unused.jpg'));
    }

    #[Test]
    public function the_unused_command_honours_the_ignore_config()
    {
        config(['statamic.asset-usage.ignore' => ['*.pdf']]);

        $this->makeContainer('assets', ['img/unused.jpg', 'docs/orphan.pdf']);
        $this->build();

        $this->artisan('statamic:asset-usage:unused')
            ->expectsOutputToContain('img/unused.jpg')
            ->doesntExpectOutputToContain('docs/orphan.pdf')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_unused_command_honours_an_ignore_option()
    {
        $this->makeContainer('assets', ['img/unused.jpg', 'docs/orphan.pdf']);
        $this->build();

        $this->artisan('statamic:asset-usage:unused --ignore=*.pdf')
            ->expectsOutputToContain('img/unused.jpg')
            ->doesntExpectOutputToContain('docs/orphan.pdf')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_unused_command_never_touches_a_recently_uploaded_asset()
    {
        config(['statamic.asset-usage.minimum_age_in_days' => 7]);

        $this->makeContainer('assets', ['img/unused.jpg']);
        $this->build();

        $this->artisan('statamic:asset-usage:unused --delete --force')
            ->expectsOutputToContain('Every asset is used somewhere.')
            ->assertExitCode(0);

        $this->assertNotNull(Asset::find('assets::img/unused.jpg'));
    }

    #[Test]
    public function the_unused_command_can_be_limited_to_one_container()
    {
        $this->makeContainer('assets', ['img/unused.jpg']);
        $this->makeContainer('documents', ['docs/orphan.pdf'], '/documents');
        $this->build();

        $this->artisan('statamic:asset-usage:unused --container=documents')
            ->expectsOutputToContain('docs/orphan.pdf')
            ->doesntExpectOutputToContain('img/unused.jpg')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_unused_command_rejects_a_container_that_is_not_enabled()
    {
        config(['statamic.asset-usage.containers' => ['assets']]);

        $this->makeContainer('assets', ['img/unused.jpg']);
        $this->build();

        $this->artisan('statamic:asset-usage:unused --container=documents')->assertExitCode(1);
    }

    /**
     * "Unused" only ever means "no reference found in the content we scan", and
     * that is worth saying right where someone is about to act on the list.
     */
    #[Test]
    public function the_unused_command_says_what_it_could_not_look_at()
    {
        $this->makeContainer('assets', ['img/unused.jpg']);
        (new IndexBuilder(new IndexStore))->build();

        $this->assertSame(0, $this->withoutMockingConsoleOutput()->artisan('statamic:asset-usage:unused'));

        // Whitespace-normalised, so console line wrapping can't break the match.
        $output = preg_replace('/\s+/', ' ', Artisan::output());

        $this->assertStringContainsString(__('asset-usage::messages.not_scanned'), $output);
    }

    #[Test]
    public function the_unused_commands_json_output_stays_machine_readable()
    {
        $this->makeContainer('assets', ['img/unused.jpg']);
        (new IndexBuilder(new IndexStore))->build();

        $this->assertSame(0, $this->withoutMockingConsoleOutput()->artisan('statamic:asset-usage:unused --json'));

        $this->assertSame(['assets::img/unused.jpg'], json_decode(trim(Artisan::output()), true));
    }
}
