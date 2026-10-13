<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds;

use App\Modules\Social\LeadAds\Models\LeadAdLead;
use App\Modules\Social\LeadAds\Models\LeadAdLog;
use Illuminate\Support\Str;

/** Writes the per-lead processing log. Messages are ours: never answers, never the platform's raw text. */
final class LeadTrail
{
    public const string RECEIVED = 'received';

    public const string FETCHED = 'fetched';

    public const string CAPTURED = 'captured';

    public const string DUPLICATE = 'duplicate';

    public const string FAILED = 'failed';

    public const string WAITING = 'waiting';

    public const string RETRY = 'retry';

    public const string SKIPPED = 'skipped';

    public const string NOTICE = 'notice';

    public function add(LeadAdLead $lead, string $step, ?string $code = null, ?string $message = null): void
    {
        LeadAdLog::query()->create([
            'lead_id' => $lead->id,
            'step' => $step,
            'code' => $code,
            'message' => $message === null ? null : Str::limit($message, 290, ''),
            'created_at' => now(),
        ]);
    }
}
