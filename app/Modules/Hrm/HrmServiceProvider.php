<?php

declare(strict_types=1);

namespace App\Modules\Hrm;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\AppStore\ScheduledJobDefinition;
use App\Core\Billing\Enums\LimitKind;
use App\Core\Modules\AppOffer;
use App\Core\Modules\LimitDefinition;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Core\Modules\WidgetDefinition;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Workforce\Contracts\EmployeeDirectory;
use App\Core\Workforce\Contracts\EmployeeTimeSummary;
use App\Modules\Hrm\Actions\EmployeeManager;
use App\Modules\Hrm\Actions\EmployeeResolver;
use App\Modules\Hrm\Actions\WorkforceReader;
use App\Modules\Hrm\Automation\Actions\AssignManagerAction;
use App\Modules\Hrm\Automation\Actions\GrantLeaveBalanceAction;
use App\Modules\Hrm\Automation\Triggers\AttendanceDayClosedTrigger;
use App\Modules\Hrm\Automation\Triggers\EmployeeCreatedTrigger;
use App\Modules\Hrm\Automation\Triggers\EmployeeTerminatedTrigger;
use App\Modules\Hrm\Automation\Triggers\LeaveApprovedTrigger;
use App\Modules\Hrm\Automation\Triggers\LeaveRequestedTrigger;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Jobs\AccrueLeave;
use App\Modules\Hrm\Jobs\CloseAttendanceDays;
use App\Modules\Hrm\Models\AttendanceDay;
use App\Modules\Hrm\Models\AttendanceEntry;
use App\Modules\Hrm\Models\Designation;
use App\Modules\Hrm\Models\EmergencyContact;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAsset;
use App\Modules\Hrm\Models\EmployeeDocument;
use App\Modules\Hrm\Models\EmploymentHistory;
use App\Modules\Hrm\Models\Holiday;
use App\Modules\Hrm\Models\HrmSetting;
use App\Modules\Hrm\Models\LeaveApproval;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeavePolicy;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\Regularization;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Models\ShiftAssignment;
use App\Modules\Hrm\Reporting\ReportProviders;
use App\Modules\Hrm\Widgets\HrmOverviewWidget;
use App\Modules\Hrm\Widgets\HrmSelfWidget;

/**
 * Employee management, attendance and leave (category HR & Payroll).
 *
 * Payroll and Talent are separate apps that depend on this one and read it only through the
 * `App\Core\Workforce` contracts, never through these models. The module imports no
 * model from CRM, Projects or Invoicing.
 */
final class HrmServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->tag(ReportProviders::ALL, ReportProvider::TAG);

        $this->app->scoped(EmployeeResolver::class);
        $this->app->bind(EmployeeDirectory::class, WorkforceReader::class);
        $this->app->bind(EmployeeTimeSummary::class, WorkforceReader::class);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'hrm',
            name: 'HR Management',
            version: '1.0.0',
            dependencies: ['core'],
            permissions: [
                new PermissionDefinition('hrm.employees.view', 'hrm_employees', scopable: true),
                new PermissionDefinition('hrm.employees.create', 'hrm_employees'),
                new PermissionDefinition('hrm.employees.manage', 'hrm_employees', scopable: true),
                new PermissionDefinition('hrm.employees.lifecycle', 'hrm_employees', scopable: true, sensitive: true),
                new PermissionDefinition('hrm.personal.view', 'hrm_employees', scopable: true, sensitive: true),
                new PermissionDefinition('hrm.self.view', 'hrm_self'),
                new PermissionDefinition('hrm.attendance.view', 'hrm_attendance', scopable: true),
                new PermissionDefinition('hrm.attendance.manage', 'hrm_attendance', scopable: true, sensitive: true),
                new PermissionDefinition('hrm.attendance.clock', 'hrm_attendance'),
                new PermissionDefinition('hrm.attendance.regularize', 'hrm_attendance'),
                new PermissionDefinition('hrm.attendance.approve', 'hrm_attendance', scopable: true),
                new PermissionDefinition('hrm.leave.view', 'hrm_leave', scopable: true),
                new PermissionDefinition('hrm.leave.request', 'hrm_leave'),
                new PermissionDefinition('hrm.leave.approve', 'hrm_leave'),
                new PermissionDefinition('hrm.leave.finalize', 'hrm_leave', scopable: true, sensitive: true),
                new PermissionDefinition('hrm.leave.manage', 'hrm_leave', scopable: true, sensitive: true),
                new PermissionDefinition('hrm.holidays.view', 'hrm_calendar'),
                new PermissionDefinition('hrm.holidays.manage', 'hrm_calendar'),
                new PermissionDefinition('hrm.reports.view', 'hrm_reports'),
                new PermissionDefinition('hrm.settings.manage', 'hrm_settings', sensitive: true),
            ],
            navigation: [
                new NavigationItem('hrmMe', 'hrm.me', 'user-round', 'hrm.self.view', 'apps', 23, 'hrm.me'),
                new NavigationItem('hrmEmployees', 'hrm.employees.index', 'contact-round', 'hrm.employees.view', 'apps', 24, 'hrm.employees.*'),
                new NavigationItem('hrmAttendance', 'hrm.attendance.index', 'clock', 'hrm.attendance.view', 'apps', 25, 'hrm.attendance.*'),
                new NavigationItem('hrmLeave', 'hrm.leave.index', 'calendar-days', 'hrm.leave.view', 'apps', 26, 'hrm.leave.index'),
                new NavigationItem('hrmLeaveApprovals', 'hrm.leave.approvals', 'badge-check', 'hrm.leave.approve', 'apps', 27, 'hrm.leave.approvals'),
                new NavigationItem('hrmHolidays', 'hrm.holidays.index', 'calendar-check', 'hrm.holidays.view', 'apps', 28, 'hrm.holidays.*'),
                new NavigationItem('hrmReports', 'hrm.reports.index', 'chart-no-axes-combined', 'hrm.reports.view', 'apps', 29, 'hrm.reports.*'),
                new NavigationItem('hrmSettings', 'hrm.settings.index', 'settings', 'hrm.settings.manage', 'apps', 900, 'hrm.settings.*'),
            ],
            widgets: [
                new WidgetDefinition('hrm.self', 'HrmSelfWidget', HrmSelfWidget::class, 'hrm.self.view', span: 4, order: 15),
                new WidgetDefinition('hrm.overview', 'HrmOverviewWidget', HrmOverviewWidget::class, 'hrm.reports.view', span: 8, order: 16),
            ],
            triggers: [
                EmployeeCreatedTrigger::class,
                EmployeeTerminatedTrigger::class,
                LeaveRequestedTrigger::class,
                LeaveApprovedTrigger::class,
                AttendanceDayClosedTrigger::class,
            ],
            actions: [GrantLeaveBalanceAction::class, AssignManagerAction::class],
            morphMap: [
                'hrm_setting' => HrmSetting::class,
                'hrm_designation' => Designation::class,
                'hrm_employee' => Employee::class,
                'hrm_employment_history' => EmploymentHistory::class,
                'hrm_emergency_contact' => EmergencyContact::class,
                'hrm_document' => EmployeeDocument::class,
                'hrm_asset' => EmployeeAsset::class,
                'hrm_holiday' => Holiday::class,
                'hrm_shift' => Shift::class,
                'hrm_shift_assignment' => ShiftAssignment::class,
                'hrm_attendance_entry' => AttendanceEntry::class,
                'hrm_attendance_day' => AttendanceDay::class,
                'hrm_regularization' => Regularization::class,
                'hrm_leave_type' => LeaveType::class,
                'hrm_leave_policy' => LeavePolicy::class,
                'hrm_leave_request' => LeaveRequest::class,
                'hrm_leave_approval' => LeaveApproval::class,
                'hrm_leave_ledger_entry' => LeaveLedgerEntry::class,
            ],
            limits: [
                new LimitDefinition(
                    EmployeeManager::LIMIT,
                    LimitKind::Gauge,
                    static fn (): int => Employee::query()->where('status', '!=', EmployeeStatus::Terminated)->count(),
                ),
            ],
            description: 'Employee records, shifts and attendance, leave policies with approvals, and HR reports.',
            category: AppCategory::HrmPayroll,
            icon: 'users-round',
            featured: true,
            features: [
                'Employee records with reporting lines and an immutable history',
                'Shifts, clock in and out, regularization and exact attendance minutes',
                'Leave types, policies, half days and an append-only balance ledger',
                'Self-service, manager approvals and HR reports',
            ],
            offers: [new AppOffer('included', 'Included', AppBillingType::Free)],
            releaseNotes: 'Initial HR management release.',
            routes: [
                'hrm.me',
                'hrm.employees.index',
                'hrm.employees.create',
                'hrm.employees.store',
                'hrm.employees.show',
                'hrm.employees.update',
                'hrm.employees.status',
                'hrm.employees.contacts.store',
                'hrm.employees.contacts.update',
                'hrm.employees.contacts.destroy',
                'hrm.employees.documents.store',
                'hrm.employees.documents.download',
                'hrm.employees.documents.destroy',
                'hrm.employees.assets.store',
                'hrm.employees.assets.return',
                'hrm.attendance.index',
                'hrm.attendance.summary',
                'hrm.attendance.employee',
                'hrm.attendance.clock-in',
                'hrm.attendance.clock-out',
                'hrm.attendance.entries.store',
                'hrm.attendance.entries.destroy',
                'hrm.attendance.shift-assignments.store',
                'hrm.attendance.corrections.index',
                'hrm.attendance.corrections.store',
                'hrm.attendance.corrections.decision',
                'hrm.attendance.corrections.cancel',
                'hrm.leave.index',
                'hrm.leave.approvals',
                'hrm.leave.balances',
                'hrm.leave.calendar',
                'hrm.leave.requests.store',
                'hrm.leave.requests.show',
                'hrm.leave.requests.decision',
                'hrm.leave.requests.cancel',
                'hrm.leave.adjustments.store',
                'hrm.holidays.index',
                'hrm.holidays.store',
                'hrm.holidays.update',
                'hrm.holidays.destroy',
                'hrm.reports.index',
                'hrm.settings.index',
                'hrm.settings.update',
                'hrm.settings.designations.store',
                'hrm.settings.designations.update',
                'hrm.settings.shifts.store',
                'hrm.settings.shifts.update',
                'hrm.settings.leave-types.store',
                'hrm.settings.leave-types.update',
                'hrm.settings.leave-policies.store',
                'hrm.settings.leave-policies.update',
            ],
            scheduledJobs: [
                new ScheduledJobDefinition('hrm.attendance_close', CloseAttendanceDays::class, ScheduledJobFrequency::Hourly),
                new ScheduledJobDefinition('hrm.leave_accrual', AccrueLeave::class, ScheduledJobFrequency::Daily),
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');
    }
}
