<?php

declare(strict_types=1);

use App\Core\Website\Blog\BlogMarkdown;
use App\Core\Website\Blog\BlogMedia;
use App\Core\Website\Enums\PostKind;
use App\Core\Website\Enums\PostStatus;
use App\Core\Website\Models\BlogPost;
use App\Core\Website\Models\BlogPostTranslation;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * The website's blog (docs/WEBSITE.md, ADR 0037): articles in the languages they are written in, safe Markdown,
 * what each page tells search and answer engines, the feed, and where the rest of the website links to it.
 */

beforeEach(function () {
    config(['platform.registration.mode' => 'open']);
});

/**
 * @param  array<string, mixed>  $attributes
 * @param  array<string, array<string, mixed>>  $words  locale => words
 */
function article(array $attributes = [], array $words = ['en' => []]): BlogPost
{
    $post = BlogPost::query()->create([
        'slug' => 'paying-people-on-time',
        'kind' => PostKind::Guide,
        'status' => PostStatus::Published,
        'published_at' => now()->subDay(),
        'featured' => false,
        'apps' => ['payroll'],
        ...$attributes,
    ]);

    foreach ($words as $locale => $text) {
        BlogPostTranslation::query()->create([
            'post_id' => $post->id,
            'locale' => $locale,
            'title' => $locale === 'bn' ? 'সময়মতো বেতন দেওয়া' : 'Paying people on time',
            'summary' => $locale === 'bn' ? 'বেতনের দিন ঠিক রাখার উপায়।' : 'How a small company keeps payday on the same day every month.',
            'body' => "## Plan the month\n\nStart early.\n\n## Approve together\n\nTwo people check.",
            ...$text,
        ]);
    }

    return $post->load('translations');
}

/** @return list<string> */
function graphTypes(TestResponse $response): array
{
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', (string) $response->getContent(), $match);

    return array_column(json_decode($match[1] ?? '{}', true)['@graph'] ?? [], '@type');
}

describe('the list', function () {
    it('shows what is published in the page\'s language, and nothing else', function () {
        article();
        article(['slug' => 'a-draft', 'status' => PostStatus::Draft], ['en' => ['title' => 'A draft']]);
        article(['slug' => 'next-week', 'published_at' => now()->addWeek()], ['en' => ['title' => 'Next week']]);
        article(['slug' => 'bengali-only'], ['bn' => ['title' => 'শুধু বাংলায়']]);

        $this->get('http://localhost/blog')
            ->assertOk()
            ->assertSee('Paying people on time')
            ->assertDontSee('A draft')
            ->assertDontSee('Next week')
            ->assertDontSee('শুধু বাংলায়')
            ->assertSee('<meta name="robots" content="index, follow', escape: false);

        $this->get('http://localhost/bn/blog')->assertOk()->assertSee('শুধু বাংলায়')->assertDontSee('Paying people on time');
    });

    it('lets a scheduled article appear by itself when its time comes', function () {
        article(['published_at' => now()->addHour()]);

        $this->get('http://localhost/blog/paying-people-on-time')->assertNotFound();

        $this->travel(2)->hours();

        $this->get('http://localhost/blog/paying-people-on-time')->assertOk();
    });

    it('filters by topic and app, and keeps a filtered view out of search results', function () {
        article();
        article(['slug' => 'whats-new', 'kind' => PostKind::Update, 'apps' => []], ['en' => ['title' => 'What is new in October']]);

        $this->get('http://localhost/blog?topic=update')
            ->assertSee('What is new in October')
            ->assertDontSee('Paying people on time')
            ->assertSee('<meta name="robots" content="noindex, follow">', escape: false);

        $this->get('http://localhost/blog?app=payroll')->assertSee('Paying people on time')->assertDontSee('What is new in October');

        $this->get('http://localhost/blog?topic=gossip')->assertNotFound();
        $this->get('http://localhost/blog?app=nothing')->assertNotFound();
        $this->get('http://localhost/blog?page=9')->assertNotFound();
    });

    it('says plainly when there is nothing to read yet, and asks search engines to wait', function () {
        $this->get('http://localhost/blog')
            ->assertOk()
            ->assertSee(__('site_pages.blog.empty_title', [], 'en'))
            ->assertSee('<meta name="robots" content="noindex, follow">', escape: false);
    });

    it('describes itself as a blog to search engines', function () {
        article();

        expect(graphTypes($this->get('http://localhost/blog')))->toContain('Organization', 'CollectionPage', 'Blog', 'BreadcrumbList');
    });
});

describe('an article', function () {
    it('renders with one heading, its contents, its structured data and its language links', function () {
        article([], [
            'en' => ['faq' => [['q' => 'When is payday?', 'a' => 'On the last working day.']]],
            'bn' => [],
        ]);

        $response = $this->get('http://localhost/blog/paying-people-on-time')->assertOk();
        $html = (string) $response->getContent();

        expect(substr_count($html, '<h1'))->toBe(1)
            ->and($html)->toContain('<link rel="canonical" href="http://localhost/blog/paying-people-on-time">')
            ->and($html)->toContain('hreflang="bn" href="http://localhost/bn/blog/paying-people-on-time"')
            ->and($html)->toContain('<meta property="og:type" content="article">')
            ->and($html)->toContain('<h2 id="plan-the-month">Plan the month</h2>')
            ->and($html)->toContain('href="#approve-together"')
            ->and(graphTypes($response))->toContain('BlogPosting', 'FAQPage', 'BreadcrumbList');

        $response->assertSee('When is payday?')->assertSee('On the last working day.');
    });

    it('exists only in the languages it is written in', function () {
        article();

        $this->get('http://localhost/bn/blog/paying-people-on-time')->assertNotFound();

        $html = (string) $this->get('http://localhost/blog/paying-people-on-time')->getContent();

        expect($html)->not->toContain('hreflang="bn"')
            // The language switch goes to the Bengali website instead of a page that does not exist.
            ->and($html)->toContain('href="http://localhost/bn" hreflang="bn"');
    });

    it('is not shown while it is a draft', function () {
        article(['status' => PostStatus::Draft]);

        $this->get('http://localhost/blog/paying-people-on-time')->assertNotFound();
    });

    it('lists the apps it is about, and appears on their pages', function () {
        article();

        $this->get('http://localhost/blog/paying-people-on-time')->assertSee('http://localhost/apps/payroll', escape: false);
        $this->get('http://localhost/apps/payroll')->assertSee('Paying people on time');
        $this->get('http://localhost/apps/crm')->assertDontSee('Paying people on time');
    });
});

describe('the Markdown', function () {
    it('shows HTML as text and never runs it', function () {
        $html = app(BlogMarkdown::class)->render("Hello <script>alert(1)</script>\n\n<img src=x onerror=alert(1)>")->html;

        expect($html)->not->toContain('<script>')
            ->and($html)->not->toContain('<img src=x')
            ->and($html)->toContain('&lt;script&gt;');
    });

    it('drops unsafe links and pictures from other websites', function () {
        $html = app(BlogMarkdown::class)->render("[click](javascript:alert(1))\n\n![tracker](https://evil.example/pixel.png)\n\n![ours](/blog/media/0123456789abcdef01234567.png)")->html;

        expect($html)->not->toContain('javascript:')
            ->and($html)->not->toContain('evil.example')
            ->and($html)->toContain('tracker')
            ->and($html)->toContain('<img loading="lazy" decoding="async" src="http://localhost/blog/media/0123456789abcdef01234567.png" alt="ours"');
    });

    it('keeps one H1 on the page and gives every section an anchor', function () {
        $article = app(BlogMarkdown::class)->render("# Big\n\n## Same\n\n## Same\n\n### Small\n\n## বাংলা অংশ");

        expect($article->html)->not->toContain('<h1')
            ->and($article->toc)->toBe([
                ['id' => 'big', 'text' => 'Big'],
                ['id' => 'same', 'text' => 'Same'],
                ['id' => 'same-2', 'text' => 'Same'],
                ['id' => 'বাংলা-অংশ', 'text' => 'বাংলা অংশ'],
            ]);
    });

    it('marks links to other websites and leaves its own alone', function () {
        $html = app(BlogMarkdown::class)->render('[elsewhere](https://example.com) and [pricing](http://localhost/pricing)')->html;

        expect($html)->toContain('<a rel="noopener" href="https://example.com">')
            ->and($html)->toContain('<a href="http://localhost/pricing">');
    });
});

describe('the rest of the website', function () {
    it('links the blog from the menu only once there is something to read', function () {
        $this->get('http://localhost/about')->assertDontSee('href="http://localhost/blog"', escape: false);

        article();

        $this->get('http://localhost/about')->assertSee('href="http://localhost/blog"', escape: false);
        $this->get('http://localhost/bn/about')->assertDontSee('href="http://localhost/bn/blog"', escape: false);
    });

    it('shows the newest articles on the home page', function () {
        article();

        $this->get('http://localhost/')->assertSee(__('site_pages.blog.latest_title', [], 'en'))->assertSee('Paying people on time');
    });

    it('puts every article in the sitemap with its own languages and date', function () {
        article(['updated_at' => '2026-10-10 08:00:00'], ['en' => [], 'bn' => []]);
        article(['slug' => 'only-english'], ['en' => ['title' => 'Only English']]);

        $xml = (string) $this->get('http://localhost/sitemap.xml')->getContent();

        expect($xml)->toContain('<loc>http://localhost/blog/paying-people-on-time</loc>')
            ->and($xml)->toContain('<loc>http://localhost/bn/blog/paying-people-on-time</loc>')
            ->and($xml)->toContain('<loc>http://localhost/blog/only-english</loc>')
            ->and($xml)->not->toContain('<loc>http://localhost/bn/blog/only-english</loc>')
            ->and($xml)->toContain('<loc>http://localhost/blog</loc>');
    });

    it('names the articles in llms.txt', function () {
        article();

        $this->get('http://localhost/llms.txt')->assertSee('[Paying people on time](http://localhost/blog/paying-people-on-time)', escape: false);
    });

    it('offers an Atom feed per language', function () {
        article(['author_name' => 'Nusrat Jahan'], ['en' => [], 'bn' => []]);

        $this->get('http://localhost/blog/feed.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/atom+xml; charset=UTF-8')
            ->assertSee('<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="en">', escape: false)
            ->assertSee('<id>http://localhost/blog/paying-people-on-time</id>', escape: false)
            ->assertSee('<name>Nusrat Jahan</name>', escape: false);

        $this->get('http://localhost/bn/blog/feed.xml')->assertSee('সময়মতো বেতন দেওয়া');
    });
});

describe('pictures', function () {
    it('serves a kept picture for good, and nothing it did not write', function () {
        Storage::fake((string) config('site.blog.disk'));
        BlogMedia::disk()->put(BlogMedia::path('0123456789abcdef01234567.png'), 'png-bytes');

        $this->get('http://localhost/blog/media/0123456789abcdef01234567.png')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');

        $this->get('http://localhost/blog/media/ffffffffffffffffffffffff.png')->assertNotFound();
        $this->get('http://localhost/blog/media/notes.txt')->assertNotFound();
        $this->get('http://acme.localhost/blog/media/0123456789abcdef01234567.png')->assertNotFound();
    });
});
