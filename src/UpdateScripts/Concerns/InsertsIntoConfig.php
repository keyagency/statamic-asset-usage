<?php

namespace KeyAgency\AssetUsage\UpdateScripts\Concerns;

use PhpToken;

trait InsertsIntoConfig
{
    /**
     * Inserts lines before the one at $index, the line that closes the array
     * they go into. PHP allows a comma after the last entry but doesn't
     * require one, so a hand-edited config may end without it; the entry
     * above gets one first, or the result would not parse.
     *
     * @param  string[]  $lines
     * @param  string[]  $insert
     */
    private function insertBefore(array $lines, int $index, array $insert): string
    {
        $head = $this->withTrailingComma(implode("\n", array_slice($lines, 0, $index)));

        return implode("\n", [$head, ...$insert, ...array_slice($lines, $index)]);
    }

    /** Adds a comma after the last token, unless that is one already or opens the array. */
    private function withTrailingComma(string $code): string
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($code), fn (PhpToken $token) => ! $token->isIgnorable()));
        $last = end($tokens);

        if (! $last || in_array($last->text, [',', '['], true)) {
            return $code;
        }

        return substr_replace($code, ',', $last->pos + strlen($last->text), 0);
    }
}
