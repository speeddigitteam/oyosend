<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Agent;
use App\Modules\Support\Models\Category;
use App\Modules\Support\Models\Queue;
use App\Modules\Support\Models\Ticket;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Shared controller helpers: the signed-in person, customer pickers, and the shapes sent to pages. */
final class SupportControllerSupport
{
    public function __construct(
        private readonly AccessChecker $access,
        private readonly RecordAccess $records,
    ) {}

    public function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    public function membership(User $actor): TenantMembership
    {
        return $this->access->membership($actor) ?? abort(403);
    }

    /**
     * A contact id and company id the actor may see (the shared directory's own scoping applies).
     *
     * @param  array<string, mixed>  $input
     * @return array{contact_id: string|null, company_id: string|null}
     */
    public function customer(array $input, User $actor): array
    {
        $contactId = $input['contact_id'] ?? null;
        $companyId = $input['company_id'] ?? null;

        try {
            return [
                'contact_id' => $contactId === null || $contactId === ''
                    ? null
                    : $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)->findOrFail($contactId)->id,
                'company_id' => $companyId === null || $companyId === ''
                    ? null
                    : $this->records->constrain(Company::query(), $actor, ContactController::VIEW)->findOrFail($companyId)->id,
            ];
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages(['contact_id' => __('Choose a customer from the directory.')]);
        }
    }

    /**
     * Pickers for ticket forms: customers narrowed to what the actor may see, and the support catalogue.
     *
     * @return array<string, mixed>
     */
    public function formOptions(User $actor, bool $withCustomers = true): array
    {
        return [
            'contacts' => ! $withCustomers ? [] : $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)
                ->orderBy('first_name')->orderBy('last_name')->limit(500)->get(['id', 'first_name', 'last_name', 'email'])
                ->map(fn (Contact $contact): array => ['id' => $contact->id, 'name' => $contact->fullName(), 'email' => $contact->email])->all(),
            'companies' => ! $withCustomers ? [] : $this->companies($actor),
            'queues' => $this->queues(),
            'categories' => $this->categories(),
            'agents' => $this->agents(),
            'priorities' => array_map(static fn (TicketPriority $priority): string => $priority->value, TicketPriority::cases()),
            'channels' => array_map(static fn (TicketChannel $channel): string => $channel->value, TicketChannel::cases()),
            'statuses' => array_map(static fn (TicketStatus $status): string => $status->value, TicketStatus::cases()),
        ];
    }

    /**
     * Companies the actor may see, by name (at most 500; larger directories need a search endpoint).
     *
     * @return list<array{id: string, name: string, email: string|null}>
     */
    public function companies(User $actor): array
    {
        return $this->records->constrain(Company::query(), $actor, ContactController::VIEW)
            ->orderBy('name')->limit(500)->get(['id', 'name', 'email'])
            ->map(fn (Company $company): array => ['id' => $company->id, 'name' => $company->name, 'email' => $company->email])->all();
    }

    /** @return list<array{id: string, name: string, assignmentMode: string}> */
    public function queues(bool $activeOnly = true): array
    {
        return Queue::query()
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')->get()
            ->map(fn (Queue $queue): array => [
                'id' => $queue->id,
                'name' => $queue->name,
                'assignmentMode' => $queue->assignment_mode->value,
            ])->all();
    }

    /** @return list<array{id: string, name: string}> */
    public function categories(bool $activeOnly = true): array
    {
        return Category::query()
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')->get()
            ->map(fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])->all();
    }

    /**
     * Active agents as assignable members. `id` is the membership id (what assign endpoints take).
     *
     * @return list<array{id: string, agentId: string, name: string, isAvailable: bool}>
     */
    public function agents(): array
    {
        return Agent::query()
            ->where('is_active', true)
            ->with('membership.user:id,name')
            ->get()
            ->filter(fn (Agent $agent): bool => $agent->membership->status === MembershipStatus::Active)
            ->map(fn (Agent $agent): array => [
                'id' => $agent->membership_id,
                'agentId' => $agent->id,
                'name' => $agent->membership->user->name,
                'isAvailable' => $agent->is_available,
            ])
            ->sortBy('name')->values()->all();
    }

    /**
     * The list row of a ticket. Relations `contact`, `company`, `queue`, `category`, `owner.user` and `tags`
     * must be eager-loaded.
     *
     * @return array<string, mixed>
     */
    public function summary(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'status' => $ticket->status->value,
            'priority' => $ticket->priority->value,
            'channel' => $ticket->channel->value,
            'requester' => [
                'name' => $ticket->displayRequester(),
                'email' => $ticket->requester_email ?? $ticket->contact?->email,
                'contactId' => $ticket->contact_id,
                'companyId' => $ticket->company_id,
                'companyName' => $ticket->company?->name,
            ],
            'queue' => $ticket->queue === null ? null : ['id' => $ticket->queue->id, 'name' => $ticket->queue->name],
            'category' => $ticket->category === null ? null : ['id' => $ticket->category->id, 'name' => $ticket->category->name],
            'assignee' => $ticket->owner === null ? null : ['id' => $ticket->owner->id, 'name' => $ticket->owner->user->name],
            'tags' => $ticket->tags->pluck('name')->values()->all(),
            'firstRespondedAt' => $ticket->first_responded_at?->toIso8601String(),
            'firstResponseDueAt' => $ticket->first_response_due_at?->toIso8601String(),
            'resolutionDueAt' => $ticket->resolution_due_at?->toIso8601String(),
            'slaPaused' => $ticket->sla_paused_at !== null,
            'firstResponseBreached' => $ticket->first_response_breached_at !== null,
            'resolutionBreached' => $ticket->resolution_breached_at !== null,
            'lastActivityAt' => $ticket->last_activity_at?->toIso8601String(),
            'createdAt' => $ticket->created_at->toIso8601String(),
        ];
    }

    /** Relations a ticket list needs for {@see summary()}. */
    public const array LIST_RELATIONS = ['contact:id,first_name,last_name,email', 'company:id,name', 'queue:id,name', 'category:id,name', 'owner.user:id,name', 'tags'];
}
