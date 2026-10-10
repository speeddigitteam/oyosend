<?php

declare(strict_types=1);

namespace App\Core\AppStore\Jobs;

use App\Core\AppStore\ScheduledJobDefinition;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Core\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs one compiled app schedule after re-checking its entitlement at worker time.
 *
 * The scheduler's first check avoids queueing work that is already unavailable.
 * This second check closes the gap where an app is deactivated or uninstalled
 * after dispatch but before a worker receives the job.
 */
final class RunScheduledAppJob implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public mixed $tries = null;

    public mixed $maxExceptions = null;

    public mixed $failOnTimeout = null;

    public mixed $countCrashesAsExceptions = null;

    public mixed $backoff = null;

    public mixed $timeout = null;

    public mixed $retryUntil = null;

    public mixed $deleteWhenMissingModels = null;

    /** @var class-string<ShouldQueue> */
    private readonly string $jobClass;

    private readonly string $innerDisplayName;

    /** @var list<string> */
    private readonly array $innerTags;

    private ?ShouldQueue $runningJob = null;

    public function __construct(
        public readonly string $module,
        public readonly string $definition,
        ShouldQueue $scheduledJob,
    ) {
        $this->jobClass = $scheduledJob::class;
        $this->innerDisplayName = method_exists($scheduledJob, 'displayName')
            ? (string) $scheduledJob->displayName()
            : $scheduledJob::class;
        $tags = method_exists($scheduledJob, 'tags') ? $scheduledJob->tags() : null;
        $this->innerTags = is_array($tags)
            ? array_values(array_filter($tags, 'is_string'))
            : ['app:'.$this->module, 'schedule:'.$this->definition];
        $attributes = get_object_vars($scheduledJob);

        // Retain the transport, retry and chain choices of the declared job.
        // Its middleware is run around the inner job in handle(), so middleware
        // still receives the job type it was written for.
        foreach ([
            'connection', 'queue', 'messageGroup', 'deduplicator', 'delay', 'afterCommit',
            'chained', 'chainConnection', 'chainQueue', 'chainCatchCallbacks',
        ] as $attribute) {
            if (array_key_exists($attribute, $attributes)) {
                $this->{$attribute} = $attributes[$attribute];
            }
        }

        foreach ([
            'tries', 'maxExceptions', 'failOnTimeout', 'countCrashesAsExceptions',
            'backoff', 'timeout', 'retryUntil', 'deleteWhenMissingModels',
        ] as $attribute) {
            $this->{$attribute} = $attributes[$attribute] ?? null;
        }

        foreach (['tries', 'backoff', 'retryUntil'] as $attribute) {
            if (method_exists($scheduledJob, $attribute)) {
                $this->{$attribute} = $scheduledJob->{$attribute}();
            }
        }
    }

    public function displayName(): string
    {
        return $this->innerDisplayName;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->innerTags;
    }

    public function handle(
        ModuleRegistry $modules,
        EntitlementChecker $entitlements,
        TenantContext $context,
        Dispatcher $dispatcher,
        Container $container,
    ): void {
        $tenant = $context->require();
        $manifest = $modules->find($this->module);

        if ($manifest === null || $manifest->core || ! $entitlements->allows($tenant, $this->module)) {
            return;
        }

        $declared = collect($manifest->scheduledJobs)
            ->first(fn (ScheduledJobDefinition $job): bool => $job->key === $this->definition
                && $job->job === $this->jobClass);

        // A deployment may remove or replace a definition while an old queue
        // payload is waiting. Stale compiled work must then fail closed.
        if ($declared === null) {
            return;
        }

        $scheduledJob = new ($this->jobClass);
        $this->runningJob = $scheduledJob;

        if ($this->job !== null && method_exists($scheduledJob, 'setJob')) {
            $scheduledJob->setJob($this->job);
        }

        $attributes = get_object_vars($scheduledJob);
        /** @var list<object> $declaredMiddleware */
        $declaredMiddleware = method_exists($scheduledJob, 'middleware')
            ? $scheduledJob->middleware()
            : [];
        /** @var list<object> $queuedMiddleware */
        $queuedMiddleware = $attributes['middleware'] ?? [];

        (new Pipeline($container))
            ->send($scheduledJob)
            ->through([...$declaredMiddleware, ...$queuedMiddleware])
            ->then(static fn (object $job): mixed => $dispatcher->dispatchNow($job));
    }

    public function failed(?Throwable $exception): void
    {
        if (! class_exists($this->jobClass)) {
            return;
        }

        $scheduledJob = $this->runningJob ?? new ($this->jobClass);

        if ($this->job !== null && method_exists($scheduledJob, 'setJob')) {
            $scheduledJob->setJob($this->job);
        }

        if (method_exists($scheduledJob, 'failed')) {
            $scheduledJob->failed($exception);
        }
    }
}
