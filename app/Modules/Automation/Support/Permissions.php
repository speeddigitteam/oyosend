<?php

declare(strict_types=1);

namespace App\Modules\Automation\Support;

/** The module's permission names. */
final class Permissions
{
    public const string WORKFLOWS_VIEW = 'automation.workflows.view';

    public const string WORKFLOWS_MANAGE = 'automation.workflows.manage';

    public const string WORKFLOWS_PUBLISH = 'automation.workflows.publish';

    public const string RUNS_VIEW = 'automation.runs.view';

    public const string RUNS_START = 'automation.runs.start';

    public const string RUNS_MANAGE = 'automation.runs.manage';

    public const string APPROVALS_DECIDE = 'automation.approvals.decide';

    public const string WEBHOOKS_MANAGE = 'automation.webhooks.manage';
}
