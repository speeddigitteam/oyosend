<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Support;

/** Permission names of the WhatsApp Business app, so controllers and managers never type them twice. */
final class Permissions
{
    public const string CONVERSATIONS_VIEW = 'whatsapp_marketing.conversations.view';

    public const string CONVERSATIONS_REPLY = 'whatsapp_marketing.conversations.reply';

    public const string CONVERSATIONS_MANAGE = 'whatsapp_marketing.conversations.manage';

    public const string CONVERSATIONS_ASSIGN = 'whatsapp_marketing.conversations.assign';

    public const string TEMPLATES_VIEW = 'whatsapp_marketing.templates.view';

    public const string TEMPLATES_MANAGE = 'whatsapp_marketing.templates.manage';

    public const string CAMPAIGNS_VIEW = 'whatsapp_marketing.campaigns.view';

    public const string CAMPAIGNS_MANAGE = 'whatsapp_marketing.campaigns.manage';

    public const string CAMPAIGNS_SEND = 'whatsapp_marketing.campaigns.send';

    public const string LISTS_VIEW = 'whatsapp_marketing.lists.view';

    public const string LISTS_MANAGE = 'whatsapp_marketing.lists.manage';

    public const string QUICK_REPLIES_VIEW = 'whatsapp_marketing.quick_replies.view';

    public const string QUICK_REPLIES_MANAGE = 'whatsapp_marketing.quick_replies.manage';

    public const string FLOWS_MANAGE = 'whatsapp_marketing.flows.manage';

    public const string ANALYTICS_VIEW = 'whatsapp_marketing.analytics.view';

    public const string SETTINGS_MANAGE = 'whatsapp_marketing.settings.manage';
}
