<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use App\Modules\Support\Actions\SurveyManager;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Actions\TicketMerger;
use App\Modules\Support\Enums\MessageKind;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Http\Requests\AssignRequest;
use App\Modules\Support\Http\Requests\MergeRequest;
use App\Modules\Support\Http\Requests\MessageRequest;
use App\Modules\Support\Http\Requests\SplitRequest;
use App\Modules\Support\Http\Requests\StatusRequest;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Support\SupportPermissions;
use App\Modules\Support\Support\TicketAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Ticket state changes: reply, status, assignment, merge, split and the satisfaction survey. */
final class TicketWorkflowController
{
    public function __construct(
        private readonly TicketAccess $access,
        private readonly TicketManager $tickets,
        private readonly TicketMerger $merger,
        private readonly SurveyManager $surveys,
        private readonly SupportControllerSupport $support,
    ) {}

    public function reply(MessageRequest $request, Ticket $ticket): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $membership = $this->support->membership($actor);
        $this->access->authorize($actor, SupportPermissions::TICKETS_REPLY, $ticket);
        $input = $request->validated();
        $kind = MessageKind::from((string) $input['kind']);
        $status = isset($input['status']) ? TicketStatus::from((string) $input['status']) : null;

        if ($status !== null) {
            $this->assertMayChangeStatus($actor, $ticket, $status);
        }

        $this->tickets->addMessage($ticket, $kind, (string) $input['body'], $membership, $request->file('attachments', []), $status);
        $this->flashSurvey($ticket);
        Toast::success($kind === MessageKind::InternalNote ? __('Note added.') : __('Reply sent.'));

        return back();
    }

    public function status(StatusRequest $request, Ticket $ticket): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->access->authorize($actor, SupportPermissions::TICKETS_REPLY, $ticket);
        $status = TicketStatus::from((string) $request->validated('status'));
        $this->assertMayChangeStatus($actor, $ticket, $status);

        $this->tickets->changeStatus($ticket, $status, $this->support->membership($actor));
        $this->flashSurvey($ticket);
        Toast::success(__('Ticket status updated.'));

        return back();
    }

    public function assign(AssignRequest $request, Ticket $ticket): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $membership = $this->support->membership($actor);
        $this->access->authorize($actor, SupportPermissions::TICKETS_ASSIGN, $ticket);
        $input = $request->validated();
        $auto = (bool) ($input['auto'] ?? false);
        $assigneeId = $input['assignee_id'] ?? null;

        // Handing a ticket to anybody but yourself needs the permission for every record.
        if (($auto || ($assigneeId !== null && $assigneeId !== $membership->id)) && ! $this->access->hasAll($actor, SupportPermissions::TICKETS_ASSIGN)) {
            abort(403);
        }

        if ($auto) {
            $this->tickets->autoAssign($ticket, $input['queue_id'] ?? null, $membership);
        } else {
            $assignee = null;

            if ($assigneeId !== null) {
                $assignee = TenantMembership::query()->find($assigneeId)
                    ?? throw ValidationException::withMessages(['assignee_id' => __('Choose an active support agent.')]);
            }

            $this->tickets->assign($ticket, $assignee, $membership);
        }

        Toast::success(__('Assignment updated.'));

        return back();
    }

    /** The signed-in agent takes the ticket. */
    public function claim(Request $request, Ticket $ticket): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $membership = $this->support->membership($actor);
        $this->access->authorize($actor, SupportPermissions::TICKETS_ASSIGN, $ticket);

        $this->tickets->assign($ticket, $membership, $membership);
        Toast::success(__('Ticket assigned to you.'));

        return back();
    }

    public function merge(MergeRequest $request, Ticket $ticket): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->access->authorize($actor, SupportPermissions::TICKETS_MERGE, $ticket);
        $input = $request->validated();

        $target = $this->access->constrain(Ticket::query(), $actor, SupportPermissions::TICKETS_MERGE)
            ->where('number', trim((string) $input['target_number']))
            ->first() ?? throw ValidationException::withMessages(['target_number' => __('No ticket with that number was found.')]);

        $merged = $this->merger->merge($ticket, $target, $this->support->membership($actor), $input['note'] ?? null, (bool) ($input['confirm'] ?? false));
        Toast::success(__('Ticket merged into :number.', ['number' => $merged->number]));

        return redirect()->route('support.tickets.show', $merged);
    }

    public function split(SplitRequest $request, Ticket $ticket): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->access->authorize($actor, SupportPermissions::TICKETS_MERGE, $ticket);
        $input = $request->validated();

        $new = $this->merger->split($ticket, $input['message_ids'], (string) $input['subject'], $this->support->membership($actor));
        Toast::success(__('Ticket :number created from the selected messages.', ['number' => $new->number]));

        return redirect()->route('support.tickets.show', $new);
    }

    /** Sends a (new) satisfaction survey for a resolved or closed ticket. */
    public function survey(Request $request, Ticket $ticket): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->access->authorize($actor, SupportPermissions::TICKETS_RESOLVE, $ticket);

        DB::transaction(function () use ($ticket, $actor): void {
            $locked = $this->tickets->lock($ticket);

            if (! $locked->status->isFinished()) {
                throw ValidationException::withMessages(['ticket' => __('A survey can only be requested for a resolved or closed ticket.')]);
            }

            $this->surveys->request($locked->load('contact:id,email'), $this->support->membership($actor), force: true);
        });
        $this->flashSurvey($ticket);
        Toast::success(__('Satisfaction survey requested.'));

        return back();
    }

    private function assertMayChangeStatus(User $actor, Ticket $ticket, TicketStatus $to): void
    {
        $finishing = $to === TicketStatus::Resolved || $to === TicketStatus::Closed;
        $reopening = $ticket->status->isFinished();

        if (($finishing || $reopening) && ! $this->access->allows($actor, SupportPermissions::TICKETS_RESOLVE, $ticket)) {
            abort(403);
        }
    }

    /** Shows the single-use survey link to the agent once, as a flash (the plain token is never stored). */
    private function flashSurvey(Ticket $ticket): void
    {
        $token = $this->surveys->takeIssuedToken($ticket->id);

        if ($token !== null) {
            Inertia::flash('surveyInvitation', [
                'ticketId' => $ticket->id,
                'url' => route('support.csat.show', ['token' => $token]),
            ]);
        }
    }
}
