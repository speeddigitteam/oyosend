<?php

declare(strict_types=1);

namespace App\Platform\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Live checks of the services the platform depends on. Each check really
 * talks to the service; a failure is reported as a failure, never guessed.
 *
 * A check answers with the name of what it reached (`detail`) and, where it
 * counts something, the number (`count`). Words around the number are the
 * interface's business, so they can be translated.
 */
final class SystemHealth
{
    public const SCHEDULER_KEY = 'platform:scheduler:heartbeat';

    /** Checks whose failure means the instance cannot serve (readiness fails). */
    public const CRITICAL = ['database', 'cache', 'queue', 'redis', 'storage', 'migrations'];

    /**
     * @return list<array{key: string, ok: bool, detail: string, count: int|null}>
     */
    public function checks(): array
    {
        return [
            $this->check('database', function (): array {
                DB::connection()->select('select 1');

                return [true, DB::connection()->getDriverName().' · '.DB::connection()->getDatabaseName(), null];
            }),
            $this->check('cache', function (): array {
                $key = 'health:'.Str::random(8);
                Cache::put($key, true, 10);

                if (Cache::pull($key) !== true) {
                    throw new RuntimeException('Cache round-trip failed.');
                }

                return [true, (string) config('cache.default'), null];
            }),
            $this->check('queue', function (): array {
                return [true, (string) config('queue.default'), Queue::connection()->size()];
            }),
            // Reached and answering, but the answer itself is the problem.
            $this->check('failed_jobs', function (): array {
                $failed = DB::table('failed_jobs')->count();

                return [$failed === 0, '', $failed];
            }),
        ];
    }

    /** Called by the scheduler every minute. Platform-wide, so never the tenant-prefixed cache. */
    public function beat(): void
    {
        app('cache.central')->forever(self::SCHEDULER_KEY, Carbon::now()->getTimestamp());
    }

    /**
     * The extended set behind GET /health/ready: the dashboard's first three
     * checks plus the ones that only matter to an operator or a load balancer.
     *
     * @return list<array{key: string, ok: bool, detail: string, count: int|null}>
     */
    public function readiness(): array
    {
        return [
            ...array_slice($this->checks(), 0, 3),
            $this->check('redis', function (): array {
                $uses = collect([config('cache.default'), config('queue.default'), config('session.driver')])
                    ->contains('redis');

                if (! $uses) {
                    return [true, 'not used', null];
                }

                Redis::connection()->ping();

                return [true, 'ping', null];
            }),
            $this->check('storage', function (): array {
                $path = 'health/'.Str::random(12).'.tmp';
                $disk = Storage::disk('local');

                if (! $disk->put($path, 'ok') || $disk->get($path) !== 'ok') {
                    throw new RuntimeException('Storage is not writable.');
                }

                $disk->delete($path);

                return [true, 'local', null];
            }),
            $this->check('migrations', function (): array {
                $migrator = app('migrator');
                $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));
                $pending = count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));

                return [$pending === 0, '', $pending];
            }),
            $this->check('scheduler', function (): array {
                $beat = app('cache.central')->get(self::SCHEDULER_KEY);

                if (! is_int($beat)) {
                    return [false, 'no heartbeat', null];
                }

                $age = max(0, Carbon::now()->getTimestamp() - $beat);

                return [$age <= (int) config('platform.health.scheduler_max_age', 180), '', $age];
            }),
            $this->check('queue_backlog', function (): array {
                $limit = (int) config('platform.health.queue_backlog', 1000);
                $over = [];
                $total = 0;

                foreach ($this->queueNames() as $queue) {
                    $size = Queue::connection()->size($queue);
                    $total += $size;

                    if ($size > $limit) {
                        $over[] = $queue;
                    }
                }

                return [$over === [], implode(',', $over), $total];
            }),
            $this->check('failed_jobs', function (): array {
                $failed = DB::table('failed_jobs')->count();

                return [$failed === 0, '', $failed];
            }),
        ];
    }

    /** @return list<string> every queue a Horizon supervisor works */
    private function queueNames(): array
    {
        $names = [];

        foreach ((array) config('horizon.defaults', []) as $supervisor) {
            foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                $names[] = (string) $queue;
            }
        }

        return array_values(array_unique($names)) ?: ['default'];
    }

    /**
     * @param  callable(): array{0: bool, 1: string, 2: int|null}  $probe
     * @return array{key: string, ok: bool, detail: string, count: int|null}
     */
    private function check(string $key, callable $probe): array
    {
        try {
            [$ok, $detail, $count] = $probe();

            return ['key' => $key, 'ok' => $ok, 'detail' => $detail, 'count' => $count];
        } catch (Throwable $exception) {
            // The message can carry hostnames or credentials: the response gets a generic
            // detail, the full exception goes only to the log.
            Log::warning('Health check failed.', ['check' => $key, 'exception' => $exception]);

            return [
                'key' => $key,
                'ok' => false,
                'detail' => 'check failed',
                'count' => null,
            ];
        }
    }
}
