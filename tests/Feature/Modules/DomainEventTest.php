<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Events\DomainEvent;
use App\Core\Identity\Events\MemberInvited;
use App\Core\Identity\Events\MemberJoined;
use App\Core\Identity\Events\MemberRemoved;
use App\Core\Tenancy\Exceptions\MissingTenantContextException;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->acme = $this->createTenant('acme');
    $this->member = $this->addMember($this->acme, Role::EMPLOYEE);
    $this->membership = $this->membershipOf($this->acme, $this->member);
});

it('captures the tenant and the moment it happened', function () {
    $this->travelTo('2026-10-08 09:30:00');

    $event = $this->inTenant($this->acme, fn () => new MemberJoined($this->membership, $this->member, 'Employee'));

    expect($event)->toBeInstanceOf(DomainEvent::class)
        ->and($event->tenantId())->toBe($this->acme->id)
        ->and($event->occurredAt()->toDateTimeString())->toBe('2026-10-08 09:30:00')
        ->and($event::name())->toBe('core.member.joined');

    // It keeps saying so after the context has moved on.
    $globex = $this->createTenant('globex');

    expect($this->inTenant($globex, fn (): string => $event->tenantId()))->toBe($this->acme->id);
});

it('cannot be raised outside a tenant', function () {
    expect(fn () => new MemberRemoved('m', 'u', 'someone@example.test'))->toThrow(MissingTenantContextException::class);
    expect(fn () => MemberJoined::dispatch($this->membership, $this->member, 'Employee'))->toThrow(MissingTenantContextException::class);
});

it('carries ids and scalars only, never live models', function () {
    [$invitation] = $this->createInvitation($this->acme, 'new@example.test');

    $events = $this->inTenant($this->acme, fn (): array => [
        new MemberInvited($invitation),
        new MemberJoined($this->membership, $this->member, 'Employee'),
        new MemberRemoved($this->membership->id, $this->member->id, $this->member->email),
    ]);

    foreach ($events as $event) {
        $values = [...array_values(get_object_vars($event)), ...array_values($event->payload())];

        expect(array_filter($values, 'is_object'))->toBe([], $event::class.' holds an object')
            ->and(serialize($event))->not->toContain('App\\Core\\Identity\\Models');
    }

    expect($events[0]->payload())->toBe([
        'invitation_id' => $invitation->id,
        'email' => 'new@example.test',
        'role_id' => $invitation->role_id,
    ])->and($events[0]::name())->toBe('core.member.invited')
        ->and($events[2]::name())->toBe('core.member.removed');
});

it('reaches listeners only once the surrounding transaction has committed', function () {
    $heard = [];
    Event::listen(MemberRemoved::class, function (MemberRemoved $event) use (&$heard): void {
        $heard[] = $event->email;
    });

    expect(is_subclass_of(MemberRemoved::class, ShouldDispatchAfterCommit::class))->toBeTrue();

    $this->inTenant($this->acme, function () use (&$heard): void {
        DB::transaction(function () use (&$heard): void {
            MemberRemoved::dispatch('m1', 'u1', 'committed@example.test');

            expect($heard)->toBe([]);
        });

        expect($heard)->toBe(['committed@example.test']);

        // A change that is rolled back never announces itself.
        try {
            DB::transaction(function (): void {
                MemberRemoved::dispatch('m2', 'u2', 'rolled-back@example.test');

                throw new RuntimeException('nope');
            });
        } catch (RuntimeException) {
            // expected
        }
    });

    expect($heard)->toBe(['committed@example.test']);
});
