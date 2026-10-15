@extends('site.layout')

@php
    use App\Core\Website\SiteUrls;

    $icons = ['layers', 'user-round-check', 'key-round', 'scroll-text', 'lock', 'eye-off', 'shield-check', 'mail'];
@endphp

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <x-site.page-hero :eyebrow="__('site_pages.security.eyebrow')" :title="__('site_pages.security.title')" :lede="__('site_pages.security.lede')"
                          :trail="[[__('site.nav.security'), SiteUrls::to('security')]]" />

        <x-site.answer :question="__('site_pages.security.answer_q')" :answer="__('site_pages.security.answer')" />

        <section class="relative isolate pt-16 sm:pt-24" aria-label="{{ __('site_pages.security.eyebrow') }}">
            <span class="pointer-events-none absolute top-1/3 -left-16 -z-10 size-80 rounded-full bg-(--orb-pine) opacity-60" aria-hidden="true"></span>
            <span class="pointer-events-none absolute -right-10 bottom-10 -z-10 size-56 rounded-full bg-(--orb-ember) opacity-70" aria-hidden="true"></span>
            <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (__('site_pages.security.pillars') as $pillar)
                    <li class="glass rounded-panel p-6" @if ($loop->index >= 4) data-reveal @endif>
                        <span class="grid size-11 place-items-center rounded-control bg-brand-soft text-brand-text">
                            <x-site.icon :name="$icons[$loop->index] ?? 'shield-check'" class="size-[1.375rem]" />
                        </span>
                        <h2 class="mt-5 text-[1.125rem] text-ink">{{ $pillar['title'] }}</h2>
                        <p class="mt-2 text-[0.9375rem] leading-relaxed text-ink-muted">{{ $pillar['body'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="pt-20 sm:pt-28" aria-labelledby="practices-title" data-reveal>
            <div class="glass grid gap-8 rounded-panel p-6 sm:p-10 lg:grid-cols-[minmax(0,4fr)_minmax(0,7fr)] lg:gap-14">
                <h2 id="practices-title" class="text-[clamp(1.5rem,3vw,2.125rem)] leading-tight text-ink">{{ __('site_pages.security.practices_heading') }}</h2>
                <ul class="grid gap-4 sm:grid-cols-2">
                    @foreach (__('site_pages.security.practices') as $practice)
                        <li class="flex items-start gap-3 text-[0.9375rem] leading-relaxed text-ink">
                            <x-site.icon name="circle-check" class="mt-0.5 size-5 shrink-0 text-brand-text" />
                            {{ $practice }}
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>

        <div class="pt-20 sm:pt-28" data-reveal>
            <x-site.faq :items="$faq" id="security-questions" />
        </div>

        <div class="pt-20 sm:pt-28" data-reveal>
            <x-site.cta :title="__('site.home.cta_title')" :body="__('site.home.cta_body')" :registration-open="$registrationOpen" />
        </div>
    </div>
@endsection
