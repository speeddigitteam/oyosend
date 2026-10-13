<?php

declare(strict_types=1);

namespace App\Modules\Payroll;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\Billing\Enums\LimitKind;
use App\Core\Modules\AppOffer;
use App\Core\Modules\LimitDefinition;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Core\Modules\WidgetDefinition;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Modules\Payroll\Actions\RunManager;
use App\Modules\Payroll\Automation\Actions\CreatePayItemAction;
use App\Modules\Payroll\Automation\Actions\PrepareRunAction;
use App\Modules\Payroll\Automation\Triggers\PayslipPaidTrigger;
use App\Modules\Payroll\Automation\Triggers\RunApprovedTrigger;
use App\Modules\Payroll\Automation\Triggers\RunReversedTrigger;
use App\Modules\Payroll\Calculation\RuleSetEngines;
use App\Modules\Payroll\Listeners\RefreshEmployeePlacement;
use App\Modules\Payroll\Models\Item;
use App\Modules\Payroll\Models\Loan;
use App\Modules\Payroll\Models\LoanInstallment;
use App\Modules\Payroll\Models\Payment;
use App\Modules\Payroll\Models\PayrollSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Models\Period;
use App\Modules\Payroll\Models\Profile;
use App\Modules\Payroll\Models\RuleSet;
use App\Modules\Payroll\Models\RuleSlab;
use App\Modules\Payroll\Models\Run;
use App\Modules\Payroll\Models\SalaryAssignment;
use App\Modules\Payroll\Models\SalaryStructure;
use App\Modules\Payroll\Models\StructureComponent;
use App\Modules\Payroll\Reporting\ReportProviders;
use App\Modules\Payroll\Widgets\PayrollOverviewWidget;
use App\Modules\Payroll\Widgets\PayrollSelfWidget;
use Illuminate\Support\Facades\Event;

/**
 * Payroll (category HR & Payroll): salary structures and revisions, pay periods, runs with
 * four-eyes approval, payslips, loans, one-off items, configurable compliance rule sets and reports.
 *
 * It depends on `hrm` and reads it only through the `App\Core\Workforce` contracts
 * (`EmployeeDirectory`, `EmployeeTimeSummary`); it imports no HRM model and no model of any other
 * optional module. HRM events are consumed by class name through a documented listener.
 */
final class PayrollServiceProvider extends ModuleServiceProvider
{
    /** The documented HRM events Payroll listens to, referenced by class name only (not imported). */
    private const array HRM_EVENTS = [
        'App\\Modules\\Hrm\\Events\\EmployeeCreated',
        'App\\Modules\\Hrm\\Events\\EmployeeUpdated',
        'App\\Modules\\Hrm\\Events\\EmployeeTerminated',
    ];

    public function register(): void
    {
        parent::register();
        $this->app->tag(ReportProviders::ALL, ReportProvider::TAG);

        // The registry of rule engines: another country's scheme registers itself here.
        $this->app->singleton(RuleSetEngines::class);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'payroll',
            name: 'Payroll',
            version: '1.0.0',
            dependencies: ['core', 'hrm'],
            permissions: [
                new PermissionDefinition('payroll.structures.view', 'payroll_structures'),
                new PermissionDefinition('payroll.structures.manage', 'payroll_structures', sensitive: true),
                new PermissionDefinition('payroll.salaries.view', 'payroll_salaries', scopable: true, sensitive: true),
                new PermissionDefinition('payroll.salaries.manage', 'payroll_salaries', scopable: true, sensitive: true),
                new PermissionDefinition('payroll.periods.view', 'payroll_periods'),
                new PermissionDefinition('payroll.periods.manage', 'payroll_periods'),
                new PermissionDefinition('payroll.runs.view', 'payroll_runs', sensitive: true),
                new PermissionDefinition('payroll.runs.prepare', 'payroll_runs', sensitive: true),
                new PermissionDefinition('payroll.runs.approve', 'payroll_runs', sensitive: true),
                new PermissionDefinition('payroll.runs.reverse', 'payroll_runs', sensitive: true),
                new PermissionDefinition('payroll.items.view', 'payroll_items', scopable: true, sensitive: true),
                new PermissionDefinition('payroll.items.manage', 'payroll_items', scopable: true, sensitive: true),
                new PermissionDefinition('payroll.loans.view', 'payroll_loans', scopable: true, sensitive: true),
                new PermissionDefinition('payroll.loans.manage', 'payroll_loans', scopable: true, sensitive: true),
                new PermissionDefinition('payroll.loans.approve', 'payroll_loans', sensitive: true),
                new PermissionDefinition('payroll.rules.view', 'payroll_rules'),
                new PermissionDefinition('payroll.rules.manage', 'payroll_rules', sensitive: true),
                new PermissionDefinition('payroll.payslips.view', 'payroll_payslips', scopable: true, sensitive: true),
                new PermissionDefinition('payroll.payments.record', 'payroll_payments', sensitive: true),
                new PermissionDefinition('payroll.payments.export', 'payroll_payments', sensitive: true),
                new PermissionDefinition('payroll.reports.view', 'payroll_reports', sensitive: true),
                new PermissionDefinition('payroll.settings.manage', 'payroll_settings', sensitive: true),
            ],
            navigation: [
                new NavigationItem('payrollPayslips', 'payroll.payslips.index', 'receipt', 'payroll.payslips.view', 'apps', 40, 'payroll.payslips.*'),
                new NavigationItem('payrollRuns', 'payroll.runs.index', 'calculator', 'payroll.runs.view', 'apps', 41, 'payroll.runs.*'),
                new NavigationItem('payrollPeriods', 'payroll.periods.index', 'calendar-range', 'payroll.periods.view', 'apps', 42, 'payroll.periods.*'),
                new NavigationItem('payrollSalaries', 'payroll.salaries.index', 'banknote', 'payroll.salaries.view', 'apps', 43, 'payroll.salaries.*'),
                new NavigationItem('payrollItems', 'payroll.items.index', 'gift', 'payroll.items.view', 'apps', 44, 'payroll.items.*'),
                new NavigationItem('payrollLoans', 'payroll.loans.index', 'hand-coins', 'payroll.loans.view', 'apps', 45, 'payroll.loans.*'),
                new NavigationItem('payrollReports', 'payroll.reports.index', 'chart-no-axes-combined', 'payroll.reports.view', 'apps', 46, 'payroll.reports.*'),
                new NavigationItem('payrollStructures', 'payroll.structures.index', 'layers', 'payroll.structures.view', 'apps', 900, 'payroll.structures.*'),
                new NavigationItem('payrollRules', 'payroll.rules.index', 'scale', 'payroll.rules.view', 'apps', 901, 'payroll.rules.*'),
                new NavigationItem('payrollSettings', 'payroll.settings.index', 'settings', 'payroll.settings.manage', 'apps', 902, 'payroll.settings.*'),
            ],
            widgets: [
                new WidgetDefinition('payroll.self', 'PayrollSelfWidget', PayrollSelfWidget::class, 'payroll.payslips.view', span: 4, order: 17),
                new WidgetDefinition('payroll.overview', 'PayrollOverviewWidget', PayrollOverviewWidget::class, 'payroll.runs.view', span: 8, order: 18),
            ],
            triggers: [
                RunApprovedTrigger::class,
                PayslipPaidTrigger::class,
                RunReversedTrigger::class,
            ],
            actions: [CreatePayItemAction::class, PrepareRunAction::class],
            morphMap: [
                'payroll_setting' => PayrollSetting::class,
                'payroll_structure' => SalaryStructure::class,
                'payroll_component' => StructureComponent::class,
                'payroll_profile' => Profile::class,
                'payroll_assignment' => SalaryAssignment::class,
                'payroll_period' => Period::class,
                'payroll_run' => Run::class,
                'payroll_payslip' => Payslip::class,
                'payroll_payslip_line' => PayslipLine::class,
                'payroll_payment' => Payment::class,
                'payroll_item' => Item::class,
                'payroll_loan' => Loan::class,
                'payroll_loan_installment' => LoanInstallment::class,
                'payroll_rule_set' => RuleSet::class,
                'payroll_rule_slab' => RuleSlab::class,
            ],
            limits: [
                // Payslips issued per billing period (counted when a run is approved).
                new LimitDefinition(RunManager::LIMIT, LimitKind::Metered),
            ],
            description: 'Salary structures, pay periods, payroll runs with four-eyes approval, payslips, loans, bonuses and configurable compliance rules.',
            category: AppCategory::HrmPayroll,
            icon: 'wallet',
            featured: true,
            features: [
                'Salary structures and immutable salary revision history',
                'Monthly or custom pay periods with attendance- and leave-based pay',
                'Overtime, allowances, festival bonuses, loans and advances',
                'Configurable tax and contribution slab tables (no hardcoded law)',
                'Run approval by a second person, locked payslip snapshots and reversals',
                'Printable bilingual payslips and bank payment sheets',
            ],
            offers: [new AppOffer('included', 'Included', AppBillingType::Free)],
            releaseNotes: 'Initial payroll release.',
            routes: [
                'payroll.payslips.index',
                'payroll.payslips.show',
                'payroll.payslips.document',
                'payroll.payments.store',
                'payroll.periods.index',
                'payroll.periods.show',
                'payroll.periods.store',
                'payroll.periods.update',
                'payroll.periods.destroy',
                'payroll.periods.close',
                'payroll.runs.index',
                'payroll.runs.show',
                'payroll.runs.store',
                'payroll.runs.adjustments.store',
                'payroll.runs.recalculate',
                'payroll.runs.submit',
                'payroll.runs.destroy',
                'payroll.runs.approve',
                'payroll.runs.return',
                'payroll.runs.reverse',
                'payroll.runs.payments.store',
                'payroll.runs.payment-sheet',
                'payroll.structures.index',
                'payroll.structures.show',
                'payroll.structures.store',
                'payroll.structures.update',
                'payroll.structures.components.store',
                'payroll.structures.components.update',
                'payroll.structures.components.destroy',
                'payroll.salaries.index',
                'payroll.salaries.show',
                'payroll.salaries.store',
                'payroll.salaries.revisions.store',
                'payroll.salaries.account.update',
                'payroll.items.index',
                'payroll.items.store',
                'payroll.items.update',
                'payroll.items.destroy',
                'payroll.loans.index',
                'payroll.loans.show',
                'payroll.loans.store',
                'payroll.loans.cancel',
                'payroll.loans.decision',
                'payroll.rules.index',
                'payroll.rules.show',
                'payroll.rules.store',
                'payroll.rules.update',
                'payroll.reports.index',
                'payroll.reports.export',
                'payroll.settings.index',
                'payroll.settings.update',
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');

        foreach (self::HRM_EVENTS as $event) {
            Event::listen($event, [RefreshEmployeePlacement::class, 'handle']);
        }
    }
}
