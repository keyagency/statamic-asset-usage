<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\Usage\IndexBuilder;
use KeyAgency\AssetUsage\Usage\IndexStore;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\User;

class IncrementalUpdateTest extends TestCase
{
    private function build(): void
    {
        (new IndexBuilder(new IndexStore))->build();
    }

    private function index()
    {
        return (new IndexStore)->indexOrEmpty();
    }

    #[Test]
    public function saving_an_entry_records_a_newly_placed_asset()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $entry = $this->makeEntry('home', ['title' => 'Home']);
        $this->build();

        $this->assertFalse($this->index()->isUsed('assets::img/photo.jpg'));

        $entry->set('hero', 'img/photo.jpg')->save();

        $this->assertTrue($this->index()->isUsed('assets::img/photo.jpg'));
        $this->assertSame('hero', $this->index()->for('assets::img/photo.jpg')[0]->field);
    }

    #[Test]
    public function saving_an_entry_drops_an_asset_that_was_removed()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $entry = $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/photo.jpg']);
        $this->build();

        $this->assertTrue($this->index()->isUsed('assets::img/photo.jpg'));

        $entry->remove('hero')->save();

        $this->assertFalse($this->index()->isUsed('assets::img/photo.jpg'));
    }

    #[Test]
    public function it_does_not_leave_a_stale_row_when_a_reference_moves_to_another_field()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $entry = $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/photo.jpg']);
        $this->build();

        $entry->remove('hero')->set('og_image', 'img/photo.jpg')->save();

        $usages = $this->index()->for('assets::img/photo.jpg');

        $this->assertCount(1, $usages);
        $this->assertSame('og_image', $usages[0]->field);
    }

    #[Test]
    public function deleting_an_entry_drops_its_usages()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $entry = $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/photo.jpg']);
        $this->build();

        $entry->delete();

        $this->assertFalse($this->index()->isUsed('assets::img/photo.jpg'));
    }

    /**
     * Statamic saves the entry before it deletes the working copy, so the
     * EntrySaved handler still sees the draft. Only the revision's deletion
     * tells us it's gone.
     */
    #[Test]
    public function publishing_a_draft_drops_the_draft_usage()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $entry = $this->draftEntry(['hero' => 'img/photo.jpg']);
        $this->build();

        $this->assertSame(['entry_draft'], $this->typesFor('assets::img/photo.jpg'));

        $entry->fresh()->publishWorkingCopy();

        $this->assertSame(['entry'], $this->typesFor('assets::img/photo.jpg'));
    }

    #[Test]
    public function discarding_a_draft_drops_the_draft_usage()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $entry = $this->draftEntry(['hero' => 'img/photo.jpg']);
        $this->build();

        $this->assertSame(['entry_draft'], $this->typesFor('assets::img/photo.jpg'));

        $entry->fresh()->deleteWorkingCopy();

        $this->assertFalse($this->index()->isUsed('assets::img/photo.jpg'));
    }

    #[Test]
    public function deleting_an_ordinary_revision_leaves_the_draft_usage_alone()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $entry = $this->draftEntry(['hero' => 'img/photo.jpg']);
        $this->build();

        tap($entry->fresh()->makeRevision()->message('a snapshot'))->save()->delete();

        $this->assertSame(['entry_draft'], $this->typesFor('assets::img/photo.jpg'));
    }

    /** An unpublished entry whose working copy holds the given data. */
    private function draftEntry(array $draftData)
    {
        config(['statamic.revisions.enabled' => true]);

        $entry = $this->makeEntry('home', ['title' => 'Home']);

        Collection::findByHandle('pages')->revisionsEnabled(true)->save();

        $entry = $entry->fresh();
        $entry->published(false)->save();

        $working = $entry->makeWorkingCopy();
        $working->attribute('data', array_merge($working->attribute('data'), $draftData));
        $working->save();

        return $entry;
    }

    /** @return string[] */
    private function typesFor(string $assetId): array
    {
        return collect($this->index()->for($assetId))->map(fn ($usage) => $usage->type)->all();
    }

    #[Test]
    public function one_entry_losing_a_reference_leaves_another_entrys_usage_alone()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $first = $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/photo.jpg']);
        $this->makeEntry('about', ['title' => 'About', 'hero' => 'img/photo.jpg']);
        $this->build();

        $this->assertSame(2, $this->index()->countFor('assets::img/photo.jpg'));

        $first->remove('hero')->save();

        $usages = $this->index()->for('assets::img/photo.jpg');

        $this->assertCount(1, $usages);
        $this->assertSame('About', $usages[0]->title);
    }

    #[Test]
    public function saving_a_global_set_updates_the_index()
    {
        $this->makeContainer('assets', ['img/logo.svg']);
        $set = tap(GlobalSet::make('branding')->title('Branding'))->save();
        $variables = tap($set->makeLocalization(Site::default()->handle()))->save();
        $this->build();

        $variables->set('logo', 'img/logo.svg')->save();

        $this->assertSame('global', $this->index()->for('assets::img/logo.svg')[0]->type);
    }

    #[Test]
    public function saving_a_nav_updates_the_index()
    {
        $this->makeContainer('assets', ['docs/brochure.pdf']);
        $nav = tap(Nav::make()->handle('main')->title('Main'))->save();
        $nav->makeTree(Site::default()->handle(), [])->save();
        $this->build();

        $nav->in(Site::default()->handle())->tree([
            ['id' => 'node-1', 'title' => 'Brochure', 'url' => 'asset::assets::docs/brochure.pdf'],
        ])->save();

        $this->assertSame('nav', $this->index()->for('assets::docs/brochure.pdf')[0]->type);
    }

    #[Test]
    public function saving_a_user_updates_the_index()
    {
        $this->makeContainer('assets', ['img/avatar.jpg']);
        $user = tap(User::make()->email('robin@example.com'))->save();
        $this->build();

        $user->set('avatar', 'img/avatar.jpg')->save();

        $this->assertSame('user', $this->index()->for('assets::img/avatar.jpg')[0]->type);
    }

    #[Test]
    public function deleting_an_asset_drops_the_usages_pointing_at_it()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/photo.jpg']);
        $this->makeAsset('assets', 'img/photo.jpg');
        $this->build();

        $this->assertTrue($this->index()->isUsed('assets::img/photo.jpg'));

        Asset::find('assets::img/photo.jpg')->delete();

        $this->assertFalse($this->index()->isUsed('assets::img/photo.jpg'));
    }

    #[Test]
    public function it_stays_out_of_the_way_when_auto_update_is_off()
    {
        config(['statamic.asset-usage.auto_update' => false]);

        $this->makeContainer('assets', ['img/photo.jpg']);
        $entry = $this->makeEntry('home', ['title' => 'Home']);
        $this->build();

        $entry->set('hero', 'img/photo.jpg')->save();

        $this->assertFalse($this->index()->isUsed('assets::img/photo.jpg'));
    }

    #[Test]
    public function it_does_nothing_before_an_index_exists()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);

        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/photo.jpg']);

        $this->assertFalse((new IndexStore)->exists());
    }

    #[Test]
    public function an_entry_created_after_the_build_is_picked_up()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->build();

        Entry::make()
            ->collection(tap(Collection::make('pages')->title('Pages'))->save())
            ->slug('new')
            ->data(['title' => 'New', 'hero' => 'img/photo.jpg'])
            ->save();

        $this->assertTrue($this->index()->isUsed('assets::img/photo.jpg'));
    }
}
