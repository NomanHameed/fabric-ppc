<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ config('app.available_locales.'.app()->getLocale().'.dir', 'ltr') }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- The app owns its own light/dark switch, so CSS (`color-scheme` on :root/.dark
             in app.css) is what finally decides; this meta only covers the first frame. --}}
        <meta name="color-scheme" content="light dark">
        <meta name="theme-color" content="#fafcfb" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#0a100d" media="(prefers-color-scheme: dark)">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Pre-paint background so there is no white flash before app.css loads.
             Keep these two values in step with `--background` in resources/css/app.css. --}}
        <style>
            html {
                background-color: oklch(0.99 0.003 161.2);
            }

            html.dark {
                background-color: oklch(0.165 0.011 161.2);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @php
            $__i18nBoot = [
                'locale' => app()->getLocale(),
                'dir' => config('app.available_locales.'.app()->getLocale().'.dir', 'ltr'),
                'dict' => app(\App\Modules\Foundation\I18n\TranslationRepository::class)->all(app()->getLocale()),
                'version' => app(\App\Modules\Foundation\I18n\TranslationRepository::class)->version(app()->getLocale()),
            ];
        @endphp
        <script>window.__I18N__ = @json($__i18nBoot);</script>

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
