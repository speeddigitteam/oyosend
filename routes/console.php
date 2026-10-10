<?php

use App\Core\Registration\Models\WorkspaceRegistration;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Run by `php artisan schedule:run` every minute (cron) or `schedule:work`.
| Everything here is safe to run twice.
|
*/

// Invoices that are due, subscriptions that are overdue, expired or canceled.
Schedule::command('billing:run')->hourly()->withoutOverlapping();

// First-party app jobs decide whether they are due per tenant timezone. The
// command also de-duplicates each tenant/job/time slot before queueing it.
Schedule::command('apps:run-scheduled')->everyMinute()->withoutOverlapping();

// Sign-ups whose confirmation link expired, and finished ones after a month.
Schedule::command('model:prune', ['--model' => [WorkspaceRegistration::class]])->daily();
