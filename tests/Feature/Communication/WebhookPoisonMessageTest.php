<?php

declare(strict_types=1);

use App\Core\Communication\Contracts\ChannelEventListener;
use App\Core\Communication\Data\ChannelEvent;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Models\Interaction;
use App\Modules\Communication\Models\WebhookEvent;
use Tests\Support\InteractsWithCommunication;

uses(InteractsWithCommunication::class);

/** Fails for one deterministic text, counts what it was handed. */
final class PoisonAwareListener implements ChannelEventListener
{
    /** @var list<string> */
    public static array $seen = [];

    public function handle(ChannelEvent $event): void
    {
        self::$seen[] = (string) $event->text;

        if ($event->text === 'poison') {
            throw new RuntimeException('boom with customer text');
        }
    }
}

beforeEach(function () {
    PoisonAwareListener::$seen = [];
    config(['communication.webhook_max_failures' => 3]);
    $this->tenant = $this->commTenant('comm-poison');
    $this->connectFakeProvider($this->tenant, Channel::Sms);
    $this->commContact($this->tenant, ['phone' => '+8801711223344']);
    $this->app->bind(PoisonAwareListener::class, fn () => new PoisonAwareListener);
    $this->app->tag([PoisonAwareListener::class], ChannelEventListener::TAG);

    $this->payload = ['events' => [
        ['id' => 'in-poison', 'type' => 'inbound', 'address' => '01711223344', 'text' => 'poison'],
        ['id' => 'in-good', 'type' => 'inbound', 'address' => '01711223344', 'text' => 'Please call me back'],
    ]];
});

it('applies the later events of a payload when an earlier one fails', function () {
    $this->webhook($this->tenant, 'fake_sms', $this->payload)->assertStatus(503);

    $this->inTenant($this->tenant, function (): void {
        // Only the good event was kept; the failing one rolled back and will be redelivered.
        expect(WebhookEvent::query()->pluck('event_id')->all())->toBe(['in-good'])
            ->and(Interaction::query()->count())->toBe(1);
    });
});

it('does not run a listener again for an event that already succeeded when the payload is redelivered', function () {
    $this->webhook($this->tenant, 'fake_sms', $this->payload)->assertStatus(503);
    $this->webhook($this->tenant, 'fake_sms', $this->payload)->assertStatus(503);

    // `Please call me back` was handled exactly once; `poison` was attempted each time.
    expect(array_count_values(PoisonAwareListener::$seen))->toBe(['poison' => 2, 'Please call me back' => 1]);
});

it('gives up on a poison message after the configured number of failures and answers 200', function () {
    $this->webhook($this->tenant, 'fake_sms', $this->payload)->assertStatus(503);
    $this->webhook($this->tenant, 'fake_sms', $this->payload)->assertStatus(503);
    $this->webhook($this->tenant, 'fake_sms', $this->payload)->assertOk();

    $this->inTenant($this->tenant, function (): void {
        expect(WebhookEvent::query()->where('event_id', 'in-poison')->value('status'))->toBe('failed')
            ->and(WebhookEvent::query()->where('event_id', 'in-good')->value('status'))->toBe('applied')
            ->and(Interaction::query()->count())->toBe(1);
    });

    // The provider stops: a further redelivery is a duplicate and no listener runs.
    $before = count(PoisonAwareListener::$seen);
    $this->webhook($this->tenant, 'fake_sms', $this->payload)->assertOk();
    expect(count(PoisonAwareListener::$seen))->toBe($before);
});

it('counts failures per event, not per payload', function () {
    $other = ['events' => [['id' => 'in-poison-2', 'type' => 'inbound', 'address' => '01711223344', 'text' => 'poison']]];

    $this->webhook($this->tenant, 'fake_sms', $this->payload)->assertStatus(503);
    $this->webhook($this->tenant, 'fake_sms', $other)->assertStatus(503);
    $this->webhook($this->tenant, 'fake_sms', $this->payload)->assertStatus(503);

    $this->inTenant($this->tenant, function (): void {
        expect(WebhookEvent::query()->where('status', 'failed')->count())->toBe(0);
    });
});
