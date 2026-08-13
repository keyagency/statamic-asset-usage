<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Asset Containers
    |--------------------------------------------------------------------------
    |
    | Which asset containers this addon applies to. Use '*' for all containers,
    | or list the handles you want: ['main', 'documents']. Assets in containers
    | that are not listed are never indexed, get no "Used in" panel and no
    | column in the asset browser.
    |
    */

    'containers' => '*',

    /*
    |--------------------------------------------------------------------------
    | Scanned Content Types
    |--------------------------------------------------------------------------
    |
    | Which content types are searched for asset references. Everything is
    | scanned through Statamic's repositories, so this works the same on
    | flat-file sites and on sites using the eloquent driver.
    |
    | This list is the complete set: a type you leave out is not scanned, so
    | ['entries' => true] really does mean entries only. Changing it makes the
    | usage data out of date — refresh it afterwards.
    |
    */

    'scanned_types' => [
        'entries' => true,
        'globals' => true,
        'terms' => true,
        'navs' => true,
        'users' => true,
        'assets' => true,
        'form_submissions' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scan For Plain URLs
    |--------------------------------------------------------------------------
    |
    | Besides the reference formats Statamic itself keeps track of, also count
    | plain asset URLs (/assets/img/photo.jpg, or the absolute variant) that
    | were typed by hand into a text, textarea, markdown or HTML field. Keeping
    | this on makes an "unused" verdict safer to act on.
    |
    */

    'scan_urls' => true,

    /*
    |--------------------------------------------------------------------------
    | Working Copies
    |--------------------------------------------------------------------------
    |
    | Count an asset as used when it only appears in an unpublished draft
    | (an entry's working copy), so cleaning up never breaks work in progress.
    |
    */

    'include_working_copies' => true,

    /*
    |--------------------------------------------------------------------------
    | Keep The Index In Sync
    |--------------------------------------------------------------------------
    |
    | Update the usage index whenever content is saved or deleted, so the asset
    | browser stays accurate without a manual rebuild. Turn this off if you'd
    | rather rebuild on a schedule with `php please asset-usage:index`.
    |
    */

    'auto_update' => true,

    /*
    |--------------------------------------------------------------------------
    | Control Panel
    |--------------------------------------------------------------------------
    |
    | Where the usage information shows up: the "Used in" panel in the asset
    | editor, and the column in the asset browser listing.
    |
    */

    'editor_panel' => true,

    'listing_column' => true,

    /*
    |--------------------------------------------------------------------------
    | Never Report As Unused
    |--------------------------------------------------------------------------
    |
    | Filename patterns (fnmatch style, matched against the asset path and its
    | basename) that are always left out of the unused list, e.g. ['*.pdf',
    | 'downloads/*']. Handy for files that are linked from outside your site.
    |
    */

    'ignore' => [],

    /*
    |--------------------------------------------------------------------------
    | Minimum Age
    |--------------------------------------------------------------------------
    |
    | Assets uploaded less than this many days ago are never reported as
    | unused, so a file you just uploaded but haven't placed yet won't show up
    | in a cleanup list. 0 disables the grace period.
    |
    */

    'minimum_age_in_days' => 0,

];
