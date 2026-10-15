@php
    use App\Core\Website\SitePrice;
    use App\Core\Website\SiteUrls;

    $columns = [
        'product' => ['apps', 'pricing', 'security', 'signin'],
        'company' => ($hasBlog ?? false) === true ? ['about', 'blog', 'faq', 'contact'] : ['about', 'faq', 'contact'],
        'legal' => ['privacy', 'terms', 'refund'],
    ];
    $labels = [
        'privacy' => __('site.footer.privacy'),
        'terms' => __('site.footer.terms'),
        'refund' => __('site.footer.refund'),
    ];
@endphp

<footer class="px-3 pt-16 pb-3 sm:px-5 sm:pt-24 sm:pb-5">
    <div class="glass mx-auto max-w-7xl rounded-panel p-6 sm:p-10">
        <div class="grid gap-10 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
            {{-- Who we are and how to reach us: the same facts as the Organization structured data. --}}
            <div>
                <a href="{{ SiteUrls::to('home') }}" class="inline-flex rounded-control" aria-label="{{ __('site.meta.logo') }}">
                    <x-site.logo id="footer-logo" />
                </a>
                <p class="mt-4 max-w-sm text-[0.9375rem] leading-relaxed text-ink-muted">{{ __('site.footer.about') }}</p>

                <address class="mt-6 grid gap-3 text-[0.875rem] not-italic text-ink-muted">
                    <span class="flex gap-3">
                        <x-site.icon name="map-pin" class="mt-0.5 size-4 shrink-0 text-brand-text" />
                        @if ($company->mapUrl())
                            <a href="{{ $company->mapUrl() }}" class="hover:text-ink" rel="noopener" target="_blank">{!! collect($company->addressLines())->map(fn ($line) => e($line))->implode('<br>') !!}</a>
                        @else
                            <span>{!! collect($company->addressLines())->map(fn ($line) => e($line))->implode('<br>') !!}</span>
                        @endif
                    </span>
                    @foreach ($company->phones() as $phone)
                        <span class="flex items-center gap-3">
                            <x-site.icon name="phone" class="size-4 shrink-0 text-brand-text" />
                            <a href="tel:{{ $phone['e164'] }}" class="tabular hover:text-ink">{{ SitePrice::digits($phone['display']) }}</a>
                        </span>
                    @endforeach
                    <span class="flex items-center gap-3">
                        <x-site.icon name="mail" class="size-4 shrink-0 text-brand-text" />
                        <a href="mailto:{{ $company->email() }}" class="hover:text-ink">{{ $company->email() }}</a>
                    </span>
                </address>

                {{-- The company's own pages elsewhere, as the operator set them (Super Admin > Website > Company). --}}
                @if ($company->social() !== [])
                    <ul class="mt-6 flex flex-wrap gap-2" aria-label="{{ __('site.footer.social') }}">
                        @foreach ($company->social() as $network => $url)
                            <li>
                                <a href="{{ $url }}" target="_blank" rel="noopener me" aria-label="{{ __('site.footer.networks.'.$network) }}"
                                   class="grid size-9 place-items-center rounded-control bg-hover text-ink-muted transition-colors hover:bg-brand-soft hover:text-brand-text">
                                    <x-site.icon :name="$network === 'x' ? 'twitter' : $network" class="size-4" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="grid grid-cols-2 gap-8 sm:grid-cols-3">
                @foreach ($columns as $column => $pages)
                    <nav aria-labelledby="footer-{{ $column }}">
                        <h2 id="footer-{{ $column }}" class="eyebrow">{{ __("site.footer.{$column}") }}</h2>
                        <ul class="mt-4 grid gap-2.5">
                            @foreach ($pages as $page)
                                <li>
                                    <a href="{{ SiteUrls::to($page) }}" class="text-[0.875rem] text-ink-muted transition-colors hover:text-ink">
                                        {{ $labels[$page] ?? __("site.nav.{$page}") }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </nav>
                @endforeach
            </div>
        </div>

        <div class="mt-10 flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border-t border-line pt-6 text-[0.8125rem] text-ink-subtle">
            <p>{{ __('site.footer.rights', ['year' => SitePrice::digits((string) now()->year)]) }} {{ __('site.footer.based') }}</p>
            <a href="{{ $meta->languageLink(SiteUrls::otherLocale($meta->locale)) }}" hreflang="{{ SiteUrls::otherLocale($meta->locale) }}" lang="{{ SiteUrls::otherLocale($meta->locale) }}"
               class="inline-flex items-center gap-1.5 hover:text-ink">
                <x-site.icon name="languages" class="size-4" />
                {{ __('site.nav.language_label') }}
            </a>
        </div>
    </div>
</footer>
