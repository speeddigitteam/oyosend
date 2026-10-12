<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Reporting;

use App\Core\Reporting\Contracts\ReportProvider;

/** The report providers WhatsApp Business publishes to Reports & Analytics (tagged in the service provider). */
final class ReportProviders
{
    /** @var list<class-string<ReportProvider>> */
    public const array ALL = [
        CampaignDeliveryReport::class,
        ConversationActivityReport::class,
    ];
}
