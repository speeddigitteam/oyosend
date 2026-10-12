<?php

declare(strict_types=1);

namespace App\Modules\Automation\Engine;

use Carbon\CarbonImmutable;

/** What one node execution decided: continue through some handles, wait, end the run, or fail. */
final readonly class StepOutcome
{
    public const string PROCEED = 'proceed';

    public const string WAIT = 'wait';

    public const string APPROVAL = 'approval';

    public const string FAIL = 'fail';

    public const string END = 'end';

    /**
     * @param  array<string, mixed>  $output
     * @param  list<string|null>  $handles
     * @param  array<string, mixed>  $input  What the step was given (already redacted by the caller when logged).
     */
    private function __construct(
        public string $kind,
        public array $output = [],
        public array $handles = [null],
        public ?CarbonImmutable $until = null,
        public string $code = '',
        public string $message = '',
        public bool $retryable = false,
        public bool $fatal = false,
        public ?string $endStatus = null,
        public array $input = [],
        public bool $silent = false,
    ) {}

    /**
     * @param  array<string, mixed>  $output
     * @param  list<string|null>  $handles
     * @param  array<string, mixed>  $input
     */
    public static function proceed(array $output = [], array $handles = [null], array $input = []): self
    {
        return new self(self::PROCEED, output: $output, handles: $handles, input: $input);
    }

    /** @param  array<string, mixed>  $output */
    public static function wait(CarbonImmutable $until, array $output = []): self
    {
        return new self(self::WAIT, output: $output, until: $until);
    }

    /** @param  array<string, mixed>  $output */
    public static function awaitApproval(CarbonImmutable $until, array $output = []): self
    {
        return new self(self::APPROVAL, output: $output, until: $until);
    }

    /** @param  array<string, mixed>  $input */
    public static function fail(string $code, string $message, bool $retryable = false, bool $fatal = false, array $input = []): self
    {
        return new self(self::FAIL, code: $code, message: $message, retryable: $retryable, fatal: $fatal, input: $input);
    }

    /** @param  array<string, mixed>  $output */
    public static function end(string $status, string $message = '', array $output = []): self
    {
        return new self(self::END, output: $output, message: $message, endStatus: $status);
    }
}
