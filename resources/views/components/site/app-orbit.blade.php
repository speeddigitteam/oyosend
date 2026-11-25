{{--
    The home page's closing call to action: the headline and the start button in the middle, the apps scattered around
    them as tilted tiles that drift a little (still when the visitor asks for less motion). Each tile opens its app's
    page. Under `lg` there is no room to scatter them, so they sit in a row above the headline.
    Our own app icons only: other companies' logos are not ours to decorate with.
--}}
@props(['title', 'body', 'apps', 'registrationOpen' => false])
@php
    use App\Core\Website\SitePrice;
    use App\Core\Website\SiteUrls;

    // The apps a visitor knows first; any that this build does not have are skipped.
    $preferred = ['crm', 'whatsapp-business', 'invoicing', 'hr-management', 'social-media-management', 'payroll',
        'email-marketing', 'customer-support', 'project-management', 'ai-assistant', 'sms-marketing', 'calendar'];
    $bySlug = collect($apps)->keyBy('slug');
    $shown = collect($preferred)->map(fn (string $slug) => $bySlug->get($slug))->filter()->values();

    // Where each tile sits on wide screens (centre point, % of the section) and how far it leans.
    $slots = [
        [9, 17, -12], [27, 11, 9], [48, 11, -6], [69, 12, 13], [88, 19, -9], [5, 46, 10],
        [93, 47, -13], [10, 79, -8], [28, 89, 12], [50, 91, -10], [71, 88, 8], [89, 77, -12],
    ];
    // Tone pairs already used together on the site, so their contrast holds in both themes.
    $tones = [
        'bg-brand text-brand-ink', 'bg-accent-soft text-accent-text', 'bg-ink text-canvas', 'bg-ok-soft text-ok',
        'bg-accent text-accent-ink', 'bg-brand-soft text-brand-text', 'bg-info-soft text-info', 'bg-warn-soft text-warn',
    ];
@endphp
<section aria-labelledby="closing-call" {{ $attributes->class('site-orbit glass relative isolate flex flex-col overflow-hidden rounded-panel px-5 py-12 sm:px-10 sm:py-16 lg:block lg:px-12 lg:py-44') }}>
    <span class="pointer-events-none absolute top-1/2 left-1/2 -z-10 size-112 -translate-x-1/2 -translate-y-1/2 rounded-full bg-(--orb-pine) opacity-25 blur-3xl" aria-hidden="true"></span>
    <span class="pointer-events-none absolute -right-16 -bottom-20 -z-10 size-64 rounded-full bg-(--orb-butter) opacity-40 blur-2xl" aria-hidden="true"></span>

    <div class="relative z-10 mx-auto max-w-2xl text-center">
        <h2 id="closing-call" class="text-[clamp(2rem,4.4vw,3.25rem)] leading-[1.05] tracking-[-0.03em] text-ink">{{ $title }}</h2>
        <p class="mx-auto mt-4 max-w-xl text-[1.0625rem] leading-relaxed text-ink-muted">{{ $body }}</p>

        <div class="mt-8 flex flex-wrap justify-center gap-3">
            @if ($registrationOpen)
                <x-site.button variant="primary" size="lg" :href="route('central.register')">
                    {{ __('site.nav.start') }}
                    <x-site.icon name="arrow-right" class="size-4" />
                </x-site.button>
            @endif
            <x-site.button size="lg" :href="SiteUrls::to('contact')">{{ __('site.nav.talk') }}</x-site.button>
        </div>

        <a href="{{ SiteUrls::to('apps') }}" class="mt-6 inline-flex items-center gap-1.5 text-[0.9375rem] font-medium text-brand-text hover:underline">
            {{ __('site.nav.all_apps', ['count' => SitePrice::number(count($apps))]) }}
            <x-site.icon name="arrow-right" class="size-4" />
        </a>
    </div>

    <ul class="order-first mb-10 flex flex-wrap justify-center gap-3 sm:gap-4 lg:mb-0" aria-label="{{ __('site.home.orbit_label') }}">
        @foreach ($shown as $index => $app)
            @php([$x, $y, $tilt] = $slots[$index % count($slots)])
            <li class="site-orbit-slot" style="--x: {{ $x }}%; --y: {{ $y }}%; --tilt: {{ $tilt }}deg; --delay: -{{ $index * 0.7 }}s">
                <a href="{{ SiteUrls::to('app', null, ['app' => $app->slug]) }}"
                   class="site-orbit-tile grid size-12 place-items-center rounded-[0.9rem] shadow-float sm:size-14 lg:size-16 lg:rounded-[1.1rem] {{ $tones[$index % count($tones)] }}"
                   title="{{ $app->text('name') }}">
                    <x-site.icon :name="$app->icon" class="size-5 sm:size-6 lg:size-7" />
                    <span class="sr-only">{{ $app->text('name') }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</section>
