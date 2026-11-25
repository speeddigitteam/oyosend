{{--
    The plans on sale, as the operator set them up in the Super Admin panel. The plan with the most apps is marked
    as the most complete; nothing here is a price the database does not hold.
--}}
@php
    use App\Core\Website\SitePrice;
@endphp
<ul @class(['mt-10 grid gap-3', 'sm:grid-cols-2 xl:grid-cols-4' => count($plans) >= 4, 'sm:grid-cols-2 lg:grid-cols-3' => count($plans) === 3, 'sm:grid-cols-2' => count($plans) <= 2])>
    @foreach ($plans as $plan)
        @php($featured = $mostApps > 0 && count($plan['apps']) === $mostApps && $loop->last)
        <li @class([
            'relative flex flex-col rounded-panel p-6 sm:p-7',
            'glass-solid ring-2 ring-brand' => $featured,
            'glass' => ! $featured,
        ])>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-[1.25rem] text-ink">{{ $plan['name'] }}</h3>
                @if ($featured)
                    <span class="inline-flex items-center gap-1 rounded-chip bg-brand px-2 py-0.5 text-xs font-medium text-brand-ink">
                        <x-site.icon name="sparkles" class="size-3" />{{ __('site.pricing.popular') }}
                    </span>
                @endif
            </div>
            @if ($plan['description'])
                <p class="mt-2 text-[0.875rem] leading-snug text-ink-muted">{{ $plan['description'] }}</p>
            @endif

            <p class="mt-6 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                @if ($plan['free'] || ($plan['monthly'] === null && $plan['yearly'] === null))
                    <span class="figure text-[2.5rem] text-ink [&:lang(bn)]:leading-[1.3] [&:lang(bn)]:tracking-normal">{{ __('site.common.free') }}</span>
                @elseif ($plan['monthly'] !== null)
                    <span class="figure text-[2.5rem] text-ink">{{ $plan['monthly'] }}</span>
                    <span class="text-[0.875rem] text-ink-muted">{{ __('site.pricing.monthly') }}</span>
                    @if ($plan['monthlyList'])
                        {{-- A running offer: the plan's own price it takes off from. --}}
                        <span class="text-[0.875rem] text-ink-subtle"><span class="sr-only">{{ __('site.pricing.usual_price') }}</span><del>{{ $plan['monthlyList'] }}</del></span>
                    @endif
                @else
                    {{-- Sold by the year only. --}}
                    <span class="figure text-[2.5rem] text-ink">{{ $plan['yearly'] }}</span>
                    <span class="text-[0.875rem] text-ink-muted">{{ __('site.pricing.yearly') }}</span>
                    @if ($plan['yearlyList'])
                        <span class="text-[0.875rem] text-ink-subtle"><span class="sr-only">{{ __('site.pricing.usual_price') }}</span><del>{{ $plan['yearlyList'] }}</del></span>
                    @endif
                @endif
            </p>
            @if (! $plan['free'] && $plan['monthly'] !== null && $plan['yearly'] !== null)
                <p class="mt-1 text-[0.8125rem] text-ink-muted">
                    {{ __('site.common.per_year', ['price' => $plan['yearly']]) }}
                    @if ($plan['yearlyList'])
                        <span class="text-ink-subtle"><span class="sr-only">{{ __('site.pricing.usual_price') }}</span><del>{{ $plan['yearlyList'] }}</del></span>
                    @endif
                </p>
            @endif
            @if (! $plan['free'] && ($plan['offerEnds'] || $plan['yearlySaving']))
                <p class="mt-3 flex flex-wrap gap-1.5">
                    @if ($plan['offerEnds'])
                        <span class="inline-flex items-center gap-1.5 rounded-chip bg-accent-soft px-2 py-0.5 text-xs font-medium text-accent-text">
                            <x-site.icon name="clock" class="size-3.5" />{{ __('site.pricing.offer_until', ['date' => $plan['offerEnds']]) }}
                        </span>
                    @endif
                    @if ($plan['yearlySaving'])
                        <span class="inline-flex items-center rounded-chip bg-ok-soft px-2 py-0.5 text-xs font-medium text-ok">
                            {{ __('site.pricing.yearly_saving', ['percent' => $plan['yearlySaving']]) }}
                        </span>
                    @endif
                </p>
                @if ($plan['offerEnds'])
                    <p class="mt-1.5 text-[0.8125rem] text-ink-subtle">{{ __('site.pricing.offer_keeps') }}</p>
                @endif
            @endif
            @if ($plan['trialDays'] > 0)
                <p class="mt-3">
                    <span class="inline-flex items-center gap-1.5 rounded-chip bg-brand-soft px-2 py-0.5 text-xs font-medium text-brand-text">
                        <x-site.icon name="clock" class="size-3.5" />{{ __('site.common.trial', ['count' => SitePrice::number($plan['trialDays'])]) }}
                    </span>
                </p>
            @endif

            <ul class="mt-6 grid gap-2.5 border-t border-line pt-5 text-[0.875rem] text-ink">
                <li class="flex items-start gap-2.5">
                    <x-site.icon name="users" class="mt-0.5 size-4 shrink-0 text-brand-text" />{{ $plan['members'] }}
                </li>
                @if ($plan['apps'] === [])
                    <li class="flex items-start gap-2.5 text-ink-muted">
                        <x-site.icon name="layout-grid" class="mt-0.5 size-4 shrink-0 text-brand-text" />{{ __('site.pricing.core_only') }}
                    </li>
                @else
                    <li class="flex items-start gap-2.5">
                        <x-site.icon name="layout-grid" class="mt-0.5 size-4 shrink-0 text-brand-text" />
                        <span>
                            {{ trans_choice('site.common.apps_count', count($plan['apps']), ['count' => SitePrice::number(count($plan['apps']))]) }}
                            @unless ($compact ?? false)
                                <span class="mt-1 block text-[0.8125rem] leading-relaxed text-ink-muted">{{ collect($plan['apps'])->map(fn ($app) => $app->text('name'))->implode(', ') }}</span>
                            @endunless
                        </span>
                    </li>
                @endif
            </ul>

            <div class="mt-auto pt-7">
                <x-site.button :variant="$featured ? 'primary' : 'secondary'" :href="$plan['url']" class="w-full">
                    {{ __('site.pricing.choose', ['plan' => $plan['name']]) }}
                </x-site.button>
            </div>
        </li>
    @endforeach
</ul>
