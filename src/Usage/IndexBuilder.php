<?php

namespace KeyAgency\AssetUsage\Usage;

use Closure;
use KeyAgency\AssetUsage\Support\Run;

/**
 * Builds the whole usage index from scratch: one pass over every scannable
 * item, extracting asset references as it goes.
 */
final class IndexBuilder
{
    public function __construct(private readonly IndexStore $store) {}

    /**
     * Starts a run, flagged as building right away so the CP says so before a
     * worker gets to it. How many items there are is only known once they all
     * have been read, so the total is what the last full build counted.
     */
    public static function startRun(IndexStore $store): string
    {
        $meta = $store->meta();

        $run = Run::index()->start($meta['built_at'] !== null ? ($meta['items_scanned'] ?: null) : null, estimated: true);

        $store->markBuilding();

        return $run;
    }

    /**
     * @param  Closure|null  $onItem  called with each scanned Item, for progress reporting
     * @param  string|null  $run  the run this build belongs to; without one it starts its own
     * @return UsageIndex|null null when the run was stopped or replaced before it finished
     */
    public function build(?Closure $onItem = null, ?string $run = null): ?UsageIndex
    {
        $runs = Run::index();
        $run ??= self::startRun($this->store);

        if (! $runs->beat($run, 0, force: true)) {
            $runs->isOver($run, $this->store->cancelBuilding(...));

            return null;
        }

        $this->reportFatalErrors($run);

        $containers = Containers::make();
        $extractor = new ReferenceExtractor($containers);
        $items = new Items($containers);

        $index = new UsageIndex;
        $scanned = 0;

        foreach ($items->all() as $item) {
            foreach ($extractor->extract($item->data) as $assetId => $fields) {
                foreach ($fields as $field) {
                    $index->add($assetId, $item->usage($field));
                }
            }

            $scanned++;

            if ($onItem) {
                $onItem($item);
            }

            if (! $runs->beat($run, $scanned)) {
                $runs->isOver($run, $this->store->cancelBuilding(...));

                return null;
            }
        }

        $this->store->write($index, ['items_scanned' => $scanned]);
        $runs->finish($run);

        return $index;
    }

    /**
     * Running out of memory or time ends PHP on the spot, past every catch.
     * Recording why is what lets the CP say more than "it stopped".
     */
    private function reportFatalErrors(string $run): void
    {
        register_shutdown_function(function () use ($run) {
            $error = error_get_last();

            if (! $error || ! in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            if (Run::index()->isCurrent($run)) {
                Run::index()->fail($run, $error['message']);
                $this->store->cancelBuilding();
            }
        });
    }
}
