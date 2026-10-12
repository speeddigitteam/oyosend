<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Listeners;

use App\Core\Contacts\Events\ContactDeleted;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Tenancy\TenantContext;
use App\Modules\EmailMarketing\Actions\DeliverySender;
use App\Modules\EmailMarketing\Actions\SequenceEnroller;
use App\Modules\EmailMarketing\Models\Subscription;
use Illuminate\Support\Facades\DB;

/**
 * A contact that was deleted from the shared directory leaves every list and every running sequence. Deliveries
 * stay, so campaign reports keep their history.
 */
final class ForgetDeletedContact
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementChecker $entitlements,
        private readonly SequenceEnroller $sequences,
    ) {}

    public function handle(ContactDeleted $event): void
    {
        if (! $this->context->check()
            || $this->context->id() !== $event->tenantId()
            || ! $this->entitlements->allows($this->context->require(), DeliverySender::MODULE)) {
            return;
        }

        DB::transaction(function () use ($event): void {
            $this->sequences->stopForContact($event->contactId, 'contact_deleted');
            Subscription::query()->where('contact_id', $event->contactId)->delete();
        });
    }
}
