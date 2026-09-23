<?php

namespace KeyAgency\AssetUsage\Tests\Unit;

use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every locale mirrors the English file: a missing key falls back to English
 * silently, and a missing plural segment or placeholder only shows up in the
 * browser.
 */
class TranslationsTest extends TestCase
{
    public static function locales(): array
    {
        return collect(glob(__DIR__.'/../../lang/*/messages.php'))
            ->map(fn (string $path) => basename(dirname($path)))
            ->reject(fn (string $locale) => $locale === 'en')
            ->mapWithKeys(fn (string $locale) => [$locale => [$locale]])
            ->all();
    }

    #[Test]
    #[DataProvider('locales')]
    public function a_locale_has_the_same_keys_plurals_and_placeholders_as_english(string $locale)
    {
        $english = $this->messages('en');
        $translated = $this->messages($locale);

        $this->assertSame(array_keys($english), array_keys($translated));

        foreach ($english as $key => $text) {
            $this->assertSame(substr_count($text, '|'), substr_count($translated[$key], '|'), "{$locale}: plural segments of {$key}");
            $this->assertSame($this->placeholders($text), $this->placeholders($translated[$key]), "{$locale}: placeholders of {$key}");
        }
    }

    private function messages(string $locale): array
    {
        return Arr::dot(require __DIR__."/../../lang/{$locale}/messages.php");
    }

    private function placeholders(string $text): array
    {
        preg_match_all('/:[a-z_]+/', $text, $matches);

        $placeholders = array_unique($matches[0]);
        sort($placeholders);

        return $placeholders;
    }
}
