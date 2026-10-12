<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support;

/** The permission names the Payroll module declares. */
final class PayrollPermissions
{
    public const string STRUCTURES_VIEW = 'payroll.structures.view';

    public const string STRUCTURES_MANAGE = 'payroll.structures.manage';

    public const string SALARIES_VIEW = 'payroll.salaries.view';

    public const string SALARIES_MANAGE = 'payroll.salaries.manage';

    public const string PERIODS_VIEW = 'payroll.periods.view';

    public const string PERIODS_MANAGE = 'payroll.periods.manage';

    public const string RUNS_VIEW = 'payroll.runs.view';

    public const string RUNS_PREPARE = 'payroll.runs.prepare';

    public const string RUNS_APPROVE = 'payroll.runs.approve';

    public const string RUNS_REVERSE = 'payroll.runs.reverse';

    public const string ITEMS_VIEW = 'payroll.items.view';

    public const string ITEMS_MANAGE = 'payroll.items.manage';

    public const string LOANS_VIEW = 'payroll.loans.view';

    public const string LOANS_MANAGE = 'payroll.loans.manage';

    public const string LOANS_APPROVE = 'payroll.loans.approve';

    public const string RULES_VIEW = 'payroll.rules.view';

    public const string RULES_MANAGE = 'payroll.rules.manage';

    public const string PAYSLIPS_VIEW = 'payroll.payslips.view';

    public const string PAYMENTS_RECORD = 'payroll.payments.record';

    public const string PAYMENTS_EXPORT = 'payroll.payments.export';

    public const string REPORTS_VIEW = 'payroll.reports.view';

    public const string SETTINGS_MANAGE = 'payroll.settings.manage';
}
