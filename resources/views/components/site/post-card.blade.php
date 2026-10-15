{{--
    One blog article as a card (App\Core\Website\Blog\PostCard): its picture, kind, title, summary, date and reading
    time, the whole card one link. Without a picture, the kind's mark on the brand's own colours stands in, glass over
    discs; no text sits on a disc. `lead` lays it out wide, for the article at the top of the blog.
--}}
@props(['post', 'lead' => false, 'level' => 3])
@php
    $marks = ['guide' => 'book-open', 'update' => 'newspaper', 'insight' => 'lightbulb'];
    $heading = $lead ? 'h2' : 'h'.$level;
@endphp
<article {{ $attributes->class([
    'glass group relative flex h-full flex-col overflow-hidden rounded-panel transition-transform duration-200 hover:-translate-y-0.5 has-[a:focus-visible]:-translate-y-0.5',
    'lg:grid lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]' => $lead,
]) }}>
    <div @class([
        'relative isolate aspect-[16/9] overflow-hidden border-b border-line bg-brand-soft',
        'lg:aspect-auto lg:min-h-80 lg:border-r lg:border-b-0' => $lead,
    ])>
        @if ($post->cover)
            <img src="{{ $post->cover }}" alt="" @if ($post->coverWidth) width="{{ $post->coverWidth }}" height="{{ $post->coverHeight }}" @endif
                 loading="{{ $lead ? 'eager' : 'lazy' }}" decoding="async"
                 class="absolute inset-0 size-full object-cover transition-transform duration-500 group-hover:scale-[1.03]">
        @else
            <span class="pointer-events-none absolute -right-10 -bottom-16 -z-10 size-48 rounded-full bg-(--orb-pine) opacity-50" aria-hidden="true"></span>
            <span class="pointer-events-none absolute -top-12 left-8 -z-10 size-32 rounded-full bg-(--orb-butter) opacity-60" aria-hidden="true"></span>
            <span class="glass absolute top-1/2 left-1/2 grid aspect-square w-20 -translate-x-1/2 -translate-y-1/2 place-items-center rounded-panel text-brand-text sm:w-24 {{ $lead ? 'lg:w-32' : '' }}" aria-hidden="true">
                <x-site.icon :name="$marks[$post->kind->value] ?? 'file-text'" class="size-9 {{ $lead ? 'lg:size-12' : '' }}" />
            </span>
        @endif
        <span class="glass-solid absolute top-3 left-3 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[0.75rem] font-medium text-ink">
            <x-site.icon :name="$marks[$post->kind->value] ?? 'file-text'" class="size-3.5 text-brand-text" />
            {{ $post->kindLabel }}
        </span>
    </div>

    <div @class(['flex flex-1 flex-col p-5 sm:p-6', 'lg:justify-center lg:p-10' => $lead])>
        @if ($lead && $post->featured)
            <p class="eyebrow text-accent-text">{{ __('site_pages.blog.featured') }}</p>
        @endif
        <{{ $heading }} @class([
            'text-ink',
            'mt-2 text-[clamp(1.5rem,3vw,2.25rem)] leading-[1.1] tracking-[-0.03em]' => $lead,
            'text-[1.1875rem] leading-snug' => ! $lead,
        ])>
            {{-- The title is the link; it stretches over the whole card. --}}
            <a href="{{ $post->url }}" class="after:absolute after:inset-0 after:content-['']">{{ $post->title }}</a>
        </{{ $heading }}>
        <p @class([
            'mt-2.5 leading-relaxed text-ink-muted',
            'text-[1rem] sm:text-[1.0625rem]' => $lead,
            'line-clamp-3 text-[0.9375rem]' => ! $lead,
        ])>{{ $post->summary }}</p>
        <p class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 pt-5 text-[0.8125rem] text-ink-subtle">
            <time datetime="{{ $post->published->toDateString() }}">{{ $post->date }}</time>
            <span aria-hidden="true">·</span>
            <span class="inline-flex items-center gap-1.5"><x-site.icon name="clock" class="size-3.5" />{{ $post->readingTime }}</span>
            @if ($lead)
                <span class="ml-auto hidden items-center gap-1.5 font-medium text-brand-text sm:inline-flex" aria-hidden="true">
                    {{ __('site_pages.blog.read') }}
                    <x-site.icon name="arrow-right" class="size-4 transition-transform duration-200 group-hover:translate-x-0.5" />
                </span>
            @endif
        </p>
    </div>
</article>
