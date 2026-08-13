<?php

return [

    'nav_title' => 'Asset Usage',
    'view_overview' => 'Open Asset Usage',

    'loading' => 'Loading…',
    'details' => 'Details',
    'field_label' => 'Field',
    'all_sites' => 'All sites',

    /** The browser column shows a tick or a cross, so it reads as a yes/no question. */
    'column_label' => 'Used',

    'unused' => 'Not used anywhere',
    'used_count' => '{1} Used in 1 place|[2,*] Used in :count places',

    'no_results' => 'No assets match these filters.',
    'no_containers' => 'No asset containers are enabled for this addon.',

    'permissions' => [
        'group' => 'Asset Usage',
        'view' => 'View asset usage',
        'delete' => 'Delete unused assets',
    ],

    /** Everything about the state of the usage data, in plain language. */
    'index' => [
        'refresh' => 'Refresh usage data',
        'refreshing' => 'Refreshing…',
        'refresh_started' => 'The usage data is being refreshed.',
        'refreshed' => 'The usage data has been refreshed.',
        'updated_at' => 'Last updated :time',
        'checking' => 'Checking your content…',
        'not_ready' => 'Not checked yet',
        'not_ready_instructions' => 'Refresh the usage data to see where this asset is used.',
        'stale' => 'The usage data is out of date',
        'stale_instructions' => 'It was collected for different containers or settings. Refresh it to get accurate results.',
    ],

    'filters' => [
        'usage' => 'Usage',
        'all' => 'All assets',
        'used' => 'Used',
        'unused' => 'Unused',
        'container' => 'Container',
        'site' => 'Site',
        'all_sites' => 'All sites',
        'search_placeholder' => 'Search by path…',
    ],

    'sort' => [
        'name_asc' => 'Name (A-Z)',
        'name_desc' => 'Name (Z-A)',
        'used' => 'Most used first',
        'unused' => 'Unused first',
    ],

    'delete' => [
        'action' => 'Delete',
        'selected' => 'Delete selected',
        'all_unused' => 'Delete all unused',
        'all_unused_scope' => 'This covers every unused asset the current filters match, including the ones on other pages.',
        'select_all' => 'Select all deletable',
        'confirm_title' => '{1} Delete this asset?|[2,*] Delete these :count assets?',
        'confirm' => '{1} This permanently deletes the file. This cannot be undone.|[2,*] This permanently deletes the files. This cannot be undone.',
        'success' => '{1} 1 asset deleted|[2,*] :count assets deleted',
        'none' => 'Nothing was deleted.',
    ],

    'item_type' => [
        'entry' => 'Entry',
        'entry_draft' => 'Draft',
        'global' => 'Global set',
        'term' => 'Taxonomy term',
        'nav' => 'Navigation',
        'user' => 'User',
        'asset' => 'Asset',
        'form_submission' => 'Form submission',
    ],

    'errors' => [
        'stale_index' => 'The usage data is out of date. Refresh it before deleting anything.',
        'asset_is_used' => 'This asset is used in :count places and was not deleted.',
        'asset_ignored' => 'This asset is protected by the `ignore` config and was not deleted.',
        'asset_too_new' => 'This asset was uploaded less than :days days ago and was not deleted.',
        'asset_missing' => 'That asset no longer exists.',
    ],

];
