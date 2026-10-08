<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Contracts;

/**
 * Marker for queued work (jobs, mailables, notifications, listeners) that
 * must only ever run inside a tenant context.
 *
 * The tenant id travels in the queue payload and is re-established before
 * the job runs. If it is missing, or the tenant no longer exists, the job
 * is failed before `handle()` is reached.
 */
interface RequiresTenantContext {}
