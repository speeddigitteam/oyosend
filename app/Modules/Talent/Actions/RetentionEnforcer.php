<?php

declare(strict_types=1);

namespace App\Modules\Talent\Actions;

use App\Modules\Talent\Enums\ApplicationStatus;
use App\Modules\Talent\Models\Application;
use App\Modules\Talent\Models\Candidate;

/**
 * Anonymizes candidates whose retention date has passed and who have no open application.
 * Idempotent: an anonymized candidate is skipped.
 */
final class RetentionEnforcer
{
    public function __construct(
        private readonly TalentSettings $settings,
        private readonly CandidateManager $candidates,
    ) {}

    public function run(): int
    {
        $today = $this->settings->today()->toDateString();
        $count = 0;

        Candidate::query()
            ->whereNull('anonymized_at')
            ->whereNotNull('retain_until')
            ->where('retain_until', '<', $today)
            ->whereNotIn('id', Application::query()->where('status', ApplicationStatus::Open)->select('candidate_id'))
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use (&$count): void {
                foreach ($chunk as $candidate) {
                    $this->candidates->anonymize($candidate, 'retention');
                    $count++;
                }
            });

        return $count;
    }
}
