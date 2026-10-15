@extends('site.layout')

@php
    use App\Core\Website\SiteUrls;
    use Illuminate\Support\Str;
@endphp

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <x-site.page-hero :eyebrow="__('site_pages.faq.eyebrow')" :title="__('site_pages.faq.title')" :lede="__('site_pages.faq.lede')"
                          :trail="[[__('site.nav.faq'), SiteUrls::to('faq')]]" />

        <div class="grid gap-8 lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-10">
            {{-- The groups, for jumping straight to one. --}}
            <nav aria-label="{{ __('site_pages.faq.title') }}" class="lg:sticky lg:top-24 lg:self-start">
                <ul class="glass flex gap-1 overflow-x-auto rounded-panel p-2 lg:grid">
                    @foreach ($groups as $group)
                        <li class="shrink-0">
                            <a href="#faq-{{ $loop->iteration }}" class="block rounded-control px-3 py-2 text-[0.875rem] font-medium text-ink-muted hover:bg-hover hover:text-ink">{{ $group['title'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <div class="grid gap-3">
                @foreach ($groups as $group)
                    <div class="scroll-mt-24" id="faq-{{ $loop->iteration }}" @if (! $loop->first) data-reveal @endif>
                        <x-site.faq :items="$group['items']" :title="$group['title']" :id="'faq-title-'.$loop->iteration" :columns="false" />
                    </div>
                @endforeach
            </div>
        </div>

        <div class="pt-20 sm:pt-28" data-reveal>
            <x-site.cta :title="__('site.home.cta_title')" :body="__('site.home.cta_body')" :registration-open="$registrationOpen" />
        </div>
    </div>
@endsection
