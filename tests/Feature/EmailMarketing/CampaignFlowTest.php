<?php

declare(strict_types=1);

use App\Modules\Communication\Models\Message;
use App\Modules\EmailMarketing\Actions\CampaignManager;
use App\Modules\EmailMarketing\Actions\ListManager;
use App\Modules\EmailMarketing\Enums\CampaignStatus;
use App\Modules\EmailMarketing\Enums\DeliveryStatus;
use App\Modules\EmailMarketing\Models\Campaign;
use App\Modules\EmailMarketing\Models\Delivery;
use App\Modules\EmailMarketing\Support\VariantSplitter;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithEmailMarketing;

uses(InteractsWithEmailMarketing::class);

beforeEach(function () {
    Mail::fake();
    $this->tenant = $this->emTenant('em-flow');
    $this->list = $this->emList($this->tenant);
});

it('creates, edits, schedules and cancels a campaign through the pages', function () {
    $this->actingAsOwner($this->tenant);

    $payload = [
        'name' => 'Eid offers',
        'subject' => 'Eid offers for {{first_name|you}}',
        'preheader' => 'Up to 30 percent off',
        'blocks' => self::BLOCKS,
        'list_id' => $this->list->id,
        'track_clicks' => true,
    ];

    $this->get($this->emUrl($this->tenant, '/campaigns/create'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Modules/EmailMarketing/Pages/Campaigns/Form', false));

    $response = $this->post($this->emUrl($this->tenant, '/campaigns'), $payload);
    $campaign = $this->inTenant($this->tenant, fn () => Campaign::query()->sole());
    $response->assertRedirect($this->emUrl($this->tenant, "/campaigns/{$campaign->id}"));

    expect($campaign->status)->toBe(CampaignStatus::Draft)->and($campaign->owner_id)->not->toBeNull();

    $this->get($this->emUrl($this->tenant, "/campaigns/{$campaign->id}"))->assertOk()
        ->assertInertia(fn ($page) => $page->where('campaign.name', 'Eid offers')->where('campaign.status', 'draft')
            ->where('campaign.abilities.schedule', true)->has('campaign.preview.html'));

    $this->put($this->emUrl($this->tenant, "/campaigns/{$campaign->id}"), [...$payload, 'name' => 'Eid offers v2'])->assertRedirect();
    $this->post($this->emUrl($this->tenant, "/campaigns/{$campaign->id}/schedule"), ['scheduled_for' => now()->addDay()->format('Y-m-d\TH:i')])->assertRedirect();
    $this->inTenant($this->tenant, fn () => expect($campaign->fresh()->status)->toBe(CampaignStatus::Scheduled)->and($campaign->fresh()->name)->toBe('Eid offers v2'));

    // Editing a scheduled campaign takes it back to draft: a new decision to send is needed.
    $this->put($this->emUrl($this->tenant, "/campaigns/{$campaign->id}"), [...$payload, 'subject' => 'Changed'])->assertRedirect();
    $this->inTenant($this->tenant, fn () => expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft));

    $this->post($this->emUrl($this->tenant, "/campaigns/{$campaign->id}/cancel"))->assertRedirect();
    $this->inTenant($this->tenant, fn () => expect($campaign->fresh()->status)->toBe(CampaignStatus::Cancelled));

    $this->put($this->emUrl($this->tenant, "/campaigns/{$campaign->id}"), $payload)->assertSessionHasErrors('campaign');
});

it('rejects unsafe content on the campaign form', function () {
    $this->actingAsOwner($this->tenant);

    $this->post($this->emUrl($this->tenant, '/campaigns'), [
        'name' => 'Bad', 'subject' => 'Hi {{password}}', 'list_id' => $this->list->id,
        'blocks' => [['type' => 'button', 'label' => 'Go', 'url' => 'javascript:alert(1)']],
    ])->assertSessionHasErrors(['subject']);

    $this->post($this->emUrl($this->tenant, '/campaigns'), [
        'name' => 'Bad', 'subject' => 'Fine', 'list_id' => $this->list->id,
        'blocks' => [['type' => 'button', 'label' => 'Go', 'url' => 'javascript:alert(1)']],
    ])->assertSessionHasErrors(['blocks.0.url']);

    $this->inTenant($this->tenant, fn () => expect(Campaign::query()->count())->toBe(0));
});

it('previews unsaved content with made-up data and reports exact errors', function () {
    $this->actingAsOwner($this->tenant);

    $ok = $this->postJson($this->emUrl($this->tenant, '/content/preview'), ['subject' => 'Hi {{first_name}}', 'blocks' => self::BLOCKS])->assertOk();
    expect($ok->json('valid'))->toBeTrue()
        ->and($ok->json('subject'))->toBe('Hi Alex')
        ->and($ok->json('text'))->toContain('Hello Alex');

    $bad = $this->postJson($this->emUrl($this->tenant, '/content/preview'), ['blocks' => [['type' => 'text', 'text' => '{{nope}}']]])->assertOk();
    expect($bad->json('valid'))->toBeFalse()->and($bad->json('errors'))->toHaveKey('blocks.0.text');
});

it('narrows the audience with a declarative segment', function () {
    $dhaka = $this->emContact($this->tenant, ['city' => 'Dhaka']);
    $khulna = $this->emContact($this->tenant, ['city' => 'Khulna']);
    $this->emSubscribe($this->tenant, $this->list, $dhaka);
    $this->emSubscribe($this->tenant, $this->list, $khulna);

    $segment = $this->inTenant($this->tenant, fn () => app(ListManager::class)->createSegment($this->list, [
        'name' => 'Dhaka', 'match' => 'all', 'rules' => [['field' => 'city', 'operator' => 'equals', 'value' => 'Dhaka']],
    ]));
    $campaign = $this->emCampaign($this->tenant, $this->list, ['segment_id' => $segment->id]);

    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null));
    $this->emRun($this->tenant);
    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, function () use ($campaign, $dhaka): void {
        expect(Delivery::query()->where('campaign_id', $campaign->id)->pluck('contact_id')->all())->toBe([$dhaka->id]);
    });
});

it('refuses a segment of another list on a campaign', function () {
    $other = $this->emList($this->tenant, 'Other');
    $segment = $this->inTenant($this->tenant, fn () => app(ListManager::class)->createSegment($other, [
        'name' => 'S', 'match' => 'any', 'rules' => [['field' => 'city', 'operator' => 'exists', 'value' => null]],
    ]));

    expect(fn () => $this->emCampaign($this->tenant, $this->list, ['segment_id' => $segment->id]))->toThrow(ValidationException::class);
});

it('runs an A/B test on a deterministic cohort, then sends the better subject to the rest', function () {
    $campaign = $this->emCampaign($this->tenant, $this->list, [
        'subject' => 'Subject A', 'ab_enabled' => true, 'ab_subject_b' => 'Subject B', 'ab_test_percent' => 50, 'ab_wait_hours' => 4, 'ab_metric' => 'clicks',
    ]);

    // Add members until the test cohort holds both variants and some members are left for the winner.
    $contacts = [];
    $seen = ['a' => 0, 'b' => 0, 'rest' => 0];

    while (count($contacts) < 120 && ($seen['a'] < 2 || $seen['b'] < 2 || $seen['rest'] < 2)) {
        $contact = $this->emContact($this->tenant);
        $this->emSubscribe($this->tenant, $this->list, $contact);
        $contacts[] = $contact;

        if (VariantSplitter::inTestCohort($campaign->id, $contact->id, 50)) {
            $seen[VariantSplitter::variant($campaign->id, $contact->id)]++;
        } else {
            $seen['rest']++;
        }
    }

    $total = count($contacts);
    $expectedTest = collect($contacts)->filter(fn ($c) => VariantSplitter::inTestCohort($campaign->id, $c->id, 50))->pluck('id')->all();
    expect($seen['a'])->toBeGreaterThanOrEqual(2)->and($seen['b'])->toBeGreaterThanOrEqual(2)->and(count($expectedTest))->toBeLessThan($total);

    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null));
    $this->emRun($this->tenant);
    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, function () use ($campaign, $expectedTest, $total): void {
        $fresh = Campaign::query()->find($campaign->id);
        expect($fresh->status)->toBe(CampaignStatus::AwaitingWinner)
            ->and($fresh->ab_decide_at)->not->toBeNull()
            ->and(Delivery::query()->where('campaign_id', $campaign->id)->where('status', DeliveryStatus::Sent)->pluck('contact_id')->sort()->values()->all())
            ->toBe(collect($expectedTest)->sort()->values()->all())
            ->and(Delivery::query()->where('campaign_id', $campaign->id)->where('status', DeliveryStatus::Held)->count())->toBe($total - count($expectedTest));

        // Variant B clicked, variant A did not.
        Delivery::query()->where('campaign_id', $campaign->id)->where('variant', 'b')->update(['clicked_at' => now(), 'click_count' => 1]);
    });

    // Before the wait is over nothing more is sent.
    $this->emRun($this->tenant);
    $this->inTenant($this->tenant, fn () => expect(Message::query()->count())->toBe(count($expectedTest)));

    $this->travelTo(now()->addHours(5));
    $this->emRun($this->tenant);
    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, function () use ($campaign, $expectedTest, $total): void {
        $fresh = Campaign::query()->find($campaign->id);
        $heldSubjects = Message::query()->whereNotIn('contact_id', $expectedTest)->pluck('subject')->unique()->all();

        expect($fresh->status)->toBe(CampaignStatus::Sent)
            ->and($fresh->ab_winner)->toBe('b')
            ->and($fresh->ab_winner_reason)->toBe('clicks')
            ->and($heldSubjects)->toBe(['Subject B'])
            ->and(Message::query()->count())->toBe($total);
    });
});

it('says so when an A/B test could not be measured instead of inventing a winner', function () {
    foreach (range(1, 6) as $i) {
        $this->emSubscribe($this->tenant, $this->list, $this->emContact($this->tenant));
    }

    $campaign = $this->emCampaign($this->tenant, $this->list, [
        'ab_enabled' => true, 'ab_subject_b' => 'Other', 'ab_test_percent' => 50, 'ab_wait_hours' => 1, 'ab_metric' => 'clicks',
    ]);
    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null));
    $this->emRun($this->tenant);
    $this->emRun($this->tenant);
    $this->travelTo(now()->addHours(2));
    $this->emRun($this->tenant);
    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, function () use ($campaign): void {
        $fresh = Campaign::query()->find($campaign->id);
        expect($fresh->ab_winner)->toBe('a')->and($fresh->ab_winner_reason)->toBe('no_responses');
    });

    // Measuring clicks needs click tracking.
    expect(fn () => $this->emCampaign($this->tenant, $this->list, ['name' => 'X', 'ab_enabled' => true, 'ab_subject_b' => 'Y', 'track_clicks' => false, 'ab_metric' => 'clicks']))
        ->toThrow(ValidationException::class);
});

it('sends a test email to allow-listed members only, through the same consent rules', function () {
    $this->actingAsOwner($this->tenant);
    $owner = $this->ownerOf($this->tenant);
    $membership = $this->membershipOf($this->tenant, $owner);
    $campaign = $this->emCampaign($this->tenant, $this->list);

    // The owner is not a contact yet: nothing is sent, and the page says why.
    $this->post($this->emUrl($this->tenant, "/campaigns/{$campaign->id}/test"), ['recipients' => [$membership->id]])->assertRedirect();
    Mail::assertNothingSent();

    $contact = $this->emContact($this->tenant, ['email' => $owner->email]);
    $this->optIn($this->tenant, $contact);

    $this->post($this->emUrl($this->tenant, "/campaigns/{$campaign->id}/test"), ['recipients' => [$membership->id]])->assertRedirect();

    $this->inTenant($this->tenant, function (): void {
        $message = Message::query()->sole();
        expect($message->subject)->toStartWith('[Test] ')->and($message->source)->toBe('email_marketing');
    });

    // An id that is not a member of this workspace is refused, and so is a list that is too long.
    $this->post($this->emUrl($this->tenant, "/campaigns/{$campaign->id}/test"), ['recipients' => [str_repeat('A', 26)]])->assertSessionHasErrors('recipients');
    $this->post($this->emUrl($this->tenant, "/campaigns/{$campaign->id}/test"), ['recipients' => array_fill(0, 6, $membership->id)])->assertSessionHasErrors('recipients');
});
