<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Audit\AuditLogger;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Communication\Actions\ChannelConnections;
use App\Modules\Communication\Actions\ManualMessages;
use App\Modules\Communication\Actions\Outbox;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Http\Requests\SendMessageRequest;
use App\Modules\Communication\Models\Message;
use App\Modules\Communication\Models\MessageTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The message log (outbox) and the one-off composer. */
final class MessageController
{
    public const string VIEW = 'communication.messages.view';

    public const string SEND = 'communication.messages.send';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly CommunicationControllerSupport $support,
        private readonly ManualMessages $messages,
        private readonly ChannelConnections $connections,
        private readonly Outbox $outbox,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(MessageStatus::class)],
            'channel' => ['nullable', Rule::enum(Channel::class)],
            'source' => ['nullable', 'string', 'max:60'],
            'contact' => ['nullable', 'string', 'size:26'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $source = $filters['source'] ?? null;

        $messages = $this->records->constrain(Message::query(), $actor, self::VIEW)
            ->with(['contact:id,first_name,last_name', 'company:id,name'])
            ->when($source !== null, fn ($query) => $query->where('source', $source), fn ($query) => $query->where(
                fn ($match) => $match->whereNull('source')->orWhere('source', '!=', 'notification'),
            ))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['channel']), fn ($query) => $query->where('channel', $filters['channel']))
            ->when(isset($filters['contact']), fn ($query) => $query->where('contact_id', $filters['contact']))
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('recipient', 'like', Like::contains($search))
                ->orWhere('subject', 'like', Like::contains($search))))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Message $message): array => $this->support->message($message, $actor));

        return Inertia::render('Modules/Communication/Pages/Messages/Index', [
            'messages' => $messages,
            'filters' => [
                'search' => $search,
                'status' => $filters['status'] ?? null,
                'channel' => $filters['channel'] ?? null,
                'source' => $source,
                'contact' => $filters['contact'] ?? null,
            ],
            'statuses' => array_map(static fn (MessageStatus $status): string => $status->value, MessageStatus::cases()),
            'channels' => $this->channelStates(),
            'canSend' => $actor->can(self::SEND),
        ]);
    }

    public function show(Request $request, Message $message): Response
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::VIEW, $message);
        $message->load(['contact:id,first_name,last_name', 'company:id,name', 'owner.user:id,name']);

        return Inertia::render('Modules/Communication/Pages/Messages/Show', [
            'message' => [
                ...$this->support->message($message, $actor),
                'body' => $message->body,
                'locale' => $message->locale,
                'provider' => $message->provider,
                'failureMessage' => $message->failure_message,
                'templateId' => $message->template_id,
                'owner' => $message->owner === null ? null : ['id' => $message->owner->id, 'name' => $message->owner->user->name],
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $contact = $request->query('contact');

        return Inertia::render('Modules/Communication/Pages/Messages/Compose', [
            'contacts' => $this->support->contactOptions($actor),
            'channels' => $this->channelStates(),
            'purposes' => array_map(static fn (MessagePurpose $purpose): string => $purpose->value, MessagePurpose::cases()),
            'templates' => MessageTemplate::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (MessageTemplate $template): array => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'channel' => $template->channel->value,
                    'locale' => $template->locale,
                    'purpose' => $template->purpose->value,
                    'subject' => $template->subject,
                ])->all(),
            'contactId' => is_string($contact) && strlen($contact) === 26 ? $contact : null,
        ]);
    }

    public function store(SendMessageRequest $request): RedirectResponse
    {
        $message = $this->messages->send($this->support->actor($request), $request->validated());
        Toast::success($message->status === MessageStatus::Queued
            ? __('Message queued.')
            : __('The message was not sent.'));

        return redirect()->route('communication.messages.show', $message->id);
    }

    public function retry(Request $request, Message $message): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::SEND, $message);
        abort_unless($message->status === MessageStatus::Failed, 404);
        $this->outbox->assertWithinLimit('message');

        DB::transaction(function () use ($message): void {
            $message->forceFill([
                'status' => MessageStatus::Queued,
                'attempts' => 0,
                'next_attempt_at' => CarbonImmutable::now(),
                'failure_code' => null,
                'failure_message' => null,
                'failed_at' => null,
            ])->save();
            $this->audit->record('communication.message.retried', $message, ['channel' => $message->channel->value]);
        });

        $this->outbox->dispatch($message);
        Toast::success(__('Message queued again.'));

        return back();
    }

    public function cancel(Request $request, Message $message): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::SEND, $message);

        $cancelled = DB::transaction(function () use ($message): bool {
            $locked = Message::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== MessageStatus::Queued) {
                return false;
            }

            $locked->forceFill(['status' => MessageStatus::Cancelled, 'failure_code' => 'cancelled', 'next_attempt_at' => null])->save();
            $this->audit->record('communication.message.cancelled', $locked, ['channel' => $locked->channel->value]);

            return true;
        });

        $cancelled ? Toast::success(__('Message cancelled.')) : Toast::warning(__('This message has already been sent or is being sent.'));

        return back();
    }

    /** @return list<array{channel: string, state: string}> */
    private function channelStates(): array
    {
        return array_map(fn (Channel $channel): array => [
            'channel' => $channel->value,
            'state' => $this->connections->state($channel)->value,
        ], Channel::cases());
    }
}
