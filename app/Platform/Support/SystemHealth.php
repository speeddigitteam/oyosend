<?php

declare(strict_types=1);

namespace App\Platform\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
            // The message can carry hostnames or credentials; keep it short and generic.
            return [
                'key' => $key,
                'ok' => false,
                'detail' => Str::limit(class_basename($exception).': '.$exception->getMessage(), 80),
                'count' => null,
            ];
        }
    }
}
