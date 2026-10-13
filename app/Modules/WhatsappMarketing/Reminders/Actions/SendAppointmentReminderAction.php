<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Reminders\Actions;

use App\Modules\WhatsappMarketing\Reminders\ReminderAction;

/** Sends an appointment-reminder template. Values come from the triggering event or manual input (no booking data is invented). */
final class SendAppointmentReminderAction extends ReminderAction
{
    public static function key(): string
    {
        return 'whatsapp_marketing.send_appointment_reminder';
    }

    public static function label(): string
    {
        return 'automation.actions.whatsapp_marketing.send_appointment_reminder';
    }
}
