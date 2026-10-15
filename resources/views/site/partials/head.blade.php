{{--
    What the page says about itself to search engines, answer engines and link previews (App\Core\Website\PageMeta).
    The same page in the other language is linked with hreflang; English is the default for everyone else.
--}}
@php($otherLocale = \App\Core\Website\SiteUrls::otherLocale($meta->locale))
<title>{{ $meta->title }}</title>
<meta name="description" content="{{ $meta->description }}">
<meta name="robots" content="{{ $meta->index ? 'index, follow, max-image-preview:large, max-snippet:-1' : 'noindex, follow' }}">
<link rel="canonical" href="{{ $meta->canonical }}">
@foreach ($meta->alternates as $locale => $url)
    <link rel="alternate" hreflang="{{ $locale }}" href="{{ $url }}">
@endforeach
<link rel="alternate" hreflang="x-default" href="{{ $meta->defaultAlternate() }}">

<meta property="og:type" content="{{ $meta->type }}">
<meta property="og:site_name" content="{{ $company->name() }}">
<meta property="og:title" content="{{ $meta->title }}">
<meta property="og:description" content="{{ $meta->description }}">
<meta property="og:url" content="{{ $meta->canonical }}">
<meta property="og:image" content="{{ $meta->image() }}">
@if ($meta->imageWidth() && $meta->imageHeight())
    <meta property="og:image:width" content="{{ $meta->imageWidth() }}">
    <meta property="og:image:height" content="{{ $meta->imageHeight() }}">
@endif
<meta property="og:image:alt" content="{{ $meta->imageAlt() }}">
<meta property="og:locale" content="{{ $meta->ogLocale() }}">
@if (isset($meta->alternates[$otherLocale]))
    <meta property="og:locale:alternate" content="{{ $meta->ogLocale($otherLocale) }}">
@endif
@foreach (['published' => 'published_time', 'modified' => 'modified_time', 'section' => 'section'] as $fact => $property)
    @if (isset($meta->article[$fact]))
        <meta property="article:{{ $property }}" content="{{ $meta->article[$fact] }}">
    @endif
@endforeach
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $meta->title }}">
<meta name="twitter:description" content="{{ $meta->description }}">
<meta name="twitter:image" content="{{ $meta->image() }}">

<link rel="alternate" type="text/markdown" title="llms.txt" href="{{ route('central.llms') }}">
@if ($meta->feed)
    <link rel="alternate" type="application/atom+xml" title="{{ __('site_pages.blog.feed_title', ['name' => $company->name()]) }}" href="{{ $meta->feed }}">
@endif
<script type="application/ld+json">{!! $meta->jsonLd() !!}</script>
