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
    | usage data out of date, so refresh it afterwards.
    |
    | Besides the content itself, the last four cover the places an asset can be
    | referenced without ever being saved onto a single item:
    |
    | - collection_cascades / taxonomy_cascades: the cascade (`inject`) values
    |   that fall through to every entry or term, where an addon such as an SEO
    |   one stores its per-section defaults.
    | - addon_settings: what addons save in resources/addons/{slug}.yaml, which
    |   is where a site-wide default image usually lives.
    | - blueprints: the `default` values in blueprints and fieldsets, which hold
    |   an asset until something is saved over them.
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
        'collection_cascades' => true,
        'taxonomy_cascades' => true,
        'addon_settings' => true,
        'blueprints' => true,
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
    | Rebuild Reminder
    |--------------------------------------------------------------------------
    |
    | How many days the usage data may go without a full rebuild before the
    | Tools page suggests one. Two numbers, because what age means depends on
    | `auto_update` above:
    |
    | - auto_update: the index is patched on every save, so it is not drifting.
    |   The reminder is only about the few things that fire no event, such as
    |   files put on the disk outside Statamic.
    | - manual: nothing is updating the index in between, so age is exactly how
    |   far behind your content it has fallen.
    |
    | Either one set to 0 turns the reminder off. This is a reminder, not a
    | correctness check: an index built for other settings is reported as out
    | of date regardless of its age.
    |
    */

    'rebuild_reminder_days' => [
        'auto_update' => 30,
        'manual' => 7,
    ],

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
