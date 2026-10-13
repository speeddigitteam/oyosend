<?php

declare(strict_types=1);

use App\Core\Automation\AutomationRegistry;
use App\Core\Crm\Data\AudienceSegment;
use App\Core\Crm\Data\AudienceSegmentKind;
use App\Modules\WhatsappMarketing\Crm\CaptureLeadFromConversation;
use App\Modules\WhatsappMarketing\Crm\LeadCaptureSettings;
use App\Modules\WhatsappMarketing\Enums\CampaignStatus;
use App\Modules\WhatsappMarketing\Events\MessageReceived;
use App\Modules\WhatsappMarketing\Models\Campaign;
use App\Modules\WhatsappMarketing\Models\CapturedLead;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\ListMember;
use App\Modules\WhatsappMarketing\Models\Message;
use App\Modules\WhatsappMarketing\Models\Recipient;
use App\Modules\WhatsappMarketing\Reminders\Actions\SendAppointmentReminderAction;
use App\Modules\WhatsappMarketing\Reminders\Actions\SendInvoiceNotificationAction;
use App\Modules\WhatsappMarketing\Reminders\Actions\SendOrderConfirmationAction;
use App\Modules\WhatsappMarketing\Reminders\Actions\SendPaymentReminderAction;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithCrmContracts;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class, InteractsWithCrmContracts::class);

beforeEach(function () {
    $this->tenant = $this->waTenant('wa-crm');
    $this->waConnectCloud($this->tenant);
    [$this->user, $this->membership] = $this->waAdmin($this->tenant);
    $this->template = $this->waTemplate($this->tenant, ['name' => 'order_update']);
});

describe('broadcast to a CRM segment', function () {
    it('creates a draft from a segment, scoped to the launching member, and sends only to opted-in contacts', function () {
        $optedIn = $this->waContact($this->tenant, ['first_name' => 'Opted', 'phone' => '+8801711000011']);
        $noConsent = $this->waContact($this->tenant, ['first_name' => 'Nope', 'phone' => '+8801711000012']);
        $this->waOptIn($this->tenant, $optedIn);

        $segment = new AudienceSegment('lead_status:new', 'New leads', AudienceSegmentKind::LeadStatus, 2);
        $audience = $this->fakeCrmAudience([$segment], ['lead_status:new' => [$optedIn->id, $noConsent->id]]);

        // The form fetches the segments the member may broadcast to.
        $this->actingAsMember($this->tenant, $this->user)
            ->getJson($this->waUrl($this->tenant, '/campaigns/segments'))
            ->assertOk()
            ->assertJsonPath('segments.0.id', 'lead_status:new')
            ->assertJsonPath('segments.0.approximateSize', 2);

        $this->actingAsMember($this->tenant, $this->user)
            ->post($this->waUrl($this->tenant, '/campaigns/from-segment'), [
                'name' => 'VIP blast',
                'template_id' => $this->template->id,
                'segment_id' => 'lead_status:new',
                'variables' => [1 => ['type' => 'field', 'field' => 'contact.first_name'], 2 => ['type' => 'text', 'value' => 'Acme']],
            ])
            ->assertSessionHasNoErrors();

        // The segment was resolved for the launching member (record scope honoured by the contract).
        expect($audience->calls)->toHaveCount(1)
            ->and($audience->calls[0][0])->toBe('lead_status:new')
            ->and($audience->calls[0][1])->toBe($this->membership->id);

        $campaign = $this->inTenant($this->tenant, function () use ($optedIn, $noConsent) {
            $campaign = Campaign::query()->where('name', 'VIP blast')->firstOrFail();

            expect($campaign->status)->toBe(CampaignStatus::Draft)
                ->and(ListMember::query()->where('list_id', $campaign->list_id)->pluck('contact_id')->all())
                ->toEqualCanonicalizing([$optedIn->id, $noConsent->id]);

            return $campaign;
        });

        $this->actingAsMember($this->tenant, $this->user)
            ->post($this->waUrl($this->tenant, "/campaigns/{$campaign->id}/send"))
            ->assertSessionHasNoErrors();

        // Consent is still enforced by the platform: only the opted-in contact is reached.
        Http::assertSentCount(1);
        $this->inTenant($this->tenant, function () use ($campaign, $optedIn, $noConsent) {
            $states = Recipient::query()->where('campaign_id', $campaign->id)->pluck('state', 'contact_id')->map(fn ($s) => $s->value)->all();

            expect($states[$optedIn->id])->toBe('sent')
                ->and($states[$noConsent->id])->toBe('blocked');
        });
    });

    it('rejects a segment the member cannot see', function () {
        $this->fakeCrmAudience([], []);

        $this->actingAsMember($this->tenant, $this->user)
            ->post($this->waUrl($this->tenant, '/campaigns/from-segment'), [
                'name' => 'No segment',
                'template_id' => $this->template->id,
                'segment_id' => 'lead_status:new',
            ])
            ->assertSessionHasErrors('segment_id');

        $this->inTenant($this->tenant, fn () => expect(Campaign::query()->count())->toBe(0));
    });
});

describe('capture inbound conversations as CRM leads', function () {
    it('captures an unknown number once, records the ids and tags the conversation', function () {
        $intake = $this->fakeLeadIntake();
        $this->inTenant($this->tenant, fn () => app(LeadCaptureSettings::class)->update(['enabled' => true]));

        $this->waInbound($this->tenant, '+8801712009001', 'Hi, I would like a quote', 'wamid.LEAD1')->assertOk();

        expect($intake->captures)->toHaveCount(1)
            ->and($intake->captures[0]->source)->toBe('whatsapp')
            ->and($intake->captures[0]->phone)->toBe('+8801712009001');

        $this->inTenant($this->tenant, function () {
            $conversation = Conversation::query()->where('phone', '+8801712009001')->firstOrFail();

            expect(CapturedLead::query()->count())->toBe(1)
                ->and(CapturedLead::query()->value('message_id'))->toBeString()
                ->and($conversation->tags ?? [])->toContain(CaptureLeadFromConversation::TAG);

            $captured = CapturedLead::query()->firstOrFail();
            expect($captured->lead_id)->toStartWith('fake-lead-')
                ->and($captured->contact_id)->toStartWith('fake-contact-');
        });
    });

    it('is idempotent: re-processing the same message captures nothing new', function () {
        $intake = $this->fakeLeadIntake();
        $this->inTenant($this->tenant, fn () => app(LeadCaptureSettings::class)->update(['enabled' => true]));
        $this->waInbound($this->tenant, '+8801712009002', 'Quote please', 'wamid.LEAD2')->assertOk();

        $this->inTenant($this->tenant, function () {
            $conversation = Conversation::query()->where('phone', '+8801712009002')->firstOrFail();
            $message = Message::query()->where('conversation_id', $conversation->id)->firstOrFail();
            $event = new MessageReceived([
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'contact_id' => $conversation->contact_id,
                'owner_id' => $conversation->owner_id,
                'message_type' => 'text',
                'new_conversation' => true,
            ]);

            app(CaptureLeadFromConversation::class)->handle($event);
            app(CaptureLeadFromConversation::class)->handle($event);

            expect(CapturedLead::query()->count())->toBe(1);
        });

        // The listener ran once during the webhook; the replays above added nothing.
        expect($intake->captures)->toHaveCount(1);
    });

    it('does nothing when the setting is off (the default)', function () {
        $intake = $this->fakeLeadIntake();
        $this->waInbound($this->tenant, '+8801712009003', 'Hello', 'wamid.LEAD3')->assertOk();

        expect($intake->captures)->toHaveCount(0);
        $this->inTenant($this->tenant, fn () => expect(CapturedLead::query()->count())->toBe(0));
    });

    it('breaks nothing and records no lead when the CRM is not entitled', function () {
        $this->fakeLeadIntake(available: false);
        $this->inTenant($this->tenant, fn () => app(LeadCaptureSettings::class)->update(['enabled' => true]));

        $this->waInbound($this->tenant, '+8801712009004', 'Interested', 'wamid.LEAD4')->assertOk();

        $this->inTenant($this->tenant, function () {
            $conversation = Conversation::query()->where('phone', '+8801712009004')->firstOrFail();

            expect(CapturedLead::query()->count())->toBe(0)
                ->and($conversation->tags ?? [])->not->toContain(CaptureLeadFromConversation::TAG);
        });
    });

    it('captures from a keyword on an existing conversation, not only a new one', function () {
        $intake = $this->fakeLeadIntake();
        $contact = $this->waContact($this->tenant, ['first_name' => 'Regular', 'phone' => '+8801712009005']);
        $this->waConversation($this->tenant, $contact);
        $this->inTenant($this->tenant, fn () => app(LeadCaptureSettings::class)->update(['enabled' => true, 'keywords' => ['refund']]));

        // Existing conversation, no keyword: nothing captured.
        $this->waInbound($this->tenant, '+8801712009005', 'just saying hello', 'wamid.KW0')->assertOk();
        expect($intake->captures)->toHaveCount(0);

        // Existing conversation, keyword present: captured.
        $this->waInbound($this->tenant, '+8801712009005', 'I need a refund today', 'wamid.KW1')->assertOk();
        expect($intake->captures)->toHaveCount(1);
        $this->inTenant($this->tenant, fn () => expect(CapturedLead::query()->count())->toBe(1));
    });
});

describe('transactional reminder and notification packs', function () {
    it('registers the four reminder actions with the automation registry', function () {
        $registry = app(AutomationRegistry::class);

        foreach (['send_order_confirmation', 'send_invoice_notification', 'send_appointment_reminder', 'send_payment_reminder'] as $name) {
            expect($registry->action('whatsapp_marketing.'.$name))->not->toBeNull();
        }
    });

    it('sends each reminder template once and replays on retry', function () {
        $contact = $this->waContact($this->tenant, ['first_name' => 'Rahim', 'phone' => '+8801711000021']);
        $this->waOptIn($this->tenant, $contact);
        $input = ['contact_id' => $contact->id, 'template_id' => $this->template->id, 'parameters' => ['Rahim', 'Acme']];

        $actions = [
            SendOrderConfirmationAction::class => 'oc',
            SendInvoiceNotificationAction::class => 'inv',
            SendAppointmentReminderAction::class => 'appt',
            SendPaymentReminderAction::class => 'pay',
        ];

        $sent = 0;

        foreach ($actions as $action => $key) {
            $sent++;
            $first = $this->inTenant($this->tenant, fn () => app($action)->execute($input, $key.':1'));
            $again = $this->inTenant($this->tenant, fn () => app($action)->execute($input, $key.':1'));

            expect($first['state'])->not->toBe('refused')
                ->and($first['state'])->not->toBe('blocked')
                ->and($again)->toBe($first);

            Http::assertSentCount($sent);
        }
    });

    it('stores a blocked output, not a send, for a contact without an opt-in', function () {
        $contact = $this->waContact($this->tenant, ['first_name' => 'Nina', 'phone' => '+8801711000022']);

        $output = $this->inTenant($this->tenant, fn () => app(SendInvoiceNotificationAction::class)->execute(
            ['contact_id' => $contact->id, 'template_id' => $this->template->id, 'parameters' => ['Nina', 'Acme']],
            'blocked:1',
        ));

        expect($output['state'])->toBe('blocked')->and($output['reason'])->toBe('no_consent');
        Http::assertNothingSent();
    });

    it('sends a reminder manually to a contact, with mapped values', function () {
        $contact = $this->waContact($this->tenant, ['first_name' => 'Karim', 'phone' => '+8801711000023']);
        $this->waOptIn($this->tenant, $contact);

        $this->actingAsMember($this->tenant, $this->user)
            ->post($this->waUrl($this->tenant, '/crm/reminders'), [
                'reminder_type' => 'order_confirmation',
                'template_id' => $this->template->id,
                'contact_id' => $contact->id,
                'variables' => [1 => ['type' => 'field', 'field' => 'contact.first_name'], 2 => ['type' => 'text', 'value' => 'Acme']],
            ])
            ->assertSessionHasNoErrors();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['template']['components'][0]['parameters'][0]['text'] === 'Karim');
    });

    it('needs a contact the member can see for a manual reminder', function () {
        $this->actingAsMember($this->tenant, $this->user)
            ->post($this->waUrl($this->tenant, '/crm/reminders'), [
                'reminder_type' => 'order_confirmation',
                'template_id' => $this->template->id,
                'contact_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
                'variables' => [1 => ['type' => 'text', 'value' => 'x'], 2 => ['type' => 'text', 'value' => 'y']],
            ])
            ->assertSessionHasErrors('contact_id');

        Http::assertNothingSent();
    });
});
