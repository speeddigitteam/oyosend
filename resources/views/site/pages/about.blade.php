@extends('site.layout')

@php
    use App\Core\Website\SitePrice;
    use App\Core\Website\SiteUrls;
@endphp

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <x-site.page-hero :eyebrow="__('site_pages.about.eyebrow')" :title="__('site_pages.about.title')" :lede="__('site_pages.about.lede')"
                          :trail="[[__('site.nav.about'), SiteUrls::to('about')]]" />

        <x-site.answer :question="__('site_pages.about.answer_q')" :answer="__('site_pages.about.answer')" />

        <section class="relative isolate pt-16 sm:pt-24" aria-labelledby="story-title">
            <span class="pointer-events-none absolute top-24 -right-14 -z-10 size-72 rounded-full bg-(--orb-ember) opacity-70" aria-hidden="true"></span>
            <div class="glass grid gap-8 rounded-panel p-6 sm:p-10 lg:grid-cols-[minmax(0,4fr)_minmax(0,7fr)] lg:gap-14">
                <h2 id="story-title" class="text-[clamp(1.5rem,3vw,2.125rem)] leading-tight text-ink">{{ __('site_pages.about.story_heading') }}</h2>
                <div class="grid gap-5 text-[1.0625rem] leading-relaxed text-ink">
                    @foreach (__('site_pages.about.story') as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="pt-20 sm:pt-28" aria-labelledby="principles-title" data-reveal>
            <x-site.section-head id="principles-title" :title="__('site_pages.about.principles_heading')" />
            <ul class="mt-8 grid gap-3 sm:grid-cols-2">
                @foreach (__('site_pages.about.principles') as $principle)
                    <li class="glass rounded-panel p-6 sm:p-8">
                        <span class="figure block text-[2.25rem] text-brand-text/40" aria-hidden="true">{{ SitePrice::digits('0'.$loop->iteration) }}</span>
                        <h3 class="mt-3 text-[1.25rem] text-ink">{{ $principle['title'] }}</h3>
                        <p class="mt-2 text-[0.9375rem] leading-relaxed text-ink-muted">{{ $principle['body'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>

        {{-- Where to find us. --}}
        <section class="pt-20 sm:pt-28" aria-labelledby="office-title" data-reveal>
            <div class="glass grid gap-6 rounded-panel p-6 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:p-10">
                <div>
                    <h2 id="office-title" class="text-[clamp(1.5rem,3vw,2.125rem)] leading-tight text-ink">{{ __('site_pages.contact.details_heading') }}</h2>
                    <p class="mt-3 text-[1rem] leading-relaxed text-ink-muted">
                        {{ implode(', ', $company->addressLines()) }}
                    </p>
                </div>
                <x-site.button variant="primary" :href="SiteUrls::to('contact')">
                    {{ __('site.common.contact_us') }}
                    <x-site.icon name="arrow-right" class="size-4" />
                </x-site.button>
            </div>
        </section>
    </div>
@endsection
