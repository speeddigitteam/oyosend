<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Crm;

use App\Core\Identity\Models\User;

final class PipelineWidget
{
    /** How often the provider ran: it must not run for someone who may not see the widget. */
    public static int $invocations = 0;

    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $user): array
    {
        self::$invocations++;

        return ['open' => 0];
    }
}
