<?php

declare(strict_types=1);

use App\Core\Access\RoleManager;
use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\Models\Contact;
use App\Core\Crm\Contracts\CrmAudience;
use App\Core\Crm\Contracts\LeadIntake;
use App\Core\Crm\Data\AudienceSegmentKind;
use App\Core\Crm\Data\LeadCapture;
use App\Core\Crm\Data\LeadCaptureStatus;
use App\Core\Crm\NullCrmAudience;
use App\Core\Crm\NullLeadIntake;
use App\Modules\CRM\Actions\DealManager;
use App\Modules\CRM\Actions\LeadManager;
use App\Modules\CRM\Actions\PipelineManager;
use App\Modules\CRM\Enums\CrmEntityType;
use App\Modules\CRM\Enums\CustomerLifecycleStage;
use App\Modules\CRM\Events\LeadCreated;
use App\Modules\CRM\Models\AutomationActionReceipt;
use App\Modules\CRM\Models\CustomerProfile;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\Note;
use App\Modules\CRM\Models\Segment;
use App\Modules\CRM\Models\Tag;
use App\Modules\CRM\Models\Taggable;
use App\Modules\CRM\Models\TimelineEntry;
use Illuminate\Support\Facades\Event;
use Tests\Support\Fixtures\FakeCrmAudience;
use Tests\Support\Fixtures\RecordingLeadIntake;
use Tests\Support\InteractsWithCrmContracts;

uses(InteractsWithCrmContracts::class);

function capture(array $overrides = []): LeadCapture
{
    return new LeadCapture(...[
        'source' => 'whatsapp',
        'idempotencyKey' => 'wamid.1',
        'name' => 'Rahim Uddin',
        'phone' => '01711000001',
        ...$overrides,
    ]);
}

describe('LeadIntake', function () {
    beforeEach(function () {
        $this->tenant = $this->createTenant('crm-intake');
        $this->entitle($this->tenant, 'crm');
        $this->intake = fn (LeadCapture $capture) => $this->inTenant($this->tenant, fn () => app(LeadIntake::class)->capture($capture));
    });

    it('captures a new contact and lead, registers the source and fires the created event once', function () {
        Event::fake([LeadCreated::class]);

        $result = ($this->intake)(capture(['campaignRef' => 'camp-1', 'answers' => ['Budget' => '5000', 'bad' => ['x']], 'consentEvidence' => true]));

        expect($result->status)->toBe(LeadCaptureStatus::Captured)
            ->and($result->contactCreated)->toBeTrue()
            ->and($result->leadCreated)->toBeTrue();

        $this->inTenant($this->tenant, function () use ($result): void {
            $contact = Contact::query()->sole();
            $lead = Lead::query()->sole();

            expect($contact->id)->toBe($result->contactId)
                ->and($contact->phone_e164)->toBe('+8801711000001')
                ->and($contact->first_name)->toBe('Rahim')
                ->and($contact->last_name)->toBe('Uddin')
                ->and($lead->id)->toBe($result->leadId)
                ->and($lead->owner_id)->toBeNull()
                ->and(LeadSource::query()->pluck('name')->all())->toBe(['WhatsApp'])
                ->and($lead->source_id)->toBe(LeadSource::query()->sole()->id)
                ->and(TimelineEntry::query()->where('event', 'crm.lead.captured')->count())->toBe(1)
                ->and(Note::query()->sole()->body)->toBe("Campaign: camp-1\nBudget: 5000");
        });

        Event::assertDispatchedTimes(LeadCreated::class, 1);
        Event::assertDispatched(LeadCreated::class, fn (LeadCreated $event): bool => $event->payload()['source_key'] === 'whatsapp'
            && $event->payload()['source_id'] !== null);
    });

    it('is idempotent per source and key and fires no second event', function () {
        Event::fake([LeadCreated::class]);

        $first = ($this->intake)(capture());
        $again = ($this->intake)(capture(['name' => 'Someone Else']));
        $otherSource = ($this->intake)(capture(['source' => 'web_form']));

        expect($again->status)->toBe(LeadCaptureStatus::Replayed)
            ->and($again->leadId)->toBe($first->leadId)
            ->and($again->contactId)->toBe($first->contactId)
            ->and($again->leadCreated)->toBeTrue()
            ->and($otherSource->status)->toBe(LeadCaptureStatus::Captured)
            ->and($otherSource->contactCreated)->toBeFalse()
            ->and($otherSource->leadId)->not->toBe($first->leadId);

        $this->inTenant($this->tenant, function (): void {
            expect(Contact::query()->count())->toBe(1)
                ->and(Lead::query()->count())->toBe(2)
                ->and(AutomationActionReceipt::query()->where('action', 'like', 'crm.lead_intake.%')->count())->toBe(2)
                ->and(LeadSource::query()->count())->toBe(2);
        });

        Event::assertDispatchedTimes(LeadCreated::class, 2);
    });

    it('reuses the open lead of the same contact and source with a new key', function () {
        $first = ($this->intake)(capture(['idempotencyKey' => 'm1']));
        $second = ($this->intake)(capture(['idempotencyKey' => 'm2', 'answers' => ['Q' => 'A']]));

        expect($second->status)->toBe(LeadCaptureStatus::Captured)
            ->and($second->leadId)->toBe($first->leadId)
            ->and($second->leadCreated)->toBeFalse()
            ->and($second->contactCreated)->toBeFalse();

        $this->inTenant($this->tenant, fn () => expect(Lead::query()->count())->toBe(1));
    });

    it('dedupes by email and by every phone spelling', function () {
        $existing = $this->inTenant($this->tenant, fn () => app(ContactDirectory::class)->createContact([
            'first_name' => 'Karim', 'email' => 'Karim@Example.com', 'phone' => '+880 1712-345678',
        ], null));

        $byEmail = ($this->intake)(capture(['idempotencyKey' => 'e', 'email' => 'karim@example.COM', 'phone' => null, 'name' => 'K']));
        $byPhone = ($this->intake)(capture(['idempotencyKey' => 'p', 'source' => 'web_form', 'phone' => '8801712345678']));
        $byLocal = ($this->intake)(capture(['idempotencyKey' => 'l', 'source' => 'facebook_lead_ads', 'phone' => '১৭১২৩৪৫৬৭৮']));

        expect($byEmail->contactId)->toBe($existing->id)
            ->and($byPhone->contactId)->toBe($existing->id)
            ->and($byLocal->contactId)->toBe($existing->id)
            ->and($byEmail->contactCreated)->toBeFalse();

        $this->inTenant($this->tenant, fn () => expect(Contact::query()->count())->toBe(1));
    });

    it('matches a legacy contact whose canonical phone column is empty', function () {
        $legacy = $this->inTenant($this->tenant, fn () => Contact::factory()->create(['first_name' => 'Old', 'phone' => '01711 000001']));
        Contact::query()->withoutGlobalScopes()->whereKey($legacy->id)->update(['phone_e164' => null]);

        $result = ($this->intake)(capture());

        expect($result->contactId)->toBe($legacy->id)->and($result->contactCreated)->toBeFalse();
    });

    it('fills a missing email on the existing contact and attaches a company to a new one only', function () {
        $first = ($this->intake)(capture(['companyName' => 'Acme Ltd']));
        $second = ($this->intake)(capture(['idempotencyKey' => 'm2', 'email' => 'rahim@acme.test', 'companyName' => 'Other Co']));

        $this->inTenant($this->tenant, function () use ($first, $second): void {
            $contact = Contact::query()->findOrFail($first->contactId);

            expect($second->contactId)->toBe($first->contactId)
                ->and($contact->email)->toBe('rahim@acme.test')
                ->and($contact->company()->first()?->name)->toBe('Acme Ltd');
        });
    });

    it('rejects unusable captures at the value object', function () {
        expect(fn () => new LeadCapture('WhatsApp!', 'k', phone: '01711000001'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new LeadCapture('whatsapp', '', phone: '01711000001'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new LeadCapture('whatsapp', 'k'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new LeadCapture('whatsapp', 'k', phone: 'abc'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new LeadCapture('whatsapp', 'k', email: 'nope'))->toThrow(InvalidArgumentException::class);

        $c = new LeadCapture('whatsapp', 'k', name: '<b>Ann</b>', phone: '01711000001', answers: array_fill_keys(range(1, 50), 'x'));

        expect($c->name)->toBe('Ann')->and($c->answers)->toHaveCount(30)->and($c->phone)->toBe('+8801711000001');
    });

    it('is tenant isolated: the same contact details in another workspace stay separate', function () {
        $other = $this->createTenant('crm-intake-other');
        $this->entitle($other, 'crm');

        $mine = ($this->intake)(capture());
        $theirs = $this->inTenant($other, fn () => app(LeadIntake::class)->capture(capture()));

        expect($theirs->status)->toBe(LeadCaptureStatus::Captured)
            ->and($theirs->contactId)->not->toBe($mine->contactId)
            ->and($theirs->leadId)->not->toBe($mine->leadId);

        $this->inTenant($other, fn () => expect(Lead::query()->sole()->id)->toBe($theirs->leadId));
        $this->inTenant($this->tenant, fn () => expect(Lead::query()->sole()->id)->toBe($mine->leadId));
    });

    it('answers unavailable and writes nothing when CRM is not entitled', function () {
        $plain = $this->createTenant('crm-intake-none');

        $result = $this->inTenant($plain, fn () => app(LeadIntake::class)->capture(capture()));

        expect($result->status)->toBe(LeadCaptureStatus::Unavailable)
            ->and($result->wasCaptured())->toBeFalse()
            ->and((new NullLeadIntake)->capture(capture())->status)->toBe(LeadCaptureStatus::Unavailable);
    });
});

describe('CrmAudience', function () {
    beforeEach(function () {
        $this->tenant = $this->createTenant('crm-audience');
        $this->entitle($this->tenant, 'crm');
        $this->owner = $this->ownerOf($this->tenant);
        $this->ownerMembership = $this->membershipOf($this->tenant, $this->owner);

        $role = $this->inTenant($this->tenant, fn () => app(RoleManager::class)->create(
            $this->owner,
            'Own sales',
            null,
            ['crm.leads.view', 'crm.deals.view', 'crm.customers.view', 'core.contacts.view'],
            ['crm.leads.view' => 'own', 'crm.deals.view' => 'own', 'crm.customers.view' => 'own', 'core.contacts.view' => 'own'],
        ));
        $this->rep = $this->addMember($this->tenant, $role);
        $this->repMembership = $this->membershipOf($this->tenant, $this->rep);

        $this->inTenant($this->tenant, function (): void {
            $this->mine = Contact::factory()->create(['first_name' => 'Mine']);
            $this->theirs = Contact::factory()->create(['first_name' => 'Theirs']);
            $this->unknown = Contact::factory()->create(['first_name' => 'Unknown']);

            $leads = app(LeadManager::class);
            $leads->create(['title' => 'Mine', 'contact_id' => $this->mine->id], $this->repMembership);
            $leads->create(['title' => 'Theirs', 'contact_id' => $this->theirs->id], $this->ownerMembership);
        });

        $this->audience = fn () => app(CrmAudience::class);
        $this->ids = fn (string $id, $viewer = null, int $limit = 500, ?string $after = null) => $this->inTenant(
            $this->tenant,
            fn () => app(CrmAudience::class)->contactIds($id, $limit, $after, $viewer),
        );
    });

    it('lists segments of every kind with sizes', function () {
        $segments = $this->inTenant($this->tenant, function () {
            $pipeline = app(PipelineManager::class)->create('Sales', [['name' => 'New', 'probability' => 10], ['name' => 'Won', 'probability' => 100, 'is_won' => true]]);
            $tag = Tag::query()->create(['name' => 'VIP']);
            Segment::query()->create(['name' => 'High score', 'entity_type' => CrmEntityType::Lead, 'filters' => ['min_score' => 0], 'is_active' => true]);

            return [$pipeline, $tag, app(CrmAudience::class)->segments()];
        });

        $byId = collect($segments[2])->keyBy('id');

        expect($byId['lead_status:new']->approximateSize)->toBe(2)
            ->and($byId['lead_status:new']->kind)->toBe(AudienceSegmentKind::LeadStatus)
            ->and($byId['tag:'.$segments[1]->id]->label)->toBe('VIP')
            ->and($byId->contains(fn ($s) => $s->kind === AudienceSegmentKind::DealStage))->toBeTrue()
            ->and($byId->contains(fn ($s) => $s->kind === AudienceSegmentKind::SavedSegment && $s->approximateSize === 2))->toBeTrue()
            ->and($byId->contains(fn ($s) => $s->kind === AudienceSegmentKind::CustomerLifecycle))->toBeTrue();
    });

    it('narrows a lead-status audience by the launching member scope', function () {
        $all = ($this->ids)('lead_status:new');
        $rep = ($this->ids)('lead_status:new', $this->repMembership);
        $owner = ($this->ids)('lead_status:new', $this->ownerMembership);

        expect($all->ids)->toEqualCanonicalizing([$this->mine->id, $this->theirs->id])
            ->and($rep->ids)->toBe([$this->mine->id])
            ->and($owner->ids)->toEqualCanonicalizing([$this->mine->id, $this->theirs->id])
            ->and($all->ids)->not->toContain($this->unknown->id);

        $sizes = $this->inTenant($this->tenant, fn () => collect(app(CrmAudience::class)->segments($this->repMembership))->keyBy('id'));
        expect($sizes['lead_status:new']->approximateSize)->toBe(1);
    });

    it('serves tag, saved segment, deal stage and lifecycle audiences, scoped', function () {
        $this->inTenant($this->tenant, function (): void {
            $lead = Lead::query()->where('contact_id', $this->theirs->id)->sole();
            $this->tag = Tag::query()->create(['name' => 'Hot']);
            Taggable::query()->create(['tag_id' => $this->tag->id, 'entity_type' => CrmEntityType::Lead, 'entity_id' => $lead->id]);
            Taggable::query()->create(['tag_id' => $this->tag->id, 'entity_type' => CrmEntityType::Contact, 'entity_id' => $this->unknown->id]);

            $this->saved = Segment::query()->create(['name' => 'Mine only', 'entity_type' => CrmEntityType::Lead, 'filters' => ['search' => 'Mine'], 'is_active' => true]);
            $this->broken = Segment::query()->create(['name' => 'Broken', 'entity_type' => CrmEntityType::Lead, 'filters' => ['tenant_id' => 'x'], 'is_active' => true]);

            $pipeline = app(PipelineManager::class)->create('P', [['name' => 'Open', 'probability' => 10], ['name' => 'Won', 'probability' => 100]]);
            app(DealManager::class)->create(['title' => 'D', 'contact_id' => $this->mine->id, 'currency' => 'BDT', 'amount' => 100], $pipeline, $pipeline->stages[0], $this->repMembership, $this->ownerMembership);
            $this->stage = $pipeline->stages[0];

            CustomerProfile::query()->create(['contact_id' => $this->theirs->id, 'stage' => CustomerLifecycleStage::Customer]);
        });

        expect(($this->ids)('tag:'.$this->tag->id)->ids)->toEqualCanonicalizing([$this->theirs->id, $this->unknown->id])
            ->and(($this->ids)('tag:'.$this->tag->id, $this->repMembership)->ids)->toBe([])
            ->and(($this->ids)('saved:'.$this->saved->id)->ids)->toBe([$this->mine->id])
            ->and(($this->ids)('saved:'.$this->broken->id)->ids)->toBe([])
            ->and(($this->ids)('deal_stage:'.$this->stage->id)->ids)->toBe([$this->mine->id])
            ->and(($this->ids)('deal_stage:'.$this->stage->id, $this->repMembership)->ids)->toBe([$this->mine->id])
            ->and(($this->ids)('lifecycle:customer')->ids)->toBe([$this->theirs->id])
            ->and(($this->ids)('lifecycle:customer', $this->repMembership)->ids)->toBe([])
            ->and(($this->ids)('lifecycle:nonsense')->ids)->toBe([])
            ->and(($this->ids)('garbage')->ids)->toBe([]);
    });

    it('cursor-paginates in bounded pages', function () {
        $first = ($this->ids)('lead_status:new', null, 1);
        $second = ($this->ids)('lead_status:new', null, 1, $first->nextCursor);

        expect($first->ids)->toHaveCount(1)->and($first->hasMore())->toBeTrue()
            ->and($second->ids)->toHaveCount(1)->and($second->hasMore())->toBeFalse()
            ->and(array_merge($first->ids, $second->ids))->toEqualCanonicalizing([$this->mine->id, $this->theirs->id])
            ->and(($this->ids)('lead_status:new', null, 5000)->ids)->toHaveCount(2);
    });

    it('does not reach another workspace or a viewer from elsewhere', function () {
        $other = $this->createTenant('crm-audience-other');
        $this->entitle($other, 'crm');
        $otherMembership = $this->membershipOf($other, $this->ownerOf($other));

        $inOther = $this->inTenant($other, fn () => app(CrmAudience::class)->contactIds('lead_status:new'));
        $foreignViewer = ($this->ids)('lead_status:new', $otherMembership);

        expect($inOther->ids)->toBe([])->and($foreignViewer->ids)->toBe([]);
    });

    it('is empty and honest when CRM is not entitled', function () {
        $plain = $this->createTenant('crm-audience-none');

        $result = $this->inTenant($plain, fn () => [app(CrmAudience::class)->segments(), app(CrmAudience::class)->contactIds('lead_status:new')]);

        expect($result[0])->toBe([])->and($result[1]->ids)->toBe([])
            ->and((new NullCrmAudience)->segments())->toBe([]);
    });
});

describe('test doubles', function () {
    it('replays by key like the real contract', function () {
        $intake = $this->fakeLeadIntake();

        $a = app(LeadIntake::class)->capture(capture());
        $b = app(LeadIntake::class)->capture(capture());

        expect($intake)->toBeInstanceOf(RecordingLeadIntake::class)
            ->and($b->status)->toBe(LeadCaptureStatus::Replayed)
            ->and($b->leadId)->toBe($a->leadId)
            ->and($intake->captures)->toHaveCount(2)
            ->and($this->fakeLeadIntake(false)->capture(capture())->status)->toBe(LeadCaptureStatus::Unavailable);
    });

    it('pages the fake audience', function () {
        $fake = $this->fakeCrmAudience([], ['s' => ['c', 'a', 'b']]);

        $page = app(CrmAudience::class)->contactIds('s', 2);

        expect($fake)->toBeInstanceOf(FakeCrmAudience::class)
            ->and($page->ids)->toBe(['a', 'b'])
            ->and(app(CrmAudience::class)->contactIds('s', 2, $page->nextCursor)->ids)->toBe(['c']);
    });
});
