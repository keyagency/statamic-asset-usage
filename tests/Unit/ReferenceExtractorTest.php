<?php

namespace KeyAgency\AssetUsage\Tests\Unit;

use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\Usage\Containers;
use KeyAgency\AssetUsage\Usage\ReferenceExtractor;
use PHPUnit\Framework\Attributes\Test;

class ReferenceExtractorTest extends TestCase
{
    private function extractor(array $files = ['img/photo.jpg', 'docs/report.pdf'], string $handle = 'assets'): ReferenceExtractor
    {
        $this->makeContainer($handle, $files);

        return new ReferenceExtractor(new Containers(Containers::enabled()));
    }

    /** @return string[] the asset ids that were found */
    private function ids(array $found): array
    {
        $ids = array_keys($found);
        sort($ids);

        return $ids;
    }

    #[Test]
    public function it_finds_a_bare_path_from_an_assets_field()
    {
        $found = $this->extractor()->extract(['hero' => 'img/photo.jpg']);

        $this->assertSame(['assets::img/photo.jpg'], $this->ids($found));
        $this->assertSame(['hero'], $found['assets::img/photo.jpg']);
    }

    #[Test]
    public function it_finds_bare_paths_in_a_multi_value_assets_field()
    {
        $found = $this->extractor()->extract([
            'gallery' => ['img/photo.jpg', 'docs/report.pdf'],
        ]);

        $this->assertSame(
            ['assets::docs/report.pdf', 'assets::img/photo.jpg'],
            $this->ids($found)
        );
        $this->assertSame(['gallery.0'], $found['assets::img/photo.jpg']);
        $this->assertSame(['gallery.1'], $found['assets::docs/report.pdf']);
    }

    #[Test]
    public function it_ignores_a_bare_path_that_is_not_a_real_asset()
    {
        $found = $this->extractor()->extract([
            'text' => 'img/does-not-exist.jpg',
        ]);

        $this->assertSame([], $found);
    }

    #[Test]
    public function it_finds_an_explicit_asset_reference_from_a_link_field()
    {
        $found = $this->extractor()->extract([
            'download' => 'asset::assets::docs/report.pdf',
        ]);

        $this->assertSame(['assets::docs/report.pdf'], $this->ids($found));
        $this->assertSame(['download'], $found['assets::docs/report.pdf']);
    }

    #[Test]
    public function it_finds_a_statamic_url_in_a_markdown_string()
    {
        $found = $this->extractor()->extract([
            'body' => 'Read the [report](statamic://asset::assets::docs/report.pdf) first.',
        ]);

        $this->assertSame(['assets::docs/report.pdf'], $this->ids($found));
    }

    #[Test]
    public function it_finds_an_image_node_in_a_bard_field()
    {
        $found = $this->extractor()->extract([
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]],
                ['type' => 'image', 'attrs' => ['src' => 'asset::assets::img/photo.jpg', 'alt' => 'A photo']],
            ],
        ]);

        $this->assertSame(['assets::img/photo.jpg'], $this->ids($found));
        $this->assertSame(['content.1.attrs.src'], $found['assets::img/photo.jpg']);
    }

    #[Test]
    public function it_finds_an_asset_link_mark_in_a_bard_field()
    {
        $found = $this->extractor()->extract([
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'the report',
                    'marks' => [[
                        'type' => 'link',
                        'attrs' => ['href' => 'asset::assets::docs/report.pdf'],
                    ]],
                ]],
            ]],
        ]);

        $this->assertSame(['assets::docs/report.pdf'], $this->ids($found));
    }

    #[Test]
    public function it_finds_references_nested_in_a_replicator_or_grid()
    {
        $found = $this->extractor()->extract([
            'blocks' => [
                ['type' => 'text_block', 'text' => 'Nothing here'],
                ['type' => 'image_block', 'image' => 'img/photo.jpg'],
            ],
        ]);

        $this->assertSame(['assets::img/photo.jpg'], $this->ids($found));
        $this->assertSame(['blocks.1.image'], $found['assets::img/photo.jpg']);
    }

    #[Test]
    public function it_finds_references_inside_a_bard_set()
    {
        $found = $this->extractor()->extract([
            'content' => [[
                'type' => 'set',
                'attrs' => [
                    'id' => 'abc',
                    'values' => ['type' => 'gallery', 'image' => 'img/photo.jpg'],
                ],
            ]],
        ]);

        $this->assertSame(['assets::img/photo.jpg'], $this->ids($found));
        $this->assertSame(['content.0.attrs.values.image'], $found['assets::img/photo.jpg']);
    }

    #[Test]
    public function it_records_every_field_a_single_asset_appears_in()
    {
        $found = $this->extractor()->extract([
            'hero' => 'img/photo.jpg',
            'og_image' => 'asset::assets::img/photo.jpg',
        ]);

        $this->assertSame(['hero', 'og_image'], $found['assets::img/photo.jpg']);
    }

    #[Test]
    public function it_finds_a_plain_url_when_url_scanning_is_on()
    {
        $found = $this->extractor()->extract([
            'body' => '<p>See <img src="/assets/img/photo.jpg"> and https://example.com/assets/docs/report.pdf</p>',
        ]);

        $this->assertSame(
            ['assets::docs/report.pdf', 'assets::img/photo.jpg'],
            $this->ids($found)
        );
    }

    #[Test]
    public function it_ignores_plain_urls_when_url_scanning_is_off()
    {
        config(['statamic.asset-usage.scan_urls' => false]);

        $found = $this->extractor()->extract([
            'body' => '<img src="/assets/img/photo.jpg">',
        ]);

        $this->assertSame([], $found);
    }

    #[Test]
    public function it_ignores_urls_that_do_not_point_at_a_real_asset()
    {
        $found = $this->extractor()->extract([
            'body' => '<img src="/assets/img/deleted.jpg">',
        ]);

        $this->assertSame([], $found);
    }

    #[Test]
    public function it_reports_a_shared_path_for_every_container_that_holds_it()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->makeContainer('documents', ['img/photo.jpg'], '/documents');

        $extractor = new ReferenceExtractor(new Containers(Containers::enabled()));

        $found = $extractor->extract(['hero' => 'img/photo.jpg']);

        $this->assertSame(
            ['assets::img/photo.jpg', 'documents::img/photo.jpg'],
            $this->ids($found)
        );
    }

    #[Test]
    public function it_skips_containers_that_are_not_enabled()
    {
        config(['statamic.asset-usage.containers' => ['documents']]);

        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->makeContainer('documents', ['docs/report.pdf'], '/documents');

        $extractor = new ReferenceExtractor(new Containers(Containers::enabled()));

        $found = $extractor->extract([
            'hero' => 'img/photo.jpg',
            'download' => 'asset::assets::img/photo.jpg',
            'manual' => 'docs/report.pdf',
        ]);

        $this->assertSame(['documents::docs/report.pdf'], $this->ids($found));
    }

    #[Test]
    public function it_does_not_treat_ids_dates_or_structural_keys_as_references()
    {
        $found = $this->extractor(['5c8e2d6f-6a9c-4c1e-9f3a-1b2c3d4e5f60'])->extract([
            'id' => '5c8e2d6f-6a9c-4c1e-9f3a-1b2c3d4e5f60',
            'updated_at' => '2026-07-30',
            'blueprint' => 'article',
            'updated_by' => '5c8e2d6f-6a9c-4c1e-9f3a-1b2c3d4e5f60',
        ]);

        $this->assertSame([], $found);
    }

    #[Test]
    public function it_handles_data_without_any_references()
    {
        $found = $this->extractor()->extract([
            'title' => 'Hello world',
            'count' => 3,
            'published' => true,
            'nothing' => null,
        ]);

        $this->assertSame([], $found);
    }
}
