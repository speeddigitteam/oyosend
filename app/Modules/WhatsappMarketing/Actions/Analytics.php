<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Billing\UsageLimits;
use App\Modules\WhatsappMarketing\Enums\CampaignStatus;
use App\Modules\WhatsappMarketing\Enums\ConversationStatus;
use App\Modules\WhatsappMarketing\Enums\Direction;
use App\Modules\WhatsappMarketing\Enums\MessageStatus;
use App\Modules\WhatsappMarketing\Enums\RecipientState;
use App\Modules\WhatsappMarketing\Enums\SenderType;
use App\Modules\WhatsappMarketing\Models\Campaign;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Message;
use App\Modules\WhatsappMarketing\Models\Recipient;
use App\Modules\WhatsappMarketing\Models\Window;
use Carbon\CarbonInterface;

/**
 * Workspace-wide WhatsApp figures. Every number is derived from this app's own records, which hold only what
 * the provider reported. Cost is never computed: the provider's webhooks carry no amount, so it is `null`
 * ("not reported"), next to the billable counts the provider did report.
 */
final class Analytics
{
    public function __construct(private readonly UsageLimits $limits) {}

    /**
     * @return array{
     *   conversations: array{new: int, inbound: int, outbound: int, campaign: int, open: int, unassigned: int, firstResponseAverage: int|null},
     *   windows: array{customer: int, business: int, used: int, limit: int|null},
     *   delivery: array{sent: int, delivered: int, read: int, failed: int, blocked: int, deliveredBps: int|null, readBps: int|null},
     *   providerReported: array{billable: int, notBillable: int, notReported: int, byCategory: array<string, int>},
     *   cost: null,
     *   campaigns: list<array<string, mixed>>
     * }
     */
    public function overview(CarbonInterface $from, CarbonInterface $to): array
    {
        $messages = fn () => Message::query()->whereBetween('occurred_at', [$from, $to]);

        $inbound = $messages()->where('direction', Direction::Inbound->value)->count();
        $campaign = $messages()->where('direction', Direction::Outbound->value)->where('sender_type', SenderType::Campaign->value)->count();
        $outbound = $messages()->where('direction', Direction::Outbound->value)->where('sender_type', '!=', SenderType::Campaign->value)->count();

        /** @var array<string, int> $status */
        $status = $messages()->where('direction', Direction::Outbound->value)
            ->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
            ->map(static fn (mixed $count): int => (int) $count)->all();

        $read = $status[MessageStatus::Read->value] ?? 0;
        $delivered = ($status[MessageStatus::Delivered->value] ?? 0) + $read;
        $sent = ($status[MessageStatus::Sent->value] ?? 0) + $delivered;

        $averageMinutes = Conversation::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('first_responded_at')
            ->toBase()->selectRaw('avg(timestampdiff(minute, created_at, first_responded_at)) as minutes')
            ->value('minutes');

        $reported = $messages()->where('direction', Direction::Outbound->value)->where('type', 'template');
        $billable = (clone $reported)->where('billable', true)->count();
        $notBillable = (clone $reported)->where('billable', false)->count();
        $notReported = (clone $reported)->whereNull('billable')->count();

        /** @var array<string, int> $categories */
        $categories = (clone $reported)->whereNotNull('pricing_category')
            ->toBase()->selectRaw('pricing_category, count(*) as total')->groupBy('pricing_category')->pluck('total', 'pricing_category')
            ->map(static fn (mixed $count): int => (int) $count)->all();

        $windows = Window::query()->whereBetween('opened_at', [$from, $to])
            ->toBase()->selectRaw('origin, count(*) as total')->groupBy('origin')->pluck('total', 'origin');

        return [
            'conversations' => [
                'new' => Conversation::query()->whereBetween('created_at', [$from, $to])->count(),
                'inbound' => $inbound,
                'outbound' => $outbound,
                'campaign' => $campaign,
                'open' => Conversation::query()->where('status', ConversationStatus::Open->value)->count(),
                'unassigned' => Conversation::query()->where('status', ConversationStatus::Open->value)->whereNull('owner_id')->count(),
                'firstResponseAverage' => $averageMinutes === null ? null : (int) round((float) $averageMinutes),
            ],
            'windows' => [
                'customer' => (int) ($windows['customer'] ?? 0),
                'business' => (int) ($windows['business'] ?? 0),
                'used' => $this->limits->used(WindowTracker::LIMIT),
                'limit' => $this->limits->limit(WindowTracker::LIMIT),
            ],
            'delivery' => [
                'sent' => $sent,
                'delivered' => $delivered,
                'read' => $read,
                'failed' => $status[MessageStatus::Failed->value] ?? 0,
                'blocked' => $status[MessageStatus::Blocked->value] ?? 0,
                'deliveredBps' => $sent === 0 ? null : intdiv($delivered * 10000, $sent),
                'readBps' => $delivered === 0 ? null : intdiv($read * 10000, $delivered),
            ],
            'providerReported' => [
                'billable' => $billable,
                'notBillable' => $notBillable,
                'notReported' => $notReported,
                'byCategory' => $categories,
            ],
            'cost' => null,
            'campaigns' => $this->campaigns($from, $to, 10),
        ];
    }

    /**
     * One row per campaign started in the range, with its recipients by state.
     *
     * @return list<array<string, mixed>>
     */
    public function campaigns(CarbonInterface $from, CarbonInterface $to, int $limit, ?string $status = null): array
    {
        $campaigns = Campaign::query()
            ->whereNotNull('started_at')
            ->whereBetween('started_at', [$from, $to])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get();

        if ($campaigns->isEmpty()) {
            return [];
        }

        $counts = Recipient::query()
            ->whereIn('campaign_id', $campaigns->pluck('id')->all())
            ->toBase()->selectRaw('campaign_id, state, count(*) as total')
            ->groupBy('campaign_id', 'state')
            ->get();

        $rows = [];

        foreach ($campaigns as $campaign) {
            $by = [];

            foreach ($counts->where('campaign_id', $campaign->id) as $row) {
                $by[(string) $row->state] = (int) $row->total;
            }

            $read = $by[RecipientState::Read->value] ?? 0;
            $delivered = ($by[RecipientState::Delivered->value] ?? 0) + $read;
            $sent = ($by[RecipientState::Sent->value] ?? 0) + $delivered;

            $rows[] = [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status->value,
                'startedAt' => $campaign->started_at?->toIso8601String(),
                'recipients' => array_sum($by),
                'queued' => $by[RecipientState::Queued->value] ?? 0,
                'sent' => $sent,
                'delivered' => $delivered,
                'read' => $read,
                'failed' => $by[RecipientState::Failed->value] ?? 0,
                'blocked' => $by[RecipientState::Blocked->value] ?? 0,
                'skipped' => $by[RecipientState::Skipped->value] ?? 0,
                'deliveredBps' => $sent === 0 ? null : intdiv($delivered * 10000, $sent),
                'readBps' => $delivered === 0 ? null : intdiv($read * 10000, $delivered),
            ];
        }

        return $rows;
    }

    /**
     * Messages and new windows per local day.
     *
     * @return list<array{date: string, newConversations: int, inbound: int, outbound: int, campaign: int, windowsCustomer: int, windowsBusiness: int}>
     */
    public function byDay(CarbonInterface $from, CarbonInterface $to, string $timezone): array
    {
        $offset = $from->copy()->setTimezone($timezone)->utcOffset() * 60;
        $days = [];

        for ($day = $from->copy()->setTimezone($timezone)->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()->startOfDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'newConversations' => 0, 'inbound' => 0, 'outbound' => 0, 'campaign' => 0, 'windowsCustomer' => 0, 'windowsBusiness' => 0];
        }

        $bucket = "date(date_add(%s, interval {$offset} second))";

        $messages = Message::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->toBase()->selectRaw(sprintf($bucket, 'occurred_at').' as day, direction, sender_type, count(*) as total')
            ->groupBy('day', 'direction', 'sender_type')
            ->get();

        foreach ($messages as $row) {
            $key = (string) $row->day;

            if (! isset($days[$key])) {
                continue;
            }

            $field = $row->direction === Direction::Inbound->value
                ? 'inbound'
                : ($row->sender_type === SenderType::Campaign->value ? 'campaign' : 'outbound');
            $days[$key][$field] += (int) $row->total;
        }

        $conversations = Conversation::query()
            ->whereBetween('created_at', [$from, $to])
            ->toBase()->selectRaw(sprintf($bucket, 'created_at').' as day, count(*) as total')
            ->groupBy('day')
            ->get();

        foreach ($conversations as $row) {
            $key = (string) $row->day;

            if (isset($days[$key])) {
                $days[$key]['newConversations'] += (int) $row->total;
            }
        }

        $windows = Window::query()
            ->whereBetween('opened_at', [$from, $to])
            ->toBase()->selectRaw(sprintf($bucket, 'opened_at').' as day, origin, count(*) as total')
            ->groupBy('day', 'origin')
            ->get();

        foreach ($windows as $row) {
            $key = (string) $row->day;

            if (isset($days[$key])) {
                $days[$key][$row->origin === 'customer' ? 'windowsCustomer' : 'windowsBusiness'] += (int) $row->total;
            }
        }

        return array_values($days);
    }

    /** Campaigns waiting or sending now. */
    public function activeCampaigns(): int
    {
        return Campaign::query()->whereIn('status', [CampaignStatus::Scheduled->value, CampaignStatus::Sending->value])->count();
    }
}
