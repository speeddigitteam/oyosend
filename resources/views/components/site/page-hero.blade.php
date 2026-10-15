{{--
    The top of an inner page: where it sits (breadcrumbs, also in the structured data), its eyebrow, its one H1 and
    its lede. Set on the backdrop's colour fields, clear of any disc.
--}}
@props(['eyebrow' => null, 'title', 'lede' => null, 'trail' => []])
<header {{ $attributes->class('pt-10 pb-8 sm:pt-16 sm:pb-12') }}>
    @if ($trail !== [])
        <nav aria-label="{{ __('site.common.breadcrumb') }}">
            <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[0.8125rem] text-ink-muted">
                <li><a href="{{ \App\Core\Website\SiteUrls::to('home') }}" class="hover:text-ink">{{ __('site.nav.home') }}</a></li>
                @foreach ($trail as [$name, $url])
                    <li class="flex items-center gap-2">
                        <x-site.icon name="chevron-down" class="size-3.5 -rotate-90 text-ink-subtle" />
                        @if ($loop->last)
                            <span aria-current="page" class="text-ink">{{ $name }}</span>
                        @else
                            <a href="{{ $url }}" class="hover:text-ink">{{ $name }}</a>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
    @endif

    <div class="mt-6 max-w-3xl">
        @if ($eyebrow)
            <p class="eyebrow text-brand-text">{{ $eyebrow }}</p>
        @endif
        <h1 class="mt-3 text-[clamp(2.25rem,5.4vw,3.75rem)] leading-[1.02] tracking-[-0.04em] text-ink">{{ $title }}</h1>
        @if ($lede)
            <p class="mt-5 text-[1.0625rem] leading-relaxed text-ink-muted sm:text-[1.1875rem]">{{ $lede }}</p>
        @endif
        {{ $slot }}
    </div>
</header>
