<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Communication\Data\InteractionEntry;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use App\Modules\Communication\Actions\TimelineRecorder;
use App\Modules\Communication\Enums\InteractionType;
use App\Modules\Communication\Http\Requests\InteractionRequest;
use App\Modules\Communication\Models\Interaction;
use App\Modules\Communication\Models\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The interaction timeline of a shared contact or company.
 *
 * Who may see a timeline is decided by who may see the contact (`core.contacts.view`, with its scope) plus
 * `communication.timeline.view` on the route. A company's timeline shows its own entries and those of its
 * contacts that the viewer may also see.
 */
final class TimelineController
{
    public const string VIEW = 'communication.timeline.view';

    public const string LOG = 'communication.timeline.log';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly CommunicationControllerSupport $support,
        private readonly TimelineRecorder $timeline,
    ) {}

    public function contact(Request $request, Contact $contact): Response
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, ContactController::VIEW, $contact);

        $query = Interaction::query()->where('contact_id', $contact->id);

        return $this->render($request, $actor, $query, [
            'type' => 'contact',
            'id' => $contact->id,
            'name' => $contact->fullName(),
            'email' => $contact->email,
            'phone' => $contact->phone,
        ]);
    }

    public function company(Request $request, Company $company): Response
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, ContactController::VIEW, $company);

        $visibleContacts = $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)
            ->where('company_id', $company->id)->select('contacts.id');

        $query = Interaction::query()->where(fn (Builder $match) => $match
            ->where('company_id', $company->id)
            ->where(fn (Builder $inner) => $inner->whereNull('contact_id')->orWhereIn('contact_id', $visibleContacts)));

        return $this->render($request, $actor, $query, [
            'type' => 'company',
            'id' => $company->id,
            'name' => $company->name,
            'email' => $company->email,
            'phone' => $company->phone,
        ]);
    }

    public function storeContact(InteractionRequest $request, Contact $contact): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, ContactController::VIEW, $contact);

        return $this->log($request, $actor, contactId: $contact->id, companyId: $contact->company_id);
    }

    public function storeCompany(InteractionRequest $request, Company $company): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, ContactController::VIEW, $company);

        return $this->log($request, $actor, contactId: null, companyId: $company->id);
    }

    public function destroy(Request $request, Interaction $interaction): RedirectResponse
    {
        $actor = $this->support->actor($request);
        // Reach is the actor's `communication.timeline.log` scope; only hand-logged types can be removed.
        $this->records->authorize($actor, self::LOG, $interaction);
        abort_unless($interaction->type->isManual() && $interaction->source_module === null, 404);

        if ($interaction->contact_id !== null) {
            $this->records->authorize($actor, ContactController::VIEW, Contact::query()->findOrFail($interaction->contact_id));
        }

        $interaction->delete();
        Toast::success(__('Entry removed.'));

        return back();
    }

    private function log(InteractionRequest $request, User $actor, ?string $contactId, ?string $companyId): RedirectResponse
    {
        $input = $request->validated();
        $membership = $this->support->membership($actor);

        $this->timeline->write(new InteractionEntry(
            type: (string) $input['type'],
            summary: trim((string) $input['summary']),
            contactId: $contactId,
            companyId: $companyId,
            body: $input['body'] ?? null,
            direction: $input['direction'] ?? null,
            occurredAt: isset($input['occurred_at']) ? Carbon::parse($input['occurred_at']) : null,
        ), null, $membership->id);
        Toast::success(__('Entry added to the timeline.'));

        return back();
    }

    /**
     * @param  Builder<Interaction>  $query
     * @param  array<string, mixed>  $subject
     */
    private function render(Request $request, User $actor, Builder $query, array $subject): Response
    {
        $filters = $request->validate(['type' => ['nullable', 'string', 'in:'.implode(',', array_map(static fn (InteractionType $type): string => $type->value, InteractionType::cases()))]]);

        $entries = $query
            ->with(['owner.user:id,name'])
            ->when(isset($filters['type']), fn (Builder $builder) => $builder->where('type', $filters['type']))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $statuses = Message::query()
            ->whereIn('id', $entries->getCollection()->pluck('message_id')->filter()->all())
            ->pluck('status', 'id');

        $entries = $entries->through(fn (Interaction $entry): array => [
            'id' => $entry->id,
            'type' => $entry->type->value,
            'direction' => $entry->direction,
            'summary' => $entry->summary,
            'body' => $entry->body,
            'occurredAt' => $entry->occurred_at->toIso8601String(),
            'sourceModule' => $entry->source_module,
            'sourceType' => $entry->source_type,
            'sourceId' => $entry->source_id,
            'contactId' => $entry->contact_id,
            'messageId' => $entry->message_id,
            'messageStatus' => $entry->message_id === null ? null : ($statuses[$entry->message_id] ?? null),
            'owner' => $entry->owner === null ? null : ['id' => $entry->owner->id, 'name' => $entry->owner->user->name],
            'deletable' => $entry->type->isManual() && $entry->source_module === null
                && $actor->can(self::LOG) && $this->records->allows($actor, self::LOG, $entry),
        ]);

        return Inertia::render('Modules/Communication/Pages/Timeline/Show', [
            'subject' => $subject,
            'entries' => $entries,
            'filters' => ['type' => $filters['type'] ?? null],
            'types' => array_map(static fn (InteractionType $type): string => $type->value, InteractionType::manual()),
            'canLog' => $actor->can(self::LOG),
            'canCompose' => $actor->can(MessageController::SEND),
        ]);
    }
}
