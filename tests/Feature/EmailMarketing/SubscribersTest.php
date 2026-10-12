<?php

declare(strict_types=1);

use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\Models\Contact;
use App\Modules\EmailMarketing\Actions\CampaignManager;
use App\Modules\EmailMarketing\Actions\SubscriberManager;
use App\Modules\EmailMarketing\Enums\SubscriptionSource;
use App\Modules\EmailMarketing\Enums\SubscriptionStatus;
use App\Modules\EmailMarketing\Models\Delivery;
use App\Modules\EmailMarketing\Models\ImportRun;
use App\Modules\EmailMarketing\Models\Subscription;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithEmailMarketing;

uses(InteractsWithEmailMarketing::class);

beforeEach(function () {
    $this->tenant = $this->emTenant('em-subs');
    $this->list = $this->emList($this->tenant);
    $this->actingAsOwner($this->tenant);
});

$csv = fn (string $body) => UploadedFile::fake()->createWithContent('subscribers.csv', $body);

it('adds a member with consent evidence as subscribed and without it as pending', function () {
    $with = $this->emContact($this->tenant);
    $without = $this->emContact($this->tenant);

    $this->post($this->emUrl($this->tenant, "/lists/{$this->list->id}/subscribers"), [
        'contact_id' => $with->id,
        'consent_method' => 'website_form',
        'consent_evidence' => 'Signed up on the footer form',
    ])->assertRedirect();
    $this->post($this->emUrl($this->tenant, "/lists/{$this->list->id}/subscribers"), ['contact_id' => $without->id])->assertRedirect();

    $this->inTenant($this->tenant, function () use ($with, $without): void {
        $subscribed = Subscription::query()->where('contact_id', $with->id)->sole();
        $pending = Subscription::query()->where('contact_id', $without->id)->sole();

        expect($subscribed->status)->toBe(SubscriptionStatus::Subscribed)
            ->and($subscribed->consent_method)->toBe('website_form')
            ->and($subscribed->consent_evidence)->toBe('Signed up on the footer form')
            ->and($subscribed->subscribed_at)->not->toBeNull()
            ->and($pending->status)->toBe(SubscriptionStatus::Pending)
            ->and($pending->consent_evidence)->toBeNull()
            ->and($pending->subscribed_at)->toBeNull();
    });
});

it('refuses evidence without a method and a bare method without evidence', function () {
    $contact = $this->emContact($this->tenant);
    $url = $this->emUrl($this->tenant, "/lists/{$this->list->id}/subscribers");

    $this->post($url, ['contact_id' => $contact->id, 'consent_evidence' => 'I said so'])->assertSessionHasErrors('consent_method');
    $this->post($url, ['contact_id' => $contact->id, 'consent_method' => 'phone', 'consent_evidence' => 'no'])->assertSessionHasErrors('consent_evidence');
    $this->post($url, ['contact_id' => $contact->id, 'consent_method' => 'bogus', 'consent_evidence' => 'Signed up on the form'])->assertSessionHasErrors('consent_method');

    $this->inTenant($this->tenant, fn () => expect(Subscription::query()->count())->toBe(0));
});

it('confirms a pending member only with evidence, and keeps an unsubscribed one out without fresh evidence', function () {
    $contact = $this->emContact($this->tenant);
    $subscription = $this->emSubscribe($this->tenant, $this->list, $contact, platformConsent: false, evidence: false);

    $this->put($this->emUrl($this->tenant, "/subscriptions/{$subscription->id}"), ['action' => 'confirm'])->assertSessionHasErrors('consent_evidence');
    $this->put($this->emUrl($this->tenant, "/subscriptions/{$subscription->id}"), [
        'action' => 'confirm', 'consent_method' => 'phone', 'consent_evidence' => 'Agreed during the call on Monday',
    ])->assertSessionHasNoErrors();

    $this->inTenant($this->tenant, fn () => expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Subscribed));

    $this->post($this->emUrl($this->tenant, "/subscriptions/{$subscription->id}/unsubscribe"))->assertRedirect();
    $this->inTenant($this->tenant, fn () => expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Unsubscribed));

    // Adding the same contact again without new evidence is refused; with evidence it reopens.
    $url = $this->emUrl($this->tenant, "/lists/{$this->list->id}/subscribers");
    $this->post($url, ['contact_id' => $contact->id])->assertSessionHasErrors('consent_evidence');
    $this->post($url, ['contact_id' => $contact->id, 'consent_method' => 'email_reply', 'consent_evidence' => 'Replied asking to rejoin'])->assertSessionHasNoErrors();
    $this->inTenant($this->tenant, fn () => expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Subscribed));
});

it('never reopens a bounced or complained membership', function () {
    $contact = $this->emContact($this->tenant);
    $subscription = $this->emSubscribe($this->tenant, $this->list, $contact);
    $this->inTenant($this->tenant, fn () => $subscription->forceFill(['status' => SubscriptionStatus::Bounced])->save());

    expect(fn () => $this->inTenant($this->tenant, fn () => app(SubscriberManager::class)->add(
        $this->list, $contact->id, ['consent_method' => 'phone', 'consent_evidence' => 'They called us up'], SubscriptionSource::Manual,
    )))->toThrow(ValidationException::class);
});

it('counts a person once against the subscriber limit however many lists they join', function () {
    $plan = $this->createFreePlan(['key' => 'em-subs', 'modules' => ['email_marketing', 'communication'], 'limits' => ['email_marketing.subscribers' => 1]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);
    $second = $this->emList($this->tenant, 'Offers');

    $first = $this->emContact($this->tenant);
    $other = $this->emContact($this->tenant);

    $this->emSubscribe($this->tenant, $this->list, $first, platformConsent: false);
    // Same person, another list: free.
    $this->emSubscribe($this->tenant, $second, $first, platformConsent: false);

    expect(fn () => $this->emSubscribe($this->tenant, $this->list, $other, platformConsent: false))->toThrow(ValidationException::class);
});

it('imports subscribers: evidence makes them subscribed, everything else stays pending and unsendable', function () use ($csv) {
    $this->inTenant($this->tenant, fn () => Contact::factory()->create(['email' => 'existing@example.test', 'first_name' => 'Existing']));

    $file = $csv(implode("\n", [
        'email,first_name,last_name,consent_method,consent_evidence,consented_at,prop_plan',
        'amina@example.test,Amina,Rahman,website_form,"Signed up on the footer form",2026-09-30,pro',
        'rafi@example.test,Rafi,Hasan,,,,',
        'existing@example.test,,,existing_customer,"Bought from us in 2025 and agreed to updates",,basic',
        'not-an-email,Bad,Row,,,,',
        '=cmd|calc@example.test,Formula,Row,,,,',
        'half@example.test,Half,Evidence,website_form,,,',
    ]));

    $this->post($this->emUrl($this->tenant, '/imports'), ['file' => $file, 'list_id' => $this->list->id])->assertRedirect();

    $this->inTenant($this->tenant, function (): void {
        $run = ImportRun::query()->sole();
        expect($run->total_rows)->toBe(6)
            ->and($run->subscribed_rows)->toBe(2)
            ->and($run->pending_rows)->toBe(1)
            ->and($run->failed_rows)->toBe(3);

        $byEmail = fn (string $email) => Subscription::query()->whereHas('contact', fn ($q) => $q->where('email', $email))->first();

        expect($byEmail('amina@example.test')->status)->toBe(SubscriptionStatus::Subscribed)
            ->and($byEmail('amina@example.test')->consent_evidence)->toBe('Signed up on the footer form')
            ->and($byEmail('amina@example.test')->properties)->toBe(['plan' => 'pro'])
            ->and($byEmail('amina@example.test')->source)->toBe('import')
            ->and($byEmail('rafi@example.test')->status)->toBe(SubscriptionStatus::Pending)
            ->and($byEmail('existing@example.test')->status)->toBe(SubscriptionStatus::Subscribed)
            ->and($byEmail('half@example.test'))->toBeNull()
            ->and(Subscription::query()->count())->toBe(3);
    });
});

it('refuses a repeated file, an unknown column, a binary file and too many rows', function () use ($csv) {
    $url = $this->emUrl($this->tenant, '/imports');
    $body = "email\none@example.test\n";

    $this->post($url, ['file' => $csv($body), 'list_id' => $this->list->id])->assertSessionHasNoErrors();
    $this->post($url, ['file' => $csv($body), 'list_id' => $this->list->id])->assertSessionHasErrors('file');
    $this->post($url, ['file' => $csv("email,ssn\na@example.test,1\n"), 'list_id' => $this->list->id])->assertSessionHasErrors('file');
    $this->post($url, ['file' => $csv("email\na@example.test\0\n"), 'list_id' => $this->list->id])->assertSessionHasErrors('file');
    $this->post($url, ['file' => $csv("email\n"), 'list_id' => $this->list->id])->assertSessionHasErrors('file');

    config(['email_marketing.import.max_rows' => 2]);
    $this->post($url, ['file' => $csv("email\na@example.test\nb@example.test\nc@example.test\n"), 'list_id' => $this->list->id])->assertSessionHasErrors('file');

    $this->inTenant($this->tenant, fn () => expect(Subscription::query()->count())->toBe(1));
});

it('does not let an importer without contact rights create contacts', function () use ($csv) {
    [$user] = $this->emMember($this->tenant, ['email_marketing.subscribers.import', 'email_marketing.lists.view']);
    $this->actingAsMember($this->tenant, $user);

    $this->post($this->emUrl($this->tenant, '/imports'), [
        'file' => $csv("email,consent_method,consent_evidence\nnew@example.test,website_form,\"Filled in the form\"\n"),
        'list_id' => $this->list->id,
    ])->assertRedirect();

    $this->inTenant($this->tenant, function (): void {
        expect(ImportRun::query()->sole()->failed_rows)->toBe(1)
            ->and(Contact::query()->where('email', 'new@example.test')->exists())->toBeFalse()
            ->and(Subscription::query()->count())->toBe(0);
    });
});

it('exports members as a CSV in which formulas are neutralised', function () {
    $contact = $this->emContact($this->tenant, ['first_name' => '=HYPERLINK("http://evil.test","x")', 'last_name' => '+SUM(1)', 'email' => 'safe@example.test']);
    $this->emSubscribe($this->tenant, $this->list, $contact, platformConsent: false);

    $response = $this->get($this->emUrl($this->tenant, "/lists/{$this->list->id}/export"))->assertOk();
    $body = $response->streamedContent();

    expect($body)->toContain("'=HYPERLINK(")
        ->and($body)->toContain("'+SUM(1)")
        ->and($body)->toContain('safe@example.test')
        ->and($body)->not->toContain(',=HYPERLINK')
        ->and($response->headers->get('content-type'))->toContain('text/csv');
});

it('shows the platform answer next to every member without ever overriding it', function () {
    $consenting = $this->emContact($this->tenant);
    $silent = $this->emContact($this->tenant);
    $this->emSubscribe($this->tenant, $this->list, $consenting);
    $this->emSubscribe($this->tenant, $this->list, $silent, platformConsent: false);

    $this->get($this->emUrl($this->tenant, "/lists/{$this->list->id}"))
        ->assertOk()
        ->assertInertia(function ($page) use ($consenting, $silent): void {
            $members = collect($page->toArray()['props']['members']['data'])->keyBy('contact.id');

            expect($members[$consenting->id]['platform'])->toBe(['allowed' => true, 'reason' => 'ok'])
                ->and($members[$silent->id]['status'])->toBe('subscribed')
                ->and($members[$silent->id]['platform'])->toBe(['allowed' => false, 'reason' => 'no_consent']);
        });
});

it('removes a deleted contact from every list and stops its sequences, keeping delivery history', function () {
    $contact = $this->emContact($this->tenant);
    $this->emSubscribe($this->tenant, $this->list, $contact);
    $campaign = $this->emCampaign($this->tenant, $this->list);
    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null));
    $this->emRun($this->tenant);

    $this->inTenant($this->tenant, fn () => app(ContactDirectory::class)->deleteContact(Contact::query()->findOrFail($contact->id)));

    $this->inTenant($this->tenant, function () use ($campaign): void {
        expect(Subscription::query()->count())->toBe(0)
            ->and(Delivery::query()->where('campaign_id', $campaign->id)->count())->toBe(1);
    });

    $this->get($this->emUrl($this->tenant, "/lists/{$this->list->id}"))->assertOk();
    $this->get($this->emUrl($this->tenant, "/campaigns/{$campaign->id}"))->assertOk()
        ->assertInertia(fn ($page) => $page->where('deliveries.data.0.contact.name', 'Deleted contact'));
});
