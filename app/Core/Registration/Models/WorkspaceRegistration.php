<?php

declare(strict_types=1);

namespace App\Core\Registration\Models;

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Models\Plan;
use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A sign-up that is waiting for its email address to be confirmed. Platform
 * data: no tenant exists yet. The account and the workspace are only created
 * when the emailed link is confirmed.
 *
 * @property string $id
 * @property string $email
 * @property string $name
 * @property string $password
 * @property string $company
 * @property string $slug
 * @property string $locale
 * @property string|null $plan_id
 * @property BillingInterval|null $interval
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $completed_at
 * @property string|null $tenant_id
 * @property string|null $ip_address
 * @property-read Plan|null $plan
 */
class WorkspaceRegistration extends Model
{
    use HasUlids;
    use Prunable;
    use UsesCentralConnection;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email', 'name', 'password', 'company', 'slug', 'locale', 'plan_id', 'interval',
        'token_hash', 'expires_at', 'completed_at', 'tenant_id', 'ip_address',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['password', 'token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interval' => BillingInterval::class,
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Still waiting to be confirmed: its workspace address is reserved.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeWaiting(Builder $query): void
    {
        $query->whereNull('completed_at')->where('expires_at', '>', Carbon::now());
    }

    public function isWaiting(): bool
    {
        return $this->completed_at === null && $this->expires_at->isFuture();
    }

    /**
     * Expired sign-ups go after a day, finished ones after a month.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where(fn (Builder $expired) => $expired->whereNull('completed_at')->where('expires_at', '<', Carbon::now()->subDay()))
            ->orWhere('completed_at', '<', Carbon::now()->subMonth());
    }
}
