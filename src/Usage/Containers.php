<?php

namespace KeyAgency\AssetUsage\Usage;

use Illuminate\Support\Collection;
use KeyAgency\AssetUsage\Support\Settings;
use Statamic\Facades\AssetContainer;

/**
 * The asset containers this addon applies to, plus the two lookup tables the
 * extractor needs: every known asset path, and the URL prefixes that point at
 * a container. Built once per scan and passed around, because building them
 * means listing every file in every container.
 */
final class Containers
{
    /** @var array<string, string[]> path => container handles that hold that path */
    private array $paths;

    /** @var array<int, array{prefix: string, container: string}> longest prefix first */
    private array $urlPrefixes;

    /**
     * @param  Collection<int, \Statamic\Contracts\Assets\AssetContainer>  $containers
     */
    public function __construct(private readonly Collection $containers)
    {
        $this->paths = $this->buildPaths();
        $this->urlPrefixes = $this->buildUrlPrefixes();
    }

    public static function make(): self
    {
        return new self(self::enabled());
    }

    /**
     * The containers the addon applies to, per the `containers` config.
     *
     * @return Collection<int, \Statamic\Contracts\Assets\AssetContainer>
     */
    public static function enabled(): Collection
    {
        $handles = Settings::containers();

        return AssetContainer::all()
            ->filter(fn ($container) => $handles === null || in_array($container->handle(), $handles))
            ->values();
    }

    public static function includes(string $handle): bool
    {
        $handles = Settings::containers();

        return $handles === null || in_array($handle, $handles);
    }

    /**
     * @return string[]
     */
    public function handles(): array
    {
        return $this->containers->map->handle()->all();
    }

    /**
     * @return Collection<int, \Statamic\Contracts\Assets\AssetContainer>
     */
    public function all(): Collection
    {
        return $this->containers;
    }

    /**
     * Every asset in the enabled containers, as `container::path` ids.
     *
     * @return string[]
     */
    public function assetIds(): array
    {
        $ids = [];

        foreach ($this->paths as $path => $containers) {
            foreach ($containers as $container) {
                $ids[] = "{$container}::{$path}";
            }
        }

        return $ids;
    }

    /**
     * Resolve a bare, container-relative path (how an `assets` field stores its
     * value) to references. A path that exists in more than one enabled
     * container yields one reference per container: without the field's config
     * we can't tell them apart, and over-reporting a usage is far safer than
     * calling an asset unused.
     *
     * @return Reference[]
     */
    public function resolveBarePath(string $path): array
    {
        $path = ltrim(trim($path), '/');

        if (! isset($this->paths[$path])) {
            return [];
        }

        return array_map(fn ($container) => new Reference($container, $path), $this->paths[$path]);
    }

    /**
     * Resolve an explicit `container::path` pair, verifying that the container
     * is enabled and the asset actually exists.
     */
    public function resolveExplicit(string $container, string $path): ?Reference
    {
        $path = ltrim(rawurldecode(trim($path)), '/');

        if (! in_array($container, $this->handles(), true)) {
            return null;
        }

        return isset($this->paths[$path]) && in_array($container, $this->paths[$path], true)
            ? new Reference($container, $path)
            : null;
    }

    public function has(string $container, string $path): bool
    {
        return isset($this->paths[$path]) && in_array($container, $this->paths[$path], true);
    }

    /**
     * @return array<int, array{prefix: string, container: string}>
     */
    public function urlPrefixes(): array
    {
        return $this->urlPrefixes;
    }

    /**
     * Paths come from the container's asset query, which is the same source the
     * asset browser uses.
     *
     * Not from `$container->files()`: on the eloquent driver that listing only
     * reports the container root. Its recursive lookup matches `folder LIKE '/%'`,
     * while a nested asset stores its folder without a leading slash ('logos'),
     * so nothing below the root ever matches and every asset in a folder would
     * silently go missing.
     *
     * @return array<string, string[]>
     */
    private function buildPaths(): array
    {
        $paths = [];

        foreach ($this->containers as $container) {
            foreach ($container->queryAssets()->pluck('path') as $path) {
                if (! $path) {
                    continue;
                }

                $paths[$path][] = $container->handle();
            }
        }

        return $paths;
    }

    /**
     * The URL prefixes a hand-written asset URL could start with: the path part
     * of the container URL (`/assets/`) and, when the container is served from
     * another host, the absolute URL as well. Longest first so a container
     * nested inside another one wins.
     *
     * @return array<int, array{prefix: string, container: string}>
     */
    private function buildUrlPrefixes(): array
    {
        $prefixes = [];

        foreach ($this->containers as $container) {
            if (! $url = $container->url()) {
                continue;
            }

            $url = rtrim($url, '/');
            $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');

            /*
             * A container served from the web root would give us an empty or
             * "/" prefix, which would match every URL on the site. Skip it
             * rather than flag half the content as an asset reference.
             */
            if ($path !== '') {
                $prefixes[] = ['prefix' => $path.'/', 'container' => $container->handle()];
            }

            if (parse_url($url, PHP_URL_HOST)) {
                $prefixes[] = ['prefix' => $url.'/', 'container' => $container->handle()];
            }
        }

        usort($prefixes, fn ($a, $b) => strlen($b['prefix']) <=> strlen($a['prefix']));

        return $prefixes;
    }
}
