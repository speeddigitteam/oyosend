@extends('site.layout')

@php
    use App\Core\Website\SiteUrls;
@endphp

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <x-site.page-hero :eyebrow="__('site_pages.apps.eyebrow')" :title="__('site_pages.apps.title')" :lede="__('site_pages.apps.lede')"
                          :trail="[[__('site.nav.apps'), SiteUrls::to('apps')]]" />

        <x-site.answer :question="__('site_pages.apps.answer_q')" :answer="__('site_pages.apps.answer')" />

        {{-- One section per area, each app with its own page. --}}
        @foreach ($groups as $category => $apps)
            <section class="relative isolate pt-16 sm:pt-20" aria-labelledby="area-{{ $category }}" @if (! $loop->first) data-reveal @endif>
                @if ($loop->index % 2 === 1)
                    <span class="pointer-events-none absolute top-36 -right-12 -z-10 size-64 rounded-full bg-(--orb-pine) opacity-60" aria-hidden="true"></span>
                @endif
                <div class="max-w-2xl">
                    <h2 id="area-{{ $category }}" class="text-[clamp(1.5rem,3vw,2.125rem)] leading-tight text-ink">{{ __("site_apps.categories.{$category}.name") }}</h2>
                    <p class="mt-2 text-[1rem] text-ink-muted">{{ __("site_apps.categories.{$category}.description") }}</p>
                </div>
                <ul class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($apps as $app)
                        <li>
                            <a href="{{ SiteUrls::to('app', null, ['app' => $app->slug]) }}" class="glass group flex h-full flex-col rounded-panel p-6 transition-transform duration-200 hover:-translate-y-0.5">
                                <span class="flex items-start justify-between gap-3">
                                    <span class="grid size-11 place-items-center rounded-control bg-brand-soft text-brand-text">
                                        <x-site.icon :name="$app->icon" class="size-[1.375rem]" />
                                    </span>
                                    <span class="text-[0.75rem] font-medium text-ink-subtle">
                                        {{ $app->price->short() }}
                                    </span>
                                </span>
                                <span class="mt-5 text-[1.1875rem] font-semibold text-ink">{{ $app->text('name') }}</span>
                                <span class="mt-1.5 text-[0.9375rem] leading-relaxed text-ink-muted">{{ $app->text('tagline') }}</span>
                                <span class="mt-auto inline-flex items-center gap-1.5 pt-5 text-[0.875rem] font-medium text-brand-text">
                                    {{ __('site.common.learn_more') }}
                                    <x-site.icon name="arrow-right" class="size-4 transition-transform duration-200 group-hover:translate-x-0.5" />
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach

        <div class="pt-24 sm:pt-32" data-reveal>
            <x-site.cta :title="__('site_pages.apps.cta_title')" :body="__('site_pages.apps.cta_body')" :registration-open="$registrationOpen" />
        </div>
    </div>
@endsection
