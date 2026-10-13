<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Controllers;

use App\Core\Ai\Contracts\AiTool;
use App\Core\Ai\Contracts\ProposalTool;
use App\Core\Ai\ToolContext;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Support\Toast;
use App\Modules\Ai\Actions\AiAvailability;
use App\Modules\Ai\Actions\AssistantService;
use App\Modules\Ai\Actions\ConversationManager;
use App\Modules\Ai\Actions\ToolRegistry;
use App\Modules\Ai\Enums\ProposalStatus;
use App\Modules\Ai\Http\Requests\MessageRequest;
use App\Modules\Ai\Models\Conversation;
use App\Modules\Ai\Models\Message;
use App\Modules\Ai\Models\Proposal;
use App\Modules\Ai\Support\AiRefusal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The assistant chat. Every conversation is private to its member: it is always looked up through the
 * signed-in membership, so another member (the owner included) gets a 404.
 */
final class AssistantController
{
    public function __construct(
        private readonly AiControllerSupport $support,
        private readonly ConversationManager $conversations,
        private readonly AssistantService $assistant,
        private readonly AiAvailability $availability,
        private readonly ToolRegistry $tools,
    ) {}

    public function index(Request $request): Response
    {
        $context = $this->support->context($request);

        return Inertia::render('Modules/Ai/Pages/Assistant/Index', [
            'conversations' => $this->conversationList($context->membership),
            'availability' => $this->availability->describe(),
            'tools' => $this->toolList($request),
            'canConfirm' => $context->user->can('ai.proposals.confirm'),
        ]);
    }

    public function store(MessageRequest $request): RedirectResponse
    {
        $context = $this->support->context($request);
        $content = (string) $request->validated('content');

        $conversation = $this->conversations->start($context->membership, $content);

        try {
            $this->assistant->send($conversation, $context, $content);
        } catch (AiRefusal $refusal) {
            $conversation->delete();

            throw $refusal;
        }

        return to_route('ai.assistant.conversations.show', ['conversation' => $conversation->id]);
    }

    public function show(Request $request, string $conversation): Response
    {
        $context = $this->support->context($request);
        $record = $this->find($request, $conversation);

        $messages = Message::query()
            ->where('conversation_id', $record->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(500)
            ->get();

        $proposals = Proposal::query()
            ->where('conversation_id', $record->id)
            ->where('membership_id', $context->membership->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return Inertia::render('Modules/Ai/Pages/Assistant/Show', [
            'conversation' => ['id' => $record->id, 'title' => $record->title, 'lastMessageAt' => $record->last_message_at?->toIso8601String()],
            'conversations' => $this->conversationList($context->membership),
            'messages' => $messages->map(static fn (Message $message): array => [
                'id' => $message->id,
                'role' => $message->role->value,
                'content' => $message->content,
                'aiGenerated' => $message->role->value === 'assistant',
                'toolCalls' => $message->tool_calls ?? [],
                'inputTokens' => $message->input_tokens,
                'outputTokens' => $message->output_tokens,
                'createdAt' => $message->created_at?->toIso8601String(),
            ])->all(),
            'proposals' => $proposals->map(fn (Proposal $proposal): array => [
                'id' => $proposal->id,
                'tool' => $proposal->tool,
                'summary' => $proposal->summary,
                'changes' => $this->changes($proposal, $context),
                'status' => $proposal->status->value,
                'pending' => $proposal->status === ProposalStatus::Pending && $proposal->expires_at->isFuture(),
                'expiresAt' => $proposal->expires_at->toIso8601String(),
                'decidedAt' => $proposal->decided_at?->toIso8601String(),
            ])->all(),
            'availability' => $this->availability->describe(),
            'canConfirm' => $context->user->can('ai.proposals.confirm'),
        ]);
    }

    /**
     * What a pending proposal would write, shown on its card. Decided proposals show none.
     *
     * @return list<array{field: string, old: string|null, new: string|null}>
     */
    private function changes(Proposal $proposal, ToolContext $context): array
    {
        if ($proposal->status !== ProposalStatus::Pending || ! $proposal->expires_at->isFuture()) {
            return [];
        }

        $tool = $this->tools->findProposalTool($proposal->tool, $context->user);

        return $tool === null ? [] : $tool->changes($context, $proposal->payload);
    }

    public function message(MessageRequest $request, string $conversation): RedirectResponse
    {
        $context = $this->support->context($request);
        $record = $this->find($request, $conversation);

        $this->assistant->send($record, $context, (string) $request->validated('content'));

        return back();
    }

    public function destroy(Request $request, string $conversation): RedirectResponse
    {
        $context = $this->support->context($request);
        $record = $this->find($request, $conversation);

        $this->conversations->delete($record, $context->user);
        Toast::success(__('Conversation deleted.'));

        return to_route('ai.assistant.index');
    }

    public function clear(Request $request): RedirectResponse
    {
        $context = $this->support->context($request);

        $this->conversations->clear($context->membership, $context->user);
        Toast::success(__('All your conversations were deleted.'));

        return to_route('ai.assistant.index');
    }

    private function find(Request $request, string $id): Conversation
    {
        $context = $this->support->context($request);

        return $this->conversations->mine($context->membership)->findOrFail($id);
    }

    /**
     * @return list<array{id: string, title: string, lastMessageAt: string|null}>
     */
    private function conversationList(TenantMembership $membership): array
    {
        return $this->conversations->mine($membership)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(static fn (Conversation $conversation): array => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'lastMessageAt' => $conversation->last_message_at?->toIso8601String(),
            ])->all();
    }

    /**
     * What the assistant can do for this member: read tools and tools that only propose.
     *
     * @return list<array{name: string, description: string, proposes: bool}>
     */
    private function toolList(Request $request): array
    {
        $context = $this->support->context($request);

        return array_values(array_map(
            static fn (AiTool $tool): array => [
                'name' => $tool::name(),
                'description' => $tool::description(),
                'proposes' => $tool instanceof ProposalTool,
            ],
            $this->tools->availableFor($context->user),
        ));
    }
}
