<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Access\AccessChecker;
use App\Core\Ai\Contracts\TextAssistant;
use App\Core\Ai\NullTextAssistant;
use App\Core\Ai\Text\ContextBlock;
use App\Core\Ai\Text\TextKind;
use App\Core\Ai\Text\TextTask;
use App\Core\Ai\Text\TextTone;
use App\Core\Communication\Inbox\InboxMessage;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Suggest a reply" in the unified inbox (ADR 0030, 2026-10-14 note): a draft for the composer, written by the core
 * {@see TextAssistant} contract (ADR 0024) from the newest customer message and the few messages around it.
 *
 * It returns TEXT only. Nothing is sent, saved or marked read here; the member edits the draft and sends it through
 * the normal reply, which applies every rule of the owning app again. The conversation is resolved through
 * {@see UnifiedInbox::answerable()}, so the same visibility and reply-permission gates apply as for a reply. This
 * module imports nothing of the AI app: the contract decides (and says honestly) whether AI is configured, switched on
 * and within the workspace's allowance.
 */
final class InboxReplySuggestions
{
    /** Messages read from the conversation: the newest customer message and what was said around it. */
    public const int MESSAGES = 10;

    /** The longest suggestion asked for, in characters. */
    public const int MAX_LENGTH = 600;

    /** The app whose entitlement makes the assistant usable in a workspace (a key, not an import). */
    private const string AI_MODULE = 'ai';

    /** Each quoted message is cut to this many characters before it is handed over. */
    private const int MESSAGE_CHARS = 500;

    public function __construct(
        private readonly UnifiedInbox $inbox,
        private readonly TextAssistant $assistant,
        private readonly EntitlementChecker $entitlements,
        private readonly TenantContext $context,
        private readonly AccessChecker $access,
    ) {}

    /**
     * Whether the composer offers the button: the AI app is installed and the workspace is entitled to it. Whether
     * AI is configured, switched on and within its allowance is answered by the assistant on each request, with a
     * message the page shows.
     */
    public function available(): bool
    {
        $tenant = $this->context->tenant();

        return $tenant !== null
            && ! $this->assistant instanceof NullTextAssistant
            && $this->entitlements->allows($tenant, self::AI_MODULE);
    }

    /**
     * A reply suggestion for one conversation the member could answer now.
     *
     * @return array{state: string, text: string, message: string, retryable: bool}
     *
     * @throws NotFoundHttpException|AccessDeniedHttpException as for {@see UnifiedInbox::answerable()}
     * @throws ValidationException on `text` when no reply can be written now or there is no customer message to answer
     */
    public function suggest(string $sourceKey, string $id, User $viewer): array
    {
        $thread = $this->inbox->answerable($sourceKey, $id, $viewer, self::MESSAGES);
        $messages = $thread->messages;
        $latest = null;

        foreach ($messages as $position => $message) {
            if (! $message->isOutgoing() && trim((string) $message->text) !== '') {
                $latest = $position;
            }
        }

        if ($latest === null) {
            throw ValidationException::withMessages(['text' => __('There is no customer message to reply to yet.')]);
        }

        $input = $messages[$latest];
        $context = array_values(array_filter([
            $this->transcript('earlier_messages', array_slice($messages, 0, $latest)),
            $this->transcript('replies_since', array_slice($messages, $latest + 1)),
        ]));

        $result = $this->assistant->generate(new TextTask(
            kind: TextKind::ReplySuggestion,
            input: (string) $input->text,
            purpose: $this->purpose($sourceKey, $input->id),
            // The reply goes to the customer: in the language they wrote in, not the member's interface language.
            locale: null,
            tone: TextTone::Friendly,
            maxLength: self::MAX_LENGTH,
            platform: preg_match('/^[a-z0-9_]{1,32}$/', $thread->channel) === 1 ? $thread->channel : null,
            context: $context,
            actorMembershipId: $this->access->membership($viewer)?->id,
        ));

        return [
            'state' => $result->state->value,
            'text' => $result->ok() ? $result->text : '',
            'message' => $result->message,
            'retryable' => $result->retryable,
        ];
    }

    /** `inbox:reply:{source}:{message}`: the same customer message is metered once and replayed for an hour. */
    private function purpose(string $sourceKey, string $messageId): string
    {
        $safe = static fn (string $value): string => (string) preg_replace('/[^A-Za-z0-9._-]/', '', $value);

        return mb_substr('inbox:reply:'.$safe($sourceKey).':'.$safe($messageId), 0, 100);
    }

    /**
     * The messages as quoted data, newest kept when they do not all fit. Names are left out: who wrote is enough.
     *
     * @param  list<InboxMessage>  $messages
     */
    private function transcript(string $label, array $messages): ?ContextBlock
    {
        $lines = [];
        $room = ContextBlock::MAX_CHARS;

        foreach (array_reverse($messages) as $message) {
            $text = trim((string) $message->text);
            $body = $text !== '' ? mb_substr($text, 0, self::MESSAGE_CHARS) : ($message->attachmentLabel !== null ? '['.$message->attachmentLabel.']' : '');

            if ($body === '') {
                continue;
            }

            $line = ($message->isOutgoing() ? 'Business: ' : 'Customer: ').$body;

            if (mb_strlen($line) + 1 > $room) {
                break;
            }

            $room -= mb_strlen($line) + 1;
            array_unshift($lines, $line);
        }

        return $lines === [] ? null : new ContextBlock($label, implode("\n", $lines));
    }
}
