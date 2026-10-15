{{--
    The logo an operator uploaded (Super Admin > Website), with its dark-background version in the dark theme, or else
    the built-in mark and wordmark (resources/js/Core/Components/Logo.vue). `id` keeps the clip path unique when the
    logo appears twice.
--}}
@props(['id' => 'logo', 'size' => 30])
@php
    $brand = app(\App\Core\Website\SiteBrand::class);
    $logo = $brand->url('logo');
    $logoDark = $brand->url('logo_dark');
    $height = round($size * 1.15);
@endphp
@if ($logo)
<span {{ $attributes->class('inline-flex items-center select-none') }}>
    <img src="{{ $logo }}" alt="{{ app(\App\Core\Website\SiteCompany::class)->name() }}" style="height: {{ $height }}px" @class(['w-auto max-w-48 object-contain', 'dark:hidden' => $logoDark])>
    @if ($logoDark)
        <img src="{{ $logoDark }}" alt="" aria-hidden="true" style="height: {{ $height }}px" class="hidden w-auto max-w-48 object-contain dark:block">
    @endif
</span>
@else
<span {{ $attributes->class('inline-flex items-center gap-2.5 select-none') }}>
    <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 32 32" fill="none" aria-hidden="true" focusable="false">
        <defs>
            <clipPath id="{{ $id }}-clip"><circle cx="12.5" cy="16" r="9.5" /></clipPath>
        </defs>
        <circle cx="12.5" cy="16" r="9.5" fill="var(--brand)" />
        <circle cx="22" cy="16" r="7" fill="var(--accent)" />
        <circle cx="22" cy="16" r="7" fill="var(--canvas)" fill-opacity="0.82" clip-path="url(#{{ $id }}-clip)" />
    </svg>
    <span class="font-display text-[1.25rem] leading-none font-semibold tracking-[-0.035em] text-ink" lang="en">oyosend</span>
</span>
@endif
