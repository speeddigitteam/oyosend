<?php

declare(strict_types=1);

use App\Modules\Social\Actions\MediaLibrary;
use App\Modules\Social\Actions\PostManager;
use App\Modules\Social\Models\Media;
use App\Modules\Social\Models\PostMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithSocial;

uses(InteractsWithSocial::class);

beforeEach(function () {
    $this->disk = (string) config('tenancy.filesystem.disk', 'local');
    Storage::fake($this->disk);
    $this->tenant = $this->socialTenant('soc-media');
    $this->fakePlatform();
    $this->url = fn (string $path = '') => $this->socialUrl($this->tenant, $path);
    $this->upload = fn (UploadedFile ...$files) => $this->post(($this->url)('/media'), ['files' => $files]);
});

it('stores an uploaded image privately under the workspace directory and never exposes its path', function () {
    $this->actingAsOwner($this->tenant);

    ($this->upload)(UploadedFile::fake()->image('Brand Logo.png', 200, 100))->assertRedirect();

    $media = $this->inTenant($this->tenant, fn () => Media::query()->sole());
    $tenantId = $this->tenant->getTenantKey();

    expect($media->name)->toBe('Brand Logo.png')
        ->and($media->mime)->toBe('image/png')
        ->and($media->kind->value)->toBe('image')
        ->and($media->width)->toBe(200)->and($media->height)->toBe(100)
        ->and($media->path)->toStartWith('social/media/')
        // The generated name does not carry the uploaded name.
        ->and($media->path)->not->toContain('Brand')
        ->and($media->sha256)->toHaveLength(64);
    Storage::disk($this->disk)->assertExists("tenants/{$tenantId}/{$media->path}");
    expect(Storage::disk($this->disk)->exists($media->path))->toBeFalse();

    $page = $this->get(($this->url)('/media'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('media.data', 1)
        ->where('media.data.0.url', ($this->url)("/media/{$media->id}/file")));
    expect($page->getContent())->not->toContain($media->path);
});

it('serves the file only to an authorised member of its own workspace, with no sniffing and no scripting', function () {
    $this->actingAsOwner($this->tenant);
    ($this->upload)(UploadedFile::fake()->image('a.jpg', 20, 20));
    $media = $this->inTenant($this->tenant, fn () => Media::query()->sole());

    $response = $this->get(($this->url)("/media/{$media->id}/file"))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and(strlen($response->getContent()))->toBe($media->size_bytes);

    // A member without media access is refused; signed-out visitors are sent to sign in.
    [$viewer] = $this->socialMember($this->tenant, ['social.posts.view']);
    $this->actingAsMember($this->tenant, $viewer);
    $this->get(($this->url)("/media/{$media->id}/file"))->assertForbidden();

    $this->startNewBrowserSession();
    $this->get(($this->url)("/media/{$media->id}/file"))->assertRedirect();
});

it('verifies a file by its content, not by its name or the type the browser claims', function () {
    $this->actingAsOwner($this->tenant);

    // A script renamed to .png.
    ($this->upload)(UploadedFile::fake()->createWithContent('shell.png', "<?php echo 'owned';"))->assertSessionHasErrors();
    // Not an image at all, but declared as one.
    ($this->upload)(UploadedFile::fake()->create('fake.jpg', 5, 'image/jpeg'))->assertSessionHasErrors();
    // SVG can carry script and is not accepted.
    ($this->upload)(UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertSessionHasErrors();
    // A document.
    ($this->upload)(UploadedFile::fake()->create('report.pdf', 5, 'application/pdf'))->assertSessionHasErrors();

    $this->inTenant($this->tenant, fn () => expect(Media::query()->count())->toBe(0));
    expect(Storage::disk($this->disk)->allFiles())->toBe([]);
});

it('enforces the size limit per kind', function () {
    config(['social.media.image_max_kb' => 100]);
    $this->actingAsOwner($this->tenant);

    ($this->upload)(UploadedFile::fake()->image('big.jpg', 50, 50)->size(500))->assertSessionHasErrors();
    ($this->upload)(UploadedFile::fake()->image('small.jpg', 50, 50)->size(50))->assertRedirect();

    $this->inTenant($this->tenant, fn () => expect(Media::query()->count())->toBe(1));
});

it('keeps one copy of a file uploaded twice', function () {
    $this->actingAsOwner($this->tenant);
    $source = UploadedFile::fake()->image('x.png', 30, 30);
    $bytes = (string) file_get_contents((string) $source->getRealPath());
    $image = fn () => UploadedFile::fake()->createWithContent('same.png', $bytes);

    ($this->upload)($image());
    ($this->upload)($image());

    $this->inTenant($this->tenant, fn () => expect(Media::query()->count())->toBe(1));
    expect(Storage::disk($this->disk)->allFiles())->toHaveCount(1);
});

it('does not let a used file be deleted and removes the bytes when an unused one is', function () {
    $this->actingAsOwner($this->tenant);
    ($this->upload)(UploadedFile::fake()->image('a.png', 20, 20));
    $media = $this->inTenant($this->tenant, fn () => Media::query()->sole());
    $account = $this->socialAccount($this->tenant);

    $post = $this->inTenant($this->tenant, fn () => app(PostManager::class)->create(
        ['body' => 'With picture'], [['account_id' => $account->id]], [$media->id],
    ));

    $this->delete(($this->url)("/media/{$media->id}"))->assertSessionHasErrors('media');
    $this->inTenant($this->tenant, fn () => expect(Media::query()->count())->toBe(1)->and(PostMedia::query()->count())->toBe(1));

    $this->inTenant($this->tenant, fn () => app(PostManager::class)->update($post, ['body' => 'No picture'], [['account_id' => $account->id]], []));
    $this->delete(($this->url)("/media/{$media->id}"))->assertRedirect();

    $this->inTenant($this->tenant, fn () => expect(Media::query()->count())->toBe(0));
    expect(Storage::disk($this->disk)->allFiles())->toBe([]);
});

it('keeps media of another workspace out of the library and out of a post', function () {
    $other = $this->socialTenant('soc-media-other');
    $this->actingAsOwner($other);
    $this->post($this->socialUrl($other, '/media'), ['files' => [UploadedFile::fake()->image('theirs.png', 20, 20)]]);
    $theirs = $this->inTenant($other, fn () => Media::query()->sole());

    $this->actingAsOwner($this->tenant);
    $this->get(($this->url)('/media'))->assertInertia(fn (AssertableInertia $page) => $page->has('media.data', 0));
    $this->get(($this->url)("/media/{$theirs->id}/file"))->assertNotFound();
    $account = $this->socialAccount($this->tenant);
    $this->post(($this->url)('/posts'), ['body' => 'x', 'targets' => [['account_id' => $account->id]], 'media_ids' => [$theirs->id]])
        ->assertSessionHasErrors('media');

    expect(app(MediaLibrary::class))->not->toBeNull();
});

it('describes a file for accessibility', function () {
    $this->actingAsOwner($this->tenant);
    ($this->upload)(UploadedFile::fake()->image('a.png', 20, 20));
    $media = $this->inTenant($this->tenant, fn () => Media::query()->sole());

    $this->put(($this->url)("/media/{$media->id}"), ['alt_text' => 'A red square', 'name' => "Hero\x00 image.png"])->assertRedirect();

    $this->inTenant($this->tenant, fn () => expect(Media::query()->sole()->alt_text)->toBe('A red square')
        ->and(Media::query()->sole()->name)->toBe('Hero image.png'));
});

it('validates the size against the configured limit for the file\'s own kind, as the page states it', function () {
    config(['social.media.image_max_kb' => 100, 'social.media.video_max_kb' => 1000]);
    $this->actingAsOwner($this->tenant);

    // An image over the image limit is refused by the request itself.
    ($this->upload)(UploadedFile::fake()->image('big.jpg', 50, 50)->size(500))->assertSessionHasErrors('files.0');

    // A video of the same size is judged against the video limit, so the request lets it through
    // (the library then verifies its content).
    ($this->upload)(UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4'))->assertSessionDoesntHaveErrors('files.0');

    // And a video over its own limit is refused.
    ($this->upload)(UploadedFile::fake()->create('long.mp4', 1500, 'video/mp4'))->assertSessionHasErrors('files.0');
});
