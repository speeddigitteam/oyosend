<?php

declare(strict_types=1);

use App\Core\Tenancy\Exceptions\MissingTenantContextException;
use App\Core\Tenancy\Storage\TenantStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->disk = Storage::fake('local');

    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');
    $this->storage = fn (): TenantStorage => app(TenantStorage::class);
});

it('stores every file under the active tenant\'s own directory', function () {
    $this->inTenant($this->acme, function (): void {
        expect(($this->storage)()->put('invoices/2026/a.pdf', 'acme invoice'))->toBeTrue()
            ->and(($this->storage)()->path('invoices/2026/a.pdf'))->toBe("tenants/{$this->acme->id}/invoices/2026/a.pdf")
            ->and(($this->storage)()->path())->toBe("tenants/{$this->acme->id}");
    });

    $this->disk->assertExists("tenants/{$this->acme->id}/invoices/2026/a.pdf");

    expect($this->disk->allFiles())->toBe(["tenants/{$this->acme->id}/invoices/2026/a.pdf"]);
});

it('reads, checks and deletes only inside the active tenant', function () {
    $this->inTenant($this->acme, fn () => ($this->storage)()->put('notes.txt', 'acme notes'));

    $this->inTenant($this->globex, function (): void {
        $storage = ($this->storage)();

        // Same relative path, another tenant: it is simply not there.
        expect($storage->exists('notes.txt'))->toBeFalse()
            ->and($storage->get('notes.txt'))->toBeNull();

        $storage->delete('notes.txt');
        $storage->put('notes.txt', 'globex notes');
    });

    $this->inTenant($this->acme, function (): void {
        $storage = ($this->storage)();

        expect($storage->exists('notes.txt'))->toBeTrue()
            ->and($storage->get('notes.txt'))->toBe('acme notes')
            ->and($storage->delete('notes.txt'))->toBeTrue()
            ->and($storage->exists('notes.txt'))->toBeFalse();
    });

    expect($this->inTenant($this->globex, fn () => ($this->storage)()->get('notes.txt')))->toBe('globex notes');
});

it('rejects any path that tries to leave the tenant directory', function (string $path) {
    // Something worth stealing next door.
    $this->inTenant($this->globex, fn () => ($this->storage)()->put('secret.txt', 'globex secret'));

    $this->inTenant($this->acme, function () use ($path): void {
        $storage = ($this->storage)();

        expect(fn () => $storage->path($path))->toThrow(InvalidArgumentException::class);
        expect(fn () => $storage->get($path))->toThrow(InvalidArgumentException::class);
        expect(fn () => $storage->exists($path))->toThrow(InvalidArgumentException::class);
        expect(fn () => $storage->put($path, 'overwritten'))->toThrow(InvalidArgumentException::class);
        expect(fn () => $storage->delete($path))->toThrow(InvalidArgumentException::class);
        expect(fn () => $storage->temporaryUrl($path, now()->addMinute()))->toThrow(InvalidArgumentException::class);
        expect(fn () => $storage->putFile($path, UploadedFile::fake()->create('a.txt', 1)))->toThrow(InvalidArgumentException::class);
    });

    expect($this->inTenant($this->globex, fn () => ($this->storage)()->get('secret.txt')))->toBe('globex secret')
        ->and($this->disk->allFiles())->toHaveCount(1);
})->with([
    'parent directory' => ['../secret.txt'],
    'sibling tenant by id' => fn () => '../'.$this->globex->id.'/secret.txt',
    'climbing out from a subdirectory' => ['docs/../../secret.txt'],
    'deep climb' => ['a/b/../../../../etc/passwd'],
    'windows separators' => ['..\\secret.txt'],
    'mixed separators' => ['docs\\..\\../secret.txt'],
    'trailing parent' => ['docs/..'],
    'only parent' => ['..'],
    'null byte' => ["notes.txt\0.jpg"],
]);

it('normalises harmless path noise instead of rejecting it', function (string $given, string $expected) {
    $this->inTenant($this->acme, function () use ($given, $expected): void {
        expect(($this->storage)()->path($given))->toBe("tenants/{$this->acme->id}/{$expected}");
    });
})->with([
    'leading slash' => ['/etc/passwd', 'etc/passwd'],
    'current directory' => ['./docs/./a.txt', 'docs/a.txt'],
    'doubled slashes' => ['docs//a.txt', 'docs/a.txt'],
    'backslashes' => ['docs\\a.txt', 'docs/a.txt'],
    'dots inside a name' => ['docs/..hidden/a..b.txt', 'docs/..hidden/a..b.txt'],
]);

it('stores an upload under a generated name and returns the tenant-relative path', function () {
    $relative = $this->inTenant(
        $this->acme,
        fn (): string => ($this->storage)()->putFile('avatars', UploadedFile::fake()->create('me.png', 4)),
    );

    expect($relative)->toStartWith('avatars/')->not->toContain($this->acme->id);

    $this->disk->assertExists("tenants/{$this->acme->id}/{$relative}");

    expect($this->inTenant($this->acme, fn (): bool => ($this->storage)()->exists($relative)))->toBeTrue()
        ->and($this->inTenant($this->globex, fn (): bool => ($this->storage)()->exists($relative)))->toBeFalse();
});

it('only hands out temporary URLs for the active tenant\'s files', function () {
    $url = $this->inTenant($this->acme, function (): string {
        ($this->storage)()->put('report.pdf', 'x');

        return ($this->storage)()->temporaryUrl('report.pdf', now()->addMinutes(5));
    });

    expect($url)->toContain("tenants/{$this->acme->id}/report.pdf");
});

it('refuses to work without a tenant', function () {
    $storage = ($this->storage)();

    expect(fn () => $storage->path('a.txt'))->toThrow(MissingTenantContextException::class);
    expect(fn () => $storage->put('a.txt', 'x'))->toThrow(MissingTenantContextException::class);
    expect(fn () => $storage->get('a.txt'))->toThrow(MissingTenantContextException::class);
    expect(fn () => $storage->exists('a.txt'))->toThrow(MissingTenantContextException::class);
    expect(fn () => $storage->delete('a.txt'))->toThrow(MissingTenantContextException::class);
    expect(fn () => $storage->putFile('docs', UploadedFile::fake()->create('a.txt', 1)))->toThrow(MissingTenantContextException::class);
    expect(fn () => $storage->temporaryUrl('a.txt', now()->addMinute()))->toThrow(MissingTenantContextException::class);

    expect($this->disk->allFiles())->toBe([]);
});

it('follows the tenant when the context switches under a resolved instance', function () {
    // One instance held across a switch (a long-lived service) must not keep writing to the first tenant.
    $storage = ($this->storage)();

    $this->inTenant($this->acme, fn () => $storage->put('a.txt', 'acme'));
    $this->inTenant($this->globex, fn () => $storage->put('a.txt', 'globex'));

    expect($this->disk->get("tenants/{$this->acme->id}/a.txt"))->toBe('acme')
        ->and($this->disk->get("tenants/{$this->globex->id}/a.txt"))->toBe('globex');
});
