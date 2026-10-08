<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Core\Tenancy\Exceptions\MissingTenantContextException;
use App\Core\Tenancy\TenantContext;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Fails closed when a tenant-bound job is about to run without its tenant.
 *
 * Runs after the tenancy package has restored the context from the payload,
 * so reaching here with no tenant means the job was dispatched outside a
 * tenant context or its payload was tampered with.
 */
final class EnforceTenantContextOnJobs
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(JobProcessing $event): void
    {
        $payload = $event->job->payload();
        $class = $this->queuedClass($payload);

        if ($class === null || ! is_subclass_of($class, RequiresTenantContext::class)) {
            return;
        }

        $expected = $payload['tenant_id'] ?? null;

        if ($expected === null || ! $this->context->check() || $this->context->id() !== (string) $expected) {
            throw new MissingTenantContextException(sprintf(
                'Queued job %s requires a tenant context but none could be established.',
                $class,
            ));
        }
    }

    /**
     * The class the worker is about to run: for a queued mailable,
     * notification or listener it is the wrapped class, not the wrapper.
     *
     * @param  array<string, mixed>  $payload
     * @return class-string|null
     */
    private function queuedClass(array $payload): ?string
    {
        $candidates = [
            $payload['displayName'] ?? null,
            $payload['data']['commandName'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
