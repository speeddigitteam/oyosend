# ADR 0037 — The website's blog: platform articles in Markdown, per language, published from the Super Admin panel

- **Status:** Proposed, built. Not reviewed by the owner. Tested with Pest and Vitest; not yet looked at in a browser
  by the owner, not measured with Lighthouse or any search console.
- **Date:** 2026-10-15
- **Changes:** adds two platform-wide tables (`blog_posts`, `blog_post_translations`) listed in `docs/DATABASE.md`
  section 2, a stateless image route on the central host, and a Blog tab under Super Admin > Website. Changes no locked
  decision.

## Context

The website (ADR 0034) had pages that stay much the same: home, apps, pricing and the rest. The owner asked for a blog,
written from the admin panel, "professional and unique". Its job is the same as the website's: to be found and quoted
by search engines and answer engines, in English and in Bengali, and to bring readers to the apps.

## Decision

1. **Platform data, not tenant data.** The company behind the platform writes for its own website. The tables have no
   `tenant_id`, use the central connection (`UsesCentralConnection`) and are written only from the Super Admin panel
   under `platform.website.manage` (Super Admin). A Support Admin does not see the blog.

2. **One article, one translation per language.** `blog_posts` holds what is shared: address (`slug`, the same in
   both languages), kind (`guide`, `update`, `insight`), state, publishing time, featured flag, the apps it is about,
   the cover picture and an optional author name. `blog_post_translations` holds the words of each language: title,
   the "In short" summary, the Markdown body, the cover's description, a search title and description, and questions
   and answers. An article exists in a language only when it is written in it: `/blog/{slug}` and `/bn/blog/{slug}`
   answer 404 otherwise, `hreflang` lists only the languages it has, and the language switch falls back to the blog
   (or the home page) in the other language.

3. **Markdown, rendered on the server, safe by construction** (`BlogMarkdown`, league/commonmark, already installed
   with Laravel, BSD-3-Clause). Raw HTML is escaped and shown as text, unsafe links are dropped, and an image is kept
   only when it is one of the blog's own (`/blog/media/{file}`); any other image becomes its description, so an
   article cannot load a tracker or anything from another site. A `#` heading becomes a section heading (the page has
   one H1); section headings get anchors for the table of contents. Links to other sites get `rel="noopener"`. No HTML
   editor and no `v-html`: the admin panel previews by asking the server to draw the page.

4. **States without a scheduler.** `draft` or `published`; a published article whose `published_at` is still ahead is
   scheduled and appears by itself when the time comes, because every read asks for `published_at <= now`. Nothing
   runs on a schedule. Taking an article off the website makes it a draft and keeps its date. One article at a time is
   featured at the top of the blog.

5. **Pictures are redrawn before they are kept** (`BlogMedia`). PNG, JPG or WebP up to 5 MB; with GD available the
   picture is decoded and encoded again, so only pixels are stored (no camera details or location), a phone photo is
   turned upright from its orientation note, and anything wider than 2400 pixels is scaled down. Files are named after
   their content on `site.blog.disk` (default the brand disk) and served by a stateless central route with a year-long
   immutable cache, `nosniff` and a sandboxing CSP, like the uploaded logo. SVG is refused.

6. **What the pages tell machines.** The list is a `CollectionPage` with a `Blog` node; an article is a `BlogPosting`
   (headline, description, dates, author or the Organization, publisher, image, word count, reading time, the apps it
   mentions) with its breadcrumbs and, when it has questions, an `FAQPage`. `og:type` is `article` with its times, and
   the cover is the share image. Filtered lists (`?topic=`, `?app=`) and an empty blog are `noindex, follow`. The
   sitemap lists the blog and every article in its own languages with its own date; `llms.txt` names the newest
   English articles; each language has an Atom feed at `/blog/feed.xml`.

7. **Woven into the website, only when there is something to read.** The menu, the footer and the home page link the
   blog only once it has an article in the page's language, and each app's page lists the articles about it.
   The article page has a reading-progress bar drawn by the browser from the scroll position (no script; hidden with
   reduced motion or where unsupported), a table of contents that follows the reader, and plain share links
   (Facebook, LinkedIn, X, WhatsApp) that load no script and track nobody.

8. **The editor** (`Platform/Pages/Website/Blog/Edit.vue`): a tab per language, a Markdown toolbar, picture upload,
   publishing time in the company's time zone (`site.blog.timezone`, Asia/Dhaka), a search-result preview, and a
   preview drawn by the website itself from the unsaved form, shown in a sandboxed frame that may run nothing. Every
   save is one transaction with its audit entries (`platform.website.post_created`, `_updated`, `_published`,
   `_unpublished`, `_deleted`).

## Consequences

- The blog's static words (headings, labels) are in `lang/{en,bn}/site_pages.php` under `blog` and can be changed in
  Website > Words like every other page.
- Pictures are never deleted automatically: a file may be used by more than one article. Unused files stay on the
  disk until someone removes them.
- The admin panel shows pictures from the central host; with the CSP enforced, `img-src` must allow it, as it already
  must for the uploaded logo.
- Not built: categories beyond the three kinds, tags, comments, an author directory, search inside the blog, analytics
  (the privacy page says there are none).
