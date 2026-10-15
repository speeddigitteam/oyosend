@extends('site.layout')

@php
    use App\Core\Website\SiteUrls;
@endphp

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <x-site.page-hero :title="__('site_legal.'.$document.'.title')" :lede="__('site_legal.'.$document.'.lede')"
                          :trail="[[__('site_legal.'.$document.'.title'), SiteUrls::to($document)]]">
            <p class="mt-5 text-[0.875rem] text-ink-subtle">{{ __('site_legal.updated_label') }}: <time datetime="{{ config('site.content_updated') }}">{{ __('site_legal.updated') }}</time></p>
        </x-site.page-hero>

        <div class="grid gap-8 lg:grid-cols-[16rem_minmax(0,1fr)] lg:gap-10">
            <nav aria-labelledby="contents-title" class="lg:sticky lg:top-24 lg:self-start">
                <div class="glass rounded-panel p-4">
                    <h2 id="contents-title" class="eyebrow px-2">{{ __('site_legal.contents') }}</h2>
                    <ol class="mt-3 grid gap-0.5">
                        @foreach ($sections as $section)
                            <li><a href="#{{ $section['id'] }}" class="block rounded-control px-2 py-1.5 text-[0.8125rem] leading-snug text-ink-muted hover:bg-hover hover:text-ink">{{ $section['heading'] }}</a></li>
                        @endforeach
                    </ol>
                </div>
            </nav>

            <article class="glass rounded-panel p-6 sm:p-10 lg:p-12">
                <div class="mx-auto grid max-w-3xl gap-10">
                    @foreach ($sections as $section)
                        <section id="{{ $section['id'] }}" class="scroll-mt-28" aria-labelledby="{{ $section['id'] }}-title">
                            <h2 id="{{ $section['id'] }}-title" class="text-[1.375rem] leading-tight text-ink">{{ $section['heading'] }}</h2>
                            <div class="mt-4 grid gap-4 text-[1rem] leading-relaxed text-ink">
                                @foreach ($section['body'] as $paragraph)
                                    <p>{{ $paragraph }}</p>
                                @endforeach
                            </div>
                            @if (! empty($section['items']))
                                <ul class="mt-4 grid gap-2.5">
                                    @foreach ($section['items'] as $item)
                                        <li class="flex items-start gap-3 text-[1rem] leading-relaxed text-ink">
                                            <span class="mt-2.5 size-1.5 shrink-0 rounded-full bg-brand" aria-hidden="true"></span>
                                            {{ $item }}
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </section>
                    @endforeach

                    <p class="glass-well rounded-card p-5 text-[0.9375rem] leading-relaxed text-ink-muted">{{ __('site_legal.questions') }}</p>
                </div>
            </article>
        </div>
    </div>
@endsection
