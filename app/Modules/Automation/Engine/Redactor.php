<?php

declare(strict_types=1);

namespace App\Modules\Automation\Engine;

use App\Modules\Automation\Support\Limits;
use Illuminate\Support\Str;

/**
 * Makes run data safe to persist in step logs and show in the run history: secret-looking keys are masked
 * and long strings are cut. The run context keeps real values (actions need them); only logs are redacted.
 */
final class Redactor
{
    /** Key fragments that mark a value as secret. */
    private const array FRAGMENTS = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'api_key', 'apikey', 'signature',
        'credential', 'private_key', 'cookie', 'otp', 'recovery',
    ];

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redact(array $data, int $depth = 0): array
    {
        if ($depth > 8) {
            return ['[truncated]'];
        }

        $clean = [];
        $count = 0;

        foreach ($data as $key => $value) {
            if (++$count > 100) {
                $clean['[truncated]'] = true;

                break;
            }

            if (is_string($key) && $this->secret($key)) {
                $clean[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $clean[$key] = $this->redact($value, $depth + 1);
            } elseif (is_string($value)) {
                $clean[$key] = Str::limit($value, Limits::int('max_log_string'), '...');
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } else {
                $clean[$key] = '[unsupported]';
            }
        }

        return $clean;
    }

    private function secret(string $key): bool
    {
        $key = Str::lower($key);

        foreach (self::FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
