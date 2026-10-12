<?php

declare(strict_types=1);

use App\Core\Access\Models\Permission;
use App\Core\Access\Models\Role;
use App\Core\Access\RoleManager;
use App\Core\Identity\Actions\MemberManager;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Fixtures\Crm\CrmServiceProvider;

/*
 * A role can keep a grant of a module the workspace is no longer entitled to.
 * That grant is inert, so it must not stop anyone from assigning, editing or
 * cleaning up the role, or from managing a member who holds it.
 */
beforeEach(function () {
    $this->registerModule(CrmServiceProvider::class);

    $this->acme = $this->createTenant('acme');
    $this->owner = $this->ownerOf($this->acme);
    $this->admin = $this->addMember($this->acme, Role::ADMIN);

    // What a lapsed licence leaves behind: a grant of a module the workspace no longer has.
    $this->legacy = $this->createRole($this->acme, 'Legacy', ['core.members.view']);

    DB::table('role_has_permissions')->insert([
        'permission_id' => Permission::query()->where('name', 'test_crm.leads.view')->value('id'),
        'role_id' => $this->legacy->id,
        'scope' => 'all',
    ]);

    $this->inTenant($this->acme, fn () => app(RoleManager::class)->flush());

    $this->leaver = $this->addMember($this->acme, $this->legacy);

    $this->membershipOf = fn () => $this->inTenant($this->acme, fn () => TenantMembership::query()
        ->where('user_id', $this->leaver->getKey())->firstOrFail());
});

it('still holds the stale grant in the role', function () {
    expect(DB::table('role_has_permissions')->where('role_id', $this->legacy->id)
        ->whereIn('permission_id', Permission::query()->where('name', 'test_crm.leads.view')->pluck('id'))->exists())->toBeTrue();
});

it('lets the owner and an admin assign the role', function () {
    $this->inTenant($this->acme, function () {
        $roles = app(RoleManager::class);

        expect($roles->canAssign($this->owner, $this->legacy))->toBeTrue()
            ->and($roles->canAssign($this->admin, $this->legacy))->toBeTrue();
    });
});

it('lets the owner and an admin manage and suspend a member who holds it', function () {
    $this->inTenant($this->acme, function () {
        $members = app(MemberManager::class);
        $membership = ($this->membershipOf)();

        expect($members->canManage($this->owner, $membership))->toBeTrue()
            ->and($members->canManage($this->admin, $membership))->toBeTrue();

        $members->setStatus($this->owner, $membership, MembershipStatus::Suspended);

        expect($membership->refresh()->status)->toBe(MembershipStatus::Suspended);
    });
});

it('lets an admin clean the stale grant out of the role', function () {
    $this->inTenant($this->acme, function () {
        $roles = app(RoleManager::class);

        $roles->update($this->admin, $this->legacy, 'Legacy', null, ['core.members.view']);

        expect($roles->grantedNames($this->legacy))->toBe(['core.members.view']);
    });
});

it('still refuses what the actor does not hold among the live grants', function () {
    $this->inTenant($this->acme, function () {
        $roles = app(RoleManager::class);
        $modest = $this->createRole($this->acme, 'Modest', ['core.members.view', 'core.roles.view', 'core.roles.manage']);
        $actor = $this->addMember($this->acme, $modest);

        // Holds core.members.view, not core.audit.view: adding it is still an escalation.
        expect(fn () => $roles->update($actor, $this->legacy, 'Legacy', null, ['core.members.view', 'core.audit.view']))
            ->toThrow(ValidationException::class);
    });
});
