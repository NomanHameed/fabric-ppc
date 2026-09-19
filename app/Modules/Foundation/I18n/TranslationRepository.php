<?php

namespace App\Modules\Foundation\I18n;

use Illuminate\Support\Facades\Cache;

final class TranslationRepository
{
    public function all(string $locale): array
    {
        return Cache::remember("i18n.{$locale}.".$this->version($locale), 3600, function () use ($locale) {
            $fallback = $this->load(config('app.fallback_locale', 'en'));

            return $locale === config('app.fallback_locale', 'en')
                ? $fallback
                : array_replace_recursive($fallback, $this->load($locale));
        });
    }

    public function version(string $locale): string
    {
        $sig = collect(array_merge($this->files('en'), $this->files($locale)))
            ->map(fn ($f) => $f.filemtime($f).filesize($f))->implode('|');

        return substr(md5($sig), 0, 8);
    }

    private function load(string $locale): array
    {
        $groups = [];
        foreach ($this->files($locale) as $file) {
            $groups[basename($file, '.php')] = require $file;
        }

        return $groups;
    }

    /** @return list<string> */
    private function files(string $locale): array
    {
        return glob(lang_path($locale.'/*.php')) ?: [];
    }
}
