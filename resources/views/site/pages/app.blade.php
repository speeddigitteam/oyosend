@extends('site.layout')

@php
    use App\Core\Website\SitePrice;
    use App\Core\Website\SiteUrls;

    $url = SiteUrls::to('app', null, ['app' => $app->slug]);
@endphp

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <div class="grid items-end gap-8 lg:grid-cols-[minmax(0,1fr)_auto]">
            <x-site.page-hero :eyebrow="__('site_apps.categories.'.$app->category.'.name')" :title="$app->text('headline')" :lede="$app->text('lede')"
                              :trail="[[__('site.nav.apps'), SiteUrls::to('apps')], [$app->text('name'), $url]]">
                <div class="mt-8 flex flex-wrap items-center gap-3">
                    @if ($registrationOpen)
                        <x-site.button variant="primary" size="lg" :href="route('central.register')">
                            {{ __('site.nav.start') }}
                            <x-site.icon name="arrow-right" class="size-4" />
                        </x-site.button>
                    @endif
                    <x-site.button size="lg" :href="SiteUrls::to('contact')">{{ __('site.nav.talk') }}</x-site.button>
                </div>
            </x-site.page-hero>

            {{-- The app's mark and its price, on glass over a disc. --}}
            <div class="relative isolate mb-12 hidden size-64 shrink-0 lg:block" aria-hidden="true">
                <span class="absolute inset-6 -z-10 rounded-full bg-(--orb-ember) opacity-75"></span>
                <span class="absolute -right-4 bottom-2 -z-10 size-24 rounded-full border-[1.5px] border-(--orb-pine) opacity-70"></span>
                <div class="glass absolute inset-10 grid place-items-center rounded-[2rem]">
                    <x-site.icon :name="$app->icon" class="size-16 text-brand-text" />
                </div>
            </div>
        </div>

        <x-site.answer :question="$app->text('answer_q')" :answer="$app->text('answer')" />

        {{-- What it does. --}}
        <section class="pt-20 sm:pt-28" aria-labelledby="features-title" data-reveal>
            <x-site.section-head id="features-title" :eyebrow="$app->text('name')" :title="__('site.common.features')" />
            <ul class="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($app->list('features') as $feature)
                    <li class="glass rounded-panel p-6">
                        <span class="figure block text-[1.75rem] text-brand-text/40" aria-hidden="true">{{ SitePrice::digits(str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT)) }}</span>
                        <h3 class="mt-3 text-[1.125rem] text-ink">{{ $feature['title'] }}</h3>
                        <p class="mt-2 text-[0.9375rem] leading-relaxed text-ink-muted">{{ $feature['body'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>

        {{-- Who it is for, and what it costs. --}}
        <section class="relative isolate pt-20 sm:pt-28" aria-labelledby="audience-title" data-reveal>
            <span class="pointer-events-none absolute top-24 left-[20%] -z-10 size-72 rounded-full bg-(--orb-pine) opacity-60" aria-hidden="true"></span>
            <div class="glass grid gap-10 rounded-panel p-6 sm:p-10 lg:grid-cols-[minmax(0,7fr)_minmax(0,4fr)] lg:gap-14">
                <div>
                    <h2 id="audience-title" class="text-[clamp(1.5rem,3vw,2.125rem)] leading-tight text-ink">{{ __('site.common.audience') }}</h2>
                    <ul class="mt-6 grid gap-4">
                        @foreach ($app->list('audience') as $line)
                            <li class="flex items-start gap-3 text-[1rem] leading-relaxed text-ink">
                                <x-site.icon name="circle-check" class="mt-0.5 size-5 shrink-0 text-brand-text" />
                                {{ $line }}
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="glass-well flex flex-col rounded-card p-6">
                    <p class="eyebrow">{{ __('site.nav.pricing') }}</p>
                    <p class="figure mt-3 text-[2rem] text-ink [&:lang(bn)]:leading-[1.3] [&:lang(bn)]:tracking-normal">
                        {{ $app->price->short() }}
                    </p>
                    @if ($app->price->trialDays > 0)
                        <p class="mt-2 text-[0.875rem] text-ink-muted">{{ __('site.common.trial', ['count' => SitePrice::number($app->price->trialDays)]) }}</p>
                    @endif
                    <a href="{{ SiteUrls::to('pricing') }}" class="mt-auto inline-flex items-center gap-1.5 pt-6 text-[0.9375rem] font-medium text-brand-text hover:underline">
                        {{ __('site.common.see_pricing') }}
                        <x-site.icon name="arrow-right" class="size-4" />
                    </a>
                </div>
            </div>
        </section>

        <div class="pt-20 sm:pt-28" data-reveal>
            <x-site.faq :items="$faq" id="app-questions" :columns="false" />
        </div>

        {{-- Articles about this app, once there are any in this language. --}}
        @if ($posts !== [])
            <section class="pt-20 sm:pt-28" aria-labelledby="reading-title" data-reveal>
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <h2 id="reading-title" class="text-[clamp(1.5rem,3vw,2.125rem)] leading-tight text-ink">{{ __('site_pages.blog.app_title', ['app' => $app->text('name')]) }}</h2>
                    <a href="{{ SiteUrls::to('blog', null, ['app' => $app->slug]) }}" class="inline-flex items-center gap-1.5 text-[0.9375rem] font-medium text-brand-text hover:underline">
                        {{ __('site_pages.blog.app_all', ['app' => $app->text('name')]) }}
                        <x-site.icon name="arrow-right" class="size-4" />
                    </a>
                </div>
                <ul class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($posts as $post)
                        <li><x-site.post-card :post="$post" /></li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($related !== [])
            <section class="pt-20 sm:pt-28" aria-labelledby="related-title" data-reveal>
                <h2 id="related-title" class="text-[clamp(1.5rem,3vw,2.125rem)] leading-tight text-ink">{{ __('site.common.related') }}</h2>
                <div class="glass mt-6 grid gap-1 rounded-panel p-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($related as $other)
                        <x-site.app-card :app="$other" />
                    @endforeach
                </div>
            </section>
        @endif

        <div class="pt-20 sm:pt-28" data-reveal>
            <x-site.cta :title="__('site.home.cta_title')" :body="__('site.home.cta_body')" :registration-open="$registrationOpen" />
        </div>
    </div>
@endsection
