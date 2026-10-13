<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\WhatsappMarketing\Enums\FeedbackStatus;
use App\Modules\WhatsappMarketing\Enums\MessageStatus;
use App\Modules\WhatsappMarketing\Enums\SenderType;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Feedback;
use App\Modules\WhatsappMarketing\Support\Interactive;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Customer feedback on WhatsApp: when a person closes a conversation, the customer is asked to rate the help from
 * 1 to 5 (a list message inside their open 24-hour window, so a service message, never marketing). Their next
 * message is the answer when it is a rating; anything else ends the question, so an ordinary "3" later in the
 * thread is never taken for a rating.
 *
 * Off unless the workspace switches it on. Asked at most once per conversation per 24 hours, only after a person
 * answered the customer, and never outside the window.
 */
final class FeedbackCollector
{
    public const int HOURS_BETWEEN = 24;

    public function __construct(
        private readonly SettingsManager $settings,
        private readonly Messenger $messenger,
        private readonly WindowTracker $windows,
        private readonly TenantContext $context,
    ) {}

    /** Ask for a rating after a person closed the conversation. Never throws: closing must not fail because of it. */
    public function request(Conversation $conversation): bool
    {
        if (! $this->settings->current()->feedback_enabled || $conversation->contact_id === null || $conversation->first_responded_at === null) {
            return false;
        }

        $until = $this->windows->serviceWindowUntil($conversation);

        if ($until === null) {
            return false;
        }

        $recent = Feedback::query()
            ->where('conversation_id', $conversation->id)
            ->where('requested_at', '>=', CarbonImmutable::now()->subHours(self::HOURS_BETWEEN))
            ->exists();

        if ($recent) {
            return false;
        }

        $locale = $this->locale();
        $choices = Interactive::fromInput([
            'kind' => Interactive::LIST,
            'button' => __('Rate', [], $locale),
            'options' => array_values(self::labels($locale)),
        ]);

        try {
            $message = $this->messenger->reply(
                $conversation,
                __('Thank you for contacting us. How would you rate the help you received?', [], $locale),
                null,
                SenderType::Bot,
                $choices,
            );
        } catch (ValidationException) {
            return false;
        }

        if (in_array($message->status, [MessageStatus::Failed, MessageStatus::Blocked], true)) {
            return false;
        }

        Feedback::query()->create([
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'owner_id' => $conversation->owner_id,
            'message_id' => $message->id,
            'status' => FeedbackStatus::Pending,
            'requested_at' => CarbonImmutable::now(),
            'expires_at' => $until,
        ]);

        return true;
    }

    /**
     * Called for each inbound message, inside the transaction that stores it: records the rating when the
     * conversation is waiting for one and the message is a rating, and ends the question otherwise.
     */
    public function answer(Conversation $conversation, ?string $text, CarbonInterface $at): ?Feedback
    {
        if ($conversation->getKey() === null) {
            return null;
        }

        $pending = Feedback::query()
            ->where('conversation_id', $conversation->id)
            ->where('status', FeedbackStatus::Pending->value)
            ->lockForUpdate()
            ->latest('requested_at')
            ->first();

        if ($pending === null) {
            return null;
        }

        $rating = $pending->expires_at->isAfter($at) ? self::rating($text) : null;

        if ($rating === null) {
            $pending->forceFill(['status' => FeedbackStatus::Expired])->save();

            return null;
        }

        $pending->forceFill(['status' => FeedbackStatus::Answered, 'rating' => $rating, 'answered_at' => $at])->save();

        return $pending;
    }

    /** Thank the customer for a rating. A failure to send is not an error: the rating is recorded. */
    public function thank(Conversation $conversation): void
    {
        try {
            $this->messenger->reply($conversation, __('Thank you for your feedback.', [], $this->locale()), null, SenderType::Bot);
        } catch (ValidationException) {
            // No window or no contact any more: the rating stands without a reply.
        }
    }

    /**
     * A rating from a customer's message: `4`, `৪`, `4 - Good` (a list row's title) or `4 stars`.
     */
    public static function rating(?string $text): ?int
    {
        $text = FlowDefinition::normalize((string) $text);
        $text = strtr($text, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);

        return preg_match('/^([1-5])(?:\D|$)/u', $text, $match) === 1 ? (int) $match[1] : null;
    }

    /** @return array<int, string> rating => row title (at most 24 characters) */
    public static function labels(?string $locale = null): array
    {
        return [
            5 => __('5 - Excellent', [], $locale),
            4 => __('4 - Good', [], $locale),
            3 => __('3 - Okay', [], $locale),
            2 => __('2 - Poor', [], $locale),
            1 => __('1 - Very poor', [], $locale),
        ];
    }

    /** Customer-facing text is in the workspace's language, not the language of whoever closed the conversation. */
    private function locale(): string
    {
        return $this->context->require()->locale;
    }
}
