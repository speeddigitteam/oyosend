<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Access\RoleManager;
use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\Ai\Models\Conversation;
use App\Modules\Ai\Providers\FakeAiProvider;
use Tests\TestCase;

/**
 * Building blocks for AI module tests: an entitled workspace, members with exact permissions, shared
 * contacts, and the scripted provider (never a real one).
 *
 * @mixin TestCase
 */
trait InteractsWithAi
{
    protected function aiTenant(string $slug = 'ai-ws'): Tenant
    {
        $tenant = $this->createTenant($slug, ['timezone' => 'Asia/Dhaka', 'currency' => 'BDT']);
        $this->entitle($tenant, 'ai');

        return $tenant;
    }

    protected function aiUrl(Tenant $tenant, string $path = ''): string
    {
        return $this->tenantUrl($tenant, '/ai'.$path);
    }

    /** Switch to the scripted provider. It exists only in the testing and local environments. */
    protected function fakeAi(): FakeAiProvider
    {
        config(['ai.default' => 'fake']);

        /** @var FakeAiProvider $fake */
        $fake = $this->app->make(FakeAiProvider::class);

        return $fake;
    }

    /**
     * A member whose role holds exactly these permissions.
     *
     * @param  list<string>  $permissions
     * @param  array<string, string>  $scopes  permission => own|department|branch|all
     * @return array{0: User, 1: TenantMembership}
     */
    protected function aiMember(Tenant $tenant, array $permissions, array $scopes = []): array
    {
        $owner = $this->ownerOf($tenant);
        $role = $this->inTenant($tenant, fn () => app(RoleManager::class)->create(
            $owner,
            'Role '.bin2hex(random_bytes(4)),
            null,
            $permissions,
            $scopes,
        ));
        $user = $this->addMember($tenant, $role);

        return [$user, $this->membershipOf($tenant, $user)];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function aiContact(Tenant $tenant, array $attributes = [], ?TenantMembership $owner = null): Contact
    {
        return $this->inTenant($tenant, fn (): Contact => app(ContactDirectory::class)->createContact([
            'first_name' => 'Rima',
            'last_name' => 'Das',
            ...$attributes,
        ], $owner));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function aiCompany(Tenant $tenant, array $attributes = []): Company
    {
        return $this->inTenant($tenant, fn (): Company => app(ContactDirectory::class)->createCompany([
            'name' => 'Acme Traders',
            ...$attributes,
        ], null));
    }

    protected function aiConversation(Tenant $tenant, TenantMembership $membership, string $title = 'Chat'): Conversation
    {
        return $this->inTenant($tenant, function () use ($membership, $title): Conversation {
            $conversation = new Conversation(['title' => $title, 'last_message_at' => now()]);
            $conversation->membership_id = $membership->id;
            $conversation->save();

            return $conversation;
        });
    }
}
