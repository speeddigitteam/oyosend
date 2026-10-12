<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Flow;

/** Remembers what the fixture actions did so a test can assert it (and that a retry did not repeat it). */
final class FlowRecorder
{
    /** @var list<array<string, mixed>> */
    public static array $calls = [];

    /** @var array<string, array<string, mixed>> idempotency key => output */
    public static array $receipts = [];

    /** @var array<string, int> */
    public static array $failures = [];

    public static function reset(): void
    {
        self::$calls = [];
        self::$receipts = [];
        self::$failures = [];
    }
}
