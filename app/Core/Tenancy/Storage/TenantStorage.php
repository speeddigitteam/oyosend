<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Storage;

use App\Core\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * The only way tenant files are read or written.
 *
 * Every path is forced under `tenants/{tenant_id}/` on a private disk, so a
 * tenant cannot name its way into another tenant's files. There is no active
 * tenant fallback: without one, every method throws.
 */
final class TenantStorage
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * The absolute (disk-relative) path for a tenant-relative path.
     */
    public function path(string $path = ''): string
    {
        return $this->root().($path === '' ? '' : '/'.$this->normalize($path));
    }

    public function put(string $path, string $contents): bool
    {
        return $this->disk()->put($this->path($path), $contents);
    }

    /**
     * Store an upload under a generated name; returns the tenant-relative path.
     */
    public function putFile(string $directory, UploadedFile $file): string
    {
        $stored = $this->disk()->putFile($this->path($directory), $file);

        if ($stored === false) {
            throw new InvalidArgumentException('The uploaded file could not be stored.');
        }

        return ltrim(substr($stored, strlen($this->root())), '/');
    }

    public function get(string $path): ?string
    {
        return $this->disk()->get($this->path($path));
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($this->path($path));
    }

    public function delete(string $path): bool
    {
        return $this->disk()->delete($this->path($path));
    }

    /**
     * A short-lived signed URL. Files are never exposed by a public path.
     */
    public function temporaryUrl(string $path, DateTimeInterface $expiration): string
    {
        $disk = $this->disk();

        if (! $disk instanceof FilesystemAdapter) {
            throw new InvalidArgumentException('The tenant disk cannot generate temporary URLs.');
        }

        return $disk->temporaryUrl($this->path($path), $expiration);
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('tenancy.filesystem.disk', 'local'));
    }

    private function root(): string
    {
        return trim((string) config('tenancy.filesystem.root', 'tenants'), '/').'/'.$this->context->requireId();
    }

    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Invalid file path.');
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw new InvalidArgumentException('File paths may not traverse outside the tenant directory.');
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }
}
