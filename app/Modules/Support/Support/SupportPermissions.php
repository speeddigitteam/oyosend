<?php

declare(strict_types=1);

namespace App\Modules\Support\Support;

/** Permission names of the Support app, so controllers and managers never type them twice. */
final class SupportPermissions
{
    public const string TICKETS_VIEW = 'support.tickets.view';

    public const string TICKETS_CREATE = 'support.tickets.create';

    public const string TICKETS_REPLY = 'support.tickets.reply';

    public const string TICKETS_EDIT = 'support.tickets.edit';

    public const string TICKETS_ASSIGN = 'support.tickets.assign';

    public const string TICKETS_RESOLVE = 'support.tickets.resolve';

    public const string TICKETS_MERGE = 'support.tickets.merge';

    public const string CANNED_VIEW = 'support.canned.view';

    public const string CANNED_MANAGE = 'support.canned.manage';

    public const string KB_VIEW = 'support.kb.view';

    public const string KB_MANAGE = 'support.kb.manage';

    public const string KB_PUBLISH = 'support.kb.publish';

    public const string QUEUES_MANAGE = 'support.queues.manage';

    public const string SLA_MANAGE = 'support.sla.manage';

    public const string REPORTS_VIEW = 'support.reports.view';

    public const string SETTINGS_MANAGE = 'support.settings.manage';
}
