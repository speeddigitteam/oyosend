<?php

declare(strict_types=1);

namespace App\Core\Billing;

use App\Core\Billing\Enums\InvoiceStatus;
use App\Core\Billing\Enums\LimitKind;
use App\Core\Billing\Models\Invoice;
use App\Core\Billing\Models\Subscription;
use App\Core\Modules\LimitDefinition;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * What the plan of the active workspace allows, and how much of it is used.
 *
 * A limit comes from the plan (`plans.limits`). A plan that does not mention
 * a limit, and a workspace without a subscription, are not limited.
 */
final class UsageLimits
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly UsageMeter $meter,
        private readonly CurrentSubscription $current,
    ) {}

    /**
     * The maximum for a limit, or null when there is none.
     */
    public function limit(string $key): ?int
    {
        $this->definition($key);

        return $this->subscription()?->plan->limit($key);
    }

    /**
     * How much is used: what exists now for a gauge, what was consumed in the
     * current billing period for a metered limit.
     */
    public function used(string $key): int
    {
        $definition = $this->definition($key);

        if ($definition->kind === LimitKind::Gauge) {
            return $definition->count === null ? 0 : max(0, ($definition->count)());
        }

        return $this->meter->used($key, $this->periodStart());
    }

    /**
     * What is left, or null when there is no limit.
     */
    public function remaining(string $key): ?int
    {
        $limit = $this->limit($key);

        return $limit === null ? null : max(0, $limit - $this->used($key));
    }

    public function allows(string $key, int $more = 1): bool
    {
        $remaining = $this->remaining($key);

        return $remaining === null || $remaining >= $more;
    }

    /**
     * Refuse to go over the plan's limit.
     *
     * @param  string  $field  The form field the refusal is reported on.
     *
     * @throws ValidationException
     */
    public function ensure(string $key, int $more = 1, string $field = 'limit'): void
    {
        if (! $this->allows($key, $more)) {
            throw ValidationException::withMessages([
                $field => __('Your plan\'s limit has been reached. Upgrade the plan to add more.'),
            ]);
        }
    }

    /**
     * Every registered limit with its maximum and current use, for the billing page.
     *
     * @return list<array{key: string, kind: string, limit: int|null, used: int}>
     */
    public function overview(): array
    {
        return array_values(array_map(fn (LimitDefinition $definition): array => [
            'key' => $definition->key,
            'kind' => $definition->kind->value,
            'limit' => $this->limit($definition->key),
            'used' => $this->used($definition->key),
        ], $this->modules->limits()));
    }

    private function definition(string $key): LimitDefinition
    {
        return $this->modules->findLimit($key) ?? throw new InvalidArgumentException("Unknown limit [{$key}].");
    }

    /**
     * Metered use is counted from the start of the billing period, or of the
     * calendar month for a plan without one.
     */
    private function periodStart(): Carbon
    {
        $subscription = $this->subscription();
        $start = $subscription?->current_period_start;

        if ($subscription === null || $start === null || $subscription->current_period_end === null) {
            return Carbon::now()->startOfMonth();
        }

        if (! $start->isFuture()) {
            return $start;
        }

        // A renewal paid early: the period on record has not begun, and the one
        // before it is still running. Paying early buys no second allowance.
        $now = Carbon::now();

        /** @var Carbon|null $running */
        $running = Invoice::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('status', InvoiceStatus::Paid)
            ->where('period_start', '<=', $now)
            ->where('period_end', '>', $now)
            ->orderByDesc('period_start')
            ->first()
            ?->period_start;

        // Not bought with an invoice (a plan an operator gave): one interval back.
        return $running ?? $subscription->interval->subtractFrom($start);
    }

    private function subscription(): ?Subscription
    {
        return $this->current->get();
    }
}
