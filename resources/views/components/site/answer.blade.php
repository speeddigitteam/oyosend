{{--
    The page's question answered in a few sentences, near the top: what an answer engine quotes and what a busy
    reader needs. The question is a real heading so the answer is found under it.
--}}
@props(['question', 'answer', 'id' => 'in-short'])
<section aria-labelledby="{{ $id }}" {{ $attributes->class('glass relative overflow-hidden rounded-panel p-6 sm:p-8') }}>
    <div class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,5fr)] lg:gap-10">
        <div>
            <p class="eyebrow inline-flex items-center gap-2 text-brand-text">
                <x-site.icon name="sparkles" class="size-3.5" />
                {{ __('site.common.in_short') }}
            </p>
            <h2 id="{{ $id }}" class="mt-3 text-[1.375rem] leading-tight text-ink sm:text-[1.625rem]">{{ $question }}</h2>
        </div>
        <p class="text-[1.0625rem] leading-relaxed text-ink sm:text-[1.125rem] lg:pt-7">{{ $answer }}</p>
    </div>
</section>
