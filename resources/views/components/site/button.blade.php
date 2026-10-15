{{-- The app's Button (resources/js/Core/Components/Button.vue) for server-rendered pages: same variants and sizes. --}}
@props(['href' => null, 'variant' => 'secondary', 'size' => 'md', 'type' => 'button'])
@php
    $variants = [
        'primary' => 'bg-brand text-brand-ink shadow-[inset_0_1px_0_rgb(255_255_255/0.22),0_1px_2px_rgb(0_0_0/0.16),0_8px_18px_-8px_var(--brand-shadow)] hover:bg-brand-hover active:shadow-[inset_0_1px_2px_rgb(0_0_0/0.25)]',
        'secondary' => 'bg-control text-ink border border-line-strong shadow-[inset_0_1px_0_var(--glass-edge),0_1px_2px_rgb(0_0_0/0.05)] hover:bg-control-hover active:shadow-[inset_0_1px_2px_rgb(0_0_0/0.12)]',
        'ghost' => 'text-ink-muted hover:bg-hover hover:text-ink active:bg-hover',
        'inverse' => 'bg-brand-ink text-brand shadow-[0_1px_2px_rgb(0_0_0/0.16)] hover:brightness-105',
    ];
    $sizes = [
        'sm' => 'h-8 px-3 text-[0.8125rem] gap-1.5',
        'md' => 'h-[2.375rem] px-4 text-sm gap-2',
        'lg' => 'h-12 px-6 text-[0.9375rem] gap-2',
    ];
    $classes = 'relative inline-flex shrink-0 items-center justify-center rounded-control font-medium whitespace-nowrap select-none transition-[background-color,box-shadow,filter,transform] duration-150 active:translate-y-px '
        .$variants[$variant].' '.$sizes[$size];
@endphp
@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
