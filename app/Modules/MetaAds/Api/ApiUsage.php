<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Api;

use Illuminate\Http\Client\Response;

/**
 * How much of its quota Meta says the caller has used, read from the documented usage headers:
 * `x-business-use-case-usage`, `x-ad-account-usage` and `x-app-usage`. The highest figure across them
 * wins; absent headers mean "unknown", which is 0 here and never throttles on its own.
 */
final readonly class ApiUsage
{
    public function __construct(
        public int $percent = 0,
        public ?int $regainSeconds = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public static function fromResponse(Response $response): self
    {
        $percent = 0;
        $regain = null;

        $business = self::decode($response->header('x-business-use-case-usage'));

        foreach ($business as $entries) {
            foreach (is_array($entries) ? $entries : [] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $percent = max($percent, self::percent($entry['call_count'] ?? null), self::percent($entry['total_cputime'] ?? null), self::percent($entry['total_time'] ?? null));
                $minutes = $entry['estimated_time_to_regain_access'] ?? null;

                if (is_numeric($minutes) && (int) $minutes > 0) {
                    // Meta reports this in minutes.
                    $regain = max($regain ?? 0, (int) $minutes * 60);
                }
            }
        }

        $account = self::decode($response->header('x-ad-account-usage'));
        $percent = max($percent, self::percent($account['acc_id_util_pct'] ?? null));

        if (is_numeric($account['reset_time_duration'] ?? null) && (int) $account['reset_time_duration'] > 0 && $percent >= 90) {
            $regain = max($regain ?? 0, (int) $account['reset_time_duration']);
        }

        $app = self::decode($response->header('x-app-usage'));
        $percent = max($percent, self::percent($app['call_count'] ?? null), self::percent($app['total_cputime'] ?? null), self::percent($app['total_time'] ?? null));

        return new self(min(100, $percent), $regain);
    }

    public function merge(self $other): self
    {
        return new self(max($this->percent, $other->percent), max($this->regainSeconds ?? 0, $other->regainSeconds ?? 0) ?: null);
    }

    /** @return array<array-key, mixed> */
    private static function decode(mixed $header): array
    {
        if (! is_string($header) || $header === '' || strlen($header) > 8192) {
            return [];
        }

        $decoded = json_decode($header, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function percent(mixed $value): int
    {
        return is_numeric($value) ? max(0, min(100, (int) round((float) $value))) : 0;
    }
}
