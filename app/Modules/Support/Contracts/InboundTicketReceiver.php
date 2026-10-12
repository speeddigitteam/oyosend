<?php

declare(strict_types=1);

namespace App\Modules\Support\Contracts;

use Illuminate\Validation\ValidationException;

/**
 * Entry point for channel integrations. Resolve it from the container inside the workspace's tenant
 * context (`TenantContext::run()`); it applies the plan limit, numbering, routing and SLA exactly as a
 * ticket created in the UI does. The shipped implementation is `App\Modules\Support\Actions\InboundTicketHandler`.
 *
 * Nothing in this repository calls it yet: there is no mail ingestion, and none is simulated.
 */
interface InboundTicketReceiver
{
    /** @throws ValidationException when the message is invalid or a plan limit is reached */
    public function receive(InboundTicketMessage $message): InboundTicketResult;
}
