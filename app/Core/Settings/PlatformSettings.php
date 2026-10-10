<?php

declare(strict_types=1);

namespace App\Core\Settings;

use App\Core\Settings\Enums\RegistrationMode;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Platform-wide settings the operator can change from the Super Admin panel.
 *
 * Each one falls back to its default in `config/platform.php`; only what was
 * changed is stored (`platform_settings`). Platform data, so it is cached in
 * the central cache, never in a tenant's.
 */
final class PlatformSettings
{
    /** Setting key => config key holding its default. */
    public const array KEYS = [
        'registration.mode' => 'platform.registration.mode',
        'billing.grace_days' => 'platform.billing.grace_days',
        'billing.invoice_lead_days' => 'platform.billing.invoice_lead_days',
        'billing.offline_instructions' => 'platform.billing.offline_instructions',
    ];

    private const string CACHE_KEY = 'platform:settings';

    /** @var array<string, mixed>|null per-request memo */
    private ?array $stored = null;

    public function __construct(private readonly Cache $cache) {}

    public function get(string $key): mixed
    {
        $default = self::KEYS[$key] ?? throw new InvalidArgumentException("Unknown platform setting [{$key}].");
        $stored = $this->stored();

        return array_key_exists($key, $stored) ? $stored[$key] : config($default);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function put(array $values): void
    {
        $now = Carbon::now();
        $rows = [];

        foreach ($values as $key => $value) {
            if (! isset(self::KEYS[$key])) {
                throw new InvalidArgumentException("Unknown platform setting [{$key}].");
            }

            $rows[] = ['key' => $key, 'value' => json_encode($value), 'created_at' => $now, 'updated_at' => $now];
        }

        if ($rows !== []) {
            DB::table('platform_settings')->upsert($rows, ['key'], ['value', 'updated_at']);
        }

        $this->cache->forget(self::CACHE_KEY);
        $this->stored = null;
    }

    public function registrationMode(): RegistrationMode
    {
        return RegistrationMode::tryFrom((string) $this->get('registration.mode')) ?? RegistrationMode::Closed;
    }

    public function graceDays(): int
    {
        return max(0, (int) $this->get('billing.grace_days'));
    }

    public function invoiceLeadDays(): int
    {
        return max(0, (int) $this->get('billing.invoice_lead_days'));
    }

    public function offlineInstructions(): ?string
    {
        $text = trim((string) $this->get('billing.offline_instructions'));

        return $text === '' ? null : $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return $this->stored ??= $this->cache->rememberForever(self::CACHE_KEY, static fn (): array => DB::table('platform_settings')
            ->pluck('value', 'key')
            ->map(static fn (string $value): mixed => json_decode($value, true))
            ->all());
    }
}
