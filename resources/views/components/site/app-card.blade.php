{{-- One app as a row in a directory: its icon, name and one line, linked to its page. --}}
@props(['app'])
<a href="{{ \App\Core\Website\SiteUrls::to('app', null, ['app' => $app->slug]) }}"
   {{ $attributes->class('group flex items-start gap-3.5 rounded-card p-3 transition-colors hover:bg-hover focus-visible:bg-hover') }}>
    <span class="grid size-10 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-text transition-transform duration-200 group-hover:-translate-y-0.5">
        <x-site.icon :name="$app->icon" class="size-5" />
    </span>
    <span class="min-w-0 flex-1">
        <span class="flex items-center gap-1.5 text-[0.9375rem] font-semibold text-ink">
            {{ $app->text('name') }}
            <x-site.icon name="arrow-right" class="size-3.5 -translate-x-1 text-brand-text opacity-0 transition-all duration-200 group-hover:translate-x-0 group-hover:opacity-100" />
        </span>
        <span class="mt-0.5 block text-[0.8125rem] leading-snug text-ink-muted">{{ $app->text('tagline') }}</span>
    </span>
</a>
