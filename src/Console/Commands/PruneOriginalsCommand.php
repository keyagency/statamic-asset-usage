<?php

namespace KeyAgency\AssetUsage\Console\Commands;

use Illuminate\Console\Command;
use KeyAgency\AssetUsage\Compression\Backups;
use KeyAgency\AssetUsage\Support\Settings;
use Statamic\Console\RunsInPlease;

class PruneOriginalsCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:asset-usage:prune-originals';

    protected $description = 'Delete the originals of compressed images once they are older than keep_originals_days';

    public function handle(): int
    {
        $deleted = (new Backups)->prune();
        $days = Settings::keepOriginalsDays();

        if ($days !== null) {
            $this->components->info("Deleted {$deleted} originals older than {$days} days or left without the details to restore them.");

            return self::SUCCESS;
        }

        $this->components->info('Originals are kept forever (keep_originals_days is null).');

        if ($deleted) {
            $this->components->info("Deleted {$deleted} leftover files that could not be restored.");
        }

        return self::SUCCESS;
    }
}
