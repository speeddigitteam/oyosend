<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Bootstrappers;

use App\Core\Tenancy\Models\Tenant as TenantModel;
use Illuminate\Routing\UrlGenerator;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Fills the `{tenant}` domain parameter for generated URLs, so `route()`
 * points at the active workspace in requests, queued mail and console alike.
 */
final class UrlDefaultsBootstrapper implements TenancyBootstrapper
{
    public function __construct(private readonly UrlGenerator $url) {}

    public function bootstrap(Tenant $tenant): void
    {
        if ($tenant instanceof TenantModel) {
            $this->url->defaults(['tenant' => $tenant->slug]);
        }
    }

    public function revert(): void
    {
        // UrlGenerator::defaults() merges, so an explicit null is how we clear it.
        $this->url->defaults(['tenant' => null]);
    }
}
