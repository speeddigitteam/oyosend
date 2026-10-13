<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditLog;
use App\Core\Communication\Contracts\ConsentRecorder;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\NullConsentRecorder;
use App\Modules\Communication\Actions\ConsentManager;
use App\Modules\Communication\Actions\SuppressionManager;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConsentStatus;
use App\Modules\Communication\Enums\SuppressionReason;
use App\Modules\Communication\Enums\SuppressionScope;
use App\Modules\Communication\Models\Consent;
use Tests\Support\InteractsWithCommunication;

uses(InteractsWithCommunication::class);

beforeEach(function () {
    $this->tenant = $this->commTenant('comm-optin');
    $this->contact = $this->commContact($this->tenant, ['email' => 'optin@example.test']);

    $this->optInCall = fn (string $method = 'double_opt_in', string $evidence = 'Double opt-in via form 01 confirmed'): bool => $this->inTenant(
        $this->tenant,
        fn () => app(ConsentRecorder::class)->recordOptIn($this->contact->id, MarketingChannel::Email, $method, $evidence),
    );

    $this->consentOf = fn (): ?Consent => $this->inTenant(
        $this->tenant,
        fn () => Consent::query()->where('contact_id', $this->contact->id)->where('channel', 'email')->first(),
    );
});

it('records a double opt-in as an audited form consent and is idempotent', function () {
    expect(($this->optInCall)())->toBeTrue();

    $consent = ($this->consentOf)();
    expect($consent->status)->toBe(ConsentStatus::OptedIn)
        ->and($consent->method)->toBe('form')
        ->and($consent->evidence)->toBe('Double opt-in via form 01 confirmed')
        ->and($this->inTenant($this->tenant, fn () => AuditLog::query()->where('event', 'communication.consent.recorded')->count()))->toBe(1);

    expect(($this->optInCall)('form', 'Something else'))->toBeTrue();
    expect(($this->consentOf)()->evidence)->toBe('Double opt-in via form 01 confirmed')
        ->and($this->inTenant($this->tenant, fn () => AuditLog::query()->where('event', 'communication.consent.recorded')->count()))->toBe(1);
});

it('refuses an unknown method and missing or oversized evidence', function () {
    expect(fn () => ($this->optInCall)('keyword'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ($this->optInCall)('form', '   '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ($this->optInCall)('form', str_repeat('x', 501)))->toThrow(InvalidArgumentException::class);

    expect(($this->consentOf)())->toBeNull();
});

it('does not override an unsubscribe, a bounce, a block or a complaint', function () {
    foreach ([
        [SuppressionScope::Marketing, SuppressionReason::Unsubscribed],
        [SuppressionScope::All, SuppressionReason::Bounced],
        [SuppressionScope::All, SuppressionReason::Complained],
        [SuppressionScope::Marketing, SuppressionReason::Manual],
    ] as [$scope, $reason]) {
        $suppression = $this->inTenant($this->tenant, fn () => app(SuppressionManager::class)->suppress(Channel::Email, 'optin@example.test', $scope, $reason, $this->contact->id));

        expect(($this->optInCall)())->toBeFalse()
            ->and(($this->consentOf)())->toBeNull();

        $this->inTenant($this->tenant, fn () => $suppression->delete());
    }

    expect(($this->optInCall)())->toBeTrue();
});

it('replaces only the contact\'s own plain opt-out, never a complaint', function () {
    $this->inTenant($this->tenant, fn () => app(ConsentManager::class)->record($this->contact, Channel::Email, ConsentStatus::OptedOut, 'other', 'Asked by phone'));

    expect(($this->optInCall)())->toBeTrue()
        ->and(($this->consentOf)()->status)->toBe(ConsentStatus::OptedIn);

    $this->inTenant($this->tenant, fn () => app(ConsentManager::class)->record($this->contact, Channel::Email, ConsentStatus::OptedOut, 'complaint'));

    expect(($this->optInCall)())->toBeFalse()
        ->and(($this->consentOf)()->status)->toBe(ConsentStatus::OptedOut);
});

it('records nothing when the app is not entitled, for an unknown contact or one without an address', function () {
    $plain = $this->createTenant('comm-optin-plain');
    $contact = $this->commContact($plain, ['email' => 'plain@example.test']);

    expect($this->inTenant($plain, fn () => app(ConsentRecorder::class)->recordOptIn($contact->id, MarketingChannel::Email, 'form', 'Ticked')))->toBeFalse()
        ->and($this->inTenant($plain, fn () => Consent::query()->count()))->toBe(0);

    // A contact of another workspace is not found from this one.
    expect($this->inTenant($this->tenant, fn () => app(ConsentRecorder::class)->recordOptIn($contact->id, MarketingChannel::Email, 'form', 'Ticked')))->toBeFalse();

    $noAddress = $this->commContact($this->tenant, ['email' => null]);
    expect($this->inTenant($this->tenant, fn () => app(ConsentRecorder::class)->recordOptIn($noAddress->id, MarketingChannel::Email, 'form', 'Ticked')))->toBeFalse();
});

it('has a null implementation that records nothing', function () {
    expect((new NullConsentRecorder)->recordOptIn('x', MarketingChannel::Email, 'form', 'Ticked'))->toBeFalse();
});
