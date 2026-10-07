<?php

namespace KeyAgency\AssetUsage\Console\Commands;

use Illuminate\Console\Command;
use KeyAgency\AssetUsage\Log\AssetLog;
use KeyAgency\AssetUsage\Log\Source;
use Statamic\Console\RunsInPlease;
use Statamic\Support\Str;

class LogCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:asset-usage:log
        {--type= : Only "compressed" or only "deleted"}
        {--json : Print the log as JSON}';

    protected $description = 'List what happened to assets: the images the addon compressed, and every deleted asset';

    public function handle(): int
    {
        $type = $this->option('type');

        if ($type !== null && ! in_array($type, [AssetLog::COMPRESSED, AssetLog::DELETED], true)) {
            $this->components->error('The type is either "compressed" or "deleted".');

            return self::FAILURE;
        }

        $log = new AssetLog;
        $entries = array_reverse($log->entries($type));

        if ($this->option('json')) {
            $this->line(json_encode([
                'compressed' => $log->compressionTotals(),
                'deleted' => $log->deletionTotals(),
                'entries' => $entries,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (! $entries) {
            $this->components->info('Nothing has been logged yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['When', 'What', 'Asset', 'By', 'Details'],
            array_map(fn (array $entry) => [
                $entry['at'],
                $entry['type'],
                $entry['asset_id'],
                $entry['by']['name'] ?? (in_array($entry['source'] ?? null, [Source::CLI, Source::CONSOLE], true) ? 'command line' : ''),
                $this->details($entry),
            ], $entries)
        );

        $compressed = $log->compressionTotals();
        $deleted = $log->deletionTotals();

        $this->components->twoColumnDetail('Compressed', sprintf(
            '%d (%s → %s), %d restored and not counted',
            $compressed['count'],
            Str::fileSizeForHumans($compressed['before_bytes'], 1),
            Str::fileSizeForHumans($compressed['after_bytes'], 1),
            $compressed['restored']
        ));
        $this->components->twoColumnDetail('Deleted', sprintf('%d (%s freed)', $deleted['count'], Str::fileSizeForHumans($deleted['bytes'], 1)));

        return self::SUCCESS;
    }

    private function details(array $entry): string
    {
        if ($entry['type'] === AssetLog::DELETED) {
            return trim(implode(', ', array_filter([
                $entry['bytes'] !== null ? Str::fileSizeForHumans($entry['bytes'], 1) : null,
                'via '.$entry['source'],
                $entry['usage_count'] ? "still used in {$entry['usage_count']} places" : null,
            ])));
        }

        return sprintf(
            '%s → %s%s%s',
            Str::fileSizeForHumans($entry['before_bytes'], 1),
            Str::fileSizeForHumans($entry['after_bytes'], 1),
            ($entry['source'] ?? null) === Source::CLI ? ', via asset-usage:compress' : '',
            $entry['restored_at'] ? ', restored '.$entry['restored_at'] : ''
        );
    }
}
