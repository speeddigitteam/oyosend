<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditLog;
use App\Core\Website\Blog\BlogMedia;
use App\Core\Website\Enums\PostStatus;
use App\Core\Website\Models\BlogPost;
use App\Platform\Enums\PlatformRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/*
 * The blog in the Super Admin panel (Website > Blog, ADR 0037): who may write, drafts, publishing and scheduling,
 * pictures, the preview, and the audit trail.
 */

beforeEach(function () {
    $this->admin = $this->createPlatformUser();
    $this->support = $this->createPlatformUser(PlatformRole::SupportAdmin);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function articleInput(array $overrides = []): array
{
    return array_replace_recursive([
        'slug' => '',
        'kind' => 'guide',
        'status' => 'published',
        'publish_at' => '',
        'featured' => false,
        'apps' => ['payroll'],
        'author_name' => '',
        'cover' => null,
        'translations' => [
            'en' => [
                'title' => 'Paying people on time',
                'summary' => 'How a small company keeps payday on the same day every month.',
                'body' => "## Plan the month\n\nStart early.",
                'cover_alt' => '', 'meta_title' => '', 'meta_description' => '',
                'faq' => [['q' => 'When is payday?', 'a' => 'On the last working day.']],
            ],
            'bn' => ['title' => '', 'summary' => '', 'body' => '', 'cover_alt' => '', 'meta_title' => '', 'meta_description' => '', 'faq' => []],
        ],
    ], $overrides);
}

describe('who may write', function () {
    it('keeps the blog to those who manage the website', function () {
        $this->actingAsPlatformUser($this->support);

        $this->get('http://admin.localhost/website/blog')->assertForbidden();
        $this->post('http://admin.localhost/website/blog', articleInput())->assertForbidden();
        $this->post('http://admin.localhost/website/blog/media', ['file' => UploadedFile::fake()->image('a.png', 400, 200)])->assertForbidden();

        expect(BlogPost::query()->count())->toBe(0);
    });

    it('keeps signed-out visitors out', function () {
        $this->get('http://admin.localhost/website/blog')->assertRedirect('http://admin.localhost/login');
    });

    it('exists on the admin host only', function () {
        $this->get('http://localhost/website/blog')->assertNotFound();
    });
});

describe('writing', function () {
    it('publishes an article, makes its address from the title and records it', function () {
        $this->actingAsPlatformUser($this->admin)
            ->post('http://admin.localhost/website/blog', articleInput())
            ->assertSessionHasNoErrors();

        $post = BlogPost::query()->with('translations')->sole();

        expect($post->slug)->toBe('paying-people-on-time')
            ->and($post->status)->toBe(PostStatus::Published)
            ->and($post->locales())->toBe(['en'])
            ->and($post->in('en')->questions())->toBe([['q' => 'When is payday?', 'a' => 'On the last working day.']])
            ->and($post->created_by)->toBe($this->admin->id);

        expect(AuditLog::withoutTenancy()->whereIn('event', ['platform.website.post_created', 'platform.website.post_published'])->count())->toBe(2);

        $this->get('http://localhost/blog/paying-people-on-time')->assertOk();
    });

    it('asks for the summary and the text before publishing, and only a title for a draft', function () {
        $empty = ['translations' => ['en' => ['summary' => '', 'body' => '']]];

        $this->actingAsPlatformUser($this->admin)
            ->post('http://admin.localhost/website/blog', articleInput($empty))
            ->assertSessionHasErrors(['translations.en.summary', 'translations.en.body']);

        $this->post('http://admin.localhost/website/blog', articleInput([...$empty, 'status' => 'draft']))->assertSessionHasNoErrors();

        expect(BlogPost::query()->sole()->status)->toBe(PostStatus::Draft);
    });

    it('needs at least one language, and an address when the title gives none', function () {
        $this->actingAsPlatformUser($this->admin)
            ->post('http://admin.localhost/website/blog', articleInput(['translations' => ['en' => ['title' => '', 'summary' => '', 'body' => '', 'faq' => []]]]))
            ->assertSessionHasErrors('translations');

        $bengali = articleInput([
            'translations' => [
                'en' => ['title' => '', 'summary' => '', 'body' => '', 'faq' => []],
                'bn' => ['title' => 'সময়মতো বেতন', 'summary' => 'বেতনের দিন ঠিক রাখার উপায়।', 'body' => 'লেখা।'],
            ],
        ]);

        $this->post('http://admin.localhost/website/blog', $bengali)->assertSessionHasErrors('slug');
        $this->post('http://admin.localhost/website/blog', [...$bengali, 'slug' => 'on-time-pay'])->assertSessionHasNoErrors();

        expect(BlogPost::query()->with('translations')->sole()->locales())->toBe(['bn']);
    });

    it('refuses an address that is taken, reserved or not one', function (string $slug) {
        BlogPost::query()->create(['slug' => 'taken', 'kind' => 'guide', 'status' => 'draft']);

        $this->actingAsPlatformUser($this->admin)
            ->post('http://admin.localhost/website/blog', articleInput(['slug' => $slug]))
            ->assertSessionHasErrors('slug');
    })->with(['taken', 'feed', 'Upper-Case', 'two--hyphens', 'বাংলা']);

    it('makes the address unique when the title repeats one', function () {
        $this->actingAsPlatformUser($this->admin)->post('http://admin.localhost/website/blog', articleInput());
        $this->post('http://admin.localhost/website/blog', articleInput());

        expect(BlogPost::query()->pluck('slug')->sort()->values()->all())->toBe(['paying-people-on-time', 'paying-people-on-time-2']);
    });

    it('refuses an app this build does not have and an incomplete question', function () {
        $this->actingAsPlatformUser($this->admin)
            ->post('http://admin.localhost/website/blog', articleInput(['apps' => ['crypto'], 'translations' => ['en' => ['faq' => [['q' => 'Half a question?', 'a' => '']]]]]))
            ->assertSessionHasErrors(['apps.0', 'translations.en.faq.0']);
    });

    it('schedules an article for a time in the company\'s own time zone', function () {
        $this->travelTo('2026-10-15 06:00:00'); // 12:00 in Dhaka

        $this->actingAsPlatformUser($this->admin)
            ->post('http://admin.localhost/website/blog', articleInput(['publish_at' => '2026-10-15T18:00']))
            ->assertSessionHasNoErrors();

        $post = BlogPost::query()->sole();

        expect($post->published_at->toIso8601String())->toBe('2026-10-15T12:00:00+00:00')
            ->and($post->isScheduled())->toBeTrue();

        $this->get('http://localhost/blog/paying-people-on-time')->assertNotFound();
        $this->travelTo('2026-10-15 12:01:00');
        $this->get('http://localhost/blog/paying-people-on-time')->assertOk();
    });

    it('keeps one featured article at a time', function () {
        $this->actingAsPlatformUser($this->admin)->post('http://admin.localhost/website/blog', articleInput(['featured' => true]));
        $this->post('http://admin.localhost/website/blog', articleInput(['slug' => 'second', 'featured' => true]));

        expect(BlogPost::query()->where('featured', true)->pluck('slug')->all())->toBe(['second']);
    });

    it('takes an article off the website and back, keeping its date', function () {
        $this->actingAsPlatformUser($this->admin)->post('http://admin.localhost/website/blog', articleInput());
        $post = BlogPost::query()->sole();
        $published = $post->published_at;

        $this->put("http://admin.localhost/website/blog/{$post->id}", articleInput(['slug' => $post->slug, 'status' => 'draft']))->assertSessionHasNoErrors();
        $this->get('http://localhost/blog/paying-people-on-time')->assertNotFound();

        $this->travel(3)->days();
        $this->put("http://admin.localhost/website/blog/{$post->id}", articleInput(['slug' => $post->slug]))->assertSessionHasNoErrors();

        expect($post->fresh()->published_at->equalTo($published))->toBeTrue()
            ->and(AuditLog::withoutTenancy()->where('event', 'platform.website.post_unpublished')->count())->toBe(1);
    });

    it('removes a language that was emptied', function () {
        $both = articleInput(['translations' => ['bn' => ['title' => 'সময়মতো বেতন', 'summary' => 'সংক্ষেপ।', 'body' => 'লেখা।']]]);
        $this->actingAsPlatformUser($this->admin)->post('http://admin.localhost/website/blog', $both);
        $post = BlogPost::query()->sole();

        $this->put("http://admin.localhost/website/blog/{$post->id}", articleInput(['slug' => $post->slug]))->assertSessionHasNoErrors();

        expect($post->fresh()->load('translations')->locales())->toBe(['en']);
        $this->get('http://localhost/bn/blog/paying-people-on-time')->assertNotFound();
    });

    it('deletes an article with its words and keeps who did it', function () {
        $this->actingAsPlatformUser($this->admin)->post('http://admin.localhost/website/blog', articleInput());
        $post = BlogPost::query()->sole();

        $this->delete("http://admin.localhost/website/blog/{$post->id}")->assertRedirect('http://admin.localhost/website/blog');

        expect(BlogPost::query()->count())->toBe(0)
            ->and(AuditLog::withoutTenancy()->where('event', 'platform.website.post_deleted')->value('subject_label'))->toBe('Paying people on time');
    });
});

describe('the pages', function () {
    it('lists articles by state and opens one in the editor', function () {
        $this->actingAsPlatformUser($this->admin)->post('http://admin.localhost/website/blog', articleInput());
        $this->post('http://admin.localhost/website/blog', articleInput(['slug' => 'later', 'publish_at' => now('Asia/Dhaka')->addDay()->format('Y-m-d\TH:i')]));
        $this->post('http://admin.localhost/website/blog', articleInput(['slug' => 'draft-one', 'status' => 'draft']));

        $this->get('http://admin.localhost/website/blog')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Platform/Pages/Website/Blog/Index')
                ->has('posts.data', 3)
                ->where('counts', ['published' => 1, 'scheduled' => 1, 'draft' => 1]));

        $this->get('http://admin.localhost/website/blog?status=scheduled')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('posts.data', 1)->where('posts.data.0.slug', 'later')->where('posts.data.0.state', 'scheduled'));

        $post = BlogPost::query()->where('slug', 'paying-people-on-time')->sole();

        $this->get("http://admin.localhost/website/blog/{$post->id}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Platform/Pages/Website/Blog/Edit')
                ->where('post.slug', 'paying-people-on-time')
                ->where('post.translations.en.title', 'Paying people on time')
                ->where('post.translations.bn', null)
                ->where('post.urls.en', 'http://localhost/blog/paying-people-on-time'));

        $this->get('http://admin.localhost/website/blog/create')->assertInertia(fn (AssertableInertia $page) => $page->component('Platform/Pages/Website/Blog/Edit')->where('post', null));
    });
});

describe('pictures and the preview', function () {
    it('keeps an uploaded picture redrawn, named after its content', function () {
        Storage::fake((string) config('site.blog.disk'));

        $response = $this->actingAsPlatformUser($this->admin)
            ->postJson('http://admin.localhost/website/blog/media', ['file' => UploadedFile::fake()->image('cover.jpg', 3000, 1500)])
            ->assertOk();

        $file = $response->json('file');

        expect($file)->toMatch('/^'.BlogMedia::FILE_PATTERN.'$/')
            ->and($response->json('width'))->toBe(BlogMedia::MAX_WIDTH)
            ->and($response->json('height'))->toBe(1200)
            ->and($response->json('url'))->toBe("http://localhost/blog/media/{$file}");

        BlogMedia::disk()->assertExists(BlogMedia::path($file));

        $this->post('http://admin.localhost/website/blog', articleInput(['cover' => $file, 'translations' => ['en' => ['cover_alt' => 'A payslip']]]))->assertSessionHasNoErrors();

        expect(BlogPost::query()->sole()->only(['cover', 'cover_width', 'cover_height']))->toBe(['cover' => $file, 'cover_width' => 2400, 'cover_height' => 1200]);

        $this->get('http://localhost/blog/paying-people-on-time')
            ->assertSee('<meta property="og:image" content="http://localhost/blog/media/'.$file.'">', escape: false)
            ->assertSee('alt="A payslip"', escape: false);
    });

    it('refuses what is not a picture, and a cover it did not keep', function () {
        Storage::fake((string) config('site.blog.disk'));

        $this->actingAsPlatformUser($this->admin)
            ->postJson('http://admin.localhost/website/blog/media', ['file' => UploadedFile::fake()->createWithContent('x.svg', '<svg><script>alert(1)</script></svg>')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->post('http://admin.localhost/website/blog', articleInput(['cover' => 'ffffffffffffffffffffffff.png']))->assertSessionHasErrors('cover');
        $this->post('http://admin.localhost/website/blog', articleInput(['cover' => '../../.env']))->assertSessionHasErrors('cover');
    });

    it('draws the preview from the editor without saving anything', function () {
        $html = (string) $this->actingAsPlatformUser($this->admin)
            ->postJson('http://admin.localhost/website/blog/preview', [...articleInput(['status' => 'draft', 'translations' => ['en' => ['body' => "## Unsaved section\n\n<script>alert(1)</script>"]]]), 'locale' => 'en'])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->getContent();

        expect($html)->toContain('<h1')
            ->and($html)->toContain('Unsaved section')
            ->and($html)->toContain('<meta name="robots" content="noindex, follow">')
            ->and($html)->not->toContain('<script>alert(1)</script>')
            ->and(BlogPost::query()->count())->toBe(0);
    });

    it('draws the preview in the language asked for, even from an English panel', function () {
        $input = articleInput(['translations' => ['bn' => ['title' => 'সময়মতো বেতন', 'summary' => 'সংক্ষেপ।', 'body' => 'লেখা।']]]);

        $this->actingAsPlatformUser($this->admin)
            ->postJson('http://admin.localhost/website/blog/preview', [...$input, 'locale' => 'bn'])
            ->assertOk()
            ->assertSee('<html lang="bn"', escape: false)
            ->assertSee('সময়মতো বেতন');

        expect(app()->getLocale())->toBe('en');
    });
});
