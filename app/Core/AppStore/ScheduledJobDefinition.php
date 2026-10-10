<?php

declare(strict_types=1);

namespace App\Core\AppStore;

use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\Tenancy\Contracts\RequiresTenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use InvalidArgumentException;
use ReflectionClass;

/** A trusted, compiled module job that the platform may schedule for tenants. */
final readonly class ScheduledJobDefinition
{
    /**
     * @param  class-string  $job
     */
    public function __construct(
        public string $key,
        public string $job,
        public ScheduledJobFrequency $frequency = ScheduledJobFrequency::Hourly,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_.]*$/D', $key) !== 1) {
            throw new InvalidArgumentException("Invalid scheduled job key [{$key}].");
        }

        if (! class_exists($job)
            || ! is_subclass_of($job, ShouldQueue::class)
            || ! is_subclass_of($job, RequiresTenantContext::class)) {
            throw new InvalidArgumentException(
                "Scheduled job [{$job}] must implement ShouldQueue and RequiresTenantContext.",
            );
        }

        $constructor = (new ReflectionClass($job))->getConstructor();

        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new InvalidArgumentException("Scheduled job [{$job}] must be constructible without arguments.");
        }
    }

    /** @return array{key: string, job: class-string, frequency: string} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'job' => $this->job,
            'frequency' => $this->frequency->value,
        ];
    }
}
