<?php

namespace KeyAgency\AssetUsage\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use KeyAgency\AssetUsage\Http\Controllers\Concerns\AuthorizesAssetUsage;
use KeyAgency\AssetUsage\Log\AssetLog;
use KeyAgency\AssetUsage\Log\Source;
use KeyAgency\AssetUsage\Support\NavIcon;
use KeyAgency\AssetUsage\Support\Settings;
use Statamic\Facades\Asset;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Support\Str;

/**
 * What happened to assets, newest first: compressions and deletions, filtered
 * by type. Restored compressions stay in the list, marked, but don't count
 * towards the totals.
 */
class LogController extends CpController
{
    use AuthorizesAssetUsage;

    private const PER_PAGE = 25;

    public function index(Request $request)
    {
        $this->authorizeLog();

        $type = in_array($request->input('type'), [AssetLog::COMPRESSED, AssetLog::DELETED], true)
            ? $request->input('type')
            : null;

        $log = new AssetLog;
        $visible = $log->visibleTo(User::current());
        $entries = array_reverse($type ? array_values(array_filter($visible, fn (array $entry) => $entry['type'] === $type)) : $visible);
        $page = max(1, (int) $request->input('page', 1));
        $total = count($entries);

        return Inertia::render('asset-usage::AssetLog', [
            'icon' => NavIcon::svg(),
            'type' => $type ?? 'all',
            'entries' => array_map(fn (array $entry) => $this->row($entry), array_slice($entries, ($page - 1) * self::PER_PAGE, self::PER_PAGE)),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / self::PER_PAGE)),
                'per_page' => self::PER_PAGE,
                'total' => $total,
                'from' => $total > 0 ? ($page - 1) * self::PER_PAGE + 1 : null,
                'to' => $total > 0 ? min($page * self::PER_PAGE, $total) : null,
            ],
            'compressionTotals' => $log->compressionTotals($visible),
            'deletionTotals' => $log->deletionTotals($visible),
            'logUrl' => cp_route('asset-usage.log'),
            'usageUrl' => cp_route('asset-usage.index'),
            'compressionPageUrl' => Settings::compressionEnabled() && $this->canCompress() ? cp_route('asset-usage.compression') : null,
        ]);
    }

    private function row(array $entry): array
    {
        $entry['by'] = $this->person($entry['by'] ?? null) ?? $this->commandLine($entry['source'] ?? null);
        $entry['restored_by'] = $this->person($entry['restored_by'] ?? null);

        if ($entry['type'] === AssetLog::DELETED) {
            return $entry + [
                'size' => $entry['bytes'] !== null ? Str::fileSizeForHumans($entry['bytes'], 1) : null,
            ];
        }

        $asset = Asset::find($entry['asset_id']);

        return $entry + [
            'before' => Str::fileSizeForHumans($entry['before_bytes'], 1),
            'after' => Str::fileSizeForHumans($entry['after_bytes'], 1),
            'savings' => $entry['before_bytes'] ? round(($entry['before_bytes'] - $entry['after_bytes']) / $entry['before_bytes'] * 100, 1) : 0,
            'edit_url' => $asset?->editUrl(),
            'thumbnail' => $asset?->isImage() ? $asset->thumbnailUrl('small') : null,
        ];
    }

    /**
     * Without a user, a command or a queued job is who did it. Outside the
     * Control Panel nobody can be named, so that stays empty.
     */
    private function commandLine(?string $source): ?array
    {
        return in_array($source, [Source::CLI, Source::CONSOLE], true)
            ? ['id' => null, 'name' => __('asset-usage::messages.log.by_command_line')]
            : null;
    }

    /**
     * Only a name is logged. Someone without one is shown by email address,
     * but only to users who may see other users anyway.
     */
    private function person(?array $person): ?array
    {
        if (! $person || ($person['name'] ?? null)) {
            return $person;
        }

        $viewer = User::current();

        if ($viewer && ($viewer->isSuper() || $viewer->hasPermission('view users'))) {
            $person['name'] = User::find($person['id'])?->email();
        }

        return $person;
    }
}
