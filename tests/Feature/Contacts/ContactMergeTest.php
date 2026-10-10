<?php

declare(strict_types=1);

use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\ContactMerger;
use App\Core\Contacts\Events\ContactDeleted;
use App\Core\Contacts\Events\ContactMerged;
use App\Core\Contacts\Models\Contact;
use App\Modules\CRM\Enums\CrmEntityType;
use App\Modules\CRM\Enums\CustomerLifecycleStage;
use App\Modules\CRM\Enums\CustomFieldType;
use App\Modules\CRM\Enums\LeadStatus;
use App\Modules\CRM\Models\Attachment;
use App\Modules\CRM\Models\CustomerProfile;
use App\Modules\CRM\Models\CustomField;
use App\Modules\CRM\Models\CustomFieldValue;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Tag;
use App\Modules\CRM\Models\Taggable;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->tenant = $this->createTenant('acme');
    $this->directory = app(ContactDirectory::class);

    [$this->canonical, $this->duplicate] = $this->inTenant($this->tenant, function (): array {
        return [
            $this->directory->createContact([
                'first_name' => 'Nadia',
                'last_name' => 'Rahman',
                'email' => 'nadia.primary@example.test',
                'phone' => '+880 1711-123456',
                'notes' => 'Keep this note.',
            ]),
            $this->directory->createContact([
                'first_name' => 'Nadiya',
                'last_name' => 'Rahman',
                'email' => 'nadia.other@example.test',
                'phone' => '8801711123456',
                'notes' => 'The useful duplicate note.',
            ]),
        ];
    });
});

it('detects normalized duplicate identities without exposing another tenant', function () {
    $foreignTenant = $this->createTenant('globex');
    $this->inTenant($foreignTenant, fn (): Contact => app(ContactDirectory::class)->createContact([
        'first_name' => 'Foreign',
        'phone' => '+880 1711-123456',
    ]));

    $this->actingAsOwner($this->tenant)
        ->getJson($this->tenantUrl($this->tenant, '/contacts/duplicates?phone=%2B880-1711-123456'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment([
            'id' => $this->duplicate->id,
            'matchReasons' => ['phone'],
            'mergeable' => false,
        ]);

    $this->getJson($this->tenantUrl($this->tenant, "/contacts/{$this->canonical->id}/duplicates"))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->duplicate->id)
        ->assertJsonPath('data.0.firstName', 'Nadiya')
        ->assertJsonPath('data.0.notes', 'The useful duplicate note.')
        ->assertJsonPath('data.0.matchReasons', ['phone'])
        ->assertJsonPath('data.0.mergeable', true);
});

it('merges selected contact fields, soft deletes the duplicate and publishes one business event', function () {
    Event::fake([ContactMerged::class, ContactDeleted::class]);

    $this->actingAsOwner($this->tenant)
        ->post($this->tenantUrl($this->tenant, "/contacts/{$this->canonical->id}/merge"), [
            'duplicate_id' => $this->duplicate->id,
            'fields' => [
                'first_name' => ['source' => 'duplicate'],
                'email' => ['source' => 'input', 'value' => 'ONE@example.test'],
                'notes' => ['source' => 'duplicate'],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $canonical = $this->inTenant($this->tenant, fn (): Contact => $this->canonical->refresh());

    expect($canonical->first_name)->toBe('Nadiya')
        ->and($canonical->last_name)->toBe('Rahman')
        ->and($canonical->email)->toBe('one@example.test')
        ->and($canonical->phone)->toBe('+880 1711-123456')
        ->and($canonical->notes)->toBe('The useful duplicate note.')
        ->and(Contact::forTenant($this->tenant)->withTrashed()->findOrFail($this->duplicate->id)->trashed())->toBeTrue()
        ->and($this->auditTrail($this->tenant, 'contact.merged'))->toHaveCount(1)
        ->and($this->auditTrail($this->tenant, 'contact.deleted'))->toHaveCount(1);

    Event::assertDispatchedTimes(ContactMerged::class, 1);
    Event::assertDispatched(ContactMerged::class, fn (ContactMerged $event): bool => $event->canonicalId === $canonical->id
        && $event->duplicateId === $this->duplicate->id
        && $event->resolvedFields === ['first_name', 'email', 'notes']);
    Event::assertDispatchedTimes(ContactDeleted::class, 1);
});

it('rejects self merges and any field or selection outside the fixed merge contract', function () {
    $url = $this->tenantUrl($this->tenant, "/contacts/{$this->canonical->id}/merge");
    $this->actingAsOwner($this->tenant);

    $this->post($url, ['duplicate_id' => $this->canonical->id])
        ->assertSessionHasErrors('duplicate_id');

    $this->post($url, [
        'duplicate_id' => $this->duplicate->id,
        'fields' => ['tenant_id' => ['source' => 'duplicate']],
    ])->assertSessionHasErrors('fields');

    $this->post($url, [
        'duplicate_id' => $this->duplicate->id,
        'fields' => ['first_name' => ['source' => 'expression', 'value' => 'anything']],
    ])->assertSessionHasErrors('fields.first_name.source');

    expect(Contact::forTenant($this->tenant)->withTrashed()->findOrFail($this->duplicate->id)->trashed())->toBeFalse();
});

it('requires scoped manage and delete access to both records', function () {
    $otherTenant = $this->createTenant('globex');
    $foreignDuplicate = $this->inTenant($otherTenant, fn (): Contact => app(ContactDirectory::class)->createContact([
        'first_name' => 'Foreign',
    ]));

    $this->actingAsOwner($this->tenant)
        ->post($this->tenantUrl($this->tenant, "/contacts/{$this->canonical->id}/merge"), [
            'duplicate_id' => $foreignDuplicate->id,
        ])
        ->assertNotFound();

    $this->startNewBrowserSession()
        ->actingAsOwner($otherTenant)
        ->post($this->tenantUrl($otherTenant, "/contacts/{$this->canonical->id}/merge"), [
            'duplicate_id' => $foreignDuplicate->id,
        ])
        ->assertNotFound();

    expect(Contact::forTenant($this->tenant)->withTrashed()->findOrFail($this->duplicate->id)->trashed())->toBeFalse()
        ->and(Contact::forTenant($otherTenant)->withTrashed()->findOrFail($foreignDuplicate->id)->trashed())->toBeFalse();
});

it('repoints CRM references and resolves unique polymorphic and customer profile collisions', function () {
    $records = $this->inTenant($this->tenant, function (): array {
        $lead = Lead::query()->create([
            'contact_id' => $this->duplicate->id,
            'title' => 'Duplicate lead',
            'status' => LeadStatus::New,
            'score' => 20,
        ]);

        $sharedTag = Tag::query()->create(['name' => 'Shared']);
        $movedTag = Tag::query()->create(['name' => 'Moved']);
        Taggable::query()->create(['tag_id' => $sharedTag->id, 'entity_type' => CrmEntityType::Contact, 'entity_id' => $this->canonical->id]);
        Taggable::query()->create(['tag_id' => $sharedTag->id, 'entity_type' => CrmEntityType::Contact, 'entity_id' => $this->duplicate->id]);
        Taggable::query()->create(['tag_id' => $movedTag->id, 'entity_type' => CrmEntityType::Contact, 'entity_id' => $this->duplicate->id]);

        $field = CustomField::query()->create([
            'entity_type' => CrmEntityType::Contact,
            'key' => 'reference',
            'name' => 'Reference',
            'type' => CustomFieldType::Text,
        ]);
        CustomFieldValue::query()->create(['custom_field_id' => $field->id, 'entity_type' => CrmEntityType::Contact, 'entity_id' => $this->canonical->id, 'value' => 'canonical']);
        CustomFieldValue::query()->create(['custom_field_id' => $field->id, 'entity_type' => CrmEntityType::Contact, 'entity_id' => $this->duplicate->id, 'value' => 'duplicate']);

        $attachment = Attachment::query()->create([
            'entity_type' => CrmEntityType::Contact,
            'entity_id' => $this->duplicate->id,
            'original_name' => 'brief.txt',
            'path' => 'crm/brief.txt',
            'mime_type' => 'text/plain',
            'size' => 12,
        ]);

        CustomerProfile::query()->create([
            'contact_id' => $this->canonical->id,
            'stage' => CustomerLifecycleStage::Prospect,
            'lifetime_values' => ['BDT' => 1000],
            'first_won_at' => '2026-01-10 10:00:00',
            'last_won_at' => '2026-02-10 10:00:00',
        ]);
        CustomerProfile::query()->create([
            'contact_id' => $this->duplicate->id,
            'stage' => CustomerLifecycleStage::Customer,
            'lifetime_values' => ['BDT' => 2000, 'USD' => 500],
            'first_won_at' => '2025-12-10 10:00:00',
            'last_won_at' => '2026-03-10 10:00:00',
        ]);

        app(ContactMerger::class)->merge($this->canonical, $this->duplicate, []);

        return [$lead->id, $sharedTag->id, $movedTag->id, $field->id, $attachment->id];
    });

    [$leadId, $sharedTagId, $movedTagId, $fieldId, $attachmentId] = $records;

    $this->inTenant($this->tenant, function () use ($leadId, $sharedTagId, $movedTagId, $fieldId, $attachmentId): void {
        expect(Lead::query()->findOrFail($leadId)->contact_id)->toBe($this->canonical->id)
            ->and(Taggable::query()->where('entity_type', CrmEntityType::Contact)->where('entity_id', $this->canonical->id)->pluck('tag_id')->sort()->values()->all())
            ->toBe(collect([$sharedTagId, $movedTagId])->sort()->values()->all())
            ->and(CustomFieldValue::query()->where('custom_field_id', $fieldId)->where('entity_id', $this->canonical->id)->value('value'))
            ->toBe('canonical')
            ->and(Attachment::query()->findOrFail($attachmentId)->entity_id)->toBe($this->canonical->id);

        $profile = CustomerProfile::query()->where('contact_id', $this->canonical->id)->firstOrFail();

        expect(CustomerProfile::query()->count())->toBe(1)
            ->and($profile->stage)->toBe(CustomerLifecycleStage::Customer)
            ->and($profile->lifetime_values)->toBe(['BDT' => 3000, 'USD' => 500])
            ->and($profile->first_won_at?->format('Y-m-d'))->toBe('2025-12-10')
            ->and($profile->last_won_at?->format('Y-m-d'))->toBe('2026-03-10');
    });
});
