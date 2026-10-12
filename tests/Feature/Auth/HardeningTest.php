<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Identity\Models\Invitation;
use App\Core\Registration\Rules\AvailableWorkspaceAddress;
use App\Core\Tenancy\Support\TenantHost;
use Illuminate\Support\Facades\Validator;
use Tests\Support\Fixtures\Crm\CrmServiceProvider;

beforeEach(function () {
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');
});

describe('accepting an invitation with an existing account', function () {
    it('locks out repeated wrong passwords like the sign-in form does', function () {
        $existing = $this->addMember($this->globex, Role::EMPLOYEE);
        [, $token] = $this->createInvitation($this->acme, $existing->email);

        foreach (range(1, 5) as $attempt) {
            $this->post("http://acme.localhost/invitations/{$token}", ['password' => 'wrong-password'])
                ->assertSessionHasErrors('password');
        }

        // Even the right password is refused while locked out.
        $this->post("http://acme.localhost/invitations/{$token}", ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertGuest('web');
    });

    it('still accepts the right password before the limit', function () {
        $existing = $this->addMember($this->globex, Role::EMPLOYEE);
        [, $token] = $this->createInvitation($this->acme, $existing->email);

        $this->post("http://acme.localhost/invitations/{$token}", ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');

        $this->post("http://acme.localhost/invitations/{$token}", ['password' => 'password'])
            ->assertRedirect()->assertSessionHasNoErrors();
    });
});

it('sends a signed-out visitor to sign in for an app route the workspace does not have', function () {
    $this->registerModule(CrmServiceProvider::class);

    // Not entitled: still the same answer as for an entitled workspace.
    $this->get('http://acme.localhost/test-crm/leads')->assertRedirect('http://acme.localhost/login');

    $this->entitle($this->acme, 'test_crm');

    $this->get('http://acme.localhost/test-crm/leads')->assertRedirect('http://acme.localhost/login');
});

it('answers 404 to a signed-in member for an app the workspace does not have', function () {
    $this->registerModule(CrmServiceProvider::class);

    $this->actingAsMember($this->acme, $this->ownerOf($this->acme))
        ->get('http://acme.localhost/test-crm/leads')
        ->assertNotFound();
});

it('refuses punycode labels as workspace addresses', function () {
    expect(preg_match('/^'.TenantHost::LABEL_PATTERN.'$/D', 'xn--pple-43d'))->toBe(0)
        ->and(preg_match('/^'.TenantHost::LABEL_PATTERN.'$/D', 'apple-xn'))->toBe(1)
        ->and(app(TenantHost::class)->tenantKey('xn--pple-43d.localhost'))->toBeNull();

    $validator = Validator::make(['slug' => 'xn--pple-43d'], ['slug' => [new AvailableWorkspaceAddress]]);

    expect($validator->fails())->toBeTrue();
});

describe('withdrawing an invitation', function () {
    it('applies the role hierarchy', function () {
        $inviter = $this->addMember($this->acme, $this->createRole($this->acme, 'Inviters', ['core.members.view', 'core.members.invite']));
        [$aboveMe] = $this->createInvitation($this->acme, 'admin.to-be@example.test', Role::ADMIN);
        [$belowMe] = $this->createInvitation($this->acme, 'staff.to-be@example.test', $this->createRole($this->acme, 'Viewers', ['core.members.view']));

        $this->actingAsMember($this->acme, $inviter)
            ->delete("http://acme.localhost/team/invitations/{$aboveMe->id}")
            ->assertSessionHasErrors('invitation');

        expect($this->inTenant($this->acme, fn () => Invitation::query()->findOrFail($aboveMe->id)->revoked_at))->toBeNull();

        $this->actingAsMember($this->acme, $inviter)
            ->delete("http://acme.localhost/team/invitations/{$belowMe->id}")
            ->assertSessionHasNoErrors();

        expect($this->inTenant($this->acme, fn () => Invitation::query()->findOrFail($belowMe->id)->revoked_at))->not->toBeNull();
    });
});
