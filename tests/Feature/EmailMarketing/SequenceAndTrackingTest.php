<?php

declare(strict_types=1);

use App\Core\Billing\CurrentSubscription;
use App\Modules\Communication\Models\Message;
use App\Modules\EmailMarketing\Actions\CampaignManager;
use App\Modules\EmailMarketing\Actions\SequenceManager;
use App\Modules\EmailMarketing\Actions\SubscriberManager;
use App\Modules\EmailMarketing\Enums\DeliveryStatus;
use App\Modules\EmailMarketing\Enums\EnrollmentStatus;
use App\Modules\EmailMarketing\Enums\SequenceStatus;
use App\Modules\EmailMarketing\Models\Delivery;
use App\Modules\EmailMarketing\Models\Enrollment;
use App\Modules\EmailMarketing\Models\Sequence;
use App\Modules\EmailMarketing\Support\TrackingTokens;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithEmailMarketing;

uses(InteractsWithEmailMarketing::class);

beforeEach(function () {
    Mail::fake();
    $this->tenant = $this->emTenant('em-seq');
    $this->list = $this->emList($this->tenant, 'Welcome list');
    $this->steps = [
        ['delay_minutes' => 0, 'subject' => 'Welcome {{first_name|friend}}', 'blocks' => [['type' => 'text', 'text' => 'Glad you are here.']]],
        ['delay_minutes' => 60 * 24, 'subject' => 'Day two', 'blocks' => [['type' => 'text', 'text' => 'Here is how to start.']]],
    ];
    $this->sequence = fn (?array $steps = null) => $this->inTenant($this->tenant, fn (): Sequence => app(SequenceManager::class)->create([
        'name' => 'Welcome series', 'list_id' => $this->list->id, 'steps' => $steps ?? $this->steps,
    ]));
});

it('enrolls new subscribers of an active sequence and sends each step after its delay, once', function () {
    $sequence = ($this->sequence)();
    $this->inTenant($this->tenant, fn () => app(SequenceManager::class)->activate($sequence));

    $contact = $this->emContact($this->tenant, ['first_name' => 'Nadia']);
    $this->emSubscribe($this->tenant, $this->list, $contact);

    $this->inTenant($this->tenant, fn () => expect(Enrollment::query()->sole()->status)->toBe(EnrollmentStatus::Active));

    $this->emRun($this->tenant);
    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, function (): void {
        expect(Message::query()->pluck('subject')->all())->toBe(['Welcome Nadia'])
            ->and(Delivery::query()->count())->toBe(1);
    });

    $this->travelTo(now()->addHours(25));
    $this->emRun($this->tenant);
    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, function (): void {
        expect(Message::query()->orderBy('created_at')->pluck('subject')->all())->toBe(['Welcome Nadia', 'Day two'])
            ->and(Enrollment::query()->sole()->status)->toBe(EnrollmentStatus::Completed);
    });

    Mail::assertSentCount(2);
});

it('does not send a step to someone without platform consent or who unsubscribed', function () {
    $sequence = ($this->sequence)();
    $this->inTenant($this->tenant, fn () => app(SequenceManager::class)->activate($sequence));

    $silent = $this->emContact($this->tenant);
    $this->emSubscribe($this->tenant, $this->list, $silent, platformConsent: false);

    $leaver = $this->emContact($this->tenant);
    $subscription = $this->emSubscribe($this->tenant, $this->list, $leaver);
    $this->inTenant($this->tenant, fn () => app(SubscriberManager::class)->unsubscribe($subscription));

    $this->emRun($this->tenant);
    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, function () use ($silent): void {
        expect(Delivery::query()->where('contact_id', $silent->id)->sole()->status)->toBe(DeliveryStatus::Blocked)
            ->and(Message::query()->where('contact_id', $silent->id)->sole()->status->value)->toBe('suppressed')
            ->and(Enrollment::query()->where('status', EnrollmentStatus::Stopped->value)->count())->toBe(1);
    });

    Mail::assertNothingSent();
});

it('does not enroll anyone while a sequence is a draft or paused, and never twice', function () {
    $sequence = ($this->sequence)();
    $first = $this->emContact($this->tenant);
    $this->emSubscribe($this->tenant, $this->list, $first);

    $this->inTenant($this->tenant, fn () => expect(Enrollment::query()->count())->toBe(0));

    $this->inTenant($this->tenant, fn () => app(SequenceManager::class)->activate($sequence));
    $second = $this->emContact($this->tenant);
    $this->emSubscribe($this->tenant, $this->list, $second);
    $this->emSubscribe($this->tenant, $this->list, $second);

    $this->inTenant($this->tenant, fn () => expect(Enrollment::query()->count())->toBe(1)->and(Sequence::query()->sole()->status)->toBe(SequenceStatus::Active));

    $this->inTenant($this->tenant, fn () => app(SequenceManager::class)->pause($sequence->fresh()));
    $third = $this->emContact($this->tenant);
    $this->emSubscribe($this->tenant, $this->list, $third);
    $this->inTenant($this->tenant, fn () => expect(Enrollment::query()->count())->toBe(1));
});

it('validates steps and refuses to activate an empty sequence or one without a postal address', function () {
    expect(fn () => ($this->sequence)([['delay_minutes' => -1, 'subject' => 'x', 'blocks' => [['type' => 'divider']]]]))->toThrow(ValidationException::class)
        ->and(fn () => ($this->sequence)([['delay_minutes' => 0, 'subject' => 'Hi {{nope}}', 'blocks' => [['type' => 'divider']]]]))->toThrow(ValidationException::class)
        ->and(fn () => ($this->sequence)(array_fill(0, 11, $this->steps[0])))->toThrow(ValidationException::class);

    $empty = ($this->sequence)([]);
    expect(fn () => $this->inTenant($this->tenant, fn () => app(SequenceManager::class)->activate($empty)))->toThrow(ValidationException::class);
});

it('serves a click redirect only to the address inside a signed token', function () {
    $contact = $this->emContact($this->tenant);
    $this->emSubscribe($this->tenant, $this->list, $contact);
    $campaign = $this->emCampaign($this->tenant, $this->list);
    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null));
    $this->emRun($this->tenant);

    $delivery = $this->inTenant($this->tenant, fn () => Delivery::query()->sole());
    $token = $this->inTenant($this->tenant, fn () => basename(app(TrackingTokens::class)->clickUrl($delivery->id, 'https://example.com/shop')));

    $this->get($this->tenantUrl($this->tenant, "/email-marketing/t/c/{$token}"))
        ->assertRedirect('https://example.com/shop')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    $this->get($this->tenantUrl($this->tenant, "/email-marketing/t/c/{$token}"))->assertRedirect();

    $this->inTenant($this->tenant, function () use ($delivery): void {
        $fresh = Delivery::query()->find($delivery->id);
        expect($fresh->click_count)->toBe(2)->and($fresh->clicked_at)->not->toBeNull();
    });

    // A forged payload, a swapped destination and a malformed value are all the same bare 404.
    [$payload, $signature] = explode('.', $token);
    $forged = rtrim(strtr(base64_encode(json_encode(['c', $this->tenant->id, $delivery->id, 'https://evil.test/'])), '+/', '-_'), '=');

    foreach (["{$forged}.{$signature}", "{$payload}.AAAA", 'garbage-token-garbage-token', $payload] as $bad) {
        $this->get($this->tenantUrl($this->tenant, "/email-marketing/t/c/{$bad}"))->assertNotFound();
    }

    // A token minted in one workspace is not valid in another.
    $other = $this->emTenant('em-other');
    $this->get($this->tenantUrl($other, "/email-marketing/t/c/{$token}"))->assertNotFound();
});

it('refuses a click token whose destination is not a web address', function () {
    $contact = $this->emContact($this->tenant);
    $this->emSubscribe($this->tenant, $this->list, $contact);
    $campaign = $this->emCampaign($this->tenant, $this->list);
    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null));
    $this->emRun($this->tenant);
    $delivery = $this->inTenant($this->tenant, fn () => Delivery::query()->sole());

    $token = $this->inTenant($this->tenant, fn () => basename(app(TrackingTokens::class)->clickUrl($delivery->id, 'javascript:alert(1)')));

    $this->get($this->tenantUrl($this->tenant, "/email-marketing/t/c/{$token}"))->assertNotFound();
});

it('serves the web version with a locked-down policy and counts the view', function () {
    $contact = $this->emContact($this->tenant, ['first_name' => 'Zara']);
    $this->emSubscribe($this->tenant, $this->list, $contact);
    $campaign = $this->emCampaign($this->tenant, $this->list);
    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null));
    $this->emRun($this->tenant);
    $delivery = $this->inTenant($this->tenant, fn () => Delivery::query()->sole());
    $token = $this->inTenant($this->tenant, fn () => basename(app(TrackingTokens::class)->viewUrl($delivery->id)));

    $response = $this->get($this->tenantUrl($this->tenant, "/email-marketing/v/{$token}"))->assertOk();

    expect($response->getContent())->toContain('Hello Zara')->and($response->getContent())->not->toContain('<script')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY');

    $this->inTenant($this->tenant, fn () => expect(Delivery::query()->sole()->view_count)->toBe(1));

    // A view token cannot be used as a click token or the other way round.
    $this->get($this->tenantUrl($this->tenant, "/email-marketing/t/c/{$token}"))->assertNotFound();
});

it('answers 404 for the public links when the workspace is not entitled to the app', function () {
    $plain = $this->createTenant('em-plain');

    $this->get($this->tenantUrl($plain, '/email-marketing/v/'.str_repeat('a', 40)))->assertNotFound();
    $this->get($this->tenantUrl($plain, '/email-marketing/t/c/'.str_repeat('a', 40)))->assertNotFound();
});

it('does not let a delivery refused for the plan limit hold back other sequences, and sends it once the limit is lifted', function () {
    $sequence = ($this->sequence)([['delay_minutes' => 0, 'subject' => 'Welcome {{first_name|friend}}', 'blocks' => [['type' => 'text', 'text' => 'Glad you are here.']]]]);
    $this->inTenant($this->tenant, fn () => app(SequenceManager::class)->activate($sequence));

    $plan = $this->createFreePlan(['key' => 'em-seq-zero', 'modules' => ['email_marketing', 'communication'], 'limits' => ['communication.messages' => 0]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);

    $first = $this->emContact($this->tenant, ['first_name' => 'Nadia']);
    $second = $this->emContact($this->tenant, ['first_name' => 'Karim']);
    $this->emSubscribe($this->tenant, $this->list, $first);
    $this->emSubscribe($this->tenant, $this->list, $second);

    $this->emRun($this->tenant);

    // Both were tried in the same pass (nobody stopped the pass), and both wait for the limit.
    $this->inTenant($this->tenant, function (): void {
        expect(Delivery::query()->where('status', DeliveryStatus::Pending->value)->where('send_epoch', 1)->count())->toBe(2)
            ->and(Enrollment::query()->where('status', EnrollmentStatus::Active->value)->where('next_due_at', '>', now())->count())->toBe(2);
    });

    $plan->forceFill(['limits' => ['communication.messages' => 1000]])->save();
    app(CurrentSubscription::class)->forget();
    $this->travelTo(now()->addMinutes(61));
    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, function (): void {
        expect(Message::query()->where('status', '!=', 'failed')->pluck('subject')->sort()->values()->all())->toBe(['Welcome Karim', 'Welcome Nadia'])
            ->and(Enrollment::query()->where('status', EnrollmentStatus::Completed->value)->count())->toBe(2);
    });
});
