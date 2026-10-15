@extends('site.layout')

@php
    use App\Core\Website\SitePrice;
    use App\Core\Website\SiteUrls;

    $mostApps = collect($plans)->max(fn (array $plan): int => count($plan['apps'])) ?? 0;
@endphp

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <x-site.page-hero :eyebrow="__('site_pages.pricing.eyebrow')" :title="__('site_pages.pricing.title')" :lede="__('site_pages.pricing.lede')"
                          :trail="[[__('site.nav.pricing'), SiteUrls::to('pricing')]]" />

        <x-site.answer :question="__('site_pages.pricing.answer_q')" :answer="__('site_pages.pricing.answer')" />

        {{-- Plans: what the operator offers today, read from the database. --}}
        <section class="relative isolate pt-16 sm:pt-24" aria-labelledby="plans-title">
            <span class="pointer-events-none absolute top-40 left-[40%] -z-10 size-80 rounded-full bg-(--orb-ember) opacity-70" aria-hidden="true"></span>
            <x-site.section-head id="plans-title" :title="__('site_pages.pricing.plans_heading')" :lede="__('site_pages.pricing.plans_lede')" />

            @if ($plans !== [])
                @include('site.partials.plans', ['plans' => $plans, 'mostApps' => $mostApps, 'compact' => false])
            @else
                <div class="glass mt-8 flex flex-wrap items-center justify-between gap-4 rounded-panel p-6 sm:p-8">
                    <p class="max-w-2xl text-[1.0625rem] text-ink">{{ __('site_pages.pricing.closed') }}</p>
                    <x-site.button variant="primary" :href="SiteUrls::to('contact')">{{ __('site.common.contact_us') }}</x-site.button>
                </div>
            @endif
        </section>

        {{-- Every app's own price, from its App Store offers. --}}
        <section class="pt-20 sm:pt-28" aria-labelledby="app-prices-title" data-reveal>
            <x-site.section-head id="app-prices-title" :title="__('site_pages.pricing.apps_heading')" :lede="__('site_pages.pricing.apps_lede')" />

            <div class="glass mt-8 overflow-hidden rounded-panel">
                <table class="w-full text-left text-[0.9375rem]">
                    <caption class="sr-only">{{ __('site_pages.pricing.apps_heading') }}</caption>
                    <thead class="glass-well">
                        <tr>
                            <th scope="col" class="eyebrow px-5 py-3 font-medium sm:px-6">{{ __('site.pricing.app_name') }}</th>
                            <th scope="col" class="eyebrow px-5 py-3 text-right font-medium sm:px-6">{{ __('site.pricing.app_price') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($apps as $app)
                            <tr>
                                <th scope="row" class="px-5 py-3.5 font-normal sm:px-6">
                                    <a href="{{ SiteUrls::to('app', null, ['app' => $app->slug]) }}" class="inline-flex items-center gap-3 font-medium text-ink hover:text-brand-text">
                                        <span class="grid size-8 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-text"><x-site.icon :name="$app->icon" class="size-4" /></span>
                                        {{ $app->text('name') }}
                                    </a>
                                </th>
                                <td class="px-5 py-3.5 text-right text-ink-muted sm:px-6">
                                    @if ($app->price->isPaid())
                                        <span class="text-ink">{{ $app->price->long() }}</span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 rounded-chip bg-ok-soft px-2 py-0.5 text-xs font-medium text-ok">{{ $app->price->long() }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- How billing works. --}}
        <section class="pt-20 sm:pt-28" aria-labelledby="billing-title" data-reveal>
            <div class="glass grid gap-8 rounded-panel p-6 sm:p-10 lg:grid-cols-[minmax(0,4fr)_minmax(0,7fr)] lg:gap-14">
                <h2 id="billing-title" class="text-[clamp(1.5rem,3vw,2.125rem)] leading-tight text-ink">{{ __('site_pages.pricing.billing_heading') }}</h2>
                <ol class="grid gap-5">
                    @foreach (__('site_pages.pricing.billing_points') as $point)
                        <li class="flex items-start gap-4">
                            <span class="figure grid size-8 shrink-0 place-items-center rounded-full bg-brand-soft text-[0.9375rem] text-brand-text">{{ SitePrice::number($loop->iteration) }}</span>
                            <p class="pt-1 text-[1rem] leading-relaxed text-ink">{{ $point }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        <div class="pt-20 sm:pt-28" data-reveal>
            <x-site.faq :items="$faq" id="pricing-questions" />
        </div>

        <div class="pt-20 sm:pt-28" data-reveal>
            <x-site.cta :title="__('site.home.cta_title')" :body="__('site.home.cta_body')" :registration-open="$registrationOpen" />
        </div>
    </div>
@endsection
