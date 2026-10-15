{{-- The closing call to action: a solid brand panel with its own glass discs, the one place the brand colour fills. --}}
@props(['title', 'body', 'registrationOpen' => false])
<section aria-labelledby="closing-call" {{ $attributes->class('relative isolate overflow-hidden rounded-panel bg-brand px-6 py-12 text-brand-ink shadow-float sm:px-12 sm:py-16') }}>
    <span class="pointer-events-none absolute -top-24 -right-16 -z-10 size-72 rounded-full border border-brand-ink/25" aria-hidden="true"></span>
    <span class="pointer-events-none absolute -right-6 -bottom-28 -z-10 size-64 rounded-full bg-brand-ink/10" aria-hidden="true"></span>
    <span class="pointer-events-none absolute top-10 -left-20 -z-10 size-40 rounded-full bg-brand-ink/8" aria-hidden="true"></span>

    <div class="max-w-2xl">
        <h2 id="closing-call" class="text-[clamp(1.75rem,3.8vw,2.75rem)] leading-[1.06] tracking-[-0.03em]">{{ $title }}</h2>
        <p class="mt-4 text-[1.0625rem] leading-relaxed opacity-90">{{ $body }}</p>
    </div>

    <div class="mt-8 flex flex-wrap gap-3">
        @if ($registrationOpen)
            <x-site.button variant="inverse" size="lg" :href="route('central.register')">
                {{ __('site.nav.start') }}
                <x-site.icon name="arrow-right" class="size-4" />
            </x-site.button>
        @endif
        <a href="{{ \App\Core\Website\SiteUrls::to('contact') }}"
           class="inline-flex h-12 items-center gap-2 rounded-control border border-brand-ink/35 px-6 text-[0.9375rem] font-medium transition-colors hover:bg-brand-ink/10">
            {{ __('site.nav.talk') }}
        </a>
    </div>
</section>
