<?php

declare(strict_types=1);

use App\Core\Contacts\Models\Contact;
use App\Modules\CRM\Actions\DealManager;
use App\Modules\CRM\Actions\LeadManager;
use App\Modules\CRM\Actions\PipelineManager;
use App\Modules\CRM\Automation\Actions\AssignLeadAction;
use App\Modules\CRM\Automation\Actions\MoveDealStageAction;
use App\Modules\CRM\Models\AutomationActionReceipt;
use App\Modules\CRM\Models\DealStageHistory;
use App\Modules\CRM\Models\TimelineEntry;
use Illuminate\Validation\ValidationException;

it('replays the original lead assignment output without undoing an intervening change', function () {
    $tenant = $this->createTenant('crm-assign-idempotency');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));
    $assigneeUser = $this->addMember($tenant);
    $assignee = $this->membershipOf($tenant, $assigneeUser);

    $result = $this->inTenant($tenant, function () use ($owner, $assignee): array {
        $contact = Contact::factory()->create(['first_name' => 'Automation', 'last_name' => 'Lead']);
        $lead = app(LeadManager::class)->create([
            'title' => 'Idempotent assignment',
            'contact_id' => $contact->id,
        ], $owner);
        $action = app(AssignLeadAction::class);
        $input = ['lead_id' => $lead->id, 'owner_id' => $assignee->id];

        $first = $action->execute($input, 'workflow:assign:1001');
        app(LeadManager::class)->assign($lead->refresh(), $owner);
        $retry = $action->execute($input, 'workflow:assign:1001');

        return [
            $lead->id,
            $first,
            $retry,
            $lead->refresh()->owner_id,
            AutomationActionReceipt::query()->where('action', AssignLeadAction::key())->sole(),
            TimelineEntry::query()->where('event', 'crm.lead.assigned')->count(),
        ];
    });

    [$leadId, $first, $retry, $currentOwnerId, $receipt, $assignmentEvents] = $result;

    expect($first)->toBe(['lead_id' => $leadId, 'owner_id' => $assignee->id])
        ->and($retry)->toBe($first)
        ->and($currentOwnerId)->toBe($owner->id)
        ->and($receipt->idempotency_key)->toBe('workflow:assign:1001')
        ->and($receipt->output)->toBe($first)
        ->and($assignmentEvents)->toBe(2);
});

it('replays the original deal move output without undoing a later stage change', function () {
    $tenant = $this->createTenant('crm-move-idempotency');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $result = $this->inTenant($tenant, function () use ($owner): array {
        $contact = Contact::factory()->create(['first_name' => 'Automation', 'last_name' => 'Deal']);
        $pipeline = app(PipelineManager::class)->create('Automation pipeline', [
            ['name' => 'New', 'probability' => 10],
            ['name' => 'Qualified', 'probability' => 50],
            ['name' => 'Proposal', 'probability' => 75],
        ]);
        $deal = app(DealManager::class)->create([
            'title' => 'Idempotent move',
            'contact_id' => $contact->id,
            'currency' => 'BDT',
            'amount' => 125_000,
        ], $pipeline, $pipeline->stages[0], $owner, $owner);
        $action = app(MoveDealStageAction::class);
        $input = ['deal_id' => $deal->id, 'stage_id' => $pipeline->stages[1]->id, 'note' => 'Automation move'];

        $first = $action->execute($input, 'workflow:move:1001');
        app(DealManager::class)->move($deal->refresh(), $pipeline->stages[2], $owner, 'Manual follow-on move');
        $retry = $action->execute($input, 'workflow:move:1001');

        return [
            $first,
            $retry,
            $deal->refresh()->stage_id,
            AutomationActionReceipt::query()->where('action', MoveDealStageAction::key())->sole(),
            DealStageHistory::query()->where('deal_id', $deal->id)->count(),
        ];
    });

    [$first, $retry, $currentStageId, $receipt, $historyCount] = $result;

    expect($first['stage_id'])->toBe($receipt->output['stage_id'])
        ->and($retry)->toBe($first)
        ->and($currentStageId)->not->toBe($first['stage_id'])
        ->and($receipt->idempotency_key)->toBe('workflow:move:1001')
        ->and($receipt->output)->toBe($first)
        ->and($historyCount)->toBe(3);
});

it('rolls back the receipt with a failed action so the same key can be retried', function () {
    $tenant = $this->createTenant('crm-action-retry');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function () use ($owner): void {
        $contact = Contact::factory()->create(['first_name' => 'Retryable', 'last_name' => 'Deal']);
        $pipeline = app(PipelineManager::class)->create('Retry pipeline', [
            ['name' => 'New', 'probability' => 10],
            ['name' => 'Qualified', 'probability' => 50],
        ]);
        $foreignPipeline = app(PipelineManager::class)->create('Foreign pipeline', [
            ['name' => 'Other', 'probability' => 25],
        ]);
        $deal = app(DealManager::class)->create([
            'title' => 'Retry after rollback',
            'contact_id' => $contact->id,
            'currency' => 'BDT',
            'amount' => 80_000,
        ], $pipeline, $pipeline->stages[0], $owner, $owner);
        $action = app(MoveDealStageAction::class);

        expect(fn () => $action->execute([
            'deal_id' => $deal->id,
            'stage_id' => $foreignPipeline->stages[0]->id,
        ], 'workflow:move:retryable'))->toThrow(ValidationException::class, 'does not belong');

        expect(AutomationActionReceipt::query()->count())->toBe(0);

        $output = $action->execute([
            'deal_id' => $deal->id,
            'stage_id' => $pipeline->stages[1]->id,
        ], 'workflow:move:retryable');

        expect($output['stage_id'])->toBe($pipeline->stages[1]->id)
            ->and($deal->refresh()->stage_id)->toBe($pipeline->stages[1]->id)
            ->and(AutomationActionReceipt::query()->sole()->output)->toBe($output);
    });
});
