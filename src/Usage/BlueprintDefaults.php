<?php

namespace KeyAgency\AssetUsage\Usage;

/**
 * Pulls the `default` values out of a blueprint or fieldset.
 *
 * A default holds an asset until something is saved over it, and on a field
 * nobody ever touches it holds it for good, so it counts as usage just like a
 * value on an entry does.
 *
 * The raw contents are walked rather than the field objects, because a default
 * can sit inside a grid, a group or a replicator set, and each of those keeps
 * its own fields in a shape only that fieldtype knows about.
 */
final class BlueprintDefaults
{
    /**
     * @return array<string, mixed> dotted field handle => default value
     */
    public static function in(array $contents): array
    {
        $defaults = [];

        self::walk($contents, '', $defaults);

        return $defaults;
    }

    /**
     * @param  array<string, mixed>  $defaults
     */
    private static function walk(array $node, string $handle, array &$defaults): void
    {
        if (isset($node['handle']) && is_string($node['handle'])) {
            $handle = $handle === '' ? $node['handle'] : "{$handle}.{$node['handle']}";
        }

        if ($handle !== '' && array_key_exists('default', $node)) {
            $defaults[$handle] = $node['default'];
        }

        /*
         * Not into the default itself: it is recorded whole above and the
         * extractor walks nested arrays on its own, so descending into it only
         * risks reading its data as field configuration. A `default` key in
         * there would replace the entry just made, taking the rest of that
         * default with it.
         */
        foreach ($node as $key => $value) {
            if ($key !== 'default' && is_array($value)) {
                self::walk($value, $handle, $defaults);
            }
        }
    }
}
