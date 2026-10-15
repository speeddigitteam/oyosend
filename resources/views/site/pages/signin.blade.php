@extends('site.layout')

@php
    use App\Core\Website\SiteUrls;

    $domain = app(\App\Core\Tenancy\Support\TenantHost::class)->domain();
    $suffix = '.'.$domain;
@endphp

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <div class="relative isolate mx-auto max-w-xl pt-10 sm:pt-20">
            <span class="pointer-events-none absolute -top-2 -right-16 -z-10 size-64 rounded-full bg-(--orb-ember) opacity-75" aria-hidden="true"></span>
            <span class="pointer-events-none absolute -bottom-10 -left-14 -z-10 size-52 rounded-full bg-(--orb-pine) opacity-60" aria-hidden="true"></span>

            {{-- A plain form: the server answers with a redirect to the workspace's own sign-in page. --}}
            <section class="glass rounded-panel p-6 sm:p-10" aria-labelledby="signin-title">
                <span class="grid size-12 place-items-center rounded-control bg-brand-soft text-brand-text"><x-site.icon name="log-in" class="size-6" /></span>
                <p class="eyebrow mt-6 text-brand-text">{{ __('site.signin.eyebrow') }}</p>
                <h1 id="signin-title" class="mt-2 text-[clamp(1.875rem,4.5vw,2.5rem)] leading-tight tracking-[-0.03em] text-ink">{{ __('site.signin.title') }}</h1>
                <p class="mt-3 text-[1rem] leading-relaxed text-ink-muted">{{ __('site.signin.lede') }}</p>

                <form method="POST" action="{{ route('central.workspace.find') }}" class="mt-8" novalidate>
                    @csrf
                    <label for="workspace" class="mb-1.5 block text-[0.8125rem] font-medium text-ink">{{ __('site.signin.label') }}</label>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        {{-- Right-aligned so the name runs straight into the domain: one address. --}}
                        <div class="relative min-w-0 flex-1">
                            <input id="workspace" name="workspace" type="text" value="{{ old('workspace') }}" required maxlength="255"
                                   inputmode="url" enterkeyhint="go" autocapitalize="none" autocorrect="off" spellcheck="false" autocomplete="off"
                                   placeholder="{{ __('site.signin.placeholder') }}"
                                   class="control h-12 text-right font-mono text-[0.9375rem]"
                                   style="padding-right: calc({{ mb_strlen($suffix) }}ch + 1.25rem)"
                                   aria-describedby="{{ $errors->has('workspace') ? 'workspace-error' : 'workspace-hint' }}"
                                   @error('workspace') aria-invalid="true" @enderror>
                            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center font-mono text-[0.9375rem] text-ink-muted" aria-hidden="true">{{ $suffix }}</span>
                        </div>
                        <x-site.button type="submit" variant="primary" size="lg">{{ __('site.signin.submit') }}</x-site.button>
                    </div>
                    @error('workspace')
                        <p id="workspace-error" class="mt-2 text-[0.8125rem] text-danger" role="alert">{{ $message }}</p>
                    @else
                        <p id="workspace-hint" class="mt-2 text-[0.8125rem] text-ink-subtle">{{ __('site.signin.hint', ['domain' => $domain]) }}</p>
                    @enderror
                </form>

                <p class="mt-6 text-[0.875rem] leading-relaxed text-ink-muted">{{ __('site.signin.unsure') }}</p>

                <div class="mt-8 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-6">
                    <span class="text-[0.9375rem] text-ink">{{ __('site.signin.new') }}</span>
                    @if ($registrationOpen)
                        <x-site.button :href="route('central.register')">{{ __('site.signin.create') }}</x-site.button>
                    @else
                        <x-site.button :href="SiteUrls::to('contact')">{{ __('site.nav.talk') }}</x-site.button>
                    @endif
                </div>
            </section>
        </div>
    </div>
@endsection
