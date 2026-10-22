@props(['title' => null])

@php($viewer = auth()->user())

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
        <a href="#content" class="sr-only rounded-pill bg-ink px-4 py-2 text-canvas focus:not-sr-only focus:absolute focus:left-4 focus:top-4">
            Skip to content
        </a>

        <div class="lg:grid lg:grid-cols-[240px_1fr]">
            <aside id="sidebar" class="hidden border-r border-hairline bg-surface-soft lg:block" data-drawer>
                <div class="p-6">
                    <p class="text-micro font-semibold uppercase tracking-wider text-steel">BLITTO</p>
                    <p class="mt-1 text-heading-5 font-semibold text-ink">Patent Database</p>
                </div>

                <nav aria-label="Main">
                    <ul class="px-3 pb-6">
                        @foreach (\App\Support\Navigation::for($viewer) as $item)
                            <li>
                                <a
                                    href="{{ route($item['route']) }}"
                                    @if (request()->routeIs($item['active'])) aria-current="page" @endif
                                    class="block rounded-input px-3 py-2 text-body-sm font-medium @if (request()->routeIs($item['active'])) bg-canvas text-ink @else text-steel hover:bg-canvas hover:text-ink @endif"
                                >{{ $item['label'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            </aside>

            <div class="flex min-h-screen flex-col">
                <header class="flex items-center justify-between gap-4 border-b border-hairline bg-canvas px-6 py-4">
                    <div class="flex items-center gap-3">
                        <button type="button" class="rounded-input border border-hairline px-3 py-2 text-body-sm lg:hidden" data-drawer-toggle aria-controls="sidebar" aria-expanded="false">
                            Menu
                        </button>
                        <div>
                            <p class="text-micro font-semibold uppercase tracking-wider text-steel">
                                {{ $viewer->role === 'user' ? 'Patent Management' : 'Admin Panel' }}
                            </p>
                            <p class="text-body-sm font-medium text-ink">{{ $title ?? '' }}</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <form method="POST" action="{{ route('theme.update') }}">
                            @csrf
                            <input type="hidden" name="theme" value="{{ $theme === 'dark' ? 'light' : 'dark' }}">
                            <x-ui.button variant="ghost" type="submit">
                                {{ $theme === 'dark' ? 'Light mode' : 'Dark mode' }}
                            </x-ui.button>
                        </form>

                        <div class="hidden text-right sm:block">
                            <p class="text-body-sm text-ink">{{ $viewer->email }}</p>
                            <p class="text-caption text-steel">{{ $viewer->role }}</p>
                        </div>

                        <form method="POST" action="{{ route('auth.logout') }}">
                            @csrf
                            <x-ui.button variant="secondary" type="submit">Sign out</x-ui.button>
                        </form>
                    </div>
                </header>

                <main id="content" class="flex-1 px-6 py-8">
                    @include('partials.flash')
                    {{ $slot }}
                </main>

                <footer class="border-t border-hairline px-6 py-4 text-caption text-steel">
                    University of Peradeniya &middot; Technology Transfer Office
                </footer>
            </div>
        </div>
    </body>
</html>
