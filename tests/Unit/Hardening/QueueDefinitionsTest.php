<?php

declare(strict_types=1);

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;

/*
| Architecture checks for everything in app/ that is queued (jobs, queued
| mailables, listeners): an explicit queue that a Horizon supervisor works,
| explicit tries, an explicit timeout the supervisor will honour, a backoff
| when it retries, and tenant context where the work belongs to a tenant.
| Documented in docs/DEPLOYMENT.md section 4.
*/

/** Queued classes that decide these at run time or are central by design, with the reason. */
const DYNAMIC_QUEUE_CLASSES = [
    // Reads tries/timeout/queue from the compiled module definition it runs (ScheduledJobDefinition).
    'App\Core\AppStore\Jobs\RunScheduledAppJob',
];

const CENTRAL_QUEUE_CLASSES = [
    // Sent from the central sign-up, before any workspace exists.
    'App\Core\Registration\Mail\ConfirmRegistrationMail',
    // A message from the website's contact form, to the company's own inbox.
    'App\Core\Website\Mail\WebsiteEnquiryMail',
];

/** @return list<class-string> */
function queuedClasses(): array
{
    $base = dirname(__DIR__, 3).'/app';
    $classes = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php' || ! str_contains((string) file_get_contents($file->getPathname()), 'ShouldQueue')) {
            continue;
        }

        $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $base)) + 1, -4);
        $class = 'App\\'.str_replace('/', '\\', $relative);

        if (class_exists($class) && is_subclass_of($class, ShouldQueue::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

/** @return array<string, int> queue name => worker timeout of the supervisor that works it */
function supervisorTimeouts(): array
{
    $timeouts = [];

    foreach (config('horizon.defaults') as $supervisor) {
        foreach ($supervisor['queue'] as $queue) {
            $timeouts[$queue] = $supervisor['timeout'];
        }
    }

    return $timeouts;
}

function declares(ReflectionClass $class, string $property): bool
{
    // Declared by the class itself or by its own traits/parents below the framework.
    return $class->hasProperty($property)
        && str_starts_with($class->getProperty($property)->getDeclaringClass()->getName(), 'App\\');
}

it('finds the queued classes it is supposed to police', function () {
    expect(count(queuedClasses()))->toBeGreaterThan(25);
});

it('works every queue named in CLAUDE.md from a Horizon supervisor', function () {
    $queues = array_keys(supervisorTimeouts());

    foreach (['default', 'notifications', 'webhooks', 'billing', 'automation', 'campaigns', 'ai'] as $queue) {
        expect($queues)->toContain($queue);
    }
});

it('sets a long-wait threshold for every queue', function () {
    foreach (array_keys(supervisorTimeouts()) as $queue) {
        expect(config('horizon.waits'))->toHaveKey("redis:{$queue}");
    }
});

it('keeps every supervisor timeout below the redis retry_after', function () {
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    foreach (supervisorTimeouts() as $timeout) {
        expect($timeout)->toBeLessThan($retryAfter);
    }
});

it('gives every queued class an explicit queue, tries, timeout and backoff', function () {
    $timeouts = supervisorTimeouts();
    $violations = [];

    foreach (queuedClasses() as $class) {
        if (in_array($class, DYNAMIC_QUEUE_CLASSES, true)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        $source = (string) file_get_contents((string) $reflection->getFileName());

        if (preg_match("/onQueue\('([a-z]+)'\)|public (?:string )?\\\$queue = '([a-z]+)'/", $source, $match) !== 1) {
            $violations[] = "{$class}: no explicit queue";

            continue;
        }

        $queue = $match[2] ?? $match[1];

        if (! isset($timeouts[$queue])) {
            $violations[] = "{$class}: queue [{$queue}] is not worked by any Horizon supervisor";
        }

        $defaults = $reflection->getDefaultProperties();

        if (! declares($reflection, 'tries') || ! is_int($defaults['tries'] ?? null) || $defaults['tries'] < 1) {
            $violations[] = "{$class}: no explicit tries";
        }

        if (! declares($reflection, 'timeout') || ! is_int($defaults['timeout'] ?? null)) {
            $violations[] = "{$class}: no explicit timeout";
        } elseif (isset($timeouts[$queue]) && $defaults['timeout'] > $timeouts[$queue]) {
            $violations[] = "{$class}: timeout {$defaults['timeout']} exceeds the {$queue} supervisor's {$timeouts[$queue]} s";
        }

        if (($defaults['tries'] ?? 1) > 1 && (! declares($reflection, 'backoff') || ! is_array($defaults['backoff'] ?? null))) {
            $violations[] = "{$class}: retries without a backoff";
        }
    }

    expect($violations)->toBe([]);
});

it('makes every queued class tenant-aware unless it is central by design', function () {
    $violations = [];

    foreach (queuedClasses() as $class) {
        if (in_array($class, CENTRAL_QUEUE_CLASSES, true)) {
            continue;
        }

        if (! is_subclass_of($class, RequiresTenantContext::class)) {
            $violations[] = $class;
        }
    }

    expect($violations)->toBe([]);
});
