@extends('site.layout')

@php
    use App\Core\Website\SitePrice;
    use App\Core\Website\SiteUrls;

    $domain = app(\App\Core\Tenancy\Support\TenantHost::class)->domain();
    $appCount = collect($groups)->flatten(1)->count();
    $mostApps = collect($plans)->max(fn (array $plan): int => count($plan['apps']));
    $trialDays = (int) collect($plans)->max('trialDays');
    $dash = __('site.home.dash');
    [$titleBefore, $titleAfter] = array_pad(explode(':highlight', __('site.home.title'), 2), 2, '');
    $tones = ['warn' => 'bg-warn-soft text-warn', 'accent' => 'bg-accent-soft text-accent-text', 'ok' => 'bg-ok-soft text-ok', 'info' => 'bg-info-soft text-info'];
    $navIcons = ['layout-grid', 'handshake', 'receipt', 'users-round', 'wallet', 'message-circle', 'life-buoy', 'chart-no-axes-combined'];
@endphp

@section('content')
    {{--
        Hero: the one H1 and the start card over soft drifting brand light. As the page scrolls the hero fades back and
        the product rises into place beneath it (resources/js/site.ts sets --hero and --rise).
        The section starts under the sticky header (-mt-19 / pt-19 = the header's 12px + 64px), so the light also fills
        the strip behind the header instead of being clipped into a hard line at the header's bottom edge.
    --}}
    <section class="relative isolate -mt-19 overflow-hidden pt-19" aria-labelledby="hero-title" data-hero>
        <div class="site-aurora" aria-hidden="true">
            <span class="top-[6%] -left-[8%] size-[46vw] bg-(--field-a)"></span>
            <span class="top-[34%] right-[8%] size-[28vw] bg-(--orb-ember) opacity-40 [animation-delay:-7s]"></span>
            <span class="-top-[8%] -right-[10%] size-[40vw] bg-(--field-c) [animation-delay:-13s]"></span>
            <span class="bottom-0 left-[28%] size-[26vw] bg-(--field-b) [animation-delay:-4s]"></span>
        </div>

        <div class="site-hero-body mx-auto grid max-w-7xl items-center gap-12 px-4 pt-12 pb-32 sm:px-6 sm:pt-16 lg:grid-cols-[minmax(0,1.22fr)_minmax(0,0.78fr)] lg:gap-16 lg:pt-20 lg:pb-40">
            <div class="min-w-0">
                <p class="glass inline-flex items-center rounded-full px-3.5 py-1.5 text-[0.8125rem] font-medium text-ink">
                    {{ __('site.home.eyebrow') }}
                </p>

                {{-- The highlight takes turns with the other endings (resources/js/site.ts). Screen readers get the
                     heading as written, from its label, so it does not keep changing under them; the text itself says
                     it once, for search engines and for anyone without the script. --}}
                <h1 id="hero-title" aria-label="{{ trim($titleBefore.__('site.home.title_highlight').$titleAfter) }}" class="mt-6 max-w-[16ch] text-[clamp(2.75rem,6.8vw,5.5rem)] leading-[0.96] tracking-[-0.045em] text-ink [&:lang(bn)]:max-w-[18ch] [&:lang(bn)]:leading-[1.28] [&:lang(bn)]:tracking-normal">
                    {{ $titleBefore }}<span class="site-rotate text-brand-text underline decoration-accent decoration-[0.09em] underline-offset-[0.14em] sm:whitespace-nowrap [&:lang(bn)]:underline-offset-[0.22em]" data-rotate="{{ json_encode(array_values((array) __('site.home.title_rotate'))) }}">{{ __('site.home.title_highlight') }}</span>{{ $titleAfter }}
                </h1>

                <p class="mt-6 max-w-xl text-[1.0625rem] leading-relaxed text-ink-muted sm:text-[1.1875rem]">{{ __('site.home.lede') }}</p>

                <a href="{{ SiteUrls::to('apps') }}" class="mt-6 inline-flex items-center gap-1.5 text-[0.9375rem] font-medium text-brand-text hover:underline">
                    {{ __('site.common.see_apps') }}
                    <x-site.icon name="arrow-right" class="size-4" />
                </a>

                {{-- True of the product, not borrowed numbers. --}}
                <div class="mt-10 grid max-w-xl grid-cols-3 gap-5 border-t border-line-strong pt-6">
                    <p class="text-[0.8125rem] leading-snug text-ink-muted">
                        <span class="figure block text-[2rem] text-ink">{{ SitePrice::number($appCount) }}</span>
                        {{ __('site.home.stats.apps') }}
                    </p>
                    <p class="text-[0.8125rem] leading-snug text-ink-muted">
                        <span class="figure block text-[2rem] text-ink">{{ SitePrice::number(2) }}</span>
                        {{ __('site.home.stats.languages') }}
                    </p>
                    <p class="text-[0.8125rem] leading-snug text-ink-muted">
                        @if ($trialDays > 0)
                            <span class="figure block text-[2rem] text-ink">{{ SitePrice::number($trialDays) }}</span>
                            {{ __('site.home.stats.trial') }}
                        @else
                            <span class="figure block text-[2rem] text-ink">{{ SitePrice::number(0) }}</span>
                            {{ __('site.home.stats.charges') }}
                        @endif
                    </p>
                </div>
            </div>

            {{-- The start of sign-up: the company and email are carried to the full form (no address bar, no logs). --}}
            <aside id="start" class="glass relative rounded-panel p-6 shadow-float sm:p-8" aria-labelledby="start-title">
                @if ($registrationOpen)
                    <h2 id="start-title" class="text-[1.375rem] leading-tight text-ink">{{ __('site.home.start.title') }}</h2>
                    <p class="mt-2 text-[0.9375rem] leading-relaxed text-ink-muted">{{ __('site.home.start.lede') }}</p>

                    <form method="POST" action="{{ route('central.register.start') }}" class="mt-6 grid gap-4" data-start-form>
                        @csrf
                        <div>
                            <label for="start-company" class="mb-1.5 block text-[0.8125rem] font-medium text-ink">{{ __('site.home.start.company') }}</label>
                            <input id="start-company" name="company" type="text" class="control h-11" maxlength="255" autocomplete="organization"
                                   placeholder="{{ __('site.home.start.company_placeholder') }}" data-start-company>
                        </div>
                        <div>
                            <label for="start-email" class="mb-1.5 block text-[0.8125rem] font-medium text-ink">{{ __('site.home.start.email') }}</label>
                            <input id="start-email" name="email" type="email" class="control h-11" maxlength="255" autocomplete="email"
                                   placeholder="{{ __('site.home.start.email_placeholder') }}">
                        </div>
                        <div class="glass-well rounded-control px-3.5 py-3">
                            <p class="eyebrow">{{ __('site.home.start.address') }}</p>
                            <p class="mt-1.5 truncate font-mono text-[0.9375rem] text-ink" aria-live="polite">
                                <span class="text-brand-text" data-start-address data-placeholder="yourcompany">yourcompany</span><span class="text-ink-subtle">.{{ $domain }}</span>
                            </p>
                        </div>
                        <x-site.button type="submit" variant="primary" size="lg" class="w-full">
                            {{ __('site.home.start.submit') }}
                            <x-site.icon name="arrow-right" class="size-4" />
                        </x-site.button>
                        <p class="text-center text-[0.8125rem] text-ink-subtle">{{ __('site.home.start.next') }}</p>
                    </form>
                @else
                    <h2 id="start-title" class="text-[1.375rem] leading-tight text-ink">{{ __('site.home.start.closed_title') }}</h2>
                    <p class="mt-2 text-[0.9375rem] leading-relaxed text-ink-muted">{{ __('site.home.start.closed_body') }}</p>
                    <x-site.button variant="primary" size="lg" class="mt-6 w-full" :href="SiteUrls::to('contact')">
                        {{ __('site.nav.talk') }}
                        <x-site.icon name="arrow-right" class="size-4" />
                    </x-site.button>
                @endif

                <p class="mt-6 flex flex-wrap items-center justify-center gap-x-1.5 border-t border-line pt-5 text-[0.875rem] text-ink-muted">
                    {{ __('site.home.start.existing') }}
                    <a href="{{ SiteUrls::to('signin') }}" class="font-medium text-brand-text hover:underline">{{ __('site.nav.signin') }}</a>
                </p>
            </aside>
        </div>
    </section>

    {{-- The product at work: a workspace drawn in HTML (decorative sample data), tilted until it scrolls into place. --}}
    <section class="relative -mt-20 [perspective:1600px] sm:-mt-24" aria-labelledby="show-title" data-show>
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <div class="site-stage relative" data-stage>
                <span class="pointer-events-none absolute inset-x-[12%] -bottom-10 -z-10 h-2/3 rounded-full bg-(--orb-pine) opacity-50 blur-3xl" aria-hidden="true"></span>

                <div class="glass rounded-[1.75rem] p-2 shadow-float sm:p-2.5">
                    <div class="site-screen grid overflow-hidden rounded-[1.25rem] border border-line lg:grid-cols-[13rem_minmax(0,1fr)]" role="img" aria-label="{{ $dash['label'] }}">
                        {{-- Sidebar, as in the app. --}}
                        <div class="hidden flex-col gap-0.5 border-r border-line bg-glass p-3 lg:flex" aria-hidden="true">
                            <div class="px-2 pt-1 pb-4"><x-site.logo id="show-logo" :size="22" /></div>
                            <p class="eyebrow px-2 pb-1.5">{{ $dash['apps'] }}</p>
                            @foreach ($dash['nav'] as $item)
                                <span @class([
                                    'flex items-center gap-2.5 rounded-control px-2 py-1.5 text-[0.8125rem]',
                                    'bg-active font-medium text-ink shadow-sm' => $loop->first,
                                    'text-ink-muted' => ! $loop->first,
                                ])>
                                    <x-site.icon :name="$navIcons[$loop->index] ?? 'circle'" class="size-4 {{ $loop->first ? 'text-brand-text' : '' }}" />
                                    {{ $item }}
                                </span>
                            @endforeach
                        </div>

                        <div class="grid min-w-0 content-start gap-3 p-3 sm:p-5" aria-hidden="true">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="mr-auto text-[1.125rem] font-semibold tracking-tight text-ink">{{ $dash['greeting'] }}</p>
                                <span class="glass-well rounded-full px-2.5 py-1 font-mono text-[0.6875rem] text-ink-muted">{{ $dash['period'] }}</span>
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-ok-soft px-2.5 py-1 text-[0.6875rem] font-medium text-ok">
                                    <span class="size-1.5 rounded-full bg-current"></span>{{ $dash['live'] }}
                                </span>
                            </div>

                            <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                                @foreach ($dash['kpis'] as $kpi)
                                    <div class="glass rounded-card p-3.5">
                                        <p class="text-[0.75rem] text-ink-muted">{{ $kpi['label'] }}</p>
                                        <p class="figure mt-1.5 text-[1.5rem] text-ink">
                                            {{ [SitePrice::format(124500000, 'BDT'), SitePrice::format(38250000, 'BDT'), SitePrice::number(4), SitePrice::number(9)][$loop->index] ?? '' }}
                                        </p>
                                        <p class="mt-1 text-[0.6875rem] text-ink-subtle">{{ $kpi['note'] }}</p>
                                    </div>
                                @endforeach
                            </div>

                            <div class="grid min-w-0 gap-3 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
                                <div class="glass min-w-0 rounded-card p-4">
                                    <p class="flex items-baseline justify-between gap-2 text-[0.8125rem] font-semibold text-ink">{{ $dash['deals'] }} <span class="font-mono text-[0.6875rem] font-normal text-ink-subtle">{{ $dash['deals_note'] }}</span></p>
                                    <ul class="mt-2 divide-y divide-line">
                                        @foreach ($dash['deal_list'] as $deal)
                                            <li class="flex items-center gap-3 py-2.5 text-[0.8125rem]">
                                                <span class="grid size-8 shrink-0 place-items-center rounded-control bg-brand-soft text-[0.6875rem] font-semibold text-brand-text">{{ mb_substr($deal['name'], 0, 1) }}</span>
                                                <span class="min-w-0 flex-1 truncate font-medium text-ink">{{ $deal['name'] }}</span>
                                                <span class="hidden rounded-full px-2 py-0.5 text-[0.6875rem] font-medium sm:inline {{ $tones[$deal['tone']] ?? $tones['info'] }}">{{ $deal['stage'] }}</span>
                                                <span class="tabular w-24 text-right font-semibold text-ink">{{ SitePrice::format([48000000, 12500000, 96000000, 24000000][$loop->index] ?? 0, 'BDT') }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>

                                <div class="glass min-w-0 rounded-card p-4">
                                    <p class="flex items-baseline justify-between gap-2 text-[0.8125rem] font-semibold text-ink">
                                        <span class="inline-flex items-center gap-1.5"><x-site.icon name="message-circle" class="size-4 text-brand-text" />{{ $dash['inbox'] }}</span>
                                        <span class="text-[0.6875rem] font-normal text-ok">{{ $dash['inbox_note'] }}</span>
                                    </p>
                                    <ul class="mt-3 grid gap-2">
                                        @foreach ($dash['chats'] as $chat)
                                            <li class="flex items-center gap-2.5 rounded-control bg-chat-in px-2.5 py-2 shadow-sm">
                                                <span class="grid size-7 shrink-0 place-items-center rounded-full bg-accent-soft text-[0.6875rem] font-semibold text-accent-text">{{ mb_substr($chat['name'], 0, 1) }}</span>
                                                <span class="min-w-0 text-[0.75rem] leading-snug">
                                                    <span class="block font-semibold text-ink">{{ $chat['name'] }}</span>
                                                    <span class="block truncate text-ink-muted">{{ $chat['text'] }}</span>
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                    <div class="mt-3 flex h-12 items-end gap-1.5" aria-hidden="true">
                                        @foreach ([38, 54, 46, 64, 58, 76, 100] as $bar)
                                            <span class="flex-1 rounded-t-[4px] {{ $loop->last ? 'bg-brand' : 'bg-brand-soft' }}" style="height: {{ $bar }}%"></span>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- The assistant proposes; a person decides (docs/AI.md). --}}
                <div class="site-assistant glass-solid relative mx-3 -mt-8 rounded-card p-4 sm:mx-6 lg:absolute lg:-right-5 lg:bottom-12 lg:mx-0 lg:mt-0 lg:w-80" aria-hidden="true">
                    <div class="flex items-center gap-2.5">
                        <span class="grid size-8 place-items-center rounded-control bg-brand text-brand-ink"><x-site.icon name="sparkles" class="size-4" /></span>
                        <span class="text-[0.875rem] font-semibold text-ink">{{ $dash['assistant'] }}
                            <span class="block font-mono text-[0.6875rem] font-normal text-brand-text">{{ $dash['assistant_when'] }}</span>
                        </span>
                    </div>
                    <p class="mt-3 rounded-[0.875rem] rounded-tl-sm bg-hover px-3 py-2.5 text-[0.8125rem] leading-relaxed text-ink">{{ $dash['assistant_text'] }}</p>
                    <div class="mt-3 flex gap-2">
                        <span class="rounded-full bg-ink px-3 py-1 text-[0.75rem] font-medium text-canvas">{{ $dash['assistant_send'] }}</span>
                        <span class="rounded-full border border-line-strong px-3 py-1 text-[0.75rem] font-medium text-ink">{{ $dash['assistant_dismiss'] }}</span>
                    </div>
                </div>
            </div>

            <div class="mx-auto mt-16 max-w-2xl text-center sm:mt-20">
                <h2 id="show-title" class="text-[clamp(1.75rem,3.6vw,2.75rem)] leading-[1.06] tracking-[-0.03em] text-ink">{{ __('site.home.show_title') }}</h2>
                <p class="mt-4 text-[1.0625rem] leading-relaxed text-ink-muted">{{ __('site.home.show_body') }}</p>
            </div>
        </div>
    </section>

    <div class="mx-auto max-w-7xl px-4 pt-16 sm:px-6 sm:pt-24">
        <x-site.answer :question="__('site.home.answer_q')" :answer="__('site.home.answer')" />

        {{-- Every app, by area of the business. --}}
        <section class="relative isolate pt-24 sm:pt-32" aria-labelledby="apps-title" data-reveal>
            <span class="pointer-events-none absolute top-[55%] left-[30%] -z-10 size-80 rounded-full bg-(--orb-pine) opacity-60" aria-hidden="true"></span>
            <span class="pointer-events-none absolute right-[8%] bottom-10 -z-10 size-40 rounded-full bg-(--orb-butter) opacity-80" aria-hidden="true"></span>

            <div class="flex flex-wrap items-end justify-between gap-6">
                <x-site.section-head id="apps-title"
                    :eyebrow="__('site.home.apps_eyebrow', ['count' => SitePrice::number($appCount)])"
                    :title="__('site.home.apps_title')"
                    :lede="__('site.home.apps_lede')" />
                <x-site.button :href="SiteUrls::to('apps')">
                    {{ __('site.nav.all_apps', ['count' => SitePrice::number($appCount)]) }}
                    <x-site.icon name="arrow-right" class="size-4" />
                </x-site.button>
            </div>

            <div class="mt-10 gap-3 md:columns-2 xl:columns-3">
                @foreach ($groups as $category => $apps)
                    <article class="glass mb-3 break-inside-avoid rounded-panel p-3 sm:p-4" aria-labelledby="category-{{ $category }}">
                        <header class="px-3 pt-2 pb-3">
                            <h3 id="category-{{ $category }}" class="text-[1.125rem] text-ink">{{ __("site_apps.categories.{$category}.name") }}</h3>
                            <p class="mt-1 text-[0.8125rem] leading-snug text-ink-muted">{{ __("site_apps.categories.{$category}.description") }}</p>
                        </header>
                        <div class="grid gap-0.5">
                            @foreach ($apps as $app)
                                <x-site.app-card :app="$app" />
                            @endforeach
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        {{-- What is particular to Bangladesh. --}}
        <section class="relative isolate pt-24 sm:pt-32" aria-labelledby="local-title" data-reveal>
            <span class="pointer-events-none absolute top-20 -right-10 -z-10 size-72 rounded-full bg-(--orb-ember) opacity-75 sm:top-24" aria-hidden="true"></span>

            <div class="glass rounded-panel p-6 sm:p-10 lg:p-12">
                <div class="grid gap-10 lg:grid-cols-[minmax(0,4fr)_minmax(0,7fr)] lg:gap-14">
                    <div>
                        <p class="eyebrow text-brand-text">{{ __('site.home.local_eyebrow') }}</p>
                        <h2 id="local-title" class="mt-3 text-[clamp(1.75rem,3.6vw,2.625rem)] leading-[1.08] tracking-[-0.03em] text-ink">{{ __('site.home.local_title') }}</h2>
                    </div>
                    <div class="grid gap-x-8 gap-y-8 sm:grid-cols-2">
                        @foreach (__('site.home.local') as $point)
                            <div>
                                <span class="grid size-10 place-items-center rounded-control bg-brand-soft text-brand-text"><x-site.icon :name="$point['icon']" class="size-5" /></span>
                                <h3 class="mt-4 text-[1.125rem] text-ink">{{ $point['title'] }}</h3>
                                <p class="mt-2 text-[0.9375rem] leading-relaxed text-ink-muted">{{ $point['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        {{-- Getting started. --}}
        <section class="pt-24 sm:pt-32" aria-labelledby="steps-title" data-reveal>
            <x-site.section-head id="steps-title" :eyebrow="__('site.home.steps_eyebrow')" :title="__('site.home.steps_title')" />

            <ol class="mt-10 grid gap-3 md:grid-cols-3">
                @foreach (__('site.home.steps', ['domain' => $domain]) as $step)
                    <li class="glass relative overflow-hidden rounded-panel p-6 sm:p-8">
                        <span class="figure block text-[4.5rem] leading-none text-brand-text/25" aria-hidden="true">{{ SitePrice::digits('0'.($loop->iteration)) }}</span>
                        <h3 class="mt-4 text-[1.25rem] text-ink">{{ $step['title'] }}</h3>
                        <p class="mt-2 text-[0.9375rem] leading-relaxed text-ink-muted">{{ $step['body'] }}</p>
                    </li>
                @endforeach
            </ol>
        </section>

        {{-- Security. --}}
        <section class="pt-24 sm:pt-32" aria-labelledby="security-title" data-reveal>
            <div class="glass grid gap-10 rounded-panel p-6 sm:p-10 lg:grid-cols-[minmax(0,4fr)_minmax(0,7fr)] lg:gap-14 lg:p-12">
                <div>
                    <p class="eyebrow text-brand-text">{{ __('site.home.security_eyebrow') }}</p>
                    <h2 id="security-title" class="mt-3 text-[clamp(1.75rem,3.6vw,2.625rem)] leading-[1.08] tracking-[-0.03em] text-ink">{{ __('site.home.security_title') }}</h2>
                    <p class="mt-4 text-[1rem] leading-relaxed text-ink-muted">{{ __('site.home.security_lede') }}</p>
                    <a href="{{ SiteUrls::to('security') }}" class="mt-6 inline-flex items-center gap-1.5 text-[0.9375rem] font-medium text-brand-text hover:underline">
                        {{ __('site.home.security_link') }}
                        <x-site.icon name="arrow-right" class="size-4" />
                    </a>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach (__('site.home.security_points') as $point)
                        <div class="glass-well rounded-card p-5">
                            <x-site.icon :name="$point['icon']" class="size-5 text-brand-text" />
                            <h3 class="mt-3 text-[1.0625rem] text-ink">{{ $point['title'] }}</h3>
                            <p class="mt-1.5 text-[0.875rem] leading-relaxed text-ink-muted">{{ $point['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Plans, from the database: shown only while someone can sign up for them. --}}
        <section class="pt-24 sm:pt-32" aria-labelledby="pricing-title" data-reveal>
            <div class="flex flex-wrap items-end justify-between gap-6">
                <x-site.section-head id="pricing-title" :eyebrow="__('site.home.pricing_eyebrow')" :title="__('site.home.pricing_title')" :lede="__('site.home.pricing_lede')" />
                <x-site.button :href="SiteUrls::to('pricing')">
                    {{ __('site.common.see_pricing') }}
                    <x-site.icon name="arrow-right" class="size-4" />
                </x-site.button>
            </div>

            @if ($plans !== [])
                @include('site.partials.plans', ['plans' => $plans, 'mostApps' => $mostApps, 'compact' => true])
            @else
                <div class="glass mt-10 flex flex-wrap items-center justify-between gap-4 rounded-panel p-6 sm:p-8">
                    <p class="max-w-xl text-[1.0625rem] text-ink">{{ __('site.home.pricing_closed') }}</p>
                    <x-site.button variant="primary" :href="SiteUrls::to('contact')">{{ __('site.common.contact_us') }}</x-site.button>
                </div>
            @endif
        </section>

        <div class="pt-24 sm:pt-32" data-reveal>
            <x-site.faq :items="$faq" id="home-questions">
                <a href="{{ SiteUrls::to('faq') }}" class="inline-flex items-center gap-1.5 text-[0.9375rem] font-medium text-brand-text hover:underline">
                    {{ __('site.nav.faq') }}
                    <x-site.icon name="arrow-right" class="size-4" />
                </a>
            </x-site.faq>
        </div>

        {{-- The newest articles, once the blog has any in this language. --}}
        @if ($posts !== [])
            <section class="pt-24 sm:pt-32" aria-labelledby="blog-title" data-reveal>
                <div class="flex flex-wrap items-end justify-between gap-6">
                    <x-site.section-head id="blog-title" :eyebrow="__('site_pages.blog.latest_eyebrow')" :title="__('site_pages.blog.latest_title')" :lede="__('site_pages.blog.latest_lede')" />
                    <x-site.button :href="SiteUrls::to('blog')">
                        {{ __('site_pages.blog.latest_all') }}
                        <x-site.icon name="arrow-right" class="size-4" />
                    </x-site.button>
                </div>
                <ul class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($posts as $post)
                        <li><x-site.post-card :post="$post" /></li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="pt-24 sm:pt-32" data-reveal>
            <x-site.app-orbit :title="__('site.home.cta_title')" :body="__('site.home.cta_body')" :apps="collect($groups)->flatten(1)->all()" :registration-open="$registrationOpen" />
        </div>
    </div>
@endsection
