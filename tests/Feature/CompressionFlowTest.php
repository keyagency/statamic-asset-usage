<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use KeyAgency\AssetUsage\Compression\AnalysisStore;
use KeyAgency\AssetUsage\Compression\Backups;
use KeyAgency\AssetUsage\Compression\CompressionService;
use KeyAgency\AssetUsage\Compression\PathReplacementFile;
use KeyAgency\AssetUsage\Tests\Support\Images;
use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\UpdateScripts\AddCompressionToPublishedConfig;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Events\AssetReuploaded;
use Statamic\Facades\Asset;
use Statamic\Facades\CP\Toast;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class CompressionFlowTest extends TestCase
{
    private const ID = 'assets::img/heavy.jpg';

    private string $original;

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.asset-usage.compression.max_dimension' => 200]);

        $this->makeContainer('assets');
        Storage::disk('assets')->put('img/heavy.jpg', $this->original = Images::jpeg(600, 600, dpi: 300, quality: 98));
    }

    private function asset()
    {
        return Asset::find(self::ID);
    }

    private function show()
    {
        return $this->actingAs($this->superUser())->get(cp_route('asset-usage.compress.show', ['asset' => self::ID]));
    }

    /** The page, plus the preview it asks for once it has loaded. */
    private function props(): array
    {
        $page = $this->show()->assertOk()->viewData('page')['props'];

        $preview = $this->actingAs($this->superUser())
            ->postJson(cp_route('asset-usage.compress.preview'), ['asset' => self::ID])
            ->assertOk()
            ->json();

        return $page + $preview;
    }

    private function compress(?string $version = null)
    {
        return $this->actingAs($this->superUser())->postJson(cp_route('asset-usage.compress.store'), [
            'asset' => self::ID,
            'version' => $version ?? $this->props()['asset']['version'],
        ]);
    }

    private function userWith(array $permissions)
    {
        Role::make('compressor')->addPermission($permissions)->save();

        return tap(User::make()->email('robin@example.com')->assignRole('compressor'))->save();
    }

    #[Test]
    public function the_page_shows_a_fresh_preview_next_to_the_original()
    {
        $props = $this->props();

        $this->assertSame('ok', $props['record']['status']);
        $this->assertSame([600, 600, 300], [$props['record']['before_width'], $props['record']['before_height'], $props['record']['before_dpi']]);
        $this->assertSame([200, 200, 72], [$props['record']['after_width'], $props['record']['after_height'], $props['record']['after_dpi']]);
        $this->assertFalse($props['backup']['exists']);
        $this->assertFileExists(CompressionService::make()->previewPath($this->asset()));

        $user = $this->superUser();

        $before = $this->actingAs($user)->get(cp_route('asset-usage.compress.image', ['asset' => self::ID, 'variant' => 'before']));
        $before->assertOk();
        $this->assertSame($this->original, $before->streamedContent());

        $after = $this->actingAs($user)->get(cp_route('asset-usage.compress.image', ['asset' => self::ID, 'variant' => 'after']));
        $after->assertOk();
        $this->assertSame([200, 200], array_slice(getimagesize($after->getFile()->getPathname()), 0, 2));
    }

    /**
     * The type follows the extension, never the content, so an upload named
     * .png that holds HTML can't run as a page on the CP's origin.
     */
    #[Test]
    public function the_image_route_only_serves_images_and_never_lets_the_browser_sniff()
    {
        Storage::disk('assets')->put('img/fake.png', '<html><script>alert(1)</script></html>');
        Storage::disk('assets')->put('docs/page.html', '<html></html>');

        $user = $this->superUser();

        $response = $this->actingAs($user)->get(cp_route('asset-usage.compress.image', ['asset' => 'assets::img/fake.png', 'variant' => 'before']));
        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        $this->actingAs($user)
            ->get(cp_route('asset-usage.compress.image', ['asset' => 'assets::docs/page.html', 'variant' => 'before']))
            ->assertNotFound();
    }

    /**
     * Opening the page changes nothing, so a link or a redirect alone can't set
     * the compressor to work. The preview comes from a POST.
     */
    #[Test]
    public function opening_the_page_makes_no_preview()
    {
        $this->show()->assertOk();

        $this->assertFileDoesNotExist(CompressionService::make()->previewPath($this->asset()));
        $this->assertNull((new AnalysisStore)->record(self::ID));
    }

    #[Test]
    public function compressing_replaces_the_file_in_place_and_keeps_the_original()
    {
        $reuploaded = false;
        Event::listen(AssetReuploaded::class, function () use (&$reuploaded) {
            $reuploaded = true;
        });

        $this->compress()->assertOk()->assertJsonStructure(['redirect']);

        // Shown on the page the redirect leads to, not on the one that is about to go.
        $this->assertSame('success', Toast::toArray()[0]['type']);

        $stored = Storage::disk('assets')->get('img/heavy.jpg');

        $this->assertLessThan(strlen($this->original), strlen($stored));
        $this->assertSame([200, 200], array_slice(getimagesizefromstring($stored), 0, 2));
        $this->assertSame(200, $this->asset()->width());
        $this->assertTrue($reuploaded);

        $backups = new Backups;
        $this->assertTrue($backups->has($this->asset()));
        $this->assertSame($this->original, File::get($backups->path($this->asset())));

        // The new file is analysed right away, so the overview doesn't offer it again.
        $row = collect($this->actingAs($this->superUser())->getJson(cp_route('asset-usage.assets'))->json('data'))->firstWhere('id', self::ID);
        $this->assertTrue($row['compression']['analyzed']);
        $this->assertSame('compressed', $row['compression']['status']);
        // What compressing saved, measured against the kept original.
        $this->assertEqualsWithDelta((strlen($this->original) - strlen($stored)) / strlen($this->original) * 100, $row['compression']['savings'], 0.1);
        $this->assertFalse($row['compression']['compressible']);
        $this->assertTrue($row['compression']['has_backup']);
    }

    /**
     * Saving its own output again would shave off another percent each time
     * while losing quality, so an image compressed with these settings stays
     * as it is.
     */
    #[Test]
    public function an_image_it_compressed_is_not_offered_again()
    {
        $this->compress()->assertOk();

        $this->assertSame('compressed', $this->props()['record']['status']);
        $this->compress()->assertStatus(422);
    }

    /**
     * Not with other settings either: encoding its own output again loses
     * quality whatever the settings, and whether the server finds pngquant,
     * which only concerns PNGs, changes the settings of every image at once.
     * Other settings start from the original, which can be put back.
     */
    #[Test]
    public function an_image_it_compressed_is_not_offered_again_with_other_settings()
    {
        $this->compress()->assertOk();

        config(['statamic.asset-usage.compression.jpg_quality' => 75]);
        $this->assertSame('compressed', $this->props()['record']['status']);

        config(['statamic.asset-usage.compression.pngquant_binary' => '/nonexistent/pngquant']);
        $this->assertSame('compressed', $this->props()['record']['status']);
    }

    #[Test]
    public function it_refuses_when_the_file_changed_after_the_preview()
    {
        $this->compress('not-the-current-version')->assertStatus(409);

        $this->assertSame($this->original, Storage::disk('assets')->get('img/heavy.jpg'));
        $this->assertFalse((new Backups)->has($this->asset()));
    }

    /**
     * Replaced outside Statamic, the meta still describes the old file, so only
     * the size on the disk gives it away.
     */
    #[Test]
    public function it_refuses_when_the_file_was_replaced_outside_statamic()
    {
        $version = $this->props()['asset']['version'];

        Storage::disk('assets')->put('img/heavy.jpg', $replacement = Images::jpeg(400, 400));

        $this->compress($version)->assertStatus(409);

        $this->assertSame($replacement, Storage::disk('assets')->get('img/heavy.jpg'));
    }

    #[Test]
    public function it_refuses_when_compressing_would_not_make_the_file_smaller()
    {
        Storage::disk('assets')->put('img/heavy.jpg', $small = Images::jpeg(100, 100, quality: 30));

        $this->compress()->assertStatus(422);

        $this->assertSame($small, Storage::disk('assets')->get('img/heavy.jpg'));
    }

    #[Test]
    public function the_original_can_be_put_back()
    {
        $this->compress()->assertOk();

        $this->assertTrue($this->props()['backup']['exists']);
        $this->assertGreaterThan(0, $this->props()['backup']['compressed']['savings']);

        $this->actingAs($this->superUser())
            ->postJson(cp_route('asset-usage.compress.restore'), ['asset' => self::ID])
            ->assertOk();

        $this->assertSame($this->original, Storage::disk('assets')->get('img/heavy.jpg'));
        $this->assertSame(600, $this->asset()->width());
        $this->assertFalse((new Backups)->has($this->asset()));

        $this->actingAs($this->superUser())
            ->postJson(cp_route('asset-usage.compress.restore'), ['asset' => self::ID])
            ->assertNotFound();
    }

    /**
     * A second compression keeps the very first original, which is the one
     * worth having back.
     */
    /**
     * A backup belongs to the file compressing produced. Once that file is
     * replaced, the old original would put an unrelated image back.
     */
    #[Test]
    public function a_replaced_file_no_longer_has_the_old_original()
    {
        $this->compress()->assertOk();

        $replacement = tempnam(sys_get_temp_dir(), 'au').'.jpg';
        file_put_contents($replacement, $other = Images::jpeg(500, 400, quality: 98));
        $this->asset()->reupload(new PathReplacementFile($replacement));
        unlink($replacement);

        $this->assertFalse($this->props()['backup']['exists']);

        $this->compress()->assertOk();

        $this->assertSame($other, File::get((new Backups)->path($this->asset())));
    }

    #[Test]
    public function deleting_the_asset_removes_its_original()
    {
        $this->compress()->assertOk();
        $path = (new Backups)->path($this->asset());

        $this->asset()->delete();

        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($path.'.backup.json');
    }

    #[Test]
    public function renaming_the_asset_takes_its_original_along()
    {
        $this->compress()->assertOk();

        $this->asset()->rename('renamed');
        $renamed = Asset::find('assets::img/renamed.jpg');

        $this->assertTrue((new Backups)->has($renamed));
        $this->assertSame($this->original, File::get((new Backups)->path($renamed)));
    }

    /**
     * Without a complete copy of the original there is no way back, so the
     * file is not replaced at all.
     */
    #[Test]
    public function a_failed_backup_leaves_the_file_alone()
    {
        $version = $this->props()['asset']['version'];

        // A file where the container's backup folder should go, so the copy can't be made.
        File::ensureDirectoryExists((new Backups)->directory());
        File::put((new Backups)->directory().'/assets', 'in the way');

        $this->actingAs($this->superUser())
            ->postJson(cp_route('asset-usage.compress.store'), ['asset' => self::ID, 'version' => $version])
            ->assertStatus(500)
            ->assertJsonStructure(['message']);

        $this->assertSame($this->original, Storage::disk('assets')->get('img/heavy.jpg'));
    }

    #[Test]
    public function compressing_again_is_refused_and_keeps_the_first_original()
    {
        $this->compress()->assertOk();

        config(['statamic.asset-usage.compression.max_dimension' => 100]);
        $this->compress()->assertStatus(422);

        $this->assertSame($this->original, File::get((new Backups)->path($this->asset())));
    }

    #[Test]
    public function originals_are_pruned_after_the_configured_days()
    {
        $this->compress()->assertOk();
        $backups = new Backups;

        $this->travel(29)->days();
        $this->assertSame(0, $backups->prune());

        $this->travel(2)->days();
        $this->assertSame(1, $backups->prune());
        $this->assertFalse($backups->has($this->asset()));
    }

    /**
     * The original goes, but the image is still marked as compressing's own
     * output, so it isn't offered for compressing again.
     */
    #[Test]
    public function a_pruned_image_is_not_offered_again()
    {
        $this->compress()->assertOk();

        $this->travel(31)->days();
        (new Backups)->prune();

        $this->assertSame('compressed', $this->props()['record']['status']);
        $this->assertNull($this->props()['backup']['compressed']);
    }

    /**
     * An original without its meta can't be restored by anything, and neither
     * can a temp file a failed copy left behind. Both go after a day, along
     * with the folders they leave empty.
     */
    #[Test]
    public function pruning_removes_what_can_no_longer_be_restored()
    {
        config(['statamic.asset-usage.compression.keep_originals_days' => null]);

        $directory = (new Backups)->directory();
        File::ensureDirectoryExists($directory.'/assets/old');
        File::put($directory.'/assets/old/orphan.jpg', 'x');
        File::put($directory.'/assets/old/copy.jpg.1a2b3c4d.tmp', 'x');
        File::put($directory.'/assets/broken.jpg', 'x');
        File::put($directory.'/assets/broken.jpg.backup.json', '{not json');

        // A copy being made right now has no meta yet either, so a fresh file stays.
        $this->assertSame(0, (new Backups)->prune());

        $this->travel(2)->days();
        $this->assertSame(3, (new Backups)->prune());

        $this->assertSame([], File::allFiles($directory));
        $this->assertSame([], File::directories($directory));
    }

    #[Test]
    public function originals_are_kept_forever_when_configured_so()
    {
        config(['statamic.asset-usage.compression.keep_originals_days' => null]);

        $this->compress()->assertOk();
        $this->travel(1000)->days();

        $this->assertSame(0, (new Backups)->prune());
        $this->assertNull($this->props()['backup']['expires_at']);
    }

    #[Test]
    public function the_prune_command_reports_what_it_deleted()
    {
        $this->compress()->assertOk();
        $this->travel(31)->days();

        $this->artisan('statamic:asset-usage:prune-originals')
            ->expectsOutputToContain('Deleted 1 originals older than 30 days')
            ->assertSuccessful();

        $this->assertFalse((new Backups)->has($this->asset()));
    }

    /**
     * Input that isn't a string is a validation error, not a server error.
     */
    #[Test]
    public function a_malformed_request_is_rejected_cleanly()
    {
        $this->actingAs($this->superUser());

        $this->postJson(cp_route('asset-usage.compress.store'), ['asset' => [self::ID], 'version' => 'x'])->assertStatus(422);
        $this->postJson(cp_route('asset-usage.compress.store'), ['asset' => self::ID, 'version' => ['x']])->assertStatus(422);
        $this->postJson(cp_route('asset-usage.compress.preview'), ['asset' => ['nested' => self::ID]])->assertStatus(422);
    }

    #[Test]
    public function it_needs_the_compress_permission()
    {
        $user = $this->userWith(['access cp', 'view asset usage', 'view assets assets', 'edit assets assets', 'upload assets assets']);

        $this->actingAs($user)->get(cp_route('asset-usage.compress.show', ['asset' => self::ID]))->assertForbidden();
        $this->actingAs($user)->postJson(cp_route('asset-usage.compress.store'), ['asset' => self::ID, 'version' => 'x'])->assertForbidden();
        $this->actingAs($user)->postJson(cp_route('asset-usage.compress.analyze'))->assertForbidden();
    }

    /**
     * Replacing the file is Statamic's reupload, so its container permissions
     * apply on top of the addon's own.
     */
    #[Test]
    public function it_needs_statamics_permission_to_replace_the_file()
    {
        $user = $this->userWith(['access cp', 'view asset usage', 'compress assets', 'view assets assets']);

        $this->actingAs($user)->get(cp_route('asset-usage.compress.show', ['asset' => self::ID]))->assertForbidden();

        Role::find('compressor')->addPermission(['edit assets assets', 'upload assets assets'])->save();

        $this->actingAs(User::find($user->id()))->get(cp_route('asset-usage.compress.show', ['asset' => self::ID]))->assertOk();
    }

    #[Test]
    public function assets_outside_the_enabled_containers_are_left_alone()
    {
        config(['statamic.asset-usage.containers' => ['other']]);

        $this->show()->assertNotFound();
    }

    #[Test]
    public function the_update_script_adds_the_settings_once()
    {
        $path = config_path('statamic/asset-usage.php');
        $shipped = File::get(__DIR__.'/../../config/asset-usage.php');
        $without = preg_replace("/\n    \/\*\n    \|-+\n    \| Image Compression\n.*?\n    \],\n/s", '', $shipped);

        $this->assertStringNotContainsString("'compression'", $without);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $without);

        $script = new AddCompressionToPublishedConfig('keyagency/statamic-asset-usage');
        $script->update();
        $once = File::get($path);
        $script->update();

        $this->assertSame($once, File::get($path));
        $this->assertSame(1, (require $path)['compression']['threshold_percent']);
        $this->assertStringNotContainsString("\n\n\n", $once);
    }

    #[Test]
    public function the_update_script_copes_with_a_last_setting_without_a_comma()
    {
        $path = config_path('statamic/asset-usage.php');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "<?php\n\nreturn [\n    'containers' => ['assets'], /* only this one */\n    'scan_urls' => false\n];\n");

        (new AddCompressionToPublishedConfig('keyagency/statamic-asset-usage'))->update();

        $this->assertFalse((require $path)['scan_urls']);
        $this->assertSame(30, (require $path)['compression']['keep_originals_days']);
    }
}
