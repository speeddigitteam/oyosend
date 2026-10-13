<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Reminders\Actions;

use App\Modules\WhatsappMarketing\Reminders\ReminderAction;

/** Sends an invoice-notification template, e.g. on `invoicing.invoice.issued`. Values come from the event or manual input. */
final class SendInvoiceNotificationAction extends ReminderAction
{
    public static function key(): string
    {
        return 'whatsapp_marketing.send_invoice_notification';
    }

    public static function label(): string
    {
        return 'automation.actions.whatsapp_marketing.send_invoice_notification';
    }
}
