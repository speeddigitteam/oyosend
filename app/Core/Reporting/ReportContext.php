<?php

declare(strict_types=1);

namespace App\Core\Reporting;

use App\Core\Access\Enums\AccessScope;
use App\Core\Identity\Models\User;
use Carbon\CarbonImmutable;

/**
 * What a provider is run with. Everything here has been validated against the provider's own
 * {@see ReportDefinition}; the provider must still narrow every query to {@see $user}'s record
 * scope (`RecordAccess::constrain($query, $context->user, $definition->permission)`) when its
 * permission is scopable.
 */
final readonly class ReportContext
{
    /**
     * @param  AccessScope  $scope  How far the viewer's grant of the report's permission reaches.
     * @param  string  $from  Local date `Y-m-d` in the workspace timezone (inclusive).
     * @param  string  $to  Local date `Y-m-d` (inclusive).
     * @param  CarbonImmutable  $fromAt  Start of `$from` as a UTC instant.
     * @param  CarbonImmutable  $toAt  End of `$to` as a UTC instant.
     * @param  array<string, string|int|bool>  $filters  Declared filters the viewer set; unset ones are absent.
     * @param  int  $rowLimit  Providers should stop at `$rowLimit + 1` rows; the runner truncates and flags.
     */
    public function __construct(
        public User $user,
        public AccessScope $scope,
        public string $from,
        public string $to,
        public CarbonImmutable $fromAt,
        public CarbonImmutable $toAt,
        public string $timezone,
        public array $filters,
        public int $rowLimit,
    ) {}

    public function string(string $key): ?string
    {
        $value = $this->filters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** A currency filter, upper case, or null. */
    public function currency(string $key = 'currency'): ?string
    {
        $value = $this->string($key);

        return $value === null ? null : strtoupper($value);
    }

    public function integer(string $key): ?int
    {
        $value = $this->filters[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    public function flag(string $key): bool
    {
        return ($this->filters[$key] ?? false) === true;
    }
}
