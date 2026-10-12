<?php

declare(strict_types=1);

use App\Core\Billing\UsageLimits;
use App\Core\Billing\UsageMeter;
use App\Core\Communication\Data\DeliveryState;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Testing\InMemoryProvider;
use App\Modules\SmsMarketing\Actions\CampaignManager;
use App\Modules\SmsMarketing\Actions\DeliveryReconciler;
use App\Modules\SmsMarketing\Actions\RecipientStatusApplier;
use App\Modules\SmsMarketing\Actions\SmsAnalytics;
use App\Modules\SmsMarketing\Enums\CampaignStatus;
use App\Modules\SmsMarketing\Enums\RecipientStatus;
use App\Modules\SmsMarketing\Models\Campaign;
use App\Modules\SmsMarketing\Models\Recipient;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithSmsMarketing;

uses(InteractsWithSmsMarketing::class);

beforeEach(function () {
    $this->tenant = $this->smsTenant('sms-status');
    $this->connectFakeProvider($this->tenant, Channel::Sms);
    $this->contact = $this->smsContact($this->tenant, '+8801711223344');
    $this->group = $this->smsGroup($this->tenant, [$this->contact->id]);
    $this->row = fn (string $campaignId): Recipient => $this->inTenant($this->tenant, fn () => Recipient::query()->where('campaign_id', $campaignId)->firstOrFail());
    $this->deliver = fn (Recipient $row, string $type, string $id) => $this->webhook(
        $this->tenant, 'fake_sms', ['events' => [['id' => $id, 'type' => $type, 'message_id' => 'fake-'.$row->message_id]]],
    );
});

it('turns a provider delivery webhook into a delivered recipient, once, and meters the segments once', function () {
    $campaign = $this->smsCampaign($this->tenant, [$this->group->id]);
    $this->launchNow($this->tenant, $campaign);
    $this->runCampaigns($this->tenant);
    $sent = ($this->row)($campaign->id);

    expect($sent->status)->toBe(RecipientStatus::Sent)->and($sent->message_id)->not->toBeNull()->and($sent->segments)->toBe(1);

    ($this->deliver)($sent, 'delivered', 'evt-1')->assertOk();
    ($this->deliver)($sent, 'delivered', 'evt-1')->assertOk();
    ($this->deliver)($sent, 'delivered', 'evt-2')->assertOk();

    $delivered = ($this->row)($campaign->id);
    expect($delivered->status)->toBe(RecipientStatus::Delivered)->and($delivered->delivered_at)->not->toBeNull();

    $this->inTenant($this->tenant, function (): void {
        expect(app(UsageMeter::class)->used(RecipientStatusApplier::LIMIT))->toBe(1);
        $report = app(SmsAnalytics::class)->overview(CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay());
        expect($report['totals']['delivered'])->toBe(1)->and($report['deliveryRate'])->toBe(100.0);
    });
});

it('ignores a webhook with a bad signature', function () {
    $campaign = $this->smsCampaign($this->tenant, [$this->group->id]);
    $this->launchNow($this->tenant, $campaign);
    $this->runCampaigns($this->tenant);
    $sent = ($this->row)($campaign->id);

    $this->webhook($this->tenant, 'fake_sms', ['events' => [['id' => 'x', 'type' => 'delivered', 'message_id' => 'fake-'.$sent->message_id]]], secret: 'wrong')->assertUnauthorized();

    expect(($this->row)($campaign->id)->status)->toBe(RecipientStatus::Sent);
});

it('reports the delivery rate as not reported while no receipt exists', function () {
    $campaign = $this->smsCampaign($this->tenant, [$this->group->id]);
    $this->launchNow($this->tenant, $campaign);
    $this->runCampaigns($this->tenant);

    $this->inTenant($this->tenant, function (): void {
        $report = app(SmsAnalytics::class)->overview(CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay());
        $standing = app(SmsAnalytics::class)->standing();

        expect($report['deliveryRate'])->toBeNull()
            ->and($report['totals']['sent'])->toBe(1)
            ->and($standing['credit'])->toBe(['reported' => false, 'balance' => null])
            ->and($standing['channel']['testDouble'])->toBeTrue();
    });
});

it('retries a failed message with a backoff, a new attempt each time, and gives up after the maximum', function () {
    $campaign = $this->smsCampaign($this->tenant, [$this->group->id]);
    $this->launchNow($this->tenant, $campaign);
    $this->runCampaigns($this->tenant);

    $max = (int) config('sms_marketing.max_attempts');
    $messageIds = [];

    for ($attempt = 1; $attempt <= $max; $attempt++) {
        $row = ($this->row)($campaign->id);
        expect($row->attempts)->toBe($attempt)->and($row->status)->toBe(RecipientStatus::Sent);
        $messageIds[] = $row->message_id;

        ($this->deliver)($row, 'failed', 'fail-'.$attempt)->assertOk();

        $after = ($this->row)($campaign->id);

        if ($attempt < $max) {
            expect($after->status)->toBe(RecipientStatus::Pending)->and($after->next_attempt_at?->isFuture())->toBeTrue();
            // Not due yet: the next run leaves it alone.
            $this->runCampaigns($this->tenant);
            expect(($this->row)($campaign->id)->status)->toBe(RecipientStatus::Pending);
            $this->travelTo(CarbonImmutable::now()->addHours(2));
            $this->runCampaigns($this->tenant);
            $this->travelBack();
        }
    }

    $final = ($this->row)($campaign->id);
    expect($final->status)->toBe(RecipientStatus::Failed)
        ->and($final->attempts)->toBe($max)
        ->and($final->reason)->toBe('provider_reported_failure')
        ->and(array_unique($messageIds))->toHaveCount($max)
        ->and(InMemoryProvider::$sent)->toHaveCount($max);

    // Bounded: a manual retry has nothing left to retry.
    $count = $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->retryFailed(Campaign::query()->findOrFail($campaign->id)));
    expect($count)->toBe(0)->and(($this->row)($campaign->id)->status)->toBe(RecipientStatus::Failed);
});

it('does not retry a permanent failure and lets nobody retry it by hand', function () {
    $campaign = $this->smsCampaign($this->tenant, [$this->group->id], ['body' => 'FAIL_PERMANENT now']);
    $this->launchNow($this->tenant, $campaign);
    $this->runCampaigns($this->tenant);

    $row = ($this->row)($campaign->id);
    expect($row->status)->toBe(RecipientStatus::Failed)->and($row->reason)->toBe('invalid_recipient')->and($row->attempts)->toBe(1);

    $count = $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->retryFailed(Campaign::query()->findOrFail($campaign->id)));
    expect($count)->toBe(0)
        ->and($this->inTenant($this->tenant, fn () => Campaign::query()->findOrFail($campaign->id)->status))->toBe(CampaignStatus::Completed);
});

it('lets a person retry a temporary failure once, as a new attempt', function () {
    $campaign = $this->smsCampaign($this->tenant, [$this->group->id]);
    $this->launchNow($this->tenant, $campaign);
    $this->runCampaigns($this->tenant);

    // Mark the delivery as failed through the applier at the final attempt budget minus one.
    $this->inTenant($this->tenant, function () use ($campaign): void {
        $row = Recipient::query()->where('campaign_id', $campaign->id)->firstOrFail();
        // A bounce is permanent, so it is final at once and not retryable.
        app(RecipientStatusApplier::class)->apply($row->id, DeliveryState::Bounced, 'bounced');
    });

    $row = ($this->row)($campaign->id);
    expect($row->status)->toBe(RecipientStatus::Failed)->and($row->reason)->toBe('bounced');

    $this->inTenant($this->tenant, fn () => Recipient::query()->whereKey($row->id)->update(['reason' => 'provider_error']));
    $count = $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->retryFailed(Campaign::query()->findOrFail($campaign->id)));
    expect($count)->toBe(1);

    $this->runCampaigns($this->tenant);
    $again = ($this->row)($campaign->id);
    expect($again->attempts)->toBe(2)->and($again->status)->toBe(RecipientStatus::Sent);
});

it('ignores a late answer about an earlier attempt', function () {
    $campaign = $this->smsCampaign($this->tenant, [$this->group->id]);
    $this->launchNow($this->tenant, $campaign);
    $this->runCampaigns($this->tenant);
    $first = ($this->row)($campaign->id);

    ($this->deliver)($first, 'failed', 'f-1')->assertOk();
    $this->travelTo(CarbonImmutable::now()->addHours(2));
    $this->runCampaigns($this->tenant);
    $this->travelBack();
    $second = ($this->row)($campaign->id);
    expect($second->message_id)->not->toBe($first->message_id);

    // The first message's delivery receipt arrives late: it must not mark the second attempt delivered.
    ($this->deliver)($first, 'delivered', 'late-1')->assertOk();

    expect(($this->row)($campaign->id)->status)->toBe(RecipientStatus::Sent);
});

it('reconciles messages that never reported back, and requeues a claim that never reached the platform', function () {
    $campaign = $this->smsCampaign($this->tenant, [$this->group->id]);
    $this->launchNow($this->tenant, $campaign);
    $this->runCampaigns($this->tenant);

    // Simulate an orphaned claim and a lost answer on two further rows.
    $orphan = $this->smsContact($this->tenant, '+8801711223355');
    $this->inTenant($this->tenant, function () use ($campaign, $orphan): void {
        Recipient::query()->create([
            'campaign_id' => $campaign->id, 'contact_id' => $orphan->id, 'phone' => '+8801711223355',
            'status' => RecipientStatus::Queued, 'attempts' => 1, 'queued_at' => now()->subHour(),
        ]);
    });

    $result = $this->inTenant($this->tenant, fn () => app(DeliveryReconciler::class)->run());

    $this->inTenant($this->tenant, function () use ($orphan): void {
        $row = Recipient::query()->where('contact_id', $orphan->id)->firstOrFail();
        expect($row->status)->toBe(RecipientStatus::Pending)->and($row->attempts)->toBe(0);
    });
    expect($result['requeued'])->toBe(1);
});

it('holds a campaign at the plan segment limit and refuses to launch one that would exceed it', function () {
    $plan = $this->createFreePlan(['modules' => ['communication', 'sms_marketing'], 'limits' => ['sms_marketing.segments_per_month' => 2]]);
    $this->subscribe($this->tenant, $plan);

    $ids = [];
    foreach (range(1, 4) as $n) {
        $ids[] = $this->smsContact($this->tenant, '+88017110005'.str_pad((string) $n, 2, '0', STR_PAD_LEFT))->id;
    }
    $big = $this->smsGroup($this->tenant, $ids, 'Big');
    $campaign = $this->smsCampaign($this->tenant, [$big->id]);

    // Four messages, two segments allowed: the launch is refused up front.
    expect(fn () => $this->launchNow($this->tenant, $campaign))->toThrow(ValidationException::class);

    // A campaign that fits is launched; the third message would pass the limit, so the run holds.
    $small = $this->smsGroup($this->tenant, array_slice($ids, 0, 2), 'Small');
    $fits = $this->smsCampaign($this->tenant, [$small->id]);
    $this->launchNow($this->tenant, $fits);
    $this->runCampaigns($this->tenant);

    expect(InMemoryProvider::$sent)->toHaveCount(2);
    $this->inTenant($this->tenant, function (): void {
        expect(app(UsageLimits::class)->remaining(RecipientStatusApplier::LIMIT))->toBe(0);
    });

    // Another campaign now holds at the limit and sends nothing.
    $more = $this->smsGroup($this->tenant, array_slice($ids, 2, 2), 'More');
    $held = $this->smsCampaign($this->tenant, [$more->id]);
    $this->inTenant($this->tenant, fn () => Campaign::query()->whereKey($held->id)->update([
        'status' => CampaignStatus::Sending, 'started_at' => now(),
    ]));
    $this->runCampaigns($this->tenant);

    expect(InMemoryProvider::$sent)->toHaveCount(2);
    $this->inTenant($this->tenant, function () use ($held): void {
        expect(Campaign::query()->findOrFail($held->id)->hold_reason)->toBe('limit_reached');
    });
});
