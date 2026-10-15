@extends('site.layout')

@php
    use App\Core\Website\SiteUrls;

    $networks = ['facebook' => 'facebook', 'linkedin' => 'linkedin', 'x' => 'twitter', 'whatsapp' => 'message-circle'];
@endphp

@section('content')
    {{-- How far through the article the reader is: drawn by the browser from the scroll position, no script. --}}
    <div class="site-progress" aria-hidden="true"></div>

    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        @if ($preview)
            <p class="glass-solid mt-6 flex items-start gap-3 rounded-panel border-l-4 border-l-warn px-5 py-4 text-[0.9375rem] text-ink" role="status">
                <x-site.icon name="eye-off" class="mt-0.5 size-5 shrink-0 text-warn" />
                {{ __('site_pages.blog.preview') }}
            </p>
        @endif

        <x-site.page-hero :eyebrow="$post->kindLabel" :title="$post->title"
                          :trail="[[__('site.nav.blog'), SiteUrls::to('blog')], [$post->title, $post->url]]">
            {{-- Who wrote it, when, and how long it takes. --}}
            <div class="mt-6 flex flex-wrap items-center gap-x-5 gap-y-3 text-[0.875rem] text-ink-muted">
                <span class="inline-flex items-center gap-2.5">
                    <span class="grid size-9 place-items-center rounded-full bg-brand text-[0.875rem] font-semibold text-brand-ink" aria-hidden="true">{{ mb_strtoupper(mb_substr($author, 0, 1)) }}</span>
                    <span class="font-medium text-ink">{{ __('site_pages.blog.by', ['name' => $author]) }}</span>
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <x-site.icon name="calendar" class="size-4 text-brand-text" />
                    <time datetime="{{ $post->published->toIso8601String() }}">{{ $post->date }}</time>
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <x-site.icon name="clock" class="size-4 text-brand-text" />
                    {{ $post->readingTime }}
                </span>
                @if ($other)
                    <a href="{{ $other }}" hreflang="{{ SiteUrls::otherLocale($meta->locale) }}" lang="{{ SiteUrls::otherLocale($meta->locale) }}"
                       class="inline-flex items-center gap-1.5 font-medium text-brand-text hover:underline">
                        <x-site.icon name="languages" class="size-4" />
                        {{ __('site_pages.blog.also_in') }}
                    </a>
                @endif
            </div>
        </x-site.page-hero>

        @if ($post->cover)
            <figure class="glass relative overflow-hidden rounded-panel p-2 sm:p-3">
                <img src="{{ $post->cover }}" alt="{{ $post->coverAlt }}" @if ($post->coverWidth) width="{{ $post->coverWidth }}" height="{{ $post->coverHeight }}" @endif
                     fetchpriority="high" decoding="async" class="aspect-[2/1] w-full rounded-card object-cover">
            </figure>
        @endif

        <x-site.answer :question="__('site_pages.blog.summary_title')" :answer="$post->summary" :class="$post->cover ? 'mt-6' : ''" />

        <div class="grid gap-6 pt-10 lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-10 lg:pt-12">
            <aside class="grid content-start gap-4 lg:sticky lg:top-24 lg:self-start">
                @if (count($article->toc) > 1)
                    <nav aria-labelledby="contents-title" class="glass rounded-panel p-4" data-toc>
                        <h2 id="contents-title" class="eyebrow px-2">{{ __('site_pages.blog.contents') }}</h2>
                        <ol class="mt-3 grid gap-0.5">
                            @foreach ($article->toc as $heading)
                                <li>
                                    <a href="#{{ $heading['id'] }}" class="block rounded-control border-l-2 border-transparent px-2 py-1.5 text-[0.8125rem] leading-snug text-ink-muted hover:bg-hover hover:text-ink aria-[current=true]:border-brand aria-[current=true]:bg-hover aria-[current=true]:text-ink">{{ $heading['text'] }}</a>
                                </li>
                            @endforeach
                        </ol>
                    </nav>
                @endif

                <div class="glass rounded-panel p-4">
                    <p class="eyebrow px-1">{{ __('site_pages.blog.share') }}</p>
                    <ul class="mt-3 flex flex-wrap gap-2">
                        @foreach ($share as $network => $href)
                            <li>
                                <a href="{{ $href }}" target="_blank" rel="noopener" aria-label="{{ __('site_pages.blog.share_on.'.$network) }}" title="{{ __('site_pages.blog.share_on.'.$network) }}"
                                   class="grid size-10 place-items-center rounded-control bg-hover text-ink-muted transition-colors hover:bg-brand-soft hover:text-brand-text">
                                    <x-site.icon :name="$networks[$network]" class="size-[1.125rem]" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </aside>

            <article class="glass min-w-0 rounded-panel px-5 py-8 sm:p-10 lg:p-12" aria-labelledby="article-title">
                <h2 id="article-title" class="sr-only">{{ $post->title }}</h2>
                {{-- Built from Markdown with raw HTML escaped and only the blog's own images (BlogMarkdown). --}}
                <div class="site-prose mx-auto max-w-[70ch]">{!! $article->html !!}</div>

                <div class="mx-auto mt-12 flex max-w-[70ch] flex-wrap items-center justify-between gap-4 border-t border-line pt-6">
                    <a href="{{ SiteUrls::to('blog') }}" class="inline-flex items-center gap-1.5 text-[0.9375rem] font-medium text-brand-text hover:underline">
                        <x-site.icon name="arrow-right" class="size-4 rotate-180" />
                        {{ __('site_pages.blog.back') }}
                    </a>
                    <span class="text-[0.8125rem] text-ink-subtle">
                        @if ($post->published->diffInDays($post->updated) >= 1)
                            {{ __('site_pages.blog.updated', ['date' => \App\Core\Website\SitePrice::digits($post->updated->copy()->locale($meta->locale)->translatedFormat('j F Y'))]) }}
                        @endif
                    </span>
                </div>
            </article>
        </div>

        @if ($apps !== [])
            <section class="pt-20 sm:pt-24" aria-labelledby="apps-title" data-reveal>
                <h2 id="apps-title" class="text-[clamp(1.5rem,3vw,2.125rem)] leading-tight text-ink">{{ __('site_pages.blog.apps_title') }}</h2>
                <div class="glass mt-6 grid gap-1 rounded-panel p-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($apps as $app)
                        <x-site.app-card :app="$app" />
                    @endforeach
                </div>
            </section>
        @endif

        @if ($faq !== [])
            <div class="pt-20 sm:pt-24" data-reveal>
                <x-site.faq :items="$faq" :title="__('site_pages.blog.questions')" id="post-questions" :columns="false" />
            </div>
        @endif

        @if ($related !== [])
            <section class="pt-20 sm:pt-24" aria-labelledby="related-title" data-reveal>
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <h2 id="related-title" class="text-[clamp(1.5rem,3vw,2.125rem)] leading-tight text-ink">{{ __('site_pages.blog.related_title') }}</h2>
                    <a href="{{ SiteUrls::to('blog') }}" class="inline-flex items-center gap-1.5 text-[0.9375rem] font-medium text-brand-text hover:underline">
                        {{ __('site_pages.blog.back') }}
                        <x-site.icon name="arrow-right" class="size-4" />
                    </a>
                </div>
                <ul class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($related as $next)
                        <li><x-site.post-card :post="$next" /></li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="pt-20 sm:pt-28" data-reveal>
            <x-site.cta :title="__('site.home.cta_title')" :body="__('site.home.cta_body')" :registration-open="$registrationOpen" />
        </div>
    </div>
@endsection
