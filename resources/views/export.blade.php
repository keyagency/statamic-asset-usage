<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $report->title }}: {{ $report->subtitle }}</title>
    {{-- dompdf reads CSS 2.1 with a little of CSS3, so this stays with tables and plain boxes. --}}
    <style>
        @page { margin: 28pt 28pt 34pt; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 7.5pt; color: #1f2937; }
        a { color: #1f2937; text-decoration: none; }
        h1 { font-size: 16pt; margin: 0; }
        .header { width: 100%; border-collapse: collapse; }
        .header td { padding: 0; vertical-align: top; }
        .header .maker { text-align: right; color: #6b7280; font-size: 7pt; line-height: 1.5; padding-right: 8pt; }
        .header .maker a { color: #6b7280; text-decoration: underline; }
        .header .logo, .header .logo img { width: 40pt; height: 40pt; }
        .subtitle { font-size: 10pt; color: #6b7280; margin: 2pt 0 10pt; }
        .meta { border-collapse: collapse; margin-bottom: 10pt; }
        .meta td { padding: 1.5pt 10pt 1.5pt 0; vertical-align: top; }
        .meta .label { color: #6b7280; white-space: nowrap; }
        .meta a { text-decoration: underline; }
        .notice { border: 0.75pt solid #f59e0b; background: #fffbeb; padding: 5pt 7pt; margin: 0 0 10pt; }
        .empty { color: #6b7280; font-style: italic; }
        .list { width: 100%; border-collapse: collapse; }
        .list thead { display: table-header-group; }
        .list tr { page-break-inside: avoid; }
        .list th { text-align: left; font-weight: bold; padding: 4pt; border-bottom: 0.75pt solid #9ca3af; white-space: nowrap; }
        .list td { padding: 3pt 4pt; border-bottom: 0.5pt solid #e5e7eb; vertical-align: middle; }
        .list .thumbnail { width: 28pt; height: 28pt; padding-left: 0; }
        .list img { width: 28pt; height: 28pt; }
        .list .extension { color: #6b7280; font-size: 5.5pt; text-align: center; text-transform: uppercase; }
        .list .path { word-wrap: break-word; }
        .list .path a { text-decoration: underline; }
        .nowrap { white-space: nowrap; }
        .muted { color: #6b7280; }
        .unused { color: #c2410c; }
        .saving { color: #15803d; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <h1>{{ $report->title }}</h1>
                <p class="subtitle">{{ $report->subtitle }}</p>
            </td>
            <td class="maker">
                {{ __('asset-usage::messages.made_by') }} <strong>Key Agency</strong><br>
                <a href="https://keyagency.nl">keyagency.nl</a> &nbsp;·&nbsp; <a href="mailto:hello@keyagency.nl">hello@keyagency.nl</a><br>
                <a href="https://statamic.com/addons/key-agency/asset-usage">Statamic Marketplace</a>
            </td>
            @isset ($logo)
                <td class="logo"><a href="https://keyagency.nl"><img src="{{ $logo }}" alt="Key Agency"></a></td>
            @endisset
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td class="label">{{ __('asset-usage::messages.export.created') }}</td>
            <td>{{ $report->createdAt }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('asset-usage::messages.export.filters') }}</td>
            <td>
                @forelse ($report->filters as $filter)
                    {{ $filter['label'] }}: <strong>{{ $filter['value'] }}</strong>@if (! $loop->last) &nbsp;·&nbsp; @endif
                @empty
                    {{ __('asset-usage::messages.export.no_filters') }}
                @endforelse
            </td>
        </tr>
        <tr>
            <td class="label">{{ __('asset-usage::messages.export.sorted_by') }}</td>
            <td>{{ $report->sort }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('asset-usage::messages.export.assets') }}</td>
            <td>{{ trans_choice('asset-usage::messages.export.count', $report->total, ['count' => $report->total]) }} &nbsp;·&nbsp; {{ $report->totalSize }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('asset-usage::messages.export.link') }}</td>
            <td><a href="{{ $report->overviewUrl }}">{{ __('asset-usage::messages.export.open_overview') }}</a></td>
        </tr>
    </table>

    @if ($report->truncated())
        <div class="notice">
            <strong>{{ __('asset-usage::messages.export.truncated_heading', ['limit' => count($report->rows)]) }}</strong><br>
            {{ __('asset-usage::messages.export.truncated', ['limit' => count($report->rows), 'total' => $report->total]) }}
        </div>
    @endif

    @if (! $report->rows)
        <p class="empty">{{ $report->emptyText }}</p>
    @else
        {{--
            In tables of fifty rows: dompdf lays a table out as a whole, which
            takes far more time and memory per row on a long one. Every column but
            the name has a width of its own, so the tables line up.
        --}}
        @foreach (array_chunk($report->rows, 50) as $chunk)
            <table class="list">
                <thead>
                    <tr>
                        <th class="thumbnail"></th>
                        <th>{{ __('asset-usage::messages.columns.name') }}</th>
                        @if ($report->showsContainer)
                            <th style="width: 70pt">{{ __('asset-usage::messages.filters.container') }}</th>
                        @endif
                        <th style="width: 44pt">{{ __('asset-usage::messages.columns.size') }}</th>
                        <th style="width: 58pt">{{ __('asset-usage::messages.columns.resolution') }}</th>
                        <th style="width: 28pt">{{ __('asset-usage::messages.columns.dpi') }}</th>
                        @if ($report->showsSavings)
                            <th style="width: 100pt">{{ __('asset-usage::messages.columns.savings') }}</th>
                        @endif
                        <th style="width: 62pt">{{ __('asset-usage::messages.columns.last_modified') }}</th>
                        <th style="width: 72pt">{{ __('asset-usage::messages.columns.usage') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($chunk as $row)
                        <tr>
                            @if ($row['thumbnail'])
                                <td class="thumbnail"><img src="{{ $row['thumbnail'] }}" alt=""></td>
                            @else
                                <td class="thumbnail extension">{{ $row['extension'] }}</td>
                            @endif
                            <td class="path"><a href="{{ $row['edit_url'] }}">{{ $row['path'] }}</a></td>
                            @if ($report->showsContainer)
                                <td>{{ $row['container'] }}</td>
                            @endif
                            <td>{{ $row['size'] }}</td>
                            <td>{{ $row['dimensions'] }}</td>
                            <td>{{ $row['dpi'] }}</td>
                            @if ($report->showsSavings)
                                <td class="{{ $row['saving_marked'] ? 'saving' : 'muted' }}">{{ $row['saving'] }}</td>
                            @endif
                            <td>{{ $row['last_modified'] }}</td>
                            <td class="{{ $row['unused'] ? 'unused' : '' }}">{{ $row['usage'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @endif
</body>
</html>
