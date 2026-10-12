<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Reporting;

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportResult;
use App\Modules\WhatsappMarketing\Actions\Analytics;

/** New conversations, messages and conversation windows per local day. */
final class ConversationActivityReport implements ReportProvider
{
    public function __construct(private readonly Analytics $analytics) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'whatsapp_marketing.conversation_activity',
            module: 'whatsapp_marketing',
            label: 'WhatsApp conversation activity',
            description: 'New conversations, customer and agent messages, campaign messages and conversation windows opened per day.',
            permission: 'whatsapp_marketing.analytics.view',
            columns: [
                new ReportColumn('date', 'Date', ColumnType::Date),
                new ReportColumn('new_conversations', 'New conversations', ColumnType::Integer),
                new ReportColumn('inbound', 'Customer messages', ColumnType::Integer),
                new ReportColumn('outbound', 'Agent, bot and automation messages', ColumnType::Integer),
                new ReportColumn('campaign', 'Campaign messages', ColumnType::Integer),
                new ReportColumn('windows_customer', 'Windows opened by customers', ColumnType::Integer),
                new ReportColumn('windows_business', 'Windows opened by the business', ColumnType::Integer),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $days = $this->analytics->byDay($context->fromAt, $context->toAt, $context->timezone);

        return new ReportResult(array_map(static fn (array $day): array => [
            'date' => $day['date'],
            'new_conversations' => $day['newConversations'],
            'inbound' => $day['inbound'],
            'outbound' => $day['outbound'],
            'campaign' => $day['campaign'],
            'windows_customer' => $day['windowsCustomer'],
            'windows_business' => $day['windowsBusiness'],
        ], $days));
    }
}
