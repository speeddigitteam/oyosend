<?php

declare(strict_types=1);

namespace App\Platform\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Live checks of the services the platform depends on. Each check really
 * talks to the service; a failure is reported as a failure, never guessed.
 */
final class SystemHealth
{
    /**
     * @return list<array{key: string, ok: bool, detail: string}>
     */
    public function checks(): array
    {
        return [
            $this->check('database', function (): string {
                DB::connection()->select('select 1');

                return DB::connection()->getDriverName().' · '.DB::connection()->getDatabaseName();
            }),
            $this->check('cache', function (): string {
                $key = 'health:'.Str::random(8);
                Cache::put($key, true, 10);
                $ok = Cache::pull($key) === true;

                if (! $ok) {
                    throw new \RuntimeException('Cache round-trip failed.');
                }

                return (string) config('cache.default');
            }),
            $this->check('queue', function (): string {
                $connection = (string) config('queue.default');

                return $connection.' · '.Queue::connection()->size().' pending';
            }),
            $this->check('failed_jobs', function (): string {
                $failed = DB::table('failed_jobs')->count();

                if ($failed > 0) {
                    throw new \RuntimeException($failed.' failed');
                }

                return '0 failed';
            }),
        ];
    }

    /**
     * @param  callable(): string  $probe
     * @return array{key: string, ok: bool, detail: string}
     */
    private function check(string $key, callable $probe): array
    {
        try {
            return ['key' => $key, 'ok' => true, 'detail' => $probe()];
        } catch (Throwable $exception) {
            // The message can carry hostnames or credentials; keep it short and generic.
            return ['key' => $key, 'ok' => false, 'detail' => Str::limit(class_basename($exception).': '.$exception->getMessage(), 80)];
        }
    }
}
