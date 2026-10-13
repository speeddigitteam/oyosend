<?php

declare(strict_types=1);

namespace App\Core\Contacts;

use App\Core\Access\AccessChecker;
use App\Core\Contacts\Contracts\CustomerRecordProvider;
use App\Core\Contacts\Models\Contact;
use App\Core\Contacts\Records\CustomerAction;
use App\Core\Contacts\Records\CustomerItem;
use App\Core\Contacts\Records\CustomerQuery;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Discovers Customer 360 sections and runs those a member may see (ADR 0020). It owns no data: each provider reads
 * its own module's records and narrows them for the member.
 */
final class CustomerRecordRegistry
{
    /** @var array<string, CustomerRecordProvider>|null */
    private ?array $providers = null;

    public function __construct(
        private readonly Container $container,
        private readonly AccessChecker $access,
        private readonly EntitlementChecker $entitlements,
        private readonly TenantContext $context,
    ) {}

    /** @return array<string, CustomerRecordProvider> keyed by section key, in display order */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $providers = [];

        foreach ($this->container->tagged(CustomerRecordProvider::TAG) as $provider) {
            if (! $provider instanceof CustomerRecordProvider) {
                continue;
            }

            $key = $provider->definition()->key;

            if (isset($providers[$key])) {
                throw new LogicException("Duplicate customer section [{$key}].");
            }

            $providers[$key] = $provider;
        }

        uasort($providers, fn (CustomerRecordProvider $a, CustomerRecordProvider $b): int => [$a->definition()->order, $a->definition()->key] <=> [$b->definition()->order, $b->definition()->key]);

        return $this->providers = $providers;
    }

    /** @return array<string, CustomerRecordProvider> */
    public function availableFor(User $user): array
    {
        $tenant = $this->context->require();
        $entitled = [];

        return array_filter($this->all(), function (CustomerRecordProvider $provider) use ($user, $tenant, &$entitled): bool {
            $definition = $provider->definition();
            $entitled[$definition->module] ??= $this->entitlements->allows($tenant, $definition->module);

            return $entitled[$definition->module] && $this->access->allows($user, $definition->permission);
        });
    }

    /**
     * The sections a member may see about a contact they are already allowed to view.
     *
     * @return list<array{key: string, module: string, label: string, items: list<array<string, string|null>>, total: int, viewAllUrl: string|null, actions: list<array{key: string, label: string, url: string}>}>
     */
    public function sectionsFor(User $user, Contact $contact, int $limit = CustomerQuery::DEFAULT_LIMIT): array
    {
        $query = new CustomerQuery($user, $contact, max(1, min($limit, CustomerQuery::MAX_LIMIT)));
        $sections = [];

        foreach ($this->availableFor($user) as $provider) {
            $section = $provider->forContact($query);

            if ($section === null) {
                continue;
            }

            $definition = $provider->definition();
            $sections[] = [
                'key' => $definition->key,
                'module' => $definition->module,
                'label' => __($definition->label),
                'items' => array_map(static fn (CustomerItem $item): array => $item->toArray(), array_slice($section->items, 0, $query->limit)),
                'total' => max($section->total, count($section->items)),
                'viewAllUrl' => $section->viewAllUrl,
                'actions' => array_map(static fn (CustomerAction $action): array => ['key' => $action->key, 'label' => __($action->label), 'url' => $action->url], $section->actions),
            ];
        }

        return $sections;
    }
}
