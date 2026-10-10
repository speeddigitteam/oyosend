<?php

declare(strict_types=1);

namespace App\Core\Settings;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Storage\TenantStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The workspace's logo: a private file on the tenant disk, shown through a
 * short-lived signed URL like every other tenant file.
 */
final class WorkspaceLogo
{
    /** Minutes a logo URL stays valid; pages ask for a fresh one on every visit. */
    private const int URL_TTL = 60;

    public function __construct(
        private readonly TenantStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Rules for an uploaded logo. SVG is refused on purpose: it can carry script.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        return ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000'];
    }

    public function replace(Tenant $tenant, UploadedFile $file): void
    {
        $previous = $tenant->logo_path;
        $path = $this->storage->putFile('branding', $file);

        DB::transaction(function () use ($tenant, $path): void {
            $tenant->update(['logo_path' => $path]);

            $this->audit->record('workspace.logo_updated', $tenant);
        });

        if ($previous !== null && $previous !== $path) {
            $this->storage->delete($previous);
        }
    }

    public function remove(Tenant $tenant): void
    {
        $previous = $tenant->logo_path;

        if ($previous === null) {
            return;
        }

        DB::transaction(function () use ($tenant): void {
            $tenant->update(['logo_path' => null]);

            $this->audit->record('workspace.logo_removed', $tenant);
        });

        $this->storage->delete($previous);
    }

    /**
     * A signed URL for the active workspace's logo, or null when it has none.
     */
    public function url(Tenant $tenant): ?string
    {
        if ($tenant->logo_path === null) {
            return null;
        }

        try {
            return $this->storage->temporaryUrl($tenant->logo_path, Carbon::now()->addMinutes(self::URL_TTL));
        } catch (Throwable) {
            // A disk that cannot sign URLs must not take every page down with it.
            return null;
        }
    }
}
