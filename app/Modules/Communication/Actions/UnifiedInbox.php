<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Access\AccessChecker;
use App\Core\Communication\Contracts\InboxSource;
use App\Core\Communication\Inbox\InboxCursor;
use App\Core\Communication\Inbox\InboxItem;
use App\Core\Communication\Inbox\InboxQuery;
use App\Core\Communication\Inbox\InboxState;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * The unified inbox (ADR 0020, decision 4): merges the conversations of every source the member may use, newest
 * first. It owns no conversation; each source reads and narrows its own.
 *
 * Paging keeps one position per source, so a later page continues every source exactly where it stopped.
 */
final class UnifiedInbox
{
    private const string AT_FORMAT = 'Y-m-d H:i:s';

    /** @var array<string, InboxSource>|null */
    private ?array $sources = null;

    public function __construct(
        private readonly Container $container,
        private readonly AccessChecker $access,
        private readonly EntitlementChecker $entitlements,
        private readonly TenantContext $context,
    ) {}

    /** @return array<string, InboxSource> */
    public function all(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }

        $sources = [];

        foreach ($this->container->tagged(InboxSource::TAG) as $source) {
            if (! $source instanceof InboxSource) {
                continue;
            }

            $key = $source->definition()->key;

            if (isset($sources[$key])) {
                throw new LogicException("Duplicate inbox source [{$key}].");
            }

            $sources[$key] = $source;
        }

        ksort($sources);

        return $this->sources = $sources;
    }

    /** @return array<string, InboxSource> */
    public function availableFor(User $user): array
    {
        $tenant = $this->context->require();

        return array_filter($this->all(), function (InboxSource $source) use ($user, $tenant): bool {
            $definition = $source->definition();

            return $this->entitlements->allows($tenant, $definition->module) && $this->access->allows($user, $definition->permission);
        });
    }

    /**
     * One page of the merged inbox and the position of the next one (null when there is none).
     *
     * @param  array<string, InboxSource>  $sources
     * @return array{items: list<array{source: string, item: InboxItem}>, next: string|null}
     *
     * @throws ValidationException when the position was not issued for these sources
     */
    public function page(array $sources, InboxQuery $query, ?string $after = null): array
    {
        $positions = $this->decode($after, array_keys($sources));
        $candidates = [];

        foreach ($sources as $key => $source) {
            foreach ($source->items($query, $positions[$key] ?? null) as $item) {
                $candidates[] = ['source' => $key, 'item' => $item];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => [$b['item']->at->getTimestamp(), $a['source'], $b['item']->id]
            <=> [$a['item']->at->getTimestamp(), $b['source'], $a['item']->id]);

        $page = array_slice($candidates, 0, $query->limit);

        foreach ($page as $entry) {
            $positions[$entry['source']] = new InboxCursor($entry['item']->at, $entry['item']->id);
        }

        return ['items' => $page, 'next' => count($candidates) > $query->limit ? $this->encode($positions) : null];
    }

    /**
     * @param  array<string, InboxSource>  $sources
     * @return array<value-of<InboxState>, int>
     */
    public function counts(array $sources, InboxQuery $query): array
    {
        $totals = array_fill_keys(array_map(static fn (InboxState $state): string => $state->value, InboxState::cases()), 0);

        foreach ($sources as $source) {
            foreach ($source->counts($query) as $state => $count) {
                $totals[$state] += $count;
            }
        }

        return $totals;
    }

    /** @param  array<string, InboxCursor>  $positions */
    private function encode(array $positions): string
    {
        $plain = array_map(static fn (InboxCursor $cursor): array => [$cursor->at->utc()->format(self::AT_FORMAT), $cursor->id], $positions);

        return rtrim(strtr(base64_encode((string) json_encode($plain, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, InboxCursor>
     */
    private function decode(?string $after, array $keys): array
    {
        if ($after === null || $after === '') {
            return [];
        }

        $invalid = ValidationException::withMessages(['after' => __('This page of the inbox is no longer valid. Start again from the first page.')]);

        try {
            $plain = json_decode((string) base64_decode(strtr($after, '-_', '+/'), true), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw $invalid;
        }

        if (! is_array($plain)) {
            throw $invalid;
        }

        $positions = [];

        foreach ($plain as $key => $value) {
            if (! in_array($key, $keys, true) || ! is_array($value) || count($value) !== 2 || ! is_string($value[0] ?? null) || ! is_string($value[1] ?? null)
                || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $value[1]) !== 1) {
                throw $invalid;
            }

            $at = CarbonImmutable::createFromFormat(self::AT_FORMAT, $value[0], 'UTC');

            if (! $at instanceof CarbonImmutable || $at->format(self::AT_FORMAT) !== $value[0]) {
                throw $invalid;
            }

            $positions[$key] = new InboxCursor($at, $value[1]);
        }

        return $positions;
    }
}
