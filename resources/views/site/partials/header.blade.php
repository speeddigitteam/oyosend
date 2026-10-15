@php
    use App\Core\Website\SiteUrls;

    $other = SiteUrls::otherLocale($meta->locale);
    // The blog is linked only once there is something to read in this language.
    $hasBlog = ($hasBlog ?? false) === true;
    $links = $hasBlog ? ['pricing', 'security', 'blog', 'about', 'contact'] : ['pricing', 'security', 'about', 'contact'];
    $current = request()->route()?->getName() ?? '';
    $isCurrent = static fn (string $page): bool => in_array($current, [SiteUrls::routeName($page, 'en'), SiteUrls::routeName($page, 'bn')], true);
    $appCount = collect($navGroups)->flatten(1)->count();
@endphp

{{-- A floating glass bar that never touches the window's edge, like the app's own top bar. --}}
<header class="sticky top-0 z-40 px-3 pt-3 sm:px-5">
    <div class="glass mx-auto flex h-16 max-w-7xl items-center gap-2 rounded-panel pr-2.5 pl-4 sm:pr-3">
        <a href="{{ SiteUrls::to('home') }}" class="shrink-0 rounded-control" aria-label="{{ __('site.meta.logo') }}">
            <x-site.logo id="header-logo" />
        </a>

        <nav aria-label="{{ __('site.nav.label') }}" class="ml-6 hidden items-center gap-1 lg:flex">
            {{-- Apps open a directory of every app, grouped by area. A disclosure: it works without JavaScript. --}}
            <details class="relative" data-dropdown>
                <summary @class([
                    'flex h-9 cursor-pointer list-none items-center gap-1 rounded-control px-3 text-sm font-medium transition-colors [&::-webkit-details-marker]:hidden',
                    'text-ink' => $isCurrent('apps') || $isCurrent('app'),
                    'text-ink-muted hover:bg-hover hover:text-ink' => ! ($isCurrent('apps') || $isCurrent('app')),
                ])>
                    {{ __('site.nav.apps') }}
                    <x-site.icon name="chevron-down" class="size-4 transition-transform duration-200 [details[open]_&]:rotate-180" />
                </summary>
                <div class="glass-solid absolute top-[calc(100%+0.75rem)] -left-24 z-50 w-[min(56rem,calc(100vw-3rem))] animate-pop rounded-panel p-5">
                    <p class="eyebrow">{{ __('site.nav.apps_menu') }}</p>
                    <div class="mt-3 grid grid-cols-3 gap-x-5 gap-y-4">
                        @foreach ($navGroups as $category => $apps)
                            <div>
                                <p class="px-2 text-[0.8125rem] font-semibold text-ink">{{ __("site_apps.categories.{$category}.name") }}</p>
                                <ul class="mt-1">
                                    @foreach ($apps as $app)
                                        <li>
                                            <a href="{{ SiteUrls::to('app', null, ['app' => $app->slug]) }}" class="flex items-center gap-2.5 rounded-control px-2 py-1.5 text-[0.8125rem] text-ink-muted transition-colors hover:bg-hover hover:text-ink">
                                                <x-site.icon :name="$app->icon" class="size-4 shrink-0 text-brand-text" />
                                                {{ $app->text('name') }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-4 border-t border-line pt-3">
                        <a href="{{ SiteUrls::to('apps') }}" class="inline-flex items-center gap-1.5 rounded-control px-2 py-1 text-[0.8125rem] font-medium text-brand-text hover:underline">
                            {{ __('site.nav.all_apps', ['count' => \App\Core\Website\SitePrice::number($appCount)]) }}
                            <x-site.icon name="arrow-right" class="size-3.5" />
                        </a>
                    </div>
                </div>
            </details>

            @foreach ($links as $page)
                <a href="{{ SiteUrls::to($page) }}" @if ($isCurrent($page)) aria-current="page" @endif @class([
                    'flex h-9 items-center rounded-control px-3 text-sm font-medium transition-colors',
                    // Room for one more link only on a wide screen; the footer and the menu always have it.
                    'hidden xl:flex' => $page === 'blog',
                    'text-ink' => $isCurrent($page) || ($page === 'blog' && $isCurrent('post')),
                    'text-ink-muted hover:bg-hover hover:text-ink' => ! ($isCurrent($page) || ($page === 'blog' && $isCurrent('post'))),
                ])>{{ __("site.nav.{$page}") }}</a>
            @endforeach
        </nav>

        <div class="ml-auto flex items-center gap-1">
            <a href="{{ $meta->languageLink($other) }}" hreflang="{{ $other }}" lang="{{ $other }}" title="{{ __('site.nav.language_label') }}"
               class="flex h-9 items-center gap-1.5 rounded-control px-2.5 text-sm font-medium text-ink-muted transition-colors hover:bg-hover hover:text-ink">
                <x-site.icon name="languages" class="size-4" />
                <span>{{ __('site.nav.language') }}</span>
                <span class="sr-only">{{ __('site.nav.language_label') }}</span>
            </a>

            <button type="button" data-theme-toggle aria-label="{{ __('site.nav.theme') }}"
                    class="grid size-9 place-items-center rounded-control text-ink-muted transition-colors hover:bg-hover hover:text-ink">
                <x-site.icon name="moon" class="size-[1.125rem] dark:hidden" />
                <x-site.icon name="sun" class="hidden size-[1.125rem] dark:block" />
            </button>

            {{-- Wrapped, so that hiding them on a small screen does not fight the button's own display. --}}
            <span class="hidden md:contents">
                <x-site.button variant="ghost" :href="SiteUrls::to('signin')">{{ __('site.nav.signin') }}</x-site.button>
            </span>
            <span class="hidden sm:contents">
                @if ($registrationOpen)
                    <x-site.button variant="primary" :href="route('central.register')">{{ __('site.nav.start') }}</x-site.button>
                @else
                    <x-site.button variant="primary" :href="SiteUrls::to('contact')">{{ __('site.nav.talk') }}</x-site.button>
                @endif
            </span>

            <button type="button" data-menu-toggle aria-expanded="false" aria-controls="site-menu"
                    data-label-open="{{ __('site.nav.menu') }}" data-label-close="{{ __('site.nav.close') }}" aria-label="{{ __('site.nav.menu') }}"
                    class="grid size-10 place-items-center rounded-control text-ink transition-colors hover:bg-hover lg:hidden">
                <x-site.icon name="menu" class="size-5 [[aria-expanded=true]_&]:hidden" />
                <x-site.icon name="x" class="hidden size-5 [[aria-expanded=true]_&]:block" />
            </button>
        </div>
    </div>

    {{-- The small-screen menu: every page, then the two ways in. --}}
    <div id="site-menu" hidden class="glass-solid mx-auto mt-2 max-w-7xl animate-pop rounded-panel p-3 lg:hidden">
        <nav aria-label="{{ __('site.nav.label') }}">
            <ul class="grid gap-0.5">
                @foreach ($hasBlog ? ['apps', 'pricing', 'security', 'blog', 'about', 'faq', 'contact'] : ['apps', 'pricing', 'security', 'about', 'faq', 'contact'] as $page)
                    <li>
                        <a href="{{ SiteUrls::to($page) }}" @if ($isCurrent($page)) aria-current="page" @endif
                           class="flex h-11 items-center justify-between rounded-control px-3 text-[0.9375rem] font-medium text-ink hover:bg-hover">
                            {{ __("site.nav.{$page}") }}
                            <x-site.icon name="arrow-right" class="size-4 text-ink-subtle" />
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
        <div class="mt-3 grid grid-cols-2 gap-2 border-t border-line pt-3">
            <x-site.button :href="SiteUrls::to('signin')">{{ __('site.nav.signin') }}</x-site.button>
            @if ($registrationOpen)
                <x-site.button variant="primary" :href="route('central.register')">{{ __('site.nav.start') }}</x-site.button>
            @else
                <x-site.button variant="primary" :href="SiteUrls::to('contact')">{{ __('site.nav.talk') }}</x-site.button>
            @endif
        </div>
    </div>
</header>
