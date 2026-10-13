<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Facebook;

use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\PageAccessReport;
use App\Modules\Social\Platforms\PageInsightsProvider;
use App\Modules\Social\Platforms\PageInsightsSnapshot;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Facebook Page insights through the documented Graph API.
 *
 * Calls (all with the Page access token, `Authorization: Bearer`):
 *  - `GET /{page-id}?fields=followers_count,fan_count`  current follower and like counts
 *  - `GET /{page-id}/insights?metric={name}&period=day&since=&until=`  one metric per request,
 *    candidate names from `config('social.insights.facebook.metrics')` tried in order
 *  - `GET /{page-id}/roles`  the people with a role on the Page and their tasks
 *  - `GET /debug_token?input_token=` (with the app access token) the permissions the token holds
 *    and the user it was issued for
 *
 * One metric per request keeps a retired metric name from failing the others. Meta's daily
 * values carry an `end_time`; the day a value belongs to is the Pacific Time day that ended at
 * it. A value the API did not return, or answered with an empty object, is left out (null).
 * Tokens are never put in a message, a log line or a return value.
 *
 * Needs `pages_read_engagement` and `read_insights` (and `pages_show_list`) granted to the app
 * through Meta app review. NOT yet exercised against Meta's live API in this repository.
 */
final class FacebookPageInsights implements PageInsightsProvider
{
    private const array RATE_LIMIT_CODES = [4, 17, 32, 613, 80001, 80002, 80003, 80004, 80005, 80006, 80008, 80009, 80014];

    private const array TOKEN_CODES = [102, 190, 459, 464, 467];

    /** @var list<string> Tasks a Page role may carry; anything else is ignored. */
    public const array KNOWN_TASKS = ['MANAGE', 'CREATE_CONTENT', 'MODERATE', 'MESSAGING', 'ADVERTISE', 'ANALYZE', 'MANAGE_JOBS', 'MANAGE_LEADS', 'VIEW_MONITORING'];

    public function __construct(private readonly Factory $http) {}

    public function platform(): string
    {
        return 'facebook';
    }

    public function dayTimezone(): string
    {
        return (string) config('social.insights.day_timezone', 'America/Los_Angeles');
    }

    public function daily(AccountCredentials $account, CarbonImmutable $firstDay, CarbonImmutable $lastDay): PageInsightsSnapshot
    {
        $zone = new DateTimeZone($this->dayTimezone());
        $first = $firstDay->format('Y-m-d');
        $last = $lastDay->format('Y-m-d');
        $since = CarbonImmutable::parse($first, $zone)->startOfDay();
        $until = CarbonImmutable::parse($last, $zone)->startOfDay()->addDay();
        $now = CarbonImmutable::now();
        $threshold = (int) config('social.insights.usage_stop_percent', 80);

        /** @var array<string, array<string, int>> $days */
        $days = [];
        $sources = [];
        $notReported = [];
        $serverErrors = 0;

        $stop = function (string $reason, ?string $code = null, ?int $retry = null) use (&$days, &$sources, &$notReported): PageInsightsSnapshot {
            ksort($days);

            return new PageInsightsSnapshot($days, $sources, $notReported, $reason, $code, $retry);
        };

        // Current counts (a point in time: stored on the platform's current day).
        $fields = (array) config('social.insights.facebook.page_fields', []);

        if ($fields !== []) {
            try {
                $response = $this->get($account, $account->externalId, ['fields' => implode(',', array_values($fields))]);
            } catch (ConnectionException) {
                return $stop(PageInsightsSnapshot::UNAVAILABLE);
            }

            if ($response->successful()) {
                $today = $now->setTimezone($zone)->format('Y-m-d');

                foreach ($fields as $figure => $field) {
                    $value = $this->integer($response->json((string) $field));

                    if ($value !== null && $today >= $first && $today <= $last) {
                        $days[$today][(string) $figure] = $value;
                        $sources[(string) $figure] = 'page.'.$field;
                    }
                }
            } else {
                $kind = $this->classify($response);

                if (in_array($kind, ['rate', 'token', 'permission'], true)) {
                    return $stop(...$this->stopFor($kind, $response));
                }
            }

            if ($this->throttled($response, $threshold)) {
                return $stop(PageInsightsSnapshot::THROTTLED);
            }
        }

        foreach ((array) config('social.insights.facebook.metrics', []) as $figure => $candidates) {
            $figure = (string) $figure;
            $answered = false;

            foreach ((array) $candidates as $metric) {
                $metric = (string) $metric;

                try {
                    $response = $this->get($account, $account->externalId.'/insights', [
                        'metric' => $metric,
                        'period' => 'day',
                        'since' => $since->getTimestamp(),
                        'until' => $until->getTimestamp(),
                    ]);
                } catch (ConnectionException) {
                    return $stop(PageInsightsSnapshot::UNAVAILABLE);
                }

                if ($this->throttled($response, $threshold)) {
                    if ($response->successful()) {
                        $answered = $this->absorb($response, $figure, $metric, $zone, $first, $last, $now, $days, $sources);
                    }

                    return $stop(PageInsightsSnapshot::THROTTLED);
                }

                if ($response->successful()) {
                    if ($this->absorb($response, $figure, $metric, $zone, $first, $last, $now, $days, $sources)) {
                        $answered = true;
                        break;
                    }

                    continue;
                }

                $kind = $this->classify($response);

                if (in_array($kind, ['rate', 'token', 'permission'], true)) {
                    return $stop(...$this->stopFor($kind, $response));
                }

                if ($kind === 'server' && ++$serverErrors >= 2) {
                    return $stop(PageInsightsSnapshot::UNAVAILABLE, $this->code($response));
                }
                // `invalid` (retired or unknown metric) and anything else: try the next candidate.
            }

            if (! $answered) {
                $notReported[] = $figure;
            }
        }

        ksort($days);

        return new PageInsightsSnapshot($days, $sources, $notReported);
    }

    public function access(AccountCredentials $account): PageAccessReport
    {
        $scopes = null;
        $userId = null;
        $appId = config('social.platforms.facebook.client_id');
        $secret = config('social.platforms.facebook.client_secret');

        if (is_string($appId) && $appId !== '' && is_string($secret) && $secret !== '') {
            try {
                $debug = $this->http->timeout(20)->connectTimeout(10)->acceptJson()->withUserAgent('Oyosend-Social/1.0')
                    ->withToken($appId.'|'.$secret)
                    ->get($this->graph('debug_token'), ['input_token' => $account->accessToken]);

                if ($debug->successful()) {
                    $granted = $debug->json('data.scopes');
                    $scopes = is_array($granted)
                        ? array_values(array_filter($granted, static fn ($scope): bool => is_string($scope) && preg_match('/^[a-z_]{3,64}$/', $scope) === 1))
                        : null;
                    $user = $debug->json('data.user_id');
                    $userId = is_string($user) || is_int($user) ? (string) $user : null;
                }
            } catch (Throwable) {
                // Never surface the exception: its message can carry the request URL.
            }
        }

        try {
            $roles = $this->get($account, $account->externalId.'/roles', ['fields' => 'id,name,tasks', 'limit' => 100]);
        } catch (ConnectionException) {
            return new PageAccessReport($scopes, null, PageAccessReport::UNAVAILABLE);
        }

        if (! $roles->successful()) {
            $permission = in_array($this->classify($roles), ['permission', 'token'], true);

            return new PageAccessReport($scopes, null, $permission ? PageAccessReport::PERMISSION_MISSING : PageAccessReport::UNAVAILABLE);
        }

        $people = (array) $roles->json('data', []);
        $mine = null;

        foreach ($people as $person) {
            if (is_array($person) && $userId !== null && (string) ($person['id'] ?? '') === $userId) {
                $mine = $person;
                break;
            }
        }

        if ($mine === null) {
            // The roles list is readable but the token holder is not in it (or the token's user is unknown).
            return new PageAccessReport($scopes, null, PageAccessReport::NOT_REPORTED);
        }

        $tasks = array_values(array_unique(array_filter(
            array_map(static fn ($task): string => is_string($task) ? strtoupper($task) : '', (array) ($mine['tasks'] ?? [])),
            static fn (string $task): bool => in_array($task, self::KNOWN_TASKS, true),
        )));

        return new PageAccessReport($scopes, $tasks, PageAccessReport::OK);
    }

    /**
     * Add the values of one successful insights response; false when it held none.
     *
     * @param  array<string, array<string, int>>  $days
     * @param  array<string, string>  $sources
     */
    private function absorb(Response $response, string $figure, string $metric, DateTimeZone $zone, string $first, string $last, CarbonImmutable $now, array &$days, array &$sources): bool
    {
        $found = false;

        foreach ((array) $response->json('data', []) as $series) {
            foreach ((array) ($series['values'] ?? []) as $entry) {
                if (! is_array($entry) || ! is_string($entry['end_time'] ?? null)) {
                    continue;
                }

                $value = $this->value($entry['value'] ?? null);
                $day = self::dayOf($entry['end_time'], $zone);

                try {
                    $incomplete = CarbonImmutable::parse($entry['end_time'])->gt($now);
                } catch (Throwable) {
                    continue;
                }

                if ($value === null || $day === null || $incomplete || $day < $first || $day > $last) {
                    continue;
                }

                $days[$day][$figure] = $value;
                $found = true;
            }
        }

        if ($found) {
            $sources[$figure] = $metric;
        }

        return $found;
    }

    /**
     * The platform day a daily value belongs to: the day that ended at `$endTime`.
     */
    public static function dayOf(string $endTime, DateTimeZone $zone): ?string
    {
        try {
            return CarbonImmutable::parse($endTime)->setTimezone($zone)->subSecond()->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    /** A number, or the sum of a breakdown object's numbers (reactions by type); null when empty or not numeric. */
    private function value(mixed $value): ?int
    {
        if (is_array($value)) {
            $sum = null;

            foreach ($value as $part) {
                $number = $this->integer($part);

                if ($number !== null) {
                    $sum = ($sum ?? 0) + $number;
                }
            }

            return $sum;
        }

        return $this->integer($value);
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /** @param array<string, mixed> $query */
    private function get(AccountCredentials $account, string $path, array $query): Response
    {
        return $this->http->timeout(20)->connectTimeout(10)->acceptJson()->withUserAgent('Oyosend-Social/1.0')
            ->withToken($account->accessToken)
            ->get($this->graph($path), $query);
    }

    private function graph(string $path): string
    {
        $version = config('social.platforms.facebook.api_version');

        return 'https://graph.facebook.com/'.(is_string($version) && $version !== '' ? $version : 'v23.0').'/'.ltrim($path, '/');
    }

    private function classify(Response $response): string
    {
        $code = (int) $response->json('error.code', 0);

        return match (true) {
            $response->status() === 401, in_array($code, self::TOKEN_CODES, true) => 'token',
            $response->status() === 429, in_array($code, self::RATE_LIMIT_CODES, true) => 'rate',
            $code === 10, $code >= 200 && $code <= 299 => 'permission',
            $code === 100, $code === 12 => 'invalid',
            $response->serverError(), in_array($code, [1, 2], true) => 'server',
            default => 'other',
        };
    }

    /** @return array{0: string, 1: string|null, 2: int|null} */
    private function stopFor(string $kind, Response $response): array
    {
        $header = $response->header('Retry-After');
        $retry = is_numeric($header) ? max(60, min(3600, (int) $header)) : null;

        return match ($kind) {
            'rate' => [PageInsightsSnapshot::RATE_LIMITED, $this->code($response), $retry ?? 900],
            'token' => [PageInsightsSnapshot::TOKEN_INVALID, $this->code($response), null],
            default => [PageInsightsSnapshot::PERMISSION_MISSING, $this->code($response), null],
        };
    }

    private function code(Response $response): string
    {
        $code = $response->json('error.code');

        return is_int($code) || is_string($code) ? 'fb_'.preg_replace('/[^0-9A-Za-z_]/', '', (string) $code) : 'http_'.$response->status();
    }

    /** Whether Meta's own usage headers say the app or Page is close to its limit. */
    private function throttled(Response $response, int $threshold): bool
    {
        foreach (['x-app-usage', 'x-page-usage'] as $name) {
            $header = $response->header($name);

            if ($header === '') {
                continue;
            }

            $usage = json_decode($header, true);

            if (! is_array($usage)) {
                continue;
            }

            foreach (['call_count', 'total_time', 'total_cputime'] as $key) {
                if (is_numeric($usage[$key] ?? null) && (int) $usage[$key] >= $threshold) {
                    return true;
                }
            }
        }

        return false;
    }
}
