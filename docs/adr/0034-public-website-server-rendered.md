# ADR 0034 — The public website: server-rendered pages, one address per language, built for search and answer engines

- **Status:** Proposed, built. Not reviewed by the owner. Checked in Chrome (desktop and 360px, light and dark); not
  measured with Lighthouse or any search console.
- **Date:** 2026-10-14
- **Changes:** the central site's `/` was an Inertia page (`Core/Pages/Central/Welcome.vue`); it is now the website's
  home page. Changes no locked decision.

## Context

The root domain carried one page: a headline, a "find your workspace" form and, while sign-up was open, the plans.
The owner asked for a full SaaS website, unique and professional, in the glass design of the app, with company
details, and built for search engines (SEO), answer engines such as featured snippets and voice answers (AEO) and
generative engines such as ChatGPT, Claude and Perplexity (GEO).

Search engines that run JavaScript can index an Inertia page, but several AI crawlers do not run scripts at all: they
read the HTML that arrives. An Inertia page arrives as an empty `<div>` with JSON props, and its words live in the
JavaScript bundle (vue-i18n), so such a crawler sees nothing. Inertia's server-side rendering would fix that, at the
price of a Node process in production for every Inertia page.

## Decision

1. **Server-rendered Blade pages for the website** (`resources/views/site`, `App\Core\Website`). Every word, answer,
   price and link is in the HTML the server sends. The pages use the app's design tokens and glass utilities from
   `resources/css/app.css` and one small script (`resources/js/site.ts`, about 1.5 kB) for the theme switch, the small-
   screen menu and a reveal on scroll; everything works without it. The Vue app bundle is not loaded. Sign-up
   (`/register` and its follow-up pages) stays Inertia.

2. **One address per language.** English at the root (`/pricing`), Bengali under `/bn` (`/bn/pricing`). The page's
   language comes from its address, never from a cookie or a header, so a crawler always gets the same page; the page
   then remembers the language in the `locale` cookie so the sign-up pages follow. Every page links the other with
   `hreflang` and names English as `x-default`.

3. **What each page tells machines** (`PageMeta`, `SiteSchema`): title, description, canonical, Open Graph and Twitter
   tags, and one JSON-LD `@graph` with the Organization (address, both phones, email), the WebSite, the WebPage and its
   breadcrumbs, a SoftwareApplication (with an AggregateOffer from the plans on sale, or an Offer from an app's own
   price) and an FAQPage wherever the page shows questions. Nothing is claimed that the page does not show: no ratings,
   no reviews.

4. **Answer-first copy.** Each page opens with an "In short" box: the question the page answers as a heading and a
   self-contained answer of 40 to 60 words that names Oyosend, which is what answer engines quote. Questions people ask
   follow as disclosures whose answers are in the HTML whether open or not.

5. **Files for crawlers on the central host:** `robots.txt` (allows the site, names the AI crawlers, keeps the one-time
   sign-up links out, points to the sitemap), `sitemap.xml` (every page in both languages with its alternates) and
   `llms.txt` (a plain summary and map of the site for AI assistants). `public/robots.txt` was removed because it
   answered for every host. Workspaces and the Super Admin panel send `X-Robots-Tag: noindex, nofollow` (`NoIndex`
   middleware on the `tenant`, `tenant.callback` and `platform` groups) and answer none of these files.

6. **Only the truth, read from the source.** The apps come from the module registry (a module this build does not
   register has no page); an app's price from its own App Store offers; the plans from the database, shown only while
   sign-up is open, as before. The words were checked against the manifests and `docs/`; features the docs mark as not
   built are left out.

7. **The contact form** sends one queued, encrypted mail (`WebsiteEnquiry`) to `config('site.contact_recipient')`
   (default info@oyosend.com) with the visitor as reply-to. A hidden field turns bots away silently; the route allows
   five messages per ten minutes. Nothing is stored in the database.

8. **CSP:** `form-action` now also allows the workspace hosts (`{scheme}://*.APP_DOMAIN`), because the website's plain
   "find my workspace" form is answered with a redirect to that workspace's sign-in page.

## Amendment (2026-10-14): managed from the Super Admin panel

At the owner's request the website is managed from the panel (Super Admin > Website): contact-form messages are kept
(`website_enquiries`) and handled there; the company's details are platform settings over `config/site.php`; every
word can be changed in both languages (`website_texts`, laid over the language files by the translation loader, so the
templates did not change); a plan has a Bengali name and description. Words only: the structure search engines read
(pages, sections, questions, icons, anchors, placeholders) stays in code. Two platform abilities:
`platform.website.view` (Super Admin, Support Admin) and `platform.website.manage` (Super Admin). Decision 7 changes:
messages are now stored.

## Consequences

- The website's words are PHP language files (`lang/{en,bn}/site*.php`), not vue-i18n: same keys in both languages,
  checked by `tests/Feature/Central/WebsiteTest.php`.
- A new optional module needs its words in `site_apps.php` and an entry in `SiteApps`; the test fails until it has them.
- Plan names and descriptions are the operator's own text and are shown as typed, in either language.
- The legal pages are drafts written from how the product works; they need legal review before publication (see
  `docs/WEBSITE.md`).
