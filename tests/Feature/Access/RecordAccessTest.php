<?php

declare(strict_types=1);

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Access\Models\Role;
use App\Core\Access\RecordAccess;
use App\Core\Access\RoleManager;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Actions\MemberManager;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * How far a grant reaches, evaluated on the shared contacts.
 *
 * The viewer sits in the Dhaka branch and the Sales department. Around them:
 *
 *   contact     owner       department  branch
 *   Mine        the viewer  Sales       Dhaka
 *   Teammate    teammate    Sales       Chattogram
 *   Neighbour   neighbour   Support     Dhaka
 *   Stranger    stranger    Support     Chattogram
 *   Unplaced    drifter     -           -
 *   Unowned     -           -           -
 */
beforeEach(function () {
    $this->acme = $this->createTenant('acme');
    $this->owner = $this->ownerOf($this->acme);

    [$this->dhaka, $this->chattogram, $this->sales, $this->support] = $this->inTenant($this->acme, fn (): array => [
        Branch::factory()->create(['name' => 'Dhaka']),
        Branch::factory()->create(['name' => 'Chattogram']),
        Department::factory()->create(['name' => 'Sales']),
        Department::factory()->create(['name' => 'Support']),
    ]);

    /** A member of acme with a role, sitting in a branch and a department. */
    $this->person = function (Role|string $role, ?Branch $branch = null, ?Department $department = null): User {
        $user = $this->addMember($this->acme, $role);

        $this->inTenant($this->acme, fn () => $this->membershipOf($this->acme, $user)->update([
            'branch_id' => $branch?->id,
            'department_id' => $department?->id,
        ]));

        return $user;
    };

    /** A role that grants the contact permissions with the given scopes. */
    $this->roleWith = fn (string $name, array $scopes): Role => $this->inTenant(
        $this->acme,
        fn (): Role => app(RoleManager::class)->create($this->owner, $name, null, array_keys($scopes), $scopes),
    );

    /** A contact of acme, named by its first name and handed to a member (or to nobody). */
    $this->contact = fn (string $name, ?User $owner = null): Contact => $this->inTenant($this->acme, function () use ($name, $owner): Contact {
        $contact = Contact::factory()->make(['first_name' => $name]);
        $contact->assignOwner($owner === null ? null : $this->membershipOf($this->acme, $owner));
        $contact->save();

        return $contact;
    });

    $bystanders = ($this->roleWith)('Bystanders', []);

    $this->contacts = [
        'Teammate' => ($this->contact)('Teammate', ($this->person)($bystanders, $this->chattogram, $this->sales)),
        'Neighbour' => ($this->contact)('Neighbour', ($this->person)($bystanders, $this->dhaka, $this->support)),
        'Stranger' => ($this->contact)('Stranger', ($this->person)($bystanders, $this->chattogram, $this->support)),
        'Unplaced' => ($this->contact)('Unplaced', ($this->person)($bystanders)),
        'Unowned' => ($this->contact)('Unowned'),
    ];

    /** The viewer: in Dhaka and Sales unless told otherwise, owning the contact "Mine". */
    $this->viewer = function (Role|string $role, bool $branch = true, bool $department = true): User {
        $viewer = ($this->person)($role, $branch ? $this->dhaka : null, $department ? $this->sales : null);

        $this->contacts['Mine'] = ($this->contact)('Mine', $viewer);

        return $viewer;
    };

    /**
     * The contacts a person reaches with a permission, by first name - asked
     * both ways: as a query (`constrain`) and record by record (`allows`).
     * The two must always agree.
     */
    $this->reach = function (User $user, string $permission = 'core.contacts.view'): array {
        // Decide the way the next request would: nothing remembered from an earlier one.
        $this->app->forgetScopedInstances();

        return $this->inTenant($this->acme, function () use ($user, $permission): array {
            $access = app(RecordAccess::class);

            $listed = $access->constrain(Contact::query(), $user, $permission)->orderBy('first_name')->pluck('first_name')->all();

            $allowed = Contact::query()
                ->orderBy('first_name')
                ->get()
                ->filter(fn (Contact $contact): bool => $access->allows($user, $permission, $contact))
                ->pluck('first_name')
                ->values()
                ->all();

            expect($allowed)->toBe($listed, 'constrain() and allows() disagree.');

            return $listed;
        });
    };
});

describe('each scope', function () {
    it('reaches exactly the records it is meant to', function (string $scope, array $expected) {
        $viewer = ($this->viewer)(($this->roleWith)('Viewers', ['core.contacts.view' => $scope]));

        expect(($this->reach)($viewer))->toBe($expected);
    })->with([
        'own: only the records the person owns' => ['own', ['Mine']],
        'department: those, and the department\'s' => ['department', ['Mine', 'Teammate']],
        'branch: those, the branch\'s and the department\'s' => ['branch', ['Mine', 'Neighbour', 'Teammate']],
        'all: every record of the workspace' => ['all', ['Mine', 'Neighbour', 'Stranger', 'Teammate', 'Unowned', 'Unplaced']],
    ]);

    it('reaches nothing without a grant', function () {
        // Holds something, but not this permission.
        $viewer = ($this->viewer)(($this->roleWith)('Deleters', ['core.contacts.delete' => 'all']));

        expect(($this->reach)($viewer))->toBe([])
            ->and(($this->reach)($viewer, 'core.contacts.manage'))->toBe([])
            ->and(($this->reach)($viewer, 'core.contacts.delete'))->toHaveCount(6);
    });

    it('is decided per permission: seeing every contact does not mean changing every contact', function () {
        // The Employee role as provisioned: view everything, manage their own.
        $employee = ($this->viewer)(Role::EMPLOYEE);

        expect(($this->reach)($employee, 'core.contacts.view'))->toHaveCount(6)
            ->and(($this->reach)($employee, 'core.contacts.manage'))->toBe(['Mine'])
            ->and(($this->reach)($employee, 'core.contacts.delete'))->toBe([]);
    });

    it('gives the owner of the workspace every record, wherever they sit', function () {
        ($this->viewer)(Role::EMPLOYEE);

        foreach (['core.contacts.view', 'core.contacts.manage', 'core.contacts.delete'] as $permission) {
            expect(($this->reach)($this->owner, $permission))->toHaveCount(6);
        }

        $this->inTenant($this->acme, function (): void {
            $membership = $this->membershipOf($this->acme, $this->owner);

            expect($membership->branch_id)->toBeNull()
                ->and($membership->department_id)->toBeNull();
        });
    });
});

describe('someone who is not placed', function () {
    it('gets only their own records from a department grant when they have no department', function () {
        $viewer = ($this->viewer)(($this->roleWith)('Viewers', ['core.contacts.view' => 'department']), department: false);

        // Not "every record without a department" either: Unplaced and Unowned have none.
        expect(($this->reach)($viewer))->toBe(['Mine']);
    });

    it('gets only their own records from a branch grant when they have neither branch nor department', function () {
        $viewer = ($this->viewer)(($this->roleWith)('Viewers', ['core.contacts.view' => 'branch']), branch: false, department: false);

        expect(($this->reach)($viewer))->toBe(['Mine']);
    });

    it('still gets their department\'s records from a branch grant when only the branch is missing', function () {
        $viewer = ($this->viewer)(($this->roleWith)('Viewers', ['core.contacts.view' => 'branch']), branch: false);

        expect(($this->reach)($viewer))->toBe(['Mine', 'Teammate']);
    });

    it('still gets their branch\'s records from a branch grant when only the department is missing', function () {
        $viewer = ($this->viewer)(($this->roleWith)('Viewers', ['core.contacts.view' => 'branch']), department: false);

        expect(($this->reach)($viewer))->toBe(['Mine', 'Neighbour']);
    });
});

describe('who is asking', function () {
    it('reaches nothing as someone who is not a member, or whose membership is suspended', function () {
        $globex = $this->createTenant('globex');
        $outsider = $this->addMember($globex, Role::ADMIN);
        $suspended = $this->addMember($this->acme, Role::ADMIN, status: MembershipStatus::Suspended);

        expect(($this->reach)($outsider))->toBe([])
            ->and(($this->reach)($suspended))->toBe([]);
    });

    it('answers 404 for a record outside the scope, and lets one inside it through', function () {
        $viewer = ($this->viewer)(($this->roleWith)('Viewers', ['core.contacts.view' => 'department']));

        $this->app->forgetScopedInstances();

        $this->inTenant($this->acme, function () use ($viewer): void {
            $access = app(RecordAccess::class);

            $access->authorize($viewer, 'core.contacts.view', $this->contacts['Mine']);
            $access->authorize($viewer, 'core.contacts.view', $this->contacts['Teammate']);

            expect(fn () => $access->authorize($viewer, 'core.contacts.view', $this->contacts['Stranger']))
                ->toThrow(NotFoundHttpException::class);
            expect(fn () => $access->authorize($viewer, 'core.contacts.manage', $this->contacts['Mine']))
                ->toThrow(NotFoundHttpException::class);
        });
    });

    it('follows the person when they are moved to another department', function () {
        $viewer = ($this->viewer)(($this->roleWith)('Viewers', ['core.contacts.view' => 'department']));

        expect(($this->reach)($viewer))->toBe(['Mine', 'Teammate']);

        $this->inTenant($this->acme, fn () => app(MemberManager::class)->place(
            $this->owner,
            $this->membershipOf($this->acme, $viewer),
            $this->dhaka,
            $this->support,
        ));

        // Their own record stayed in Sales, and is still theirs.
        expect(($this->reach)($viewer))->toBe(['Mine', 'Neighbour', 'Stranger']);
    });

    it('falls back to their own records when their department is deleted', function () {
        $viewer = ($this->viewer)(($this->roleWith)('Viewers', ['core.contacts.view' => 'department']));

        $this->inTenant($this->acme, fn () => $this->sales->delete());

        expect(($this->reach)($viewer))->toBe(['Mine']);
    });
});

describe('where a record belongs', function () {
    it('takes its department and branch from the member it is handed to', function () {
        $viewer = ($this->viewer)(Role::EMPLOYEE);
        $membership = $this->membershipOf($this->acme, $viewer);

        $mine = $this->contacts['Mine']->refresh();

        expect($mine->owner_id)->toBe($membership->id)
            ->and($mine->department_id)->toBe($this->sales->id)
            ->and($mine->branch_id)->toBe($this->dhaka->id)
            ->and($this->inTenant($this->acme, fn () => Contact::query()->findOrFail($mine->id)->owner->is($membership)))->toBeTrue();

        // Handed to someone in another team, it moves there ...
        $stranger = $this->inTenant($this->acme, fn () => TenantMembership::query()->findOrFail($this->contacts['Stranger']->owner_id));

        $this->inTenant($this->acme, fn () => $mine->assignOwner($stranger)->save());

        expect($mine->refresh()->owner_id)->toBe($stranger->id)
            ->and($mine->department_id)->toBe($this->support->id)
            ->and($mine->branch_id)->toBe($this->chattogram->id);

        // ... and handed to nobody, it belongs to no team at all.
        $this->inTenant($this->acme, fn () => $mine->assignOwner(null)->save());

        expect($mine->refresh()->owner_id)->toBeNull()
            ->and($mine->department_id)->toBeNull()
            ->and($mine->branch_id)->toBeNull();
    });

    it('stays with the team it was made in when its owner later moves', function () {
        $viewer = ($this->viewer)(Role::EMPLOYEE);
        $membership = $this->membershipOf($this->acme, $viewer);

        $this->inTenant($this->acme, fn () => app(MemberManager::class)->place($this->owner, $membership, $this->chattogram, $this->support));

        $mine = $this->contacts['Mine']->refresh();

        expect($mine->owner_id)->toBe($membership->id)
            ->and($mine->department_id)->toBe($this->sales->id)
            ->and($mine->branch_id)->toBe($this->dhaka->id);
    });

    it('belongs to the signed-in member who creates it, with their department and branch', function () {
        $viewer = ($this->viewer)(Role::EMPLOYEE);
        $membership = $this->membershipOf($this->acme, $viewer);

        $this->actingAsMember($this->acme, $viewer);

        $created = $this->inTenant($this->acme, fn (): Contact => Contact::factory()->create(['first_name' => 'Walk-in']));

        expect($created->owner_id)->toBe($membership->id)
            ->and($created->department_id)->toBe($this->sales->id)
            ->and($created->branch_id)->toBe($this->dhaka->id);

        // Stored, not only set on the instance.
        $stored = $this->inTenant($this->acme, fn (): Contact => Contact::query()->findOrFail($created->id));

        expect($stored->only(['owner_id', 'department_id', 'branch_id']))->toBe([
            'owner_id' => $membership->id,
            'department_id' => $this->sales->id,
            'branch_id' => $this->dhaka->id,
        ]);
    });

    it('keeps the owner it was given, even when someone else is signed in', function () {
        $viewer = ($this->viewer)(Role::EMPLOYEE);
        $stranger = $this->inTenant($this->acme, fn () => TenantMembership::query()->findOrFail($this->contacts['Stranger']->owner_id));

        $this->actingAsMember($this->acme, $viewer);

        $given = $this->inTenant($this->acme, function () use ($stranger): Contact {
            $contact = Contact::factory()->make(['first_name' => 'Referral']);
            $contact->assignOwner($stranger)->save();

            return $contact;
        });

        expect($given->refresh()->owner_id)->toBe($stranger->id)
            ->and($given->department_id)->toBe($this->support->id)
            ->and($given->branch_id)->toBe($this->chattogram->id);
    });

    it('has no owner when nobody is signed in', function () {
        $imported = $this->inTenant($this->acme, fn (): Contact => Contact::factory()->create(['first_name' => 'Imported']));

        expect($imported->refresh()->owner_id)->toBeNull()
            ->and($imported->department_id)->toBeNull()
            ->and($imported->branch_id)->toBeNull();
    });

    it('does not make a record the property of someone signed in to another workspace', function () {
        $globex = $this->createTenant('globex');
        $theirAdmin = $this->addMember($globex, Role::ADMIN);

        // Signed in over there; the record is made here (a job, a command).
        $this->actingAsMember($globex, $theirAdmin);

        $created = $this->inTenant($this->acme, fn (): Contact => Contact::factory()->create(['first_name' => 'Elsewhere']));

        expect($created->refresh()->owner_id)->toBeNull();
    });
});

it('reports the scope a person holds a permission with', function () {
    $viewer = ($this->viewer)(($this->roleWith)('Mixed', [
        'core.contacts.view' => 'branch',
        'core.contacts.manage' => 'department',
        'core.contacts.delete' => 'own',
    ]));

    $this->app->forgetScopedInstances();

    $this->inTenant($this->acme, function () use ($viewer): void {
        $checker = app(AccessChecker::class);

        expect($checker->scopeFor($viewer, 'core.contacts.view'))->toBe(AccessScope::Branch)
            ->and($checker->scopeFor($viewer, 'core.contacts.manage'))->toBe(AccessScope::Department)
            ->and($checker->scopeFor($viewer, 'core.contacts.delete'))->toBe(AccessScope::Own)
            ->and($checker->scopeFor($viewer, 'core.members.manage'))->toBeNull();
    });
});
