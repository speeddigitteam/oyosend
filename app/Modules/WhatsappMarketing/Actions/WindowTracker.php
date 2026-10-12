<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Billing\UsageLimits;
use App\Core\Billing\UsageMeter;
use App\Modules\WhatsappMarketing\Enums\WindowOrigin;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Window;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The 24-hour rules of the WhatsApp Business Platform, enforced on the server.
 *
 *  - Customer-service window: a free-form reply is allowed only until 24 hours after the customer's last
 *    message. After that only an approved template can be sent.
 *  - Conversation window: the unit of the plan limit `whatsapp_marketing.conversations_per_month`. A window opens
 *    when a customer writes (or when we send a template) and no window is running; it is counted once, under
 *    an idempotency key that names the window.
 */
final class WindowTracker
{
    public const string LIMIT = 'whatsapp_marketing.conversations_per_month';

    public const int HOURS = 24;

    public function __construct(
        private readonly UsageLimits $limits,
        private readonly UsageMeter $meter,
    ) {}

    /** The moment free-form replies stop being allowed, or null when no window is open. */
    public function serviceWindowUntil(Conversation $conversation, ?CarbonInterface $now = null): ?CarbonImmutable
    {
        $last = $conversation->last_inbound_at;

        if ($last === null) {
            return null;
        }

        $until = CarbonImmutable::instance($last)->addHours(self::HOURS);

        return $until->isAfter($now ?? CarbonImmutable::now()) ? $until : null;
    }

    public function isServiceWindowOpen(Conversation $conversation, ?CarbonInterface $now = null): bool
    {
        return $this->serviceWindowUntil($conversation, $now) !== null;
    }

    /** The running conversation window, if any. */
    public function current(Conversation $conversation, ?CarbonInterface $now = null): ?Window
    {
        return Window::query()
            ->where('conversation_id', $conversation->id)
            ->where('expires_at', '>', $now ?? CarbonImmutable::now())
            ->orderByDesc('expires_at')
            ->first();
    }

    /** A customer wrote: open (and count) a window unless one is running. Returns the window that applies. */
    public function openForInbound(Conversation $conversation, CarbonInterface $at): Window
    {
        return $this->current($conversation, $at) ?? $this->open($conversation, WindowOrigin::Customer, $at, null);
    }

    /** A business-initiated template was accepted by the provider: open (and count) a window unless one runs. */
    public function openForBusiness(Conversation $conversation, string $templateCategory, CarbonInterface $at): Window
    {
        return $this->current($conversation, $at) ?? $this->open($conversation, WindowOrigin::Business, $at, $templateCategory);
    }

    /**
     * Whether a template to this conversation may start now: a running window is free, a new one needs room
     * in the plan's monthly limit.
     */
    public function canStartBusinessWindow(Conversation $conversation): bool
    {
        return $this->current($conversation) !== null || $this->limits->allows(self::LIMIT);
    }

    private function open(Conversation $conversation, WindowOrigin $origin, CarbonInterface $at, ?string $category): Window
    {
        $opened = CarbonImmutable::instance($at);

        $window = Window::query()->create([
            'conversation_id' => $conversation->id,
            'origin' => $origin,
            'opened_at' => $opened,
            'expires_at' => $opened->addHours(self::HOURS),
            'template_category' => $category,
        ]);

        $this->meter->record(self::LIMIT, 1, 'wa-window:'.$window->id, ['origin' => $origin->value], $opened);

        return $window;
    }
}
