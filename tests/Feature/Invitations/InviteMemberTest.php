<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Events\MemberInvited;
use App\Core\Identity\Mail\InvitationMail;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->acme = $this->createTenant('acme', ['name' => 'Acme Trading']);
    $this->globex = $this->createTenant('globex');

    $this->admin = $this->addMember($this->acme, Role::ADMIN);
    $this->manager = $this->addMember($this->acme, Role::MANAGER);
    $this->employee = $this->addMember($this->acme, Role::EMPLOYEE);

    $this->inviteAs = fn (User $actor, string $email, Role|string $role = Role::EMPLOYEE, ?Tenant $tenant = null): TestResponse => $this
        ->actingAsMember($tenant ?? $this->acme, $actor)
        ->post($this->tenantUrl($tenant ?? $this->acme, 'team/invitations'), [
            'email' => $email,
            'role_id' => $role instanceof Role ? $role->id : $this->roleOf($tenant ?? $this->acme, $role)->id,
        ]);

    /** The token in the link of the most recently sent invitation email. */
    $this->emailedToken = function (): string {
        $html = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHtmlBody();

        expect(preg_match('#http://([a-z0-9-]+)\.localhost/invitations/([A-Za-z0-9]{64})#', (string) $html, $match))->toBe(1);

        return $match[2];
    };

    $this->invitations = fn (Tenant $tenant) => Invitation::forTenant($tenant)->orderBy('id')->get();
});

describe('permission', function () {
    it('needs the invite permission', function () {
        ($this->inviteAs)($this->employee, 'new@example.test')->assertForbidden();

        expect(($this->invitations)($this->acme))->toHaveCount(0);

        ($this->inviteAs)($this->manager, 'new@example.test')->assertRedirect()->assertSessionHasNoErrors();

        expect(($this->invitations)($this->acme))->toHaveCount(1);
    });

    it('needs a signed-in member', function () {
        $this->post('http://acme.localhost/team/invitations', [
            'email' => 'new@example.test',
            'role_id' => $this->roleOf($this->acme, Role::EMPLOYEE)->id,
        ])->assertRedirect('http://acme.localhost/login');

        expect(($this->invitations)($this->acme))->toHaveCount(0);
    });

    it('only lets someone hand out a role whose permissions they all hold', function () {
        // A manager holds members.view, members.invite and roles.view.
        ($this->inviteAs)($this->manager, 'a@example.test', Role::EMPLOYEE)->assertSessionHasNoErrors();
        ($this->inviteAs)($this->manager, 'b@example.test', Role::MANAGER)->assertSessionHasNoErrors();

        ($this->inviteAs)($this->manager, 'c@example.test', Role::ADMIN)
            ->assertSessionHasErrors(['role_id' => 'You cannot invite someone with this role.']);

        // A custom role with a single permission above the manager's.
        $auditors = $this->createRole($this->acme, 'Auditors', ['core.audit.view']);

        ($this->inviteAs)($this->manager, 'd@example.test', $auditors)->assertSessionHasErrors('role_id');
        ($this->inviteAs)($this->admin, 'd@example.test', $auditors)->assertSessionHasNoErrors();

        expect(($this->invitations)($this->acme)->pluck('email')->all())->toBe(['a@example.test', 'b@example.test', 'd@example.test']);
    });

    it('never lets anyone invite a second owner', function () {
        foreach ([$this->manager, $this->admin, $this->ownerOf($this->acme)] as $actor) {
            ($this->inviteAs)($actor, 'co-owner@example.test', Role::OWNER)->assertSessionHasErrors('role_id');
        }

        expect(($this->invitations)($this->acme))->toHaveCount(0);
    });

    it('does not accept a role of another workspace', function () {
        ($this->inviteAs)($this->admin, 'new@example.test', $this->roleOf($this->globex, Role::EMPLOYEE))->assertNotFound();

        expect(Invitation::withoutTenancy()->count())->toBe(0);
    });
});

describe('sending', function () {
    it('creates the invitation, emails a link into the workspace and records it', function () {
        Event::fake([MemberInvited::class]);

        ($this->inviteAs)($this->admin, '  New.Person@Example.TEST ', Role::MANAGER)
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $invitation = ($this->invitations)($this->acme)->sole();

        expect($invitation->email)->toBe('new.person@example.test')
            ->and($invitation->tenant_id)->toBe($this->acme->id)
            ->and($invitation->role_id)->toBe($this->roleOf($this->acme, Role::MANAGER)->id)
            ->and($invitation->invited_by)->toBe($this->admin->id)
            ->and($invitation->accepted_at)->toBeNull()
            ->and($invitation->revoked_at)->toBeNull()
            ->and($invitation->expires_at->diffInDays(now()->addDays(7), absolute: true))->toBeLessThan(1)
            ->and($invitation->isPending())->toBeTrue();

        // The email: to the invitee, in the workspace's name, with a link to the workspace's own host.
        $message = app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();

        expect($message->getTo()[0]->getAddress())->toBe('new.person@example.test')
            ->and($message->getSubject())->toBe('You have been invited to Acme Trading')
            ->and($message->getHtmlBody())
            ->toContain('Acme Trading', $this->admin->name, 'Manager', 'http://acme.localhost/invitations/');

        // Only a hash of the emailed token is kept.
        $token = ($this->emailedToken)();

        expect($invitation->token_hash)->toBe(hash('sha256', $token))
            ->and(DB::table('invitations')->where('token_hash', $token)->exists())->toBeFalse()
            ->and(json_encode($invitation))->not->toContain($invitation->token_hash);

        $entry = $this->auditTrail($this->acme, 'member.invited')->sole();

        expect($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->subject_type)->toBe('invitation')
            ->and($entry->subject_id)->toBe($invitation->id)
            ->and($entry->subject_label)->toBe('new.person@example.test')
            ->and($entry->properties)->toBe(['role' => 'Manager'])
            ->and(json_encode($entry))->not->toContain($token);

        Event::assertDispatched(MemberInvited::class, fn (MemberInvited $event): bool => $event->tenantId() === $this->acme->id
            && $event->payload() === [
                'invitation_id' => $invitation->id,
                'email' => 'new.person@example.test',
                'role_id' => $invitation->role_id,
            ]);
    });

    it('queues the email on the notifications queue', function () {
        Mail::fake();

        ($this->inviteAs)($this->admin, 'new@example.test');

        Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->hasTo('new@example.test')
            && $mail->queue === 'notifications'
            && $mail->invitation->is(($this->invitations)($this->acme)->sole()));
        Mail::assertNothingSent();
    });

    it('survives a real queue: encrypted payload, tenant restored, same link', function () {
        config(['queue.default' => 'database']);

        ($this->inviteAs)($this->admin, 'new@example.test');

        $job = DB::table('jobs')->sole();
        $payload = json_decode($job->payload, true);

        expect($job->queue)->toBe('notifications')
            ->and($payload['tenant_id'])->toBe($this->acme->id)
            ->and($payload['displayName'])->toBe(InvitationMail::class)
            // Encrypted: neither the mailable nor the address can be read from the jobs table.
            ->and($payload['data']['command'])->not->toContain('InvitationMail', 'new@example.test')
            ->and(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(0);

        // A worker with no tenant active picks it up.
        $this->inCentral(fn () => $this->artisan('queue:work', ['--once' => true, '--queue' => 'notifications', '--tries' => 1, '--sleep' => 0]));

        expect(DB::table('failed_jobs')->count())->toBe(0);

        $token = ($this->emailedToken)();

        expect($job->payload)->not->toContain($token)
            ->and(hash('sha256', $token))->toBe(($this->invitations)($this->acme)->sole()->token_hash);
    });

    it('validates the request', function (array $input, string $field) {
        $this->actingAsMember($this->acme, $this->admin)
            ->post('http://acme.localhost/team/invitations', [
                'email' => 'new@example.test',
                'role_id' => $this->roleOf($this->acme, Role::EMPLOYEE)->id,
                ...$input,
            ])
            ->assertSessionHasErrors($field);

        expect(($this->invitations)($this->acme))->toHaveCount(0);
    })->with([
        'no email' => [['email' => ''], 'email'],
        'not an email' => [['email' => 'not-an-email'], 'email'],
        'no role' => [['role_id' => ''], 'role_id'],
        'role id of the wrong shape' => [['role_id' => '1'], 'role_id'],
        'role id as an array' => [['role_id' => ['a']], 'role_id'],
    ]);
});

describe('the language of the email', function () {
    beforeEach(function () {
        $this->lastEmail = fn () => app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    });

    it('is the workspace language for someone who has no account yet', function () {
        $dhaka = $this->createTenant('dhaka', ['name' => 'Dhaka Traders', 'locale' => 'bn']);
        $admin = $this->addMember($dhaka, Role::ADMIN);

        ($this->inviteAs)($admin, 'new@example.test', Role::EMPLOYEE, $dhaka)->assertSessionHasNoErrors();

        $email = ($this->lastEmail)();

        expect($email->getSubject())->toBe(__('You have been invited to :workspace', ['workspace' => 'Dhaka Traders'], 'bn'))
            ->not->toBe('You have been invited to Dhaka Traders')
            ->and($email->getHtmlBody())->toContain(__('Accept invitation', [], 'bn'));
    });

    it('is the language the invitee chose, when they already have an account', function () {
        User::factory()->create(['email' => 'reader@example.test', 'locale' => 'bn']);

        ($this->inviteAs)($this->admin, 'reader@example.test')->assertSessionHasNoErrors();

        expect(($this->lastEmail)()->getHtmlBody())->toContain(__('Accept invitation', [], 'bn'))
            // Writing an email in another language does not change the language of the request.
            ->and(app()->getLocale())->toBe('en');

        // Someone without a preference gets this workspace's language, English.
        ($this->inviteAs)($this->admin, 'other@example.test')->assertSessionHasNoErrors();

        expect(($this->lastEmail)()->getHtmlBody())->toContain('Accept invitation');
    });

    it('gives the expiry as a day on the workspace\'s clock', function () {
        // Seven days on is 23:30 UTC on the 14th: already the 15th in Dhaka.
        $this->travelTo(Carbon::parse('2026-10-07 23:30:00', 'UTC'));

        ($this->inviteAs)($this->admin, 'new@example.test')->assertSessionHasNoErrors();

        expect(($this->lastEmail)()->getHtmlBody())->toContain('October 15, 2026');
    });
});

describe('who can be invited', function () {
    it('rejects someone who is already a member, active or suspended', function () {
        $suspended = $this->addMember($this->acme, Role::EMPLOYEE, status: MembershipStatus::Suspended);

        foreach ([$this->employee->email, strtoupper($this->employee->email), $suspended->email, $this->ownerOf($this->acme)->email] as $email) {
            ($this->inviteAs)($this->admin, $email)
                ->assertSessionHasErrors(['email' => 'This person is already a member of the workspace.']);
        }

        expect(($this->invitations)($this->acme))->toHaveCount(0);
    });

    it('accepts someone who only belongs to another workspace', function () {
        $elsewhere = $this->addMember($this->globex, Role::ADMIN);

        ($this->inviteAs)($this->admin, $elsewhere->email)->assertSessionHasNoErrors();

        expect(($this->invitations)($this->acme)->sole()->email)->toBe($elsewhere->email);
    });

    it('replaces an open invitation to the same address: the old link stops working', function () {
        ($this->inviteAs)($this->admin, 'new@example.test', Role::EMPLOYEE);
        $firstToken = ($this->emailedToken)();

        ($this->inviteAs)($this->admin, 'NEW@example.test', Role::MANAGER);
        $secondToken = ($this->emailedToken)();

        [$first, $second] = ($this->invitations)($this->acme)->all();

        expect($firstToken)->not->toBe($secondToken)
            ->and($first->revoked_at)->not->toBeNull()
            ->and($first->isPending())->toBeFalse()
            ->and($second->isPending())->toBeTrue()
            ->and($second->role_id)->toBe($this->roleOf($this->acme, Role::MANAGER)->id);

        $this->startNewBrowserSession();

        $this->get("http://acme.localhost/invitations/{$firstToken}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'revoked'));
        $this->get("http://acme.localhost/invitations/{$secondToken}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'new_account'));
    });

    it('keeps invitations of the same address in different workspaces independent', function () {
        $globexAdmin = $this->addMember($this->globex, Role::ADMIN);

        ($this->inviteAs)($this->admin, 'new@example.test');
        $this->startNewBrowserSession();
        ($this->inviteAs)($globexAdmin, 'new@example.test', Role::EMPLOYEE, $this->globex);

        expect(($this->invitations)($this->acme)->sole()->isPending())->toBeTrue()
            ->and(($this->invitations)($this->globex)->sole()->isPending())->toBeTrue();
    });
});

describe('revoking', function () {
    it('revokes an open invitation and records it', function () {
        [$invitation, $token] = $this->createInvitation($this->acme, 'new@example.test');

        $this->actingAsMember($this->acme, $this->manager)
            ->delete("http://acme.localhost/team/invitations/{$invitation->id}")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect($invitation->refresh()->revoked_at)->not->toBeNull()
            ->and($this->auditTrail($this->acme, 'member.invitation_revoked')->sole()->subject_label)->toBe('new@example.test');

        $this->startNewBrowserSession();
        $this->get("http://acme.localhost/invitations/{$token}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'revoked'));
    });

    it('needs the invite permission', function () {
        [$invitation] = $this->createInvitation($this->acme, 'new@example.test');

        $this->actingAsMember($this->acme, $this->employee)
            ->delete("http://acme.localhost/team/invitations/{$invitation->id}")
            ->assertForbidden();

        expect($invitation->refresh()->revoked_at)->toBeNull();
    });

    it('leaves an accepted invitation alone', function () {
        [$invitation] = $this->createInvitation($this->acme, 'new@example.test', attributes: ['accepted_at' => now()]);

        $this->actingAsMember($this->acme, $this->admin)
            ->delete("http://acme.localhost/team/invitations/{$invitation->id}")
            ->assertRedirect();

        expect($invitation->refresh()->revoked_at)->toBeNull()
            ->and($this->auditTrail($this->acme, 'member.invitation_revoked'))->toHaveCount(0);
    });

    it('cannot reach an invitation of another workspace', function () {
        [$invitation] = $this->createInvitation($this->acme, 'new@example.test');

        $this->assertNotFoundFromOtherTenant('DELETE', "/team/invitations/{$invitation->id}", $this->acme, intruder: $this->globex);
    });
});

describe('the pending list', function () {
    it('is shown to people who may invite, and to nobody else', function () {
        $this->createInvitation($this->acme, 'pending@example.test', Role::MANAGER);
        $this->createInvitation($this->acme, 'expired@example.test', attributes: ['expires_at' => now()->subDay()]);
        $this->createInvitation($this->acme, 'revoked@example.test', attributes: ['revoked_at' => now()]);
        $this->createInvitation($this->acme, 'joined@example.test', attributes: ['accepted_at' => now()]);
        $this->createInvitation($this->globex, 'other-workspace@example.test');

        $this->actingAsMember($this->acme, $this->manager)
            ->get('http://acme.localhost/team/members')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $listed = collect($page->toArray()['props']['invitations'])->keyBy('email');

                // Open ones only (an expired one is still open until revoked), of this workspace only.
                expect($listed->keys()->sort()->values()->all())->toBe(['expired@example.test', 'pending@example.test'])
                    ->and($listed['pending@example.test'])->toMatchArray(['role' => 'Manager', 'expired' => false])
                    ->and($listed['expired@example.test'])->toMatchArray(['expired' => true])
                    ->and(json_encode($listed))->not->toContain('token');
            });

        $this->actingAsMember($this->acme, $this->employee)
            ->get('http://acme.localhost/team/members')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('invitations', []));
    });
});
