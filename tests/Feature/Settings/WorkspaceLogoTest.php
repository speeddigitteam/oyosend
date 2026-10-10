<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/*
 * The workspace's logo: a private file in the tenant's own directory, shown
 * through a short-lived URL.
 */
beforeEach(function () {
    $this->disk = Storage::fake('local');

    $this->acme = $this->createTenant('acme', ['name' => 'Acme']);
    $this->globex = $this->createTenant('globex', ['name' => 'Globex']);

    // Both sides of `core.settings.manage`.
    $this->createRole($this->acme, 'Office manager', ['core.settings.manage']);
    $this->createRole($this->acme, 'Clerk', ['core.members.view']);

    $this->manager = $this->addMember($this->acme, 'Office manager');
    $this->clerk = $this->addMember($this->acme, 'Clerk');

    $this->logo = fn (string $name = 'logo.png'): UploadedFile => UploadedFile::fake()->image($name, 200, 200);

    /** Where the workspace's logo is on the disk, or null. */
    $this->fileOf = fn ($tenant): ?string => $tenant->refresh()->logo_path === null
        ? null
        : "tenants/{$tenant->id}/{$tenant->logo_path}";

    /** The `tenant.logoUrl` every page of the workspace is given, as this person sees it. */
    $this->sharedLogoUrl = fn ($tenant, $user): ?string => $this->startNewBrowserSession()
        ->actingAsMember($tenant, $user)
        ->get($this->tenantUrl($tenant, 'settings/profile'))
        ->assertOk()
        ->inertiaProps('tenant.logoUrl');
});

describe('uploading', function () {
    it('stores the file in the workspace\'s own directory and records it', function () {
        $this->actingAsMember($this->acme, $this->manager)
            ->from('http://acme.localhost/settings/workspace')
            ->post('http://acme.localhost/settings/workspace/logo', ['logo' => ($this->logo)()])
            ->assertRedirect('http://acme.localhost/settings/workspace')
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $path = $this->acme->refresh()->logo_path;

        // A generated name under the tenant-relative `branding` folder: neither the upload's name nor the tenant id.
        expect($path)->toMatch('#^branding/[A-Za-z0-9]{40}\.png$#');

        $this->disk->assertExists("tenants/{$this->acme->id}/{$path}");

        expect($this->disk->allFiles())->toBe(["tenants/{$this->acme->id}/{$path}"]);

        $entry = $this->auditTrail($this->acme, 'workspace.logo_updated')->sole();

        expect($entry->actor_id)->toBe($this->manager->id)
            ->and($entry->subject_type)->toBe('tenant')
            ->and($entry->subject_id)->toBe($this->acme->id);
    });

    it('accepts the image types the page offers', function (string $name, string $extension) {
        $this->actingAsMember($this->acme, $this->manager)
            ->post('http://acme.localhost/settings/workspace/logo', ['logo' => ($this->logo)($name)])
            ->assertSessionHasNoErrors();

        expect($this->acme->refresh()->logo_path)->toEndWith('.'.$extension);

        $this->disk->assertExists(($this->fileOf)($this->acme));
    })->with([
        'PNG' => ['logo.png', 'png'],
        'JPG' => ['logo.jpg', 'jpg'],
        'JPEG' => ['logo.jpeg', 'jpg'],
        'WebP' => ['logo.webp', 'webp'],
    ]);

    it('accepts a file of exactly one megabyte and an image of 2000 pixels a side', function () {
        $this->actingAsMember($this->acme, $this->manager)
            ->post('http://acme.localhost/settings/workspace/logo', ['logo' => UploadedFile::fake()->image('logo.png', 2000, 2000)->size(1024)])
            ->assertSessionHasNoErrors();

        expect(($this->fileOf)($this->acme))->not->toBeNull();
    });

    it('replaces the logo and deletes the old file', function () {
        $this->actingAsMember($this->acme, $this->manager);

        $this->post('http://acme.localhost/settings/workspace/logo', ['logo' => ($this->logo)()]);
        $first = ($this->fileOf)($this->acme);

        $this->post('http://acme.localhost/settings/workspace/logo', ['logo' => ($this->logo)('new.jpg')])
            ->assertSessionHasNoErrors();
        $second = ($this->fileOf)($this->acme);

        expect($second)->not->toBe($first)->toEndWith('.jpg');

        $this->disk->assertMissing($first);
        $this->disk->assertExists($second);

        expect($this->disk->allFiles())->toBe([$second])
            ->and($this->auditTrail($this->acme, 'workspace.logo_updated'))->toHaveCount(2)
            ->and($this->auditTrail($this->acme, 'workspace.logo_removed'))->toBeEmpty();
    });
});

describe('removing', function () {
    it('deletes the file, forgets the logo and records it', function () {
        $this->actingAsMember($this->acme, $this->manager);

        $this->post('http://acme.localhost/settings/workspace/logo', ['logo' => ($this->logo)()]);
        $file = ($this->fileOf)($this->acme);

        $this->from('http://acme.localhost/settings/workspace')
            ->delete('http://acme.localhost/settings/workspace/logo')
            ->assertRedirect('http://acme.localhost/settings/workspace')
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        expect($this->acme->refresh()->logo_path)->toBeNull()
            ->and($this->disk->allFiles())->toBe([]);

        $this->disk->assertMissing($file);

        $entry = $this->auditTrail($this->acme, 'workspace.logo_removed')->sole();

        expect($entry->actor_id)->toBe($this->manager->id)
            ->and($entry->subject_id)->toBe($this->acme->id);
    });

    it('has nothing to do, and nothing to record, when there is no logo', function () {
        $this->actingAsMember($this->acme, $this->manager)
            ->delete('http://acme.localhost/settings/workspace/logo')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect($this->acme->refresh()->logo_path)->toBeNull()
            ->and($this->auditTrail($this->acme, 'workspace.logo_removed'))->toBeEmpty();
    });
});

describe('validation', function () {
    it('refuses a file that is not an acceptable logo and keeps the one there is', function (Closure $upload) {
        $this->actingAsMember($this->acme, $this->manager);

        $this->post('http://acme.localhost/settings/workspace/logo', ['logo' => ($this->logo)()]);
        $current = ($this->fileOf)($this->acme);

        $this->post('http://acme.localhost/settings/workspace/logo', ['logo' => $upload()])
            ->assertSessionHasErrors('logo');

        expect(($this->fileOf)($this->acme))->toBe($current)
            ->and($this->disk->allFiles())->toBe([$current])
            ->and($this->auditTrail($this->acme, 'workspace.logo_updated'))->toHaveCount(1);
    })->with([
        // SVG can carry script, so it is refused whatever it is called.
        'an SVG' => [fn () => UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><script>alert(1)</script></svg>',
        )],
        'a PDF' => [fn () => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf')],
        'a GIF' => [fn () => UploadedFile::fake()->image('logo.gif', 200, 200)],
        'a file larger than one megabyte' => [fn () => UploadedFile::fake()->image('logo.png', 200, 200)->size(1025)],
        'an image wider than 2000 pixels' => [fn () => UploadedFile::fake()->image('logo.png', 2001, 200)],
        'an image taller than 2000 pixels' => [fn () => UploadedFile::fake()->image('logo.png', 200, 2001)],
        'a script that calls itself a PNG' => [fn () => UploadedFile::fake()->createWithContent('logo.png', '<?php echo "not an image";')],
        'an SVG that calls itself a PNG' => [fn () => UploadedFile::fake()->createWithContent(
            'logo.png',
            '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"></svg>',
        )],
        'text instead of a file' => [fn () => 'logo.png'],
        'several files' => [fn () => [UploadedFile::fake()->image('a.png', 200, 200), UploadedFile::fake()->image('b.png', 200, 200)]],
    ]);

    it('needs a file', function () {
        $this->actingAsMember($this->acme, $this->manager)
            ->post('http://acme.localhost/settings/workspace/logo', [])
            ->assertSessionHasErrors('logo');

        expect($this->disk->allFiles())->toBe([]);
    });
});

describe('showing it', function () {
    it('gives every page of the workspace a URL for the logo, and null without one', function () {
        expect(($this->sharedLogoUrl)($this->acme, $this->clerk))->toBeNull();

        $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $this->manager)
            ->post('http://acme.localhost/settings/workspace/logo', ['logo' => ($this->logo)()]);

        $file = ($this->fileOf)($this->acme);

        // Everyone in the workspace sees it, not only whoever may change it.
        foreach ([$this->manager, $this->clerk] as $member) {
            $url = ($this->sharedLogoUrl)($this->acme, $member);

            expect($url)->toBeString()
                ->and(filter_var($url, FILTER_VALIDATE_URL))->not->toBeFalse()
                ->and($url)->toContain($file);
        }

        $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $this->manager)
            ->delete('http://acme.localhost/settings/workspace/logo');

        expect(($this->sharedLogoUrl)($this->acme, $this->clerk))->toBeNull();
    });

    it('shows the same logo on the settings page', function () {
        $this->actingAsMember($this->acme, $this->manager);

        $this->get('http://acme.localhost/settings/workspace')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('workspace.logoUrl', null)->where('tenant.logoUrl', null));

        $this->post('http://acme.localhost/settings/workspace/logo', ['logo' => ($this->logo)()]);

        $file = ($this->fileOf)($this->acme);

        $this->get('http://acme.localhost/settings/workspace')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('workspace.logoUrl', fn ($url): bool => is_string($url) && str_contains($url, $file))
                ->where('tenant.logoUrl', fn ($url): bool => is_string($url) && str_contains($url, $file)));
    });

    it('hands out a signed URL that opens the file, and only for an hour', function () {
        // Not the fake's stand-in URL here: the real local disk and its signed route, kept in the fake's directory.
        Storage::forgetDisk('local');
        config(['filesystems.disks.local.root' => $this->disk->path('')]);

        $this->actingAsMember($this->acme, $this->manager)
            ->post('http://acme.localhost/settings/workspace/logo', ['logo' => ($this->logo)()])
            ->assertSessionHasNoErrors();

        $url = $this->get('http://acme.localhost/settings/profile')->inertiaProps('tenant.logoUrl');

        expect($url)->toBeString()
            ->toStartWith('http://acme.localhost/')
            ->toContain(($this->fileOf)($this->acme), 'signature=', 'expires=');

        // The browser asks for an image without any session of its own.
        $this->startNewBrowserSession()->get($url)->assertOk();

        // The framework answers 403 outside production and 404 in it.
        $refused = fn (string $address) => expect($this->get($address)->getStatusCode())->toBeIn([403, 404]);

        // Changed in any way, it opens nothing ...
        $refused(preg_replace('/signature=\w{4}/', 'signature=0000', $url));
        $refused(strtok($url, '?'));

        // ... and after an hour neither does the real one.
        $this->travel(61)->minutes();

        $refused($url);
    });
});

describe('isolation', function () {
    it('never shows a workspace another workspace\'s logo', function () {
        $this->actingAsMember($this->acme, $this->manager)
            ->post('http://acme.localhost/settings/workspace/logo', ['logo' => ($this->logo)()]);

        $acmeFile = ($this->fileOf)($this->acme);

        // Globex has none, whatever Acme has ...
        expect(($this->sharedLogoUrl)($this->globex, $this->ownerOf($this->globex)))->toBeNull()
            ->and($this->globex->refresh()->logo_path)->toBeNull();

        $this->startNewBrowserSession()
            ->actingAsOwner($this->globex)
            ->get('http://globex.localhost/settings/workspace')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('workspace.logoUrl', null));

        // ... and its own, once it has one, lives in its own directory.
        $this->post('http://globex.localhost/settings/workspace/logo', ['logo' => ($this->logo)()])
            ->assertSessionHasNoErrors();

        $globexFile = ($this->fileOf)($this->globex);

        expect($globexFile)->toStartWith("tenants/{$this->globex->id}/branding/")
            ->and($acmeFile)->toStartWith("tenants/{$this->acme->id}/branding/")
            ->and(($this->sharedLogoUrl)($this->globex, $this->ownerOf($this->globex)))->toContain($globexFile)->not->toContain($this->acme->id)
            ->and(($this->sharedLogoUrl)($this->acme, $this->clerk))->toContain($acmeFile)->not->toContain($this->globex->id);
    });

    it('removes only its own logo', function () {
        $this->actingAsMember($this->acme, $this->manager)
            ->post('http://acme.localhost/settings/workspace/logo', ['logo' => ($this->logo)()]);
        $this->startNewBrowserSession()
            ->actingAsOwner($this->globex)
            ->post('http://globex.localhost/settings/workspace/logo', ['logo' => ($this->logo)()]);

        $acmeFile = ($this->fileOf)($this->acme);

        $this->delete('http://globex.localhost/settings/workspace/logo')->assertSessionHasNoErrors();

        expect($this->globex->refresh()->logo_path)->toBeNull()
            ->and($this->disk->allFiles())->toBe([$acmeFile])
            ->and(($this->fileOf)($this->acme))->toBe($acmeFile)
            ->and($this->auditTrail($this->acme, 'workspace.logo_removed'))->toBeEmpty()
            ->and($this->auditTrail($this->globex, 'workspace.logo_removed'))->toHaveCount(1);
    });

    it('does not take a session of one workspace as a key to another\'s logo', function () {
        $this->actingAsMember($this->acme, $this->manager);

        $this->post('http://globex.localhost/settings/workspace/logo', ['logo' => ($this->logo)()])
            ->assertRedirect('http://globex.localhost/login');
        $this->delete('http://globex.localhost/settings/workspace/logo')
            ->assertRedirect('http://globex.localhost/login');

        expect($this->disk->allFiles())->toBe([])
            ->and($this->globex->refresh()->logo_path)->toBeNull();
    });
});

describe('permission', function () {
    it('lets someone who holds core.settings.manage upload and remove the logo', function () {
        $this->actingAsMember($this->acme, $this->manager);

        $this->post('http://acme.localhost/settings/workspace/logo', ['logo' => ($this->logo)()])->assertRedirect()->assertSessionHasNoErrors();

        expect($this->acme->refresh()->logo_path)->not->toBeNull();

        $this->delete('http://acme.localhost/settings/workspace/logo')->assertRedirect()->assertSessionHasNoErrors();

        expect($this->acme->refresh()->logo_path)->toBeNull();
    });

    it('refuses someone who does not hold it', function () {
        // A logo to try to remove.
        $this->actingAsMember($this->acme, $this->manager)
            ->post('http://acme.localhost/settings/workspace/logo', ['logo' => ($this->logo)()]);
        $file = ($this->fileOf)($this->acme);

        $this->startNewBrowserSession()->actingAsMember($this->acme, $this->clerk);

        $this->post('http://acme.localhost/settings/workspace/logo', ['logo' => ($this->logo)('other.png')])->assertForbidden();
        $this->delete('http://acme.localhost/settings/workspace/logo')->assertForbidden();

        expect(($this->fileOf)($this->acme))->toBe($file)
            ->and($this->disk->allFiles())->toBe([$file])
            ->and($this->auditTrail($this->acme, 'workspace.logo_updated'))->toHaveCount(1)
            ->and($this->auditTrail($this->acme, 'workspace.logo_removed'))->toBeEmpty();
    });

    it('is only for signed-in members', function () {
        $this->post('http://acme.localhost/settings/workspace/logo', ['logo' => ($this->logo)()])
            ->assertRedirect('http://acme.localhost/login');
        $this->delete('http://acme.localhost/settings/workspace/logo')
            ->assertRedirect('http://acme.localhost/login');

        expect($this->disk->allFiles())->toBe([]);
    });
});
