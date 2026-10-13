<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Communication\Contracts\ConsentRecorder;
use App\Core\Communication\Contracts\UnsubscribeLinks;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Support\Like;
use App\Modules\WhatsappMarketing\Enums\FlowMatch;
use App\Modules\WhatsappMarketing\Enums\FlowTrigger;
use App\Modules\WhatsappMarketing\Enums\SenderType;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Flow;
use App\Modules\WhatsappMarketing\Models\Message;
use App\Modules\WhatsappMarketing\Support\Interactive;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Runs the declarative chatbot flows against one inbound message. The vocabulary is closed (see
 * {@see FlowDefinition}); the engine never evaluates anything a tenant wrote, and a conversation owned by a
 * person is left to that person.
 *
 * Guards: at most {@see MAX_BOT_MESSAGES} bot messages per conversation per {@see GUARD_MINUTES} minutes, one
 * menu step awaiting an answer at a time, and a menu answer expires after {@see MENU_MINUTES} minutes.
 */
final class FlowEngine
{
    public const int MAX_BOT_MESSAGES = 5;

    public const int GUARD_MINUTES = 10;

    public const int MENU_MINUTES = 30;

    /** Messages that ask to stop receiving messages. Answered even when no flow exists. */
    private const array STOP_WORDS = ['stop', 'unsubscribe', 'opt out', 'optout', 'stop all', 'বন্ধ', 'আনসাবস্ক্রাইব'];

    public function __construct(
        private readonly SettingsManager $settings,
        private readonly Messenger $messenger,
        private readonly ConversationManager $conversations,
        private readonly AutoAssigner $assigner,
        private readonly UnsubscribeLinks $unsubscribe,
        private readonly ConsentRecorder $consent,
        private readonly AgentManager $agents,
    ) {}

    public function handle(InboundResult $inbound): void
    {
        $conversation = $inbound->conversation;
        $text = $inbound->message->body === null ? '' : FlowDefinition::normalize($inbound->message->body);

        if ($text === '') {
            return;
        }

        // The bot's own message budget applies to everything it says, the STOP confirmation included. The
        // opt-out itself is not a message: it is recorded every time, and stays in force whatever the budget.
        $overGuard = $this->overGuard($conversation);

        if (in_array($text, self::STOP_WORDS, true)) {
            $this->acknowledgeStop($conversation, $overGuard);

            return;
        }

        if (! $this->settings->current()->bots_enabled || $conversation->bot_paused || $overGuard) {
            return;
        }

        $state = $conversation->bot_state;
        $awaiting = is_array($state) && isset($state['flow_id'])
            && $conversation->bot_state_expires_at !== null && $conversation->bot_state_expires_at->isFuture();

        if ($awaiting) {
            $flow = Flow::query()->where('is_active', true)->find((string) $state['flow_id']);
            $this->clearState($conversation);

            if ($flow !== null && $this->answerMenu($conversation, $flow, $text)) {
                return;
            }
        }

        $flows = Flow::query()->where('is_active', true)->orderBy('priority')->orderBy('name')->limit(50)->get();

        foreach ($flows as $flow) {
            if ($flow->trigger === FlowTrigger::Keyword && $this->matches($flow, $text)) {
                $this->run($conversation, $flow->actions, $flow->id);

                return;
            }
        }

        if ($inbound->newWindow) {
            $welcome = $flows->first(static fn (Flow $flow): bool => $flow->trigger === FlowTrigger::Welcome);

            if ($welcome !== null) {
                $this->run($conversation, $welcome->actions, $welcome->id);
            }
        }
    }

    private function matches(Flow $flow, string $text): bool
    {
        foreach ($flow->keywords ?? [] as $keyword) {
            $hit = $flow->match_type === FlowMatch::Contains ? str_contains($text, (string) $keyword) : $text === $keyword;

            if ($hit) {
                return true;
            }
        }

        return false;
    }

    private function answerMenu(Conversation $conversation, Flow $flow, string $text): bool
    {
        foreach ($flow->actions as $action) {
            if (($action['type'] ?? null) !== 'menu') {
                continue;
            }

            foreach (array_values((array) ($action['options'] ?? [])) as $index => $option) {
                $label = FlowDefinition::normalize((string) ($option['label'] ?? ''));

                if ($text === (string) ($index + 1) || $text === $label) {
                    $this->run($conversation, (array) ($option['actions'] ?? []));

                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<array-key, mixed> $actions */
    private function run(Conversation $conversation, array $actions, ?string $flowId = null): void
    {
        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            try {
                match ($action['type'] ?? null) {
                    'reply' => $this->messenger->reply($conversation, (string) $action['text'], null, SenderType::Bot),
                    'menu' => $this->menu($conversation, $action, $flowId),
                    'tag' => $this->conversations->addTag($conversation, (string) $action['tag']),
                    'assign' => $this->assign($conversation, $action['assignee_id'] ?? null),
                    'handoff' => $conversation->forceFill(['bot_paused' => true, 'bot_state' => null, 'bot_state_expires_at' => null])->save(),
                    default => null,
                };
            } catch (ValidationException) {
                // A reply that cannot be sent (no contact yet, window closed, channel down) stops the flow
                // quietly: the message stays in the inbox for a person.
                return;
            }
        }
    }

    /**
     * The prompt with its options as reply buttons (up to three short labels) or a list (up to ten), and as
     * numbered text when the labels do not fit either. The customer's choice comes back as the label's text.
     *
     * @param  array<array-key, mixed>  $action
     */
    private function menu(Conversation $conversation, array $action, ?string $flowId): void
    {
        $labels = array_map(static fn (mixed $option): string => is_array($option) ? (string) ($option['label'] ?? '') : '', array_values((array) ($action['options'] ?? [])));
        $interactive = Interactive::forMenu($labels, __('Choose'));

        if ($interactive !== null && mb_strlen((string) $action['prompt']) <= Interactive::MAX_BODY) {
            $this->messenger->reply($conversation, (string) $action['prompt'], null, SenderType::Bot, $interactive);
        } else {
            $lines = [(string) $action['prompt']];

            foreach ($labels as $index => $label) {
                $lines[] = ($index + 1).'. '.$label;
            }

            $this->messenger->reply($conversation, implode("\n", $lines), null, SenderType::Bot);
        }

        if ($flowId !== null) {
            $this->awaitAnswer($conversation, $flowId);
        }
    }

    private function assign(Conversation $conversation, mixed $assigneeId): void
    {
        $membership = is_string($assigneeId) && $assigneeId !== ''
            ? $this->agents->activeMembership($assigneeId)
            : $this->assigner->pick();

        if ($membership !== null) {
            $this->conversations->assign($conversation, $membership, 'bot');
        }
    }

    /**
     * STOP is an explicit request: the opt-out is recorded at once (consent withdrawn, number suppressed for
     * marketing), and the customer is told so, once. Where it cannot be recorded (no contact behind the number,
     * or the communication app is not available) the older, honest fallback applies: an unsubscribe link to
     * confirm on, or a plain note that a person will see the request.
     */
    private function acknowledgeStop(Conversation $conversation, bool $overGuard): void
    {
        $recorded = $conversation->contact_id !== null && $this->consent->recordOptOut(
            $conversation->contact_id,
            MarketingChannel::Whatsapp,
            'keyword',
            'WhatsApp keyword',
            $conversation->phone,
        );

        $prefix = null;

        if ($recorded) {
            $text = __('You have been unsubscribed. You will not receive marketing messages from us unless you opt in again.');
        } else {
            $link = $conversation->contact_id === null
                ? null
                : $this->unsubscribe->issue($conversation->contact_id, MarketingChannel::Whatsapp, Messenger::MODULE);

            $text = $link === null
                ? __('We have noted your request. You will not receive marketing messages from us unless you opt in.')
                : __('To stop receiving marketing messages from us, open this link and confirm: :link', ['link' => $link]);

            // A link carries a new token each time: the same message is recognised by what precedes it.
            $prefix = $link === null ? null : __('To stop receiving marketing messages from us, open this link and confirm: :link', ['link' => '']);
        }

        // Once: the same confirmation is not repeated to a customer who keeps writing STOP, and the bot's
        // message budget still applies.
        if ($overGuard || $this->alreadySaid($conversation, $text, $prefix)) {
            return;
        }

        try {
            $this->messenger->reply($conversation, $text, null, SenderType::Bot);
        } catch (ValidationException) {
            // No contact to answer through the platform: the request stays visible in the inbox.
        }
    }

    private function alreadySaid(Conversation $conversation, string $text, ?string $prefix): bool
    {
        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', SenderType::Bot->value)
            ->where('occurred_at', '>=', CarbonImmutable::now()->subMinutes(self::GUARD_MINUTES))
            ->when(
                $prefix === null,
                static fn ($query) => $query->where('body', $text),
                static fn ($query) => $query->where('body', 'like', Like::escape((string) $prefix).'%'),
            )
            ->exists();
    }

    private function overGuard(Conversation $conversation): bool
    {
        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', SenderType::Bot->value)
            ->where('occurred_at', '>=', CarbonImmutable::now()->subMinutes(self::GUARD_MINUTES))
            ->count() >= self::MAX_BOT_MESSAGES;
    }

    private function clearState(Conversation $conversation): void
    {
        $conversation->forceFill(['bot_state' => null, 'bot_state_expires_at' => null])->save();
    }

    /** Called after the menu prompt was sent: the next customer message answers it. */
    private function awaitAnswer(Conversation $conversation, string $flowId): void
    {
        $conversation->forceFill([
            'bot_state' => ['flow_id' => $flowId],
            'bot_state_expires_at' => CarbonImmutable::now()->addMinutes(self::MENU_MINUTES),
        ])->save();
    }
}
