<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Actions;

use App\Modules\SmsMarketing\Enums\RecipientStatus;
use App\Modules\SmsMarketing\Models\Recipient;

/**
 * Recipient counts per campaign, from the recipient rows (there is no cached counter to drift).
 *
 * `accepted` is `sent` plus `delivered`: messages the provider took. `segments` counts those messages' segments.
 */
final class CampaignStats
{
    /** @return array{recipients: int, pending: int, queued: int, sent: int, delivered: int, failed: int, blocked: int, skipped: int, accepted: int, segments: int} */
    public static function empty(): array
    {
        return ['recipients' => 0, 'pending' => 0, 'queued' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'blocked' => 0, 'skipped' => 0, 'accepted' => 0, 'segments' => 0];
    }

    /**
     * @param  list<string>  $campaignIds
     * @return array<string, array{recipients: int, pending: int, queued: int, sent: int, delivered: int, failed: int, blocked: int, skipped: int, accepted: int, segments: int}>
     */
    public static function for(array $campaignIds): array
    {
        $stats = [];

        foreach ($campaignIds as $id) {
            $stats[$id] = self::empty();
        }

        if ($campaignIds === []) {
            return $stats;
        }

        $rows = Recipient::query()
            ->whereIn('campaign_id', $campaignIds)
            ->selectRaw('campaign_id, status, COUNT(*) as total, COALESCE(SUM(segments), 0) as segments')
            ->groupBy('campaign_id', 'status')
            ->get();

        foreach ($rows as $row) {
            $status = $row->status;
            $campaign = (string) $row->campaign_id;
            $total = (int) $row->getAttribute('total');

            if (! isset($stats[$campaign])) {
                continue;
            }

            $stats[$campaign]['recipients'] += $total;
            $stats[$campaign][$status->value] += $total;

            if (in_array($status, [RecipientStatus::Sent, RecipientStatus::Delivered], true)) {
                $stats[$campaign]['accepted'] += $total;
                $stats[$campaign]['segments'] += (int) $row->getAttribute('segments');
            }
        }

        return $stats;
    }
}
