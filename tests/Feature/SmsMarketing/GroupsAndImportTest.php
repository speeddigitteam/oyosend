<?php

declare(strict_types=1);

use App\Core\Contacts\ContactMerger;
use App\Core\Contacts\Contracts\ContactMergeReference;
use App\Core\Contacts\Models\Contact;
use App\Modules\SmsMarketing\Actions\GroupManager;
use App\Modules\SmsMarketing\Actions\SmsMarketingContactMergeReference;
use App\Modules\SmsMarketing\Models\Group;
use App\Modules\SmsMarketing\Models\GroupMember;
use App\Modules\SmsMarketing\Models\Import;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithSmsMarketing;

uses(InteractsWithSmsMarketing::class);

beforeEach(function () {
    $this->tenant = $this->smsTenant('sms-groups');
    $this->csv = fn (string $content, string $name = 'numbers.csv') => UploadedFile::fake()->createWithContent($name, $content);
});

it('adds contacts once and refuses a contact that does not exist in the workspace', function () {
    $contact = $this->smsContact($this->tenant);
    $group = $this->smsGroup($this->tenant);

    $result = $this->inTenant($this->tenant, function () use ($group, $contact): array {
        $manager = app(GroupManager::class);

        return [
            $manager->addContacts($group, [$contact->id, $contact->id, '01JZZZZZZZZZZZZZZZZZZZZZZZ']),
            $manager->addContacts($group, [$contact->id]),
        ];
    });

    expect($result[0])->toBe(['added' => 1, 'existing' => 0, 'missing' => 1])
        ->and($result[1])->toBe(['added' => 0, 'existing' => 1, 'missing' => 0]);
});

it('never adds a contact of another workspace', function () {
    $other = $this->smsTenant('sms-groups-other');
    $foreign = $this->smsContact($other);
    $group = $this->smsGroup($this->tenant);

    $result = $this->inTenant($this->tenant, fn () => app(GroupManager::class)->addContacts($group, [$foreign->id]));

    expect($result)->toBe(['added' => 0, 'existing' => 0, 'missing' => 1]);
});

it('counts a contact in several groups once against the plan contact limit and refuses past it', function () {
    $plan = $this->createFreePlan(['modules' => ['communication', 'sms_marketing'], 'limits' => ['sms_marketing.contacts' => 2]]);
    $this->subscribe($this->tenant, $plan);
    $a = $this->smsContact($this->tenant, '+8801711000001');
    $b = $this->smsContact($this->tenant, '+8801711000002');
    $c = $this->smsContact($this->tenant, '+8801711000003');
    $one = $this->smsGroup($this->tenant, [$a->id, $b->id], 'One');
    $two = $this->smsGroup($this->tenant, [], 'Two');

    $this->inTenant($this->tenant, function () use ($two, $a, $c): void {
        $manager = app(GroupManager::class);

        // The same contact in a second group does not use another seat.
        expect($manager->addContacts($two, [$a->id])['added'])->toBe(1)
            ->and(GroupManager::distinctContacts())->toBe(2);

        expect(fn () => $manager->addContacts($two, [$c->id]))->toThrow(ValidationException::class);
        expect(GroupMember::query()->where('contact_id', $c->id)->exists())->toBeFalse();
    });
});

it('imports phone numbers of existing contacts in any usual spelling and reports the rest', function () {
    $this->smsContact($this->tenant, '+8801711000001', true, ['first_name' => 'Rahim']);
    $this->smsContact($this->tenant, '01811000002', true, ['first_name' => 'Karim']);
    $group = $this->smsGroup($this->tenant);
    $this->actingAsOwner($this->tenant);

    $file = ($this->csv)("phone\n01711000001\n+8801811000002\n01999999999\nabc\n\n+8801711000001\n");
    $this->post($this->smsUrl($this->tenant, "/groups/{$group->id}/import"), ['file' => $file])->assertRedirect();

    $this->inTenant($this->tenant, function () use ($group): void {
        expect(GroupMember::query()->where('group_id', $group->id)->count())->toBe(2);
        $import = Import::query()->sole();

        expect($import->total_rows)->toBe(5)
            ->and($import->added_rows)->toBe(2)
            ->and($import->failed_rows)->toBe(2)
            ->and(collect($import->errors)->pluck('line')->all())->toBe([4, 5])
            ->and($import->errors[0]['message'])->toContain('No contact you can see')
            ->and($import->errors[1]['message'])->toContain('not a valid phone number');
    });
});

it('rejects a file without a phone column, too many rows, or a binary file', function () {
    $group = $this->smsGroup($this->tenant);
    $this->actingAsOwner($this->tenant);
    $url = $this->smsUrl($this->tenant, "/groups/{$group->id}/import");

    $this->post($url, ['file' => ($this->csv)("name\nRahim\n")])->assertSessionHasErrors('file');

    config(['sms_marketing.import.max_rows' => 2]);
    $this->post($url, ['file' => ($this->csv)("phone\n01711000001\n01711000002\n01711000003\n")])->assertSessionHasErrors('file');

    $this->post($url, ['file' => ($this->csv)("phone\n\x00\xFF\xFE")])->assertSessionHasErrors('file');
});

it('only matches contacts the importer may see', function () {
    $hidden = $this->smsContact($this->tenant, '+8801711000001');
    $this->inTenant($this->tenant, fn () => Contact::query()->whereKey($hidden->id)->update(['owner_id' => null]));
    $group = $this->smsGroup($this->tenant);
    [$user, $membership] = $this->smsMember($this->tenant, ['sms_marketing.groups.manage', 'sms_marketing.groups.view', 'core.contacts.view'], ['core.contacts.view' => 'own']);
    $this->actingAsMember($this->tenant, $user);

    $this->post($this->smsUrl($this->tenant, "/groups/{$group->id}/import"), ['file' => ($this->csv)("phone\n01711000001\n")])->assertRedirect();

    $this->inTenant($this->tenant, fn () => expect(GroupMember::query()->count())->toBe(0));
});

it('exports a group and a campaign formula-safe', function () {
    $evil = $this->smsContact($this->tenant, '+8801711000001', true, ['first_name' => '=HYPERLINK("http://x")', 'last_name' => '@cmd']);
    $group = $this->smsGroup($this->tenant, [$evil->id]);
    $this->actingAsOwner($this->tenant);

    $csv = $this->get($this->smsUrl($this->tenant, "/groups/{$group->id}/export"))->assertOk()->streamedContent();

    expect($csv)->toContain("'=HYPERLINK")
        ->and($csv)->toContain("'+8801711000001")
        ->and($csv)->not->toContain("\n=HYPERLINK")
        ->and($csv)->not->toContain(',=HYPERLINK');
});

it('reads back an exported number that carries the formula-safety apostrophe', function () {
    $this->smsContact($this->tenant, '+8801711000001');
    $group = $this->smsGroup($this->tenant);
    $this->actingAsOwner($this->tenant);

    $this->post($this->smsUrl($this->tenant, "/groups/{$group->id}/import"), ['file' => ($this->csv)("phone\n'+8801711000001\n")])->assertRedirect();

    $this->inTenant($this->tenant, fn () => expect(GroupMember::query()->count())->toBe(1));
});

it('moves group memberships to the canonical contact when contacts are merged', function () {
    $canonical = $this->smsContact($this->tenant, '+8801711000001');
    $duplicate = $this->smsContact($this->tenant, '+8801711000009');
    $both = $this->smsGroup($this->tenant, [$canonical->id, $duplicate->id], 'Both');
    $only = $this->smsGroup($this->tenant, [$duplicate->id], 'Only duplicate');

    $counts = $this->inTenant($this->tenant, fn () => app(SmsMarketingContactMergeReference::class)->merge(
        $this->tenant->getTenantKey(), $canonical->id, $duplicate->id,
    ));

    $this->inTenant($this->tenant, function () use ($canonical, $duplicate, $both, $only): void {
        expect(GroupMember::query()->where('contact_id', $duplicate->id)->count())->toBe(0)
            ->and(GroupMember::query()->where('group_id', $both->id)->count())->toBe(1)
            ->and(GroupMember::query()->where('group_id', $only->id)->value('contact_id'))->toBe($canonical->id);
    });
    expect($counts)->toBe(['sms_marketing_group_members' => 1]);
});

it('registers its merge participant with the core contact merger', function () {
    $references = app()->tagged(ContactMergeReference::class);

    expect(collect(iterator_to_array($references))->contains(fn ($reference) => $reference instanceof SmsMarketingContactMergeReference))->toBeTrue()
        ->and(class_exists(ContactMerger::class))->toBeTrue();
});

it('deleting a group keeps the contacts', function () {
    $contact = $this->smsContact($this->tenant);
    $group = $this->smsGroup($this->tenant, [$contact->id]);
    $this->actingAsOwner($this->tenant);

    $this->delete($this->smsUrl($this->tenant, "/groups/{$group->id}"))->assertRedirect();

    $this->inTenant($this->tenant, function () use ($group, $contact): void {
        expect(Group::query()->whereKey($group->id)->exists())->toBeFalse()
            ->and(Contact::query()->whereKey($contact->id)->exists())->toBeTrue()
            ->and(GroupMember::query()->count())->toBe(0);
    });
});
