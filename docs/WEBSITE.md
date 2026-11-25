# Public website

The central site on the root domain (`APP_DOMAIN`): the company's website, server-rendered for people, search engines
and answer engines (ADR 0034). Sign-up (`/register`) stays an Inertia page of the central site.

## Pages

Every page exists in English at the root and in Bengali under `/bn` (`/pricing` and `/bn/pricing`).

| Address | Route (English / Bengali) | What it shows |
|---|---|---|
| `/` | `central.home` / `central.site.bn.home` | Hero with the start card, the product rising into place on scroll, the "In short" answer, every app by area, what is particular to Bangladesh, three steps, security, plans, questions |
| `/apps` | `central.site.apps` | Every app by area, each with its price |
| `/apps/{app}` | `central.site.app` | One app: headline, answer, six features, who it is for, price, questions, related apps |
| `/pricing` | `central.site.pricing` | Plans from the database, every app's price, how billing works, questions |
| `/security`, `/about`, `/faq` | `central.site.{page}` | Copy from `site_pages.php` |
| `/contact` | `central.site.contact` (POST `central.site.contact.send`) | Address, phones, email, the contact form |
| `/signin` | `central.site.signin` | "Find my workspace": a plain form to `central.workspace.find` |
| `/privacy`, `/terms`, `/refund-policy` | `central.site.{privacy,terms,refund}` | Legal pages from `site_legal.php` |
| `/blog`, `/blog/{slug}`, `/blog/feed.xml` | `central.site.blog`, `central.site.post`, `central.site.blog_feed` | The blog: articles in the language of the address only (section "Blog" below) |
| `/robots.txt`, `/sitemap.xml`, `/llms.txt` | `central.robots`, `central.sitemap`, `central.llms` | For crawlers |

`App\Core\Website\SiteUrls::to($page, $locale, $parameters)` builds any of these in either language.

## Where things live

| | |
|---|---|
| `app/Core/Website/Http/Controllers/SiteController.php` | Every page and the contact form |
| `app/Core/Website/Http/Controllers/SiteFilesController.php` | robots.txt, sitemap.xml, llms.txt |
| `app/Core/Website/SiteApps.php`, `SiteApp.php`, `SitePrice.php` | The apps the site presents (from the module registry), their prices (from their App Store offers), taka formatting |
| `app/Core/Website/PageMeta.php`, `SiteSchema.php` | Head tags and JSON-LD |
| `app/Core/Website/Http/Middleware/SetSiteLocale.php` | `site.locale:{en,bn}`: the language of the address |
| `app/Core/Http/Middleware/NoIndex.php` | `X-Robots-Tag: noindex` on workspaces and the admin panel |
| `app/Core/Website/Mail/WebsiteEnquiry.php` | The contact form's mail |
| `config/site.php` | Defaults for the company details (changed in the panel), contact recipient (`SITE_CONTACT_EMAIL`) |
| `app/Core/Website/SiteCompany.php` | The company details the site shows: panel settings over the defaults |
| `app/Core/Website/SiteBrand.php`, `Http/Controllers/BrandAssetController.php` | The uploaded logo, dark-background logo and favicon, and the stateless `/brand/{file}` route that serves them |
| `app/Core/Website/WebsiteTexts.php`, `Support/WebsiteTextLoader.php` | Words changed in the panel, and the loader that lays them over the language files |
| `app/Platform/Http/Controllers/Website/` | The panel's Messages, Company, Words and Blog pages (`resources/js/Platform/Pages/Website/`) |
| `app/Core/Website/SitePages.php` | The frame and head facts every page shares (used by the pages and the blog) |
| `app/Core/Website/Blog/`, `Http/Controllers/BlogController.php`, `BlogMediaController.php` | The blog: queries (`BlogPosts`), cards (`PostCard`), the article page (`ArticlePage`), Markdown (`BlogMarkdown`), pictures (`BlogMedia`), saving (`BlogEditor`) |
| `lang/{en,bn}/site.php` | Frame, home, sign-in, labels, llms.txt |
| `lang/{en,bn}/site_pages.php` | Apps overview, pricing, security, about, FAQ, contact |
| `lang/{en,bn}/site_apps.php` | One entry per optional module: words, six features, three FAQs |
| `lang/{en,bn}/site_legal.php` | Privacy, terms, refund (drafts) |
| `resources/views/site/` | Layout, partials (head, header, footer, plans) and pages |
| `resources/views/components/site/` | `icon`, `button`, `logo`, `section-head`, `answer`, `faq`, `app-card`, `page-hero`, `cta` |
| `resources/js/site.ts` | Theme switch, small-screen menu, closing menus, reveal on scroll |
| `public/images/oyosend-og.jpg`, `oyosend-logo.png`, `public/apple-touch-icon.png` | Share image (1200x630), logo for structured data, touch icon |

## Rules

- **Design:** the app's tokens and glass utilities only. Text sits on glass or on the backdrop's colour fields; a
  section places its discs (`bg-(--orb-*)`, at most 80% opacity) behind glass, never behind text on the backdrop, so the
  contrast the app's tests guarantee holds. `<main>` clips sideways so nothing scrolls past a 360px window.
- **Words:** both languages have exactly the same keys (tested). Bengali follows the glossary in
  `resources/js/Core/i18n/locales/bn/index.ts` and is NFC. Describe only what the code does; a feature the docs mark as
  not built is left out. Prices are never written in copy: plans come from the database and app prices from offers.
- **Every page** has one H1, a canonical address, `hreflang` for both languages and `x-default`, a description, Open
  Graph and Twitter tags, and JSON-LD with the Organization and WebSite. A page that shows questions also carries them
  as FAQPage. Pages that answer a question open with the "In short" box (`<x-site.answer>`). The Organization says
  what the company does (`site.meta.description`) and, once an operator gives them, its registered name (`legalName`)
  and founding year (`foundingDate`); every `url` in it is written as the canonical link writes it (no trailing slash
  on the home page). The pages' WebPage carries `dateModified` from `config('site.content_updated')`, the date the
  sitemap gives them; the blog's pages leave it to their BlogPosting.
- **The sign-in page** (`/signin`, "find my workspace") is `noindex, follow` and is not in the sitemap: a form with
  nothing to answer a search with.
- **A new optional module** gets an entry in `SiteApps::APPS` and in `site_apps.php` (both languages); the website test
  fails until it has both.
- **Links into the website from an Inertia page** (sign-up) are plain links (`<a>`, or `Button` with `native`): an
  Inertia visit cannot load a Blade page.

## The home page's hero

The H1 (`site.home.title`, whose `:highlight` is the underlined phrase `site.home.title_highlight`; the phrase takes
turns with `site.home.title_rotate` in `site.ts`, so the H1 carries the whole heading as written in `aria-label` for
screen readers, and its text says the phrase once for search engines), true figures only
(the number of apps, two languages, the longest trial of the plans on sale or "0 automatic charges"), and the **start
card**: company name and email posted to `central.register.start`, which carries them to the sign-up form in the
session (flashed once, never in the address, a malformed email dropped) and redirects to `/register`. While sign-up is
closed the card offers "talk to us" and the route answers 404. Below the hero a workspace drawn in HTML (decorative
sample data, `site.home.dash`, one `role="img"` label) tilts up into place as the page scrolls: `resources/js/site.ts`
sets `--hero` and `--rise`, `app.css` (`.site-hero-body`, `.site-stage`, `.site-assistant`) turns them into transforms.
Without the script, or with reduced motion, everything stands still and flat.

## Prices and sign-up

As before, plan prices are shown only while sign-up is open (`PlatformSettings::registrationMode()`), from
`Plan::offered()`; plan names and descriptions are the operator's own text. While sign-up is closed the pages offer
"talk to us" instead and the structured data carries no offer. App prices come from each module's public App Store
offers: free, or the cheapest monthly amount (a yearly offer counts by its month), with any trial.

## Contact form

Validated, stored (`website_enquiries`, listed in the panel), then one queued mail (`notifications` queue, encrypted)
to the contact recipient set in the panel, with the visitor as reply-to; the visitor's text is sent as plain text in
both parts. A message is kept even when the mail cannot be queued. A filled hidden field (`website`) is thanked and
dropped. Five messages per ten minutes per route.

## Managing the website (Super Admin > Website)

| Tab | Who | What |
|---|---|---|
| Messages | `platform.website.view` (Super Admin, Support Admin) | Every contact-form message (`website_enquiries`), new or answered, searchable; reply by email or phone from the card. Marking answered, moving back and deleting need `platform.website.manage` (Super Admin) and are audited (`platform.website.enquiry_*`) |
| Company | `platform.website.manage` | Name, registered name and year founded (structured data only, left out until given), email, two phones, the address in English (also the structured data) and in Bengali, map link, where contact-form mail goes, and the company's Facebook, LinkedIn, YouTube, Instagram and X pages (footer icons and `sameAs`). Stored as `website.*` platform settings over `config/site.php` (`SiteCompany`) |
| Blog | `platform.website.manage` | Articles in English, Bengali or both: draft, publish now or at a time, take off the website, feature one, delete; pictures; a preview drawn by the website from the unsaved form (section "Blog" below) |
| Words | `platform.website.manage` | Every word of every page, both languages, by part (home, frame, each page, each app, each legal page). Only words: icons, anchors, sections and the number of questions stay as the code has them. A word must keep the placeholders the page fills in (`:count`, `:price`); one equal to the website's own or empty is not stored. "Use the website's own words" clears a part. Stored in `website_texts`, laid over the language files by `WebsiteTextLoader` (the translation loader), cached in the central cache, audited (`platform.website.texts_*`) |

**Logo and favicon** (on the Company tab). A logo, an optional logo for dark backgrounds (shown in the dark theme) and a
favicon, each uploaded on its own and used at once by the website and every area of the app: the website's header and
footer and its Organization `logo`; the app's `Logo` component through Inertia's shared `app.brand`; the favicon in both
root views (the website layout and `resources/views/app.blade.php`), on every host. Raster images only, checked on the
server: the logo PNG, JPG or WebP up to 1 MB and at least 32 pixels high; the favicon a square PNG of at least 32 pixels,
or an ICO, up to 512 KB. SVG is refused because it can carry script. `SiteBrand` keeps a file on `site.brand.disk`
(default `local`, `SITE_BRAND_DISK`) under `platform/brand/`, named after its content, records its name as a
`website.*` platform setting and deletes the one it replaces; `/brand/{file}` on the central host (stateless, the
`central.callback` group) serves it with a year-long immutable cache, `nosniff` and a sandboxing CSP. Removing one
brings back the built-in mark and favicon. With more than one web server, point `SITE_BRAND_DISK` at a disk they share.

A plan's Bengali name and description (`plans.name_bn`, `plans.description_bn`) are set on the plan's own page (Plans >
edit) and shown on the Bengali website; left empty, the English is shown.

## Blog

ADR 0037. Articles are platform data (`blog_posts`, `blog_post_translations`), written in Super Admin > Website > Blog.

- **Languages.** Each article has one translation per language it is written in; it exists only at those addresses.
  `hreflang` lists only those, and the language switch falls back to the blog (or home) in the other language.
- **States.** Draft or published. A published article with a publishing time still ahead is scheduled and appears by
  itself; nothing runs on a schedule. Publishing asks for the title, the short version and the text in every language
  written; a draft only for a title. The address is made from the English title when left empty (made unique), else
  it must be typed: lower-case letters, digits and single hyphens; `feed`, `media`, `page`, `topic` are reserved.
- **Markdown** (`BlogMarkdown`): raw HTML is escaped, unsafe links dropped, only the blog's own pictures kept, `#`
  becomes a section heading, sections get anchors and a contents list, links elsewhere get `rel="noopener"`. GitHub
  tables and strikethrough work. Reading time counts 220 words a minute in English and 160 in Bengali.
- **Pictures** (`BlogMedia`): PNG, JPG or WebP up to 5 MB, at least 200 by 100 pixels; redrawn (no metadata, upright,
  at most 2400 pixels wide), named after their content on `site.blog.disk` (`SITE_BLOG_DISK`, default the brand disk)
  under `platform/blog/`, served stateless at `/blog/media/{file}` (cached for good, `nosniff`, sandboxing CSP,
  600 a minute). Never deleted automatically.
- **The list** (`/blog`): the featured article (else the newest) at the top of the first page, then twelve a page;
  topics (`?topic=guide|update|insight`) and an app (`?app={slug}`, linked from the app's page) filter it, and those
  views are `noindex, follow`, as is an empty blog. A page past the last answers 404.
- **The article**: kind, H1, author (or the company), date, reading time, a link to the other language, the cover,
  the "In short" box from the short version, contents and share links (plain links, no script), the text, the apps
  it is about, its questions, three articles to read next and the closing call. A progress bar follows the scroll
  without a script where the browser supports scroll-driven animation.
- **Machines**: `BlogPosting` (with `mentions` for its apps), `FAQPage` when it has questions, `og:type` article with
  its times and the cover as the share image; the list is a `CollectionPage` with a `Blog`. The sitemap carries the
  blog and every article with its own languages and date; `llms.txt` the 20 newest English articles; each language
  an Atom feed of its 20 newest.
- **Around the website**: the menu (wide screens), the small-screen menu and the footer link the blog only once it
  has an article in the page's language; the home page shows the three newest; an app's page shows three about it.
- **The editor**: a tab per language (a dot shows which are written, red when one has a problem), a Markdown toolbar
  with picture upload, word count and reading time, questions and answers, search title and description with a
  search-result preview, publishing time in Asia/Dhaka (`site.blog.timezone`), featured, address, kind, author, cover
  with a description per language, up to six apps. "Preview" posts the unsaved form to
  `platform.website.blog.preview`, which draws the website's own page (`noindex`, `no-store`) into a frame with an
  empty `sandbox`. Saves are audited (`platform.website.post_*`).

## Not done, and for the owner

- **Legal pages are drafts** for a lawyer: refund window and cases, payout time, liability cap, notice periods, the
  legal entity name and trade licence, provider names, data deletion after a workspace closes. The agent's list of
  decisions is in `PROGRESS.md`.
- Not measured: Lighthouse, Core Web Vitals, Google Search Console, Rich Results Test. `og:image` is one image for all
  pages. No analytics (deliberately; the privacy page says there is none).
- SSLCommerz and aamarPay are named as payment routes; neither has been run against its sandbox yet.
