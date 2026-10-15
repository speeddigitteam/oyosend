@extends('site.layout')

@php
    use App\Core\Website\SitePrice;
    use App\Core\Website\SiteUrls;

    $other = SiteUrls::otherLocale($meta->locale);
@endphp

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <x-site.page-hero :eyebrow="__('site_pages.blog.eyebrow')" :title="__('site_pages.blog.title')" :lede="__('site_pages.blog.lede')"
                          :trail="[[__('site.nav.blog'), SiteUrls::to('blog')]]">
            <div class="mt-6 flex flex-wrap items-center gap-x-5 gap-y-2 text-[0.875rem]">
                <a href="{{ $meta->feed }}" class="inline-flex items-center gap-1.5 font-medium text-brand-text hover:underline">
                    <x-site.icon name="rss" class="size-4" />
                    {{ __('site_pages.blog.feed') }}
                </a>
                @if ($otherHasPosts)
                    <a href="{{ SiteUrls::to('blog', $other) }}" hreflang="{{ $other }}" lang="{{ $other }}" class="inline-flex items-center gap-1.5 text-ink-muted hover:text-ink">
                        <x-site.icon name="languages" class="size-4" />
                        {{ __('site_pages.blog.other_language') }}
                    </a>
                @endif
            </div>
        </x-site.page-hero>

        {{-- Topics: plain links, so every view of the blog has an address and works without a script. --}}
        <nav aria-label="{{ __('site_pages.blog.filter') }}" class="-mx-4 overflow-x-auto px-4 pb-1 sm:mx-0 sm:px-0">
            <ul class="flex min-w-max items-center gap-1.5">
                <li>
                    <a href="{{ $allUrl }}" @if ($kind === null) aria-current="page" @endif @class([
                        'inline-flex h-9 items-center rounded-full px-4 text-[0.875rem] font-medium transition-colors',
                        'bg-brand text-brand-ink' => $kind === null,
                        'glass text-ink-muted hover:text-ink' => $kind !== null,
                    ])>{{ __('site_pages.blog.all') }}</a>
                </li>
                @foreach ($topics as $topic)
                    <li>
                        <a href="{{ $topic['url'] }}" @if ($topic['current']) aria-current="page" @endif @class([
                            'inline-flex h-9 items-center rounded-full px-4 text-[0.875rem] font-medium transition-colors',
                            'bg-brand text-brand-ink' => $topic['current'],
                            'glass text-ink-muted hover:text-ink' => ! $topic['current'],
                        ])>{{ $topic['label'] }}</a>
                    </li>
                @endforeach
                @if ($app)
                    <li class="ml-1 border-l border-line pl-2.5">
                        <a href="{{ SiteUrls::to('blog', null, array_filter(['topic' => $kind?->value])) }}"
                           class="glass inline-flex h-9 items-center gap-2 rounded-full pr-2 pl-3 text-[0.875rem] font-medium text-ink"
                           aria-label="{{ __('site_pages.blog.about_app', ['app' => $app->text('name')]) }}: {{ __('site_pages.blog.clear_app') }}">
                            <x-site.icon :name="$app->icon" class="size-4 text-brand-text" />
                            {{ __('site_pages.blog.about_app', ['app' => $app->text('name')]) }}
                            <span class="grid size-5 place-items-center rounded-full bg-hover"><x-site.icon name="x" class="size-3" /></span>
                        </a>
                    </li>
                @endif
            </ul>
        </nav>

        @if ($lead)
            <div class="pt-8">
                <x-site.post-card :post="$lead" lead />
            </div>
        @endif

        @if ($posts !== [])
            <ul class="grid gap-4 pt-4 sm:grid-cols-2 lg:grid-cols-3 {{ $lead ? '' : 'pt-8' }}">
                @foreach ($posts as $post)
                    <li><x-site.post-card :post="$post" :level="$lead ? 3 : 2" /></li>
                @endforeach
            </ul>
        @elseif (! $lead)
            <section class="glass relative isolate mt-8 overflow-hidden rounded-panel px-6 py-14 text-center sm:py-20" aria-labelledby="blog-empty">
                <span class="mx-auto grid size-14 place-items-center rounded-panel bg-brand-soft text-brand-text"><x-site.icon name="pen-line" class="size-6" /></span>
                <h2 id="blog-empty" class="mt-5 text-[1.5rem] text-ink">{{ __('site_pages.blog.empty_title') }}</h2>
                <p class="mx-auto mt-2 max-w-xl text-[1rem] leading-relaxed text-ink-muted">
                    {{ $kind || $app ? __('site_pages.blog.empty_filtered') : __('site_pages.blog.empty_body') }}
                </p>
                <div class="mt-6 flex flex-wrap justify-center gap-2">
                    @if ($kind || $app)
                        <x-site.button :href="SiteUrls::to('blog')">{{ __('site_pages.blog.all') }}</x-site.button>
                    @else
                        <x-site.button :href="SiteUrls::to('apps')">{{ __('site.common.see_apps') }}</x-site.button>
                        <x-site.button variant="ghost" :href="SiteUrls::to('faq')">{{ __('site.nav.faq') }}</x-site.button>
                    @endif
                    @if ($otherHasPosts)
                        <x-site.button variant="ghost" :href="SiteUrls::to('blog', $other)">{{ __('site_pages.blog.other_language') }}</x-site.button>
                    @endif
                </div>
            </section>
        @endif

        @if ($pagination['last'] > 1)
            <nav aria-label="{{ __('site_pages.blog.pagination') }}" class="flex flex-wrap items-center justify-between gap-3 pt-10">
                @if ($pagination['previous'])
                    <x-site.button :href="$pagination['previous']" rel="prev">
                        <x-site.icon name="arrow-right" class="size-4 rotate-180" />
                        {{ __('site_pages.blog.previous') }}
                    </x-site.button>
                @else
                    <span></span>
                @endif

                <ol class="hidden items-center gap-1 sm:flex">
                    @foreach ($pagination['pages'] as $item)
                        <li>
                            <a href="{{ $item['url'] }}" @if ($item['number'] === $pagination['current']) aria-current="page" @endif
                               aria-label="{{ __('site_pages.blog.page', ['page' => SitePrice::number($item['number'])]) }}"
                               @class([
                                   'tabular grid size-9 place-items-center rounded-control text-[0.875rem] font-medium transition-colors',
                                   'bg-brand text-brand-ink' => $item['number'] === $pagination['current'],
                                   'text-ink-muted hover:bg-hover hover:text-ink' => $item['number'] !== $pagination['current'],
                               ])>{{ SitePrice::number($item['number']) }}</a>
                        </li>
                    @endforeach
                </ol>

                @if ($pagination['next'])
                    <x-site.button :href="$pagination['next']" rel="next">
                        {{ __('site_pages.blog.next') }}
                        <x-site.icon name="arrow-right" class="size-4" />
                    </x-site.button>
                @else
                    <span></span>
                @endif
            </nav>
        @endif

        <div class="pt-24 sm:pt-32" data-reveal>
            <x-site.cta :title="__('site.home.cta_title')" :body="__('site.home.cta_body')" :registration-open="$registrationOpen" />
        </div>
    </div>
@endsection
