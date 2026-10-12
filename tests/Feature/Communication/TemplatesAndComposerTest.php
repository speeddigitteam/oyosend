<?php

declare(strict_types=1);

use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Mail\OutboundMail;
use App\Modules\Communication\Models\Interaction;
use App\Modules\Communication\Models\Message;
use App\Modules\Communication\Models\MessageTemplate;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithCommunication;

uses(InteractsWithCommunication::class);

beforeEach(function () {
    $this->tenant = $this->commTenant('comm-tpl');
    $this->actingAsOwner($this->tenant);
    $this->template = fn (array $overrides = []) => [
        'slug' => 'welcome', 'name' => 'Welcome', 'channel' => 'email', 'locale' => 'en', 'purpose' => 'transactional',
        'subject' => 'Welcome {{ contact.first_name }}', 'body' => 'Hi {{ contact.full_name }}, from {{ workspace.name }}.',
        'is_active' => true, ...$overrides,
    ];
});

it('saves a template and lists it with the placeholder catalogue', function () {
    $this->post($this->commUrl($this->tenant, '/templates'), ($this->template)())->assertRedirect();

    $this->get($this->commUrl($this->tenant, '/templates'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Communication/Pages/Templates/Index', false) // pages are built by the frontend phase; drop `false` then
            ->has('templates', 1)
            ->where('templates.0.slug', 'welcome')
            ->has('variables')
            ->where('canManage', true));
});

it('refuses a template with a placeholder that is not on the allow-list', function () {
    $this->post($this->commUrl($this->tenant, '/templates'), ($this->template)([
        'body' => 'Hi {{ system("rm -rf /") }} and {{ contact.password }}',
    ]))->assertSessionHasErrors('body');

    expect($this->inTenant($this->tenant, fn () => MessageTemplate::query()->count()))->toBe(0);
});

it('requires a subject for email and refuses a duplicate name, channel and language', function () {
    $this->post($this->commUrl($this->tenant, '/templates'), ($this->template)(['subject' => '']))->assertSessionHasErrors('subject');
    $this->post($this->commUrl($this->tenant, '/templates'), ($this->template)())->assertRedirect();
    $this->post($this->commUrl($this->tenant, '/templates'), ($this->template)())->assertSessionHasErrors('slug');
    // The same slug in Bengali is a different template.
    $this->post($this->commUrl($this->tenant, '/templates'), ($this->template)(['locale' => 'bn']))->assertRedirect();
});

it('previews a draft with sample values and reports unknown placeholders without executing anything', function () {
    $this->postJson($this->commUrl($this->tenant, '/templates/preview'), [
        'subject' => 'Hi {{ contact.first_name }}',
        'body' => '{{ contact.full_name }} {{ 7*7 }} {{ phpinfo() }}',
    ])->assertOk()
        ->assertJsonPath('subject', 'Hi Rahim')
        ->assertJsonPath('body', 'Rahim Uddin  ')
        ->assertJsonPath('unknown', ['7*7', 'phpinfo()']);
});

it('composes one email to a contact from a template and logs it on the timeline', function () {
    Mail::fake();
    $contact = $this->commContact($this->tenant, ['first_name' => 'Nusrat', 'last_name' => 'Jahan', 'email' => 'nusrat@example.test']);
    $this->post($this->commUrl($this->tenant, '/templates'), ($this->template)());
    $templateId = $this->inTenant($this->tenant, fn () => MessageTemplate::query()->value('id'));

    $this->post($this->commUrl($this->tenant, '/messages'), [
        'contact_id' => $contact->id, 'channel' => 'email', 'purpose' => 'transactional', 'template_id' => $templateId, 'request_id' => 'req-1',
    ])->assertRedirect();

    $this->inTenant($this->tenant, function () use ($contact): void {
        $message = Message::query()->sole();
        expect($message->status)->toBe(MessageStatus::Sent)
            ->and($message->subject)->toBe('Welcome Nusrat')
            ->and($message->body)->toBe('Hi Nusrat Jahan, from Comm Tpl.')
            ->and($message->contact_id)->toBe($contact->id)
            ->and($message->source)->toBe('composer')
            ->and(Interaction::query()->where('message_id', $message->id)->where('type', 'email')->where('direction', 'outbound')->exists())->toBeTrue();
    });
    Mail::assertSent(OutboundMail::class, fn (OutboundMail $mail) => $mail->hasTo('nusrat@example.test'));
});

it('sends a double-submitted compose form only once', function () {
    Mail::fake();
    $contact = $this->commContact($this->tenant, ['email' => 'once@example.test']);
    $payload = ['contact_id' => $contact->id, 'channel' => 'email', 'purpose' => 'transactional', 'subject' => 'Hello', 'body' => 'Body', 'request_id' => 'same-form'];

    $this->post($this->commUrl($this->tenant, '/messages'), $payload)->assertRedirect();
    $this->post($this->commUrl($this->tenant, '/messages'), $payload)->assertRedirect();

    expect($this->inTenant($this->tenant, fn () => Message::query()->count()))->toBe(1);
    Mail::assertSentCount(1);
});

it('refuses a marketing message without consent and a suppressed address, as form errors', function () {
    Mail::fake();
    $contact = $this->commContact($this->tenant, ['email' => 'quiet@example.test']);
    $payload = ['contact_id' => $contact->id, 'channel' => 'email', 'purpose' => 'marketing', 'subject' => 'Sale', 'body' => 'Buy'];

    $this->post($this->commUrl($this->tenant, '/messages'), $payload)->assertSessionHasErrors('contact_id');

    $this->optIn($this->tenant, $contact);
    $this->post($this->commUrl($this->tenant, '/suppressions'), ['channel' => 'email', 'address' => 'QUIET@example.test', 'scope' => 'all'])->assertRedirect();
    $this->post($this->commUrl($this->tenant, '/messages'), $payload)->assertSessionHasErrors('contact_id');

    Mail::assertNothingSent();
});

it('refuses to compose on a channel that is not configured', function () {
    $contact = $this->commContact($this->tenant);

    $this->post($this->commUrl($this->tenant, '/messages'), [
        'contact_id' => $contact->id, 'channel' => 'sms', 'purpose' => 'transactional', 'body' => 'Hello',
    ])->assertSessionHasErrors('channel');
});

it('treats a contact outside the sender scope as not found', function () {
    [$user, $membership] = $this->commMember($this->tenant, ['core.contacts.view', 'communication.messages.send'], ['core.contacts.view' => 'own']);
    $mine = $this->commContact($this->tenant, ['email' => 'mine@example.test'], $membership);
    $theirs = $this->commContact($this->tenant, ['email' => 'theirs@example.test']);
    Mail::fake();

    $this->actingAsMember($this->tenant, $user);
    $send = fn ($contact) => $this->post($this->commUrl($this->tenant, '/messages'), [
        'contact_id' => $contact->id, 'channel' => 'email', 'purpose' => 'transactional', 'subject' => 'Hi', 'body' => 'Body',
    ]);

    $send($theirs)->assertNotFound();
    $send($mine)->assertRedirect();
});

it('escapes tenant text in the outgoing email view instead of compiling it', function () {
    $html = view('mail.communication.message', [
        'body' => "Hello <script>alert(1)</script> {{ 7*7 }} @php(die()) <?php echo 'x'; ?>",
        'footer' => '<b>Footer</b>',
        'unsubscribeUrl' => null,
    ])->render();

    expect($html)->toContain('&lt;script&gt;')
        ->and($html)->not->toContain('<script>')
        ->and($html)->toContain('{{ 7*7 }}')
        ->and($html)->toContain('@php(die())')
        ->and($html)->toContain('&lt;b&gt;Footer&lt;/b&gt;');
});
