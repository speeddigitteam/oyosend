<?php

declare(strict_types=1);

use App\Core\Communication\Contracts\ConsentRecorder;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Contacts\Models\Contact;
use App\Modules\Communication\Actions\ContactLocator;
use App\Modules\Communication\Actions\Outbox;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\MessagePurpose;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithCommunication;

uses(InteractsWithCommunication::class);

beforeEach(function () {
    $this->tenant = $this->commTenant('comm-locator');
    $this->locate = fn (string $address): ?string => $this->inTenant(
        $this->tenant,
        fn (): ?string => app(ContactLocator::class)->idFor(Channel::Sms, $address),
    );
});

it('keeps the canonical phone in step with the contact on create and update', function () {
    $contact = $this->commContact($this->tenant, ['phone' => '01712.345.678']);

    $this->inTenant($this->tenant, function () use ($contact): void {
        expect(Contact::query()->findOrFail($contact->id)->phone_e164)->toBe('+8801712345678');

        $contact->update(['phone' => '০১৮১২৩৪৫৬৭৮']);
        expect($contact->fresh()->phone_e164)->toBe('+8801812345678');

        $contact->update(['phone' => 'call the office']);
        expect($contact->fresh()->phone_e164)->toBeNull();

        // The column is internal: it is not serialised.
        expect($contact->fresh()->toArray())->not->toHaveKey('phone_e164');
    });
});

it('finds a contact saved with dots or Bengali digits through the indexed column', function () {
    $dotted = $this->commContact($this->tenant, ['phone' => '01712.345.678']);
    $bengali = $this->commContact($this->tenant, ['phone' => '০১৮১২৩৪৫৬৭৮']);

    expect(($this->locate)('+8801712345678'))->toBe($dotted->id)
        ->and(($this->locate)('+8801812345678'))->toBe($bengali->id)
        ->and(($this->locate)('+8801912345678'))->toBeNull();
});

it('falls back to the spelling-tolerant match for a row that has no canonical phone', function () {
    $contact = $this->commContact($this->tenant, ['phone' => '+880 1712-345678']);
    DB::table('contacts')->where('id', $contact->id)->where('tenant_id', $this->tenant->id)->update(['phone_e164' => null]);

    expect(($this->locate)('+8801712345678'))->toBe($contact->id);
});

it('backfills the canonical phone for existing rows', function () {
    $contact = $this->commContact($this->tenant, ['phone' => '+880 (0)1712-345678']);
    DB::table('contacts')->where('id', $contact->id)->where('tenant_id', $this->tenant->id)->update(['phone_e164' => null]);

    (require base_path('database/migrations/2026_10_29_000400_add_phone_e164_to_contacts.php'))->up();

    expect(DB::table('contacts')->where('id', $contact->id)->value('phone_e164'))->toBe('+8801712345678');
});

it('never finds another workspace\'s contact', function () {
    $other = $this->commTenant('comm-locator-2');
    $this->commContact($other, ['phone' => '01712345678']);

    expect(($this->locate)('+8801712345678'))->toBeNull();
});

it('suppresses the number the opt-out came from as well as the contact\'s saved number', function () {
    $contact = $this->commContact($this->tenant, ['phone' => '01712345678']);

    $this->inTenant($this->tenant, function () use ($contact): void {
        $recorded = app(ConsentRecorder::class)->recordOptOut(
            $contact->id,
            MarketingChannel::Whatsapp,
            'keyword',
            'WhatsApp keyword',
            '+8801912345678',
        );

        expect($recorded)->toBeTrue();

        $outbox = app(Outbox::class);

        foreach (['+8801712345678', '+8801912345678'] as $number) {
            expect($outbox->blockedReason(Channel::Whatsapp, MessagePurpose::Marketing, $number, null))
                ->toBe('suppressed');
        }
    });
});
