<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Controllers;

use App\Modules\Ai\Actions\AiAvailability;
use App\Modules\Ai\Actions\UsageReport;
use Inertia\Inertia;
use Inertia\Response;

/** Token allowance and the last 30 days of AI usage of the workspace. */
final class UsageController
{
    public function __construct(
        private readonly UsageReport $usage,
        private readonly AiAvailability $availability,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Modules/Ai/Pages/Usage/Index', [
            'allowance' => $this->usage->allowance(),
            'breakdown' => $this->usage->breakdown(),
            'availability' => $this->availability->describe(),
        ]);
    }
}
