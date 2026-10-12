<?php

declare(strict_types=1);

namespace App\Core\Reporting;

use App\Core\Access\AccessChecker;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Identity\Models\User;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Discovers the tagged {@see ReportProvider}s and decides which of them a member may run: the
 * workspace must be entitled to the report's module AND the member must hold its permission.
 * Both are read from the database; nothing comes from the request.
 */
final class ReportRegistry
{
    /** @var array<string, ReportProvider>|null */
    private ?array $providers = null;

    public function __construct(
        private readonly Container $container,
        private readonly AccessChecker $access,
        private readonly EntitlementChecker $entitlements,
        private readonly TenantContext $context,
    ) {}

    /**
     * Every registered provider by report key, regardless of entitlement or permission.
     *
     * @return array<string, ReportProvider>
     */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $providers = [];

        foreach ($this->container->tagged(ReportProvider::TAG) as $provider) {
            if (! $provider instanceof ReportProvider) {
                continue;
            }

            $key = $provider->definition()->key;

            if (isset($providers[$key])) {
                throw new LogicException("Duplicate report [{$key}].");
            }

            $providers[$key] = $provider;
        }

        ksort($providers);

        return $this->providers = $providers;
    }

    /**
     * The providers this member may run right now.
     *
     * @return array<string, ReportProvider>
     */
    public function availableFor(User $user): array
    {
        $tenant = $this->context->require();
        $entitled = [];

        return array_filter($this->all(), function (ReportProvider $provider) use ($user, $tenant, &$entitled): bool {
            $definition = $provider->definition();
            $entitled[$definition->module] ??= $this->entitlements->allows($tenant, $definition->module);

            return $entitled[$definition->module] && $this->access->allows($user, $definition->permission);
        });
    }

    public function find(string $key, User $user): ?ReportProvider
    {
        return $this->availableFor($user)[$key] ?? null;
    }
}
