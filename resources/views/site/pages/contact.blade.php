@extends('site.layout')

@php
    use App\Core\Website\SitePrice;
    use App\Core\Website\SiteUrls;

    $status = session('contact');
    $label = 'mb-1.5 block text-[0.8125rem] font-medium text-ink';
    $error = 'mt-1.5 text-[0.8125rem] text-danger';
@endphp

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <x-site.page-hero :eyebrow="__('site_pages.contact.eyebrow')" :title="__('site_pages.contact.title')" :lede="__('site_pages.contact.lede')"
                          :trail="[[__('site.nav.contact'), SiteUrls::to('contact')]]" />

        <div class="relative isolate grid gap-3 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
            <span class="pointer-events-none absolute top-1/3 -left-12 -z-10 size-72 rounded-full bg-(--orb-ember) opacity-70" aria-hidden="true"></span>
            <span class="pointer-events-none absolute -right-8 bottom-0 -z-10 size-56 rounded-full bg-(--orb-pine) opacity-60" aria-hidden="true"></span>

            {{-- How to reach us: the same details as the structured data and the footer. --}}
            <section class="glass flex flex-col rounded-panel p-6 sm:p-8" aria-labelledby="details-title">
                <h2 id="details-title" class="text-[1.5rem] leading-tight text-ink">{{ __('site_pages.contact.details_heading') }}</h2>
                <dl class="mt-6 grid gap-6">
                    <div class="flex gap-4">
                        <span class="grid size-10 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-text"><x-site.icon name="map-pin" /></span>
                        <div>
                            <dt class="eyebrow">{{ __('site_pages.contact.address_label') }}</dt>
                            <dd class="mt-1.5 text-[1rem] leading-relaxed text-ink">
                                @foreach ($company->addressLines() as $line)
                                    {{ $line }}@unless ($loop->last)<br>@endunless
                                @endforeach
                                @if ($company->mapUrl())
                                <a href="{{ $company->mapUrl() }}" target="_blank" rel="noopener" class="mt-2 flex items-center gap-1.5 text-[0.875rem] font-medium text-brand-text hover:underline">
                                    {{ __('site_pages.contact.map') }}
                                    <x-site.icon name="external-link" class="size-3.5" />
                                </a>
                                @endif
                            </dd>
                        </div>
                    </div>
                    <div class="flex gap-4">
                        <span class="grid size-10 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-text"><x-site.icon name="phone" /></span>
                        <div>
                            <dt class="eyebrow">{{ __('site_pages.contact.phone_label') }}</dt>
                            @foreach ($company->phones() as $phone)
                                <dd class="mt-1.5"><a href="tel:{{ $phone['e164'] }}" class="tabular text-[1rem] text-ink hover:text-brand-text">{{ SitePrice::digits($phone['display']) }}</a></dd>
                            @endforeach
                        </div>
                    </div>
                    <div class="flex gap-4">
                        <span class="grid size-10 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-text"><x-site.icon name="mail" /></span>
                        <div>
                            <dt class="eyebrow">{{ __('site_pages.contact.email_label') }}</dt>
                            <dd class="mt-1.5"><a href="mailto:{{ $company->email() }}" class="text-[1rem] text-ink hover:text-brand-text">{{ $company->email() }}</a></dd>
                        </div>
                    </div>
                </dl>

                <div class="glass-well mt-8 rounded-card p-5">
                    <p class="eyebrow inline-flex items-center gap-2 text-brand-text"><x-site.icon name="sparkles" class="size-3.5" />{{ __('site.common.in_short') }}</p>
                    <h2 class="mt-2 text-[1.0625rem] text-ink">{{ __('site_pages.contact.answer_q') }}</h2>
                    <p class="mt-2 text-[0.9375rem] leading-relaxed text-ink-muted">{{ __('site_pages.contact.answer') }}</p>
                </div>
            </section>

            {{-- The form: sent to our own inbox; a hidden field catches bots. --}}
            <section class="glass rounded-panel p-6 sm:p-8" aria-labelledby="message">
                <h2 id="message" class="scroll-mt-28 text-[1.5rem] leading-tight text-ink">{{ __('site_pages.contact.form_heading') }}</h2>
                <p class="mt-2 text-[0.9375rem] text-ink-muted">{{ __('site_pages.contact.form_lede') }}</p>

                @if ($status === 'sent')
                    <p class="mt-6 flex items-start gap-3 rounded-card bg-ok-soft p-4 text-[0.9375rem] text-ok" role="status">
                        <x-site.icon name="circle-check" class="mt-0.5 size-5 shrink-0" />{{ __('site_pages.contact.sent') }}
                    </p>
                @elseif ($status === 'failed')
                    <p class="mt-6 rounded-card bg-danger-soft p-4 text-[0.9375rem] text-danger" role="alert">{{ __('site_pages.contact.failed') }}</p>
                @endif

                <form method="POST" action="{{ SiteUrls::to('contact.send') }}" class="mt-6 grid gap-5 sm:grid-cols-2" novalidate>
                    @csrf

                    <div>
                        <label for="contact-name" class="{{ $label }}">{{ __('site_pages.contact.name') }}</label>
                        <input id="contact-name" name="name" type="text" value="{{ old('name') }}" autocomplete="name" maxlength="120" required
                               class="control" @error('name') aria-invalid="true" aria-describedby="contact-name-error" @enderror>
                        @error('name')<p id="contact-name-error" class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="contact-email" class="{{ $label }}">{{ __('site_pages.contact.email') }}</label>
                        <input id="contact-email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" maxlength="190" required
                               class="control" @error('email') aria-invalid="true" aria-describedby="contact-email-error" @enderror>
                        @error('email')<p id="contact-email-error" class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="contact-phone" class="{{ $label }}">{{ __('site_pages.contact.phone') }} <span class="font-normal text-ink-subtle">({{ __('site_pages.contact.optional') }})</span></label>
                        <input id="contact-phone" name="phone" type="tel" value="{{ old('phone') }}" autocomplete="tel" maxlength="40"
                               class="control" @error('phone') aria-invalid="true" aria-describedby="contact-phone-error" @enderror>
                        @error('phone')<p id="contact-phone-error" class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="contact-company" class="{{ $label }}">{{ __('site_pages.contact.company') }} <span class="font-normal text-ink-subtle">({{ __('site_pages.contact.optional') }})</span></label>
                        <input id="contact-company" name="company" type="text" value="{{ old('company') }}" autocomplete="organization" maxlength="160"
                               class="control" @error('company') aria-invalid="true" aria-describedby="contact-company-error" @enderror>
                        @error('company')<p id="contact-company-error" class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label for="contact-topic" class="{{ $label }}">{{ __('site_pages.contact.topic') }}</label>
                        <select id="contact-topic" name="topic" class="control" @error('topic') aria-invalid="true" aria-describedby="contact-topic-error" @enderror>
                            @foreach ($topics as $topic)
                                <option value="{{ $topic }}" @selected(old('topic', 'sales') === $topic)>{{ __("site_pages.contact.topics.{$topic}") }}</option>
                            @endforeach
                        </select>
                        @error('topic')<p id="contact-topic-error" class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label for="contact-message" class="{{ $label }}">{{ __('site_pages.contact.message') }}</label>
                        <textarea id="contact-message" name="message" rows="6" maxlength="5000" required
                                  class="control min-h-36 resize-y" @error('message') aria-invalid="true" aria-describedby="contact-message-error" @enderror>{{ old('message') }}</textarea>
                        @error('message')<p id="contact-message-error" class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>

                    {{-- People never see this field; a bot that fills it in is quietly ignored. --}}
                    <div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
                        <label for="contact-website">Website</label>
                        <input id="contact-website" name="website" type="text" tabindex="-1" autocomplete="off" value="">
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-4 sm:col-span-2">
                        <p class="max-w-sm text-[0.8125rem] text-ink-subtle">
                            {{ __('site_pages.contact.privacy_note') }}
                            <a href="{{ SiteUrls::to('privacy') }}" class="text-brand-text hover:underline">{{ __('site.footer.privacy') }}</a>
                        </p>
                        <x-site.button type="submit" variant="primary" size="lg">
                            {{ __('site_pages.contact.send') }}
                            <x-site.icon name="send" class="size-4" />
                        </x-site.button>
                    </div>
                </form>
            </section>
        </div>
    </div>
@endsection
