{{-- A Lucide icon drawn inline (App\Core\Website\Support\SiteIcons). Decorative: name the control, not the icon. --}}
@props(['name'])
<svg {{ $attributes->has('class') ? $attributes : $attributes->merge(['class' => 'size-5']) }} xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{!! \App\Core\Website\Support\SiteIcons::markup($name) !!}</svg>
