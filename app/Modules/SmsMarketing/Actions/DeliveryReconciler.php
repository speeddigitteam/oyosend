<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Actions;

use App\Core\Communication\Contracts\ChannelGateway;
use App\Modules\SmsMarketing\Enums\RecipientStatus;
use App\Modules\SmsMarketing\Models\Recipient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Catches up after downtime: recipients that were handed to the communication platform but never heard back
 * are asked about, and a claim that never reached the platform (a crashed worker) is put back in the queue.
 * Both are safe to repeat.
 */
final class DeliveryReconciler
{
    /** A claim with no message id older than this many minutes never reached the platform. */
    private const int ORPHAN_MINUTES = 10;

    public function __construct(
        private readonly ChannelGateway $gateway,
        private readonly RecipientStatusApplier $applier,
    ) {}

    /**
     * @return array{reconciled: int, requeued: int}
     */
    public function run(): array
    {
        $now = CarbonImmutable::now();
        $reconciled = 0;

        $waiting = Recipient::query()
            ->where('status', RecipientStatus::Queued->value)
            ->whereNotNull('message_id')
            ->where('queued_at', '<=', $now->subMinutes((int) config('sms_marketing.reconcile_after_minutes', 15)))
            ->orderBy('queued_at')
            ->limit(2000)
            ->get(['id', 'message_id']);

        foreach ($waiting->chunk(ChannelGateway::MAX_BATCH) as $chunk) {
            $byMessage = $chunk->pluck('id', 'message_id')->all();
            $reports = $this->gateway->statusForMany(array_map('strval', array_keys($byMessage)));

            foreach ($reports as $messageId => $report) {
                $recipientId = $byMessage[$messageId] ?? null;

                if ($recipientId !== null && $report->state->isFinal() && $this->applier->apply($recipientId, $report->state, $report->failureCode)) {
                    $reconciled++;
                }
            }
        }

        // The attempt is given back: nothing reached the platform, so the same idempotency key is used again.
        $requeued = Recipient::query()
            ->where('status', RecipientStatus::Queued->value)
            ->whereNull('message_id')
            ->where('queued_at', '<=', $now->subMinutes(self::ORPHAN_MINUTES))
            ->update([
                'status' => RecipientStatus::Pending->value,
                'attempts' => DB::raw('GREATEST(attempts - 1, 0)'),
                'updated_at' => $now,
            ]);

        return ['reconciled' => $reconciled, 'requeued' => $requeued];
    }
}
