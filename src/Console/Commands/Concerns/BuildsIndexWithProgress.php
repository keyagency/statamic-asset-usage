<?php

namespace KeyAgency\AssetUsage\Console\Commands\Concerns;

use KeyAgency\AssetUsage\Usage\IndexBuilder;
use KeyAgency\AssetUsage\Usage\IndexStore;
use KeyAgency\AssetUsage\Usage\Item;
use KeyAgency\AssetUsage\Usage\UsageIndex;

trait BuildsIndexWithProgress
{
    /**
     * Rebuilds the index with a bar that counts the items as they are read.
     * How many there are is only known once they all have been, so the bar
     * counts up instead of towards an end.
     *
     * @return array{0: UsageIndex, 1: int} the index and the number of items scanned
     */
    private function buildIndexWithProgress(): array
    {
        $bar = $this->output->createProgressBar();
        $bar->setFormat(' %current% items [%bar%] %elapsed:6s%  %message%');
        $bar->setMessage('');
        $bar->start();

        $index = (new IndexBuilder(new IndexStore))->build(function (Item $item) use ($bar) {
            $bar->setMessage(str_replace('_', ' ', $item->type));
            $bar->advance();
        });

        $bar->setMessage('');
        $bar->finish();
        $this->newLine();

        return [$index, $bar->getProgress()];
    }
}
