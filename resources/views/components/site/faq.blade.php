{{--
    Questions and answers as disclosures. Every answer is in the page's HTML, open or not, so crawlers read it; the
    same items are in the page's FAQPage structured data.
--}}
@props(['items', 'title' => null, 'id' => 'questions', 'columns' => true])
<section aria-labelledby="{{ $id }}" {{ $attributes->class('glass rounded-panel p-6 sm:p-8 lg:p-10') }}>
    <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
        <h2 id="{{ $id }}" class="text-[clamp(1.5rem,3vw,2.125rem)] leading-tight text-ink">{{ $title ?? __('site.common.questions') }}</h2>
        {{ $slot }}
    </div>
    <div @class(['mt-6 grid gap-x-8', 'lg:grid-cols-2' => $columns])>
        @foreach (collect($items)->chunk($columns ? (int) ceil(count($items) / 2) : max(count($items), 1)) as $column)
            <div class="divide-y divide-line border-y border-line lg:border-b-0 lg:last:border-b {{ $loop->first ? '' : 'border-t-0 lg:border-t' }}">
                @foreach ($column as $item)
                    <details class="group py-1" @if ($loop->parent->first && $loop->first) open @endif>
                        <summary class="flex cursor-pointer list-none items-start justify-between gap-4 rounded-control py-3.5 text-left text-[1rem] font-medium text-ink [&::-webkit-details-marker]:hidden">
                            <h3 class="font-sans text-[1rem] leading-snug font-medium tracking-normal">{{ $item['q'] }}</h3>
                            <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full bg-hover text-ink-muted transition-transform duration-200 group-open:rotate-180">
                                <x-site.icon name="chevron-down" class="size-4" />
                            </span>
                        </summary>
                        <p class="pr-10 pb-4 text-[0.9375rem] leading-relaxed text-ink-muted">{{ $item['a'] }}</p>
                    </details>
                @endforeach
            </div>
        @endforeach
    </div>
</section>
