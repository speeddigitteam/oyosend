<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds;

use App\Core\Access\AccessChecker;
use App\Core\Identity\Models\User;
use App\Modules\Social\LeadAds\Models\LeadAdLead;
use App\Modules\Social\LeadAds\Models\LeadAdsPage;

/** The lead-ad counts on the Social dashboard widget, only for a viewer who may see lead ads. */
final class LeadAdsFigures
{
    /** @return array<string, int|bool> empty when the viewer lacks `social.leadads.view` */
    public static function forUser(AccessChecker $access, User $user): array
    {
        if (! $access->allows($user, 'social.leadads.view')) {
            return [];
        }

        $week = static fn () => LeadAdLead::query()->where('received_at', '>=', now()->subDays(7));

        return [
            'leadAdsEnabled' => LeadAdsPage::query()->where('state', LeadAdsPage::SUBSCRIBED)->exists(),
            'leadAdsReceived' => $week()->count(),
            'leadAdsProcessed' => $week()->whereIn('status', [LeadStatus::Captured->value, LeadStatus::Duplicate->value])->count(),
            'leadAdsFailed' => $week()->where('status', LeadStatus::Failed->value)->count(),
            'leadAdsWaiting' => LeadAdLead::query()->whereIn('status', [LeadStatus::WaitingForCrm->value, LeadStatus::WaitingForAccount->value])->count(),
        ];
    }
}
