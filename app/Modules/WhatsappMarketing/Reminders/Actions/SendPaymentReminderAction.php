<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Reminders\Actions;

use App\Modules\WhatsappMarketing\Reminders\ReminderAction;

/**
 * Sends a payment-reminder template, e.g. on `invoicing.invoice.overdue`. Values come from the event or manual
 * input. This is also the generic scheduled reminder used in place of "abandoned cart recovery", for which the
 * platform holds no cart or commerce data (owner decision, docs/WHATSAPP.md section 16).
 */
final class SendPaymentReminderAction extends ReminderAction
{
    public static function key(): string
    {
        return 'whatsapp_marketing.send_payment_reminder';
    }

    public static function label(): string
    {
        return 'automation.actions.whatsapp_marketing.send_payment_reminder';
    }
}
