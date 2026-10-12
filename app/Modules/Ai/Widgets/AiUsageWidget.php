<?php

declare(strict_types=1);

namespace App\Modules\Ai\Widgets;

use App\Core\Identity\Models\User;
use App\Modules\Ai\Actions\AiGateway;
use App\Modules\Ai\Actions\UsageReport;
use App\Modules\Ai\Enums\Feature;

/** Monthly AI token allowance and the current state of AI for the workspace. */
final class AiUsageWidget
{
    public function __construct(
        private readonly UsageReport $usage,
        private readonly AiGateway $gateway,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(User $user): array
    {
        return [...$this->usage->allowance(), 'state' => $this->gateway->state(Feature::Chat)->value];
    }
}
