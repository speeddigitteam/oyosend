{{-- A section's eyebrow, heading and lede. Set straight on the backdrop's colour fields, never over a disc. --}}
@props(['eyebrow' => null, 'title', 'lede' => null, 'id' => null, 'center' => false])
<div {{ $attributes->class(['max-w-3xl', 'mx-auto text-center' => $center]) }}>
    @if ($eyebrow)
        <p class="eyebrow text-brand-text">{{ $eyebrow }}</p>
    @endif
    <h2 @if ($id) id="{{ $id }}" @endif class="mt-3 text-[clamp(1.75rem,3.6vw,2.625rem)] leading-[1.08] tracking-[-0.03em] text-ink">{{ $title }}</h2>
    @if ($lede)
        <p class="mt-4 text-[1rem] leading-relaxed text-ink-muted sm:text-[1.0625rem]">{{ $lede }}</p>
    @endif
</div>
