@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => $theme === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ?? config('app.name') }}</title>
        @include('partials.theme-script')
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-canvas font-sans text-ink antialiased">
        <main class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center gap-6 px-6 py-12">
            <div class="text-center">
                <p class="text-micro font-semibold uppercase tracking-wider text-steel">BLITTO</p>
                <h1 class="mt-2 text-heading-2 font-semibold text-ink">{{ config('app.name') }}</h1>
                <p class="mt-2 text-body-sm text-steel">University of Peradeniya &middot; Technology Transfer Office</p>
            </div>

            <x-ui.card>
                {{ $slot }}
            </x-ui.card>
        </main>
    </body>
</html>
