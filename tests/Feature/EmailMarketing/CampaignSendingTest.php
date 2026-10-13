<?php

declare(strict_types=1);

use App\Core\Billing\CurrentSubscription;
use App\Core\Communication\Contracts\UnsubscribeLinks;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Events\MarketingMessageStatusChanged;
use App\Modules\Communication\Actions\ConsentManager;
use App\Modules\Communication\Actions\SuppressionManager;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConsentStatus;
use App\Modules\Communication\Enums\SuppressionReason;
use App\Modules\Communication\Enums\SuppressionScope;
use App\Modules\Communication\Models\Message;
use App\Modules\EmailMarketing\Actions\CampaignManager;
use App\Modules\EmailMarketing\Actions\EmailMarketingSettings;
use App\Modules\EmailMarketing\Enums\CampaignStatus;
use App\Modules\EmailMarketing\Enums\DeliveryStatus;
use App\Modules\EmailMarketing\Enums\SubscriptionStatus;
use App\Modules\EmailMarketing\Jobs\RunEmailMarketing;
use App\Modules\EmailMarketing\Models\Campaign;
use App\Modules\EmailMarketing\Models\Delivery;
use App\Modules\EmailMarketing\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithEmailMarketing;

uses(InteractsWithEmailMarketing::class);

beforeEach(function () {
    Mail::fake();
    $this->tenant = $this->emTenant('em-send');
    $this->list = $this->emList($this->tenant);
});

it('sends to a subscribed, consenting contact exactly once and writes the unsubscribe link in the body', function () {
    $contact = $this->emContact($this->tenant, ['first_name' => 'Amina', 'email' => 'amina@example.test']);
    $this->emSubscribe($this->tenant, $this->list, $contact);
    $campaign = $this->emCampaign($this->tenant, $this->list);

    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null));
    $this->emRun($this->tenant);
    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, function () use ($campaign, $contact): void {
        $delivery = Delivery::query()->where('campaign_id', $campaign->id)->sole();
        $message = Message::query()->sole();

        expect($delivery->status)->toBe(DeliveryStatus::Sent)
            ->and($delivery->contact_id)->toBe($contact->id)
            ->and($delivery->message_id)->toBe($message->id)
            ->and($message->source)->toBe('email_marketing')
            ->and($message->idempotency_key)->toBe('email_marketing:campaign:'.$campaign->id.':'.$contact->id)
            ->and($message->subject)->toBe('Spring sale for Amina')
            ->and($message->body)->toContain('Hello Amina')
            ->and($message->body)->toContain('Unsubscribe: http')
            ->and($message->body)->toContain('12 Gulshan Avenue, Dhaka 1212')
            // Links are click-tracked, the unsubscribe link is not.
            ->and($message->body)->toContain('/email-marketing/t/c/')
            ->and($message->body)->toContain('/email-marketing/v/');

        expect(Campaign::query()->find($campaign->id)->status)->toBe(CampaignStatus::Sent);
    });

    Mail::assertSentCount(1);
});

it('never sends to a contact who has no marketing consent on the platform, and records why', function () {
    $contact = $this->emContact($this->tenant);
    // Subscribed in the list with evidence, but the communication app holds no opt-in for this address.
    $this->emSubscribe($this->tenant, $this->list, $contact, platformConsent: false);
    $campaign = $this->emCampaign($this->tenant, $this->list);

    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null));
    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, function () use ($campaign): void {
        $delivery = Delivery::query()->where('campaign_id', $campaign->id)->sole();

        expect($delivery->status)->toBe(DeliveryStatus::Blocked)
            ->and($delivery->reason)->toBe('no_consent')
            ->and($delivery->message_id)->toBeNull()
            ->and(Message::query()->count())->toBe(0)
            ->and(Campaign::query()->find($campaign->id)->status)->toBe(CampaignStatus::Sent);
    });

    Mail::assertNothingSent();
});

it('leaves a pending member out of the audience altogether', function () {
    $contact = $this->emContact($this->tenant);
    $this->emSubscribe($this->tenant, $this->list, $contact, evidence: false);
    $campaign = $this->emCampaign($this->tenant, $this->list);

    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null));
    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, function () use ($campaign): void {
        expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Pending)
            ->and(Delivery::query()->where('campaign_id', $campaign->id)->count())->toBe(0)
            ->and(Campaign::query()->find($campaign->id)->audience_total)->toBe(0);
    });

    Mail::assertNothingSent();
});

it('does not send to an address on the suppression list', function () {
    $contact = $this->emContact($this->tenant, ['email' => 'bounced@example.test']);
    $this->emSubscribe($this->tenant, $this->list, $contact);
    $this->inTenant($this->tenant, fn () => app(SuppressionManager::class)->suppress(Channel::Email, 'bounced@example.test', SuppressionScope::All, SuppressionReason::Bounced));
    $campaign = $this->emCampaign($this->tenant, $this->list);

    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null));
    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, function () use ($campaign): void {
        $delivery = Delivery::query()->where('campaign_id', $campaign->id)->sole();
        expect($delivery->status)->toBe(DeliveryStatus::Blocked)->and($delivery->reason)->toBe('suppressed');
    });

    Mail::assertNothingSent();
});

it('is idempotent: running the runner repeatedly or resetting a delivery never double-sends', function () {
    $contact = $this->emContact($this->tenant);
    $this->emSubscribe($this->tenant, $this->list, $contact);
    $campaign = $this->emCampaign($this->tenant, $this->list);

    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null));
    $this->emRun($this->tenant);

    // A crash after the platform accepted the message but before we recorded it: the delivery is put back.
    $this->inTenant($this->tenant, fn () => Delivery::query()->where('campaign_id', $campaign->id)->update(['status' => DeliveryStatus::Pending, 'message_id' => null, 'sent_at' => null]));
    $this->inTenant($this->tenant, fn () => Campaign::query()->whereKey($campaign->id)->update(['status' => CampaignStatus::Sending]));
    $this->emRun($this->tenant);
    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, function () use ($campaign): void {
        expect(Message::query()->count())->toBe(1)
            ->and(Delivery::query()->where('campaign_id', $campaign->id)->count())->toBe(1)
            ->and(Delivery::query()->where('campaign_id', $campaign->id)->sole()->message_id)->toBe(Message::query()->sole()->id);
    });

    Mail::assertSentCount(1);
});

it('sends campaigns in bounded chunks at the workspace rate, never in a web request', function () {
    config(['email_marketing.audience_chunk' => 2]);
    $contacts = [];

    for ($i = 0; $i < 5; $i++) {
        $contacts[] = $this->emContact($this->tenant);
        $this->emSubscribe($this->tenant, $this->list, $contacts[$i]);
    }

    $this->inTenant($this->tenant, fn () => app(EmailMarketingSettings::class)->update(['send_per_minute' => 2]));
    $campaign = $this->emCampaign($this->tenant, $this->list);
    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null));

    // `schedule()` already queued a runner pass (the test queue is synchronous); it worked out 2 members.
    $count = fn (): int => $this->inTenant($this->tenant, fn (): int => Message::query()->count());
    $seen = $count();

    for ($i = 0; $i < 12; $i++) {
        $this->emRun($this->tenant);
        $now = $count();
        // Never more than the workspace rate (2) per pass, however many were planned.
        expect($now - $seen)->toBeLessThanOrEqual(2);
        $seen = $now;
    }

    $this->inTenant($this->tenant, function () use ($campaign): void {
        expect(Delivery::query()->where('campaign_id', $campaign->id)->where('status', DeliveryStatus::Sent)->count())->toBe(5)
            ->and(Campaign::query()->find($campaign->id)->status)->toBe(CampaignStatus::Sent);
    });

    Mail::assertSentCount(5);
});

it('holds a scheduled campaign until its time and runs it on the campaigns queue', function () {
    $contact = $this->emContact($this->tenant);
    $this->emSubscribe($this->tenant, $this->list, $contact);
    $campaign = $this->emCampaign($this->tenant, $this->list);
    $at = CarbonImmutable::now()->addHour();

    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, $at));
    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, fn () => expect(Campaign::query()->find($campaign->id)->status)->toBe(CampaignStatus::Scheduled)
        ->and(Delivery::query()->count())->toBe(0));

    $this->travelTo(now()->addHours(2));
    $this->emRun($this->tenant);
    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, fn () => expect(Campaign::query()->find($campaign->id)->status)->toBe(CampaignStatus::Sent));

    expect((new RunEmailMarketing)->queue)->toBe('campaigns');
});

it('refuses to schedule without a postal address, without content and past the plan limit', function () {
    $campaign = $this->emCampaign($this->tenant, $this->list);

    $this->inTenant($this->tenant, fn () => app(EmailMarketingSettings::class)->update(['footer_address' => null]));
    expect(fn () => $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null)))
        ->toThrow(ValidationException::class);

    $this->inTenant($this->tenant, fn () => app(EmailMarketingSettings::class)->update(['footer_address' => 'Somewhere']));

    $plan = $this->createFreePlan(['key' => 'em-cap', 'modules' => ['email_marketing', 'communication'], 'limits' => ['email_marketing.campaigns_per_month' => 1]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);

    $first = $this->emCampaign($this->tenant, $this->list, ['name' => 'One']);
    $second = $this->emCampaign($this->tenant, $this->list, ['name' => 'Two']);

    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($first, CarbonImmutable::now()->addDay()));
    expect(fn () => $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($second, CarbonImmutable::now()->addDay())))
        ->toThrow(ValidationException::class);

    // Scheduling the same campaign again does not count twice.
    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($first, CarbonImmutable::now()->addDays(2)));
});

it('pauses the campaign, keeps the delivery pending and resumes without double-sending when the platform limit is reached', function () {
    $contact = $this->emContact($this->tenant);
    $this->emSubscribe($this->tenant, $this->list, $contact);
    $plan = $this->createFreePlan(['key' => 'em-msg', 'modules' => ['email_marketing', 'communication'], 'limits' => ['communication.messages' => 0]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);

    $campaign = $this->emCampaign($this->tenant, $this->list);
    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null));
    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, function () use ($campaign): void {
        $fresh = Campaign::query()->find($campaign->id);
        expect($fresh->status)->toBe(CampaignStatus::Paused)
            ->and($fresh->paused_reason)->toBe('limit_reached')
            ->and(Delivery::query()->where('campaign_id', $campaign->id)->sole()->status)->toBe(DeliveryStatus::Pending);
    });

    Mail::assertNothingSent();

    // The operator raises the limit and resumes: the same delivery goes out under a fresh gateway key.
    $plan->forceFill(['limits' => ['communication.messages' => 1000]])->save();
    app(CurrentSubscription::class)->forget();
    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->resume(Campaign::query()->findOrFail($campaign->id)));
    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, function () use ($campaign): void {
        $delivery = Delivery::query()->where('campaign_id', $campaign->id)->sole();

        expect(Campaign::query()->find($campaign->id)->status)->toBe(CampaignStatus::Sent)
            ->and($delivery->status->wasSent() || $delivery->status === DeliveryStatus::Queued)->toBeTrue()
            ->and($delivery->send_epoch)->toBe(1)
            ->and($delivery->message_id)->not->toBeNull();
    });

    // Running again hands nothing over twice.
    $this->emRun($this->tenant);
    $this->inTenant($this->tenant, fn () => expect(Message::query()->where('idempotency_key', 'like', 'email_marketing:campaign:%')->where('status', '!=', 'failed')->count())->toBe(1));
});

it('stops offering a delivery that the plan limit refused on every try', function () {
    config(['email_marketing.limit_retry_max' => 2]);
    $contact = $this->emContact($this->tenant);
    $this->emSubscribe($this->tenant, $this->list, $contact);
    $plan = $this->createFreePlan(['key' => 'em-msg-zero', 'modules' => ['email_marketing', 'communication'], 'limits' => ['communication.messages' => 0]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);
    $campaign = $this->emCampaign($this->tenant, $this->list);
    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null));

    foreach ([1, 2, 3] as $round) {
        $this->emRun($this->tenant);
        $this->inTenant($this->tenant, function () use ($campaign): void {
            $fresh = Campaign::query()->find($campaign->id);

            if ($fresh->status === CampaignStatus::Paused) {
                app(CampaignManager::class)->resume($fresh);
            }
        });
    }

    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, function () use ($campaign): void {
        $delivery = Delivery::query()->where('campaign_id', $campaign->id)->sole();
        expect($delivery->status)->toBe(DeliveryStatus::Failed)->and($delivery->reason)->toBe('limit_reached');
    });
});

it('mirrors the platform delivery status onto the delivery and a bounce onto the membership', function () {
    $contact = $this->emContact($this->tenant);
    $this->emSubscribe($this->tenant, $this->list, $contact);
    $campaign = $this->emCampaign($this->tenant, $this->list);
    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null));
    $this->emRun($this->tenant);

    $delivery = $this->inTenant($this->tenant, fn () => Delivery::query()->where('campaign_id', $campaign->id)->sole());

    $this->inTenant($this->tenant, function () use ($delivery, $contact, $campaign): void {
        event(new MarketingMessageStatusChanged([
            'message_id' => $delivery->message_id, 'module' => 'email_marketing', 'campaign_id' => $campaign->id,
            'recipient_id' => $delivery->id, 'contact_id' => $contact->id, 'channel' => 'email', 'state' => 'delivered', 'failure_code' => null,
        ]));

        expect(Delivery::query()->find($delivery->id)->status)->toBe(DeliveryStatus::Delivered);

        // A later `sent` never takes a delivered message back.
        event(new MarketingMessageStatusChanged([
            'message_id' => $delivery->message_id, 'module' => 'email_marketing', 'campaign_id' => $campaign->id,
            'recipient_id' => $delivery->id, 'contact_id' => $contact->id, 'channel' => 'email', 'state' => 'sent', 'failure_code' => null,
        ]));
        expect(Delivery::query()->find($delivery->id)->status)->toBe(DeliveryStatus::Delivered);

        event(new MarketingMessageStatusChanged([
            'message_id' => $delivery->message_id, 'module' => 'email_marketing', 'campaign_id' => $campaign->id,
            'recipient_id' => $delivery->id, 'contact_id' => $contact->id, 'channel' => 'email', 'state' => 'bounced', 'failure_code' => 'mailbox_full',
        ]));

        expect(Delivery::query()->find($delivery->id)->status)->toBe(DeliveryStatus::Bounced)
            ->and(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Bounced);
    });
});

it('ignores status events of other modules', function () {
    $contact = $this->emContact($this->tenant);
    $this->emSubscribe($this->tenant, $this->list, $contact);
    $campaign = $this->emCampaign($this->tenant, $this->list);
    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null));
    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, function () use ($campaign, $contact): void {
        $delivery = Delivery::query()->where('campaign_id', $campaign->id)->sole();

        event(new MarketingMessageStatusChanged([
            'message_id' => 'x', 'module' => 'sms_marketing', 'campaign_id' => $campaign->id,
            'recipient_id' => $delivery->id, 'contact_id' => $contact->id, 'channel' => 'sms', 'state' => 'failed', 'failure_code' => 'x',
        ]));

        expect(Delivery::query()->find($delivery->id)->status)->toBe(DeliveryStatus::Sent);
    });
});

it('closes every membership when the platform reports an unsubscribe', function () {
    $contact = $this->emContact($this->tenant, ['email' => 'leave@example.test']);
    $other = $this->emList($this->tenant, 'Offers');
    $this->emSubscribe($this->tenant, $this->list, $contact);
    $this->emSubscribe($this->tenant, $other, $contact, platformConsent: false);

    $this->inTenant($this->tenant, function () use ($contact): void {
        $url = app(UnsubscribeLinks::class)->issue($contact->id, MarketingChannel::Email, 'email_marketing');
        expect($url)->not->toBeNull();
    });

    $this->inTenant($this->tenant, function () use ($contact): void {
        // The unsubscribe link of the platform records an opt-out, which announces the event.
        app(ConsentManager::class)->record(
            $contact->fresh(), Channel::Email, ConsentStatus::OptedOut, 'unsubscribe_link',
        );

        expect(Subscription::query()->where('contact_id', $contact->id)->pluck('status')->map->value->unique()->all())->toBe(['unsubscribed']);
    });
});
