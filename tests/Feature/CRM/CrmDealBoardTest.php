<?php

declare(strict_types=1);

use App\Core\Contacts\Models\Contact;
use App\Modules\CRM\Actions\DealManager;
use App\Modules\CRM\Actions\PipelineManager;

it('returns only the open deals of the default pipeline for the board view', function () {
    $tenant = $this->createTenant('crm-deal-board');
    $this->entitle($tenant, 'crm');
    $membership = $this->membershipOf($tenant, $this->ownerOf($tenant));

    [$pipeline, $openDeal] = $this->inTenant($tenant, function () use ($membership): array {
        $contact = Contact::factory()->create(['first_name' => 'Board', 'last_name' => 'Buyer']);
        $pipelines = app(PipelineManager::class);
        $pipeline = $pipelines->create('Board pipeline', [
            ['name' => 'New', 'probability' => 10],
            ['name' => 'Won', 'probability' => 100, 'is_won' => true],
        ], true);
        $other = $pipelines->create('Other pipeline', [['name' => 'Start', 'probability' => 10]]);
        $deals = app(DealManager::class);
        $attributes = ['contact_id' => $contact->id, 'currency' => 'BDT', 'amount' => 50_000];
        $open = $deals->create(['title' => 'Open board deal', ...$attributes], $pipeline, $pipeline->stages[0], $membership, $membership);
        $won = $deals->create(['title' => 'Won board deal', ...$attributes], $pipeline, $pipeline->stages[0], $membership, $membership);
        $deals->move($won, $pipeline->stages[1], $membership);
        $deals->create(['title' => 'Other pipeline deal', ...$attributes], $other, $other->stages[0], $membership, $membership);

        return [$pipeline, $open];
    });

    $this->actingAsOwner($tenant)
        ->get($this->tenantUrl($tenant, '/crm/deals?view=board'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Modules/CRM/Pages/Deals/Index')
            ->where('filters.view', 'board')
            ->where('filters.pipeline', $pipeline->id)
            ->has('boardDeals', 1)
            ->where('boardDeals.0.id', $openDeal->id));
});

it('sends no board data for the list view and rejects an unknown view', function () {
    $tenant = $this->createTenant('crm-deal-list');
    $this->entitle($tenant, 'crm');

    $this->actingAsOwner($tenant)
        ->get($this->tenantUrl($tenant, '/crm/deals'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('boardDeals', null)->where('filters.view', null));

    $this->actingAsOwner($tenant)
        ->from($this->tenantUrl($tenant, '/crm/deals'))
        ->get($this->tenantUrl($tenant, '/crm/deals?view=grid'))
        ->assertSessionHasErrors('view');
});
