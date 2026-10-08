<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use App\Core\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * An ordinary queued job. Notes the tenant it found active when it ran
 * (null for the central context) so a test can assert on it.
 */
class RecordsTenantJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @var list<array{label: string, tenant: string|null}> */
    public static array $runs = [];

    public function __construct(public readonly string $label = 'job') {}

    public function handle(TenantContext $context): void
    {
        self::$runs[] = ['label' => $this->label, 'tenant' => $context->id()];
    }

    /**
     * @return list<string|null> the tenant of each run, in order
     */
    public static function tenants(): array
    {
        return array_column(self::$runs, 'tenant');
    }
}
