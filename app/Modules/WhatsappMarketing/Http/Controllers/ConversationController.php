<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Support\Toast;
use App\Modules\WhatsappMarketing\Actions\AgentManager;
use App\Modules\WhatsappMarketing\Actions\ConversationAccess;
use App\Modules\WhatsappMarketing\Actions\ConversationManager;
use App\Modules\WhatsappMarketing\Actions\FeedbackCollector;
use App\Modules\WhatsappMarketing\Actions\Messenger;
use App\Modules\WhatsappMarketing\Actions\NoteManager;
use App\Modules\WhatsappMarketing\Enums\ConversationStatus;
use App\Modules\WhatsappMarketing\Http\Presenter;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\ConversationNote;
use App\Modules\WhatsappMarketing\Models\Template;
use App\Modules\WhatsappMarketing\Support\Interactive;
use App\Modules\WhatsappMarketing\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Actions on one conversation. The route names the permission; the record scope is checked here again. */
final class ConversationController
{
    public function __construct(
        private readonly ConversationAccess $access,
        private readonly ConversationManager $conversations,
        private readonly Messenger $messenger,
        private readonly AgentManager $agents,
        private readonly RecordAccess $records,
        private readonly Presenter $presenter,
        private readonly NoteManager $notes,
        private readonly FeedbackCollector $feedback,
    ) {}

    public function reply(Request $request, Conversation $conversation): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $this->access->authorize($actor, Permissions::CONVERSATIONS_REPLY, $conversation);
        $input = $request->validate([
            'body' => ['required', 'string', 'max:4096'],
            'sender_number_id' => ['nullable', 'string', 'size:26', 'regex:/^[0-9A-Za-z]{26}$/'],
            'interactive' => ['nullable', 'array'],
            'interactive.kind' => ['required_with:interactive', 'string', Rule::in([Interactive::BUTTONS, Interactive::LIST])],
            'interactive.button' => ['nullable', 'string', 'max:'.Interactive::LIST_BUTTON],
            'interactive.options' => ['required_with:interactive', 'array', 'min:1', 'max:'.Interactive::MAX_ROWS],
            'interactive.options.*' => ['required', 'string', 'max:'.Interactive::ROW_TITLE],
        ]);
        $interactive = isset($input['interactive']) ? Interactive::fromInput($input['interactive']) : null;

        $this->messenger->reply($conversation, $input['body'], $this->presenter->membership($actor), interactive: $interactive, senderNumberId: $input['sender_number_id'] ?? null);
        Toast::success(__('Reply queued.'));

        return back();
    }

    public function template(Request $request, Conversation $conversation): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $this->access->authorize($actor, Permissions::CONVERSATIONS_REPLY, $conversation);
        $input = $request->validate([
            'template_id' => ['required', 'string', 'size:26'],
            'variables' => ['nullable', 'array', 'max:20'],
            'variables.*' => ['nullable', 'string', 'max:1024'],
            'sender_number_id' => ['nullable', 'string', 'size:26', 'regex:/^[0-9A-Za-z]{26}$/'],
        ]);

        $template = Template::query()->find($input['template_id']);
        abort_if($template === null, 404);

        $this->messenger->sendTemplate($conversation, $template, array_values(array_map('strval', $input['variables'] ?? [])), $this->presenter->membership($actor), senderNumberId: $input['sender_number_id'] ?? null);
        Toast::success(__('Template queued.'));

        return back();
    }

    public function assign(Request $request, Conversation $conversation): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $this->access->authorize($actor, Permissions::CONVERSATIONS_ASSIGN, $conversation);
        $input = $request->validate(['assignee_id' => ['nullable', 'string', 'size:26']]);
        $membership = $this->presenter->membership($actor);
        $assigneeId = $input['assignee_id'] ?? null;

        // Handing a conversation to someone else (or back to nobody) needs the permission for every record.
        if ($assigneeId !== $membership->id && ! $this->access->hasAll($actor, Permissions::CONVERSATIONS_ASSIGN)) {
            throw ValidationException::withMessages(['assignee_id' => __('You can only assign conversations to yourself.')]);
        }

        $assignee = $assigneeId === null ? null : (TenantMembership::query()->find($assigneeId)
            ?? throw ValidationException::withMessages(['assignee_id' => __('Choose an enrolled, active agent.')]));

        $this->conversations->assign($conversation, $assignee);
        Toast::success($assignee === null ? __('Conversation unassigned.') : __('Conversation assigned.'));

        return back();
    }

    public function claim(Request $request, Conversation $conversation): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $this->access->authorize($actor, Permissions::CONVERSATIONS_ASSIGN, $conversation);
        $membership = $this->presenter->membership($actor);

        if ($this->agents->activeMembership($membership->id) === null) {
            throw ValidationException::withMessages(['assignee_id' => __('Only enrolled agents can take a conversation.')]);
        }

        $this->conversations->assign($conversation, $membership, 'claim');
        Toast::success(__('Conversation assigned to you.'));

        return back();
    }

    public function status(Request $request, Conversation $conversation): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $this->access->authorize($actor, Permissions::CONVERSATIONS_MANAGE, $conversation);
        $input = $request->validate(['status' => ['required', Rule::enum(ConversationStatus::class)]]);

        $status = ConversationStatus::from($input['status']);
        $changed = $conversation->status !== $status;
        $this->conversations->setStatus($conversation, $status);

        // A person closed it: the customer may be asked to rate the help (off unless the workspace enabled it).
        $asked = $changed && $status === ConversationStatus::Closed && $this->feedback->request($conversation);

        Toast::success(match (true) {
            $asked => __('Conversation closed. The customer was asked to rate the help.'),
            $status === ConversationStatus::Closed => __('Conversation closed.'),
            default => __('Conversation reopened.'),
        });

        return back();
    }

    public function tags(Request $request, Conversation $conversation): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $this->access->authorize($actor, Permissions::CONVERSATIONS_MANAGE, $conversation);
        $input = $request->validate(['tags' => ['present', 'array', 'max:10'], 'tags.*' => ['string', 'max:30']]);

        $this->conversations->setTags($conversation, $input['tags']);
        Toast::success(__('Tags saved.'));

        return back();
    }

    /** An internal note for the team; never sent to the customer. */
    public function addNote(Request $request, Conversation $conversation): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $this->access->authorize($actor, Permissions::CONVERSATIONS_REPLY, $conversation);
        $input = $request->validate(['body' => ['required', 'string', 'max:'.NoteManager::MAX_LENGTH]]);

        $this->notes->add($conversation, $input['body'], $this->presenter->membership($actor));
        Toast::success(__('Note added.'));

        return back();
    }

    /** The author removes their own note; someone who manages the conversation may remove any. */
    public function removeNote(Request $request, Conversation $conversation, ConversationNote $note): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $this->access->authorize($actor, Permissions::CONVERSATIONS_VIEW, $conversation);
        abort_unless($note->conversation_id === $conversation->id, 404);

        $own = $note->author_id !== null && $note->author_id === $this->presenter->membership($actor)->id;
        abort_unless($own || $this->access->allows($actor, Permissions::CONVERSATIONS_MANAGE, $conversation), 403);

        $this->notes->remove($note);
        Toast::success(__('Note removed.'));

        return back();
    }

    /** Link an existing contact or create one from the number. */
    public function contact(Request $request, Conversation $conversation): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $this->access->authorize($actor, Permissions::CONVERSATIONS_MANAGE, $conversation);
        $input = $request->validate([
            'contact_id' => ['nullable', 'string', 'size:26'],
            'create' => ['nullable', 'boolean'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        if ($conversation->contact_id !== null) {
            throw ValidationException::withMessages(['contact_id' => __('This conversation already has a contact.')]);
        }

        if (($input['create'] ?? false) === true) {
            abort_unless($actor->can('core.contacts.manage'), 403);
            $this->conversations->createContact($conversation, $input['name'] ?? null, $this->presenter->membership($actor));
            Toast::success(__('Contact created.'));

            return back();
        }

        $contact = $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)->find($input['contact_id'] ?? '');

        if ($contact === null) {
            throw ValidationException::withMessages(['contact_id' => __('Choose a contact from the directory.')]);
        }

        $this->conversations->linkContact($conversation, $contact);
        Toast::success(__('Contact linked.'));

        return back();
    }
}
