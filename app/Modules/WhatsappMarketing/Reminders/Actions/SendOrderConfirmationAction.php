<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Reminders\Actions;

use App\Modules\WhatsappMarketing\Reminders\ReminderAction;

/** Sends an order-confirmation template, e.g. on `crm.quote.accepted`. Values come from the event or manual input. */
final class SendOrderConfirmationAction extends ReminderAction
{
    public static function key(): string
    {
        return 'whatsapp_marketing.send_order_confirmation';
    }

    public static function label(): string
    {
        return 'automation.actions.whatsapp_marketing.send_order_confirmation';
    }
}
