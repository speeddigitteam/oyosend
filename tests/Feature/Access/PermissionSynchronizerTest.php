<?php

declare(strict_types=1);

use App\Core\Access\Models\Permission;
use App\Core\Access\Models\Role;
use App\Core\Access\PermissionSynchronizer;
use App\Core\Access\RoleManager;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures\Crm\CrmServiceProvider;

const DECLARED_BY_CORE = [
    'core.apps.manage',
    'core.apps.view',
    'core.audit.view',
    'core.billing.manage',
    'core.billing.view',
    'core.contacts.delete',
    'core.contacts.manage',
    'core.contacts.view',
    'core.members.invite',
    'core.members.manage',
    'core.members.view',
    'core.organization.manage',
    'core.organization.view',
    'core.roles.manage',
    'core.roles.view',
    'core.settings.manage',
];

const DECLARED_BY_CRM = [
    'crm.activities.edit',
    'crm.activities.view',
    'crm.contracts.manage',
    'crm.contracts.view',
    'crm.customers.manage',
    'crm.customers.view',
    'crm.deals.assign',
    'crm.deals.edit',
    'crm.deals.view',
    'crm.leads.assign',
    'crm.leads.edit',
    'crm.leads.qualify',
    'crm.leads.view',
    'crm.orders.manage',
    'crm.orders.view',
    'crm.products.manage',
    'crm.products.view',
    'crm.quotes.accept',
    'crm.quotes.manage',
    'crm.quotes.view',
    'crm.reports.view',
    'crm.settings.manage',
    'crm.targets.manage',
    'crm.targets.view',
];

const DECLARED_BY_PAYROLL = [
    'payroll.items.manage',
    'payroll.items.view',
    'payroll.loans.approve',
    'payroll.loans.manage',
    'payroll.loans.view',
    'payroll.payments.export',
    'payroll.payments.record',
    'payroll.payslips.view',
    'payroll.periods.manage',
    'payroll.periods.view',
    'payroll.reports.view',
    'payroll.rules.manage',
    'payroll.rules.view',
    'payroll.runs.approve',
    'payroll.runs.prepare',
    'payroll.runs.reverse',
    'payroll.runs.view',
    'payroll.salaries.manage',
    'payroll.salaries.view',
    'payroll.settings.manage',
    'payroll.structures.manage',
    'payroll.structures.view',
];

const DECLARED_BY_TALENT = [
    'talent.analytics.view',
    'talent.applications.hire',
    'talent.applications.manage',
    'talent.applications.view',
    'talent.candidates.manage',
    'talent.candidates.view',
    'talent.courses.manage',
    'talent.courses.view',
    'talent.cycles.manage',
    'talent.enrolments.manage',
    'talent.enrolments.view',
    'talent.goals.manage',
    'talent.goals.view',
    'talent.interviews.feedback',
    'talent.interviews.manage',
    'talent.interviews.view',
    'talent.offers.manage',
    'talent.onboarding.manage',
    'talent.onboarding.view',
    'talent.openings.create',
    'talent.openings.manage',
    'talent.openings.view',
    'talent.probation.manage',
    'talent.probation.view',
    'talent.reviews.calibrate',
    'talent.reviews.submit',
    'talent.reviews.view',
    'talent.self.view',
    'talent.settings.manage',
    'talent.tasks.complete',
];

const DECLARED_BY_AI = [
    'ai.assistant.use',
    'ai.drafts.generate',
    'ai.proposals.confirm',
    'ai.settings.manage',
    'ai.usage.view',
];

const DECLARED_BY_AUTOMATION = [
    'automation.approvals.decide',
    'automation.runs.manage',
    'automation.runs.start',
    'automation.runs.view',
    'automation.webhooks.manage',
    'automation.workflows.manage',
    'automation.workflows.publish',
    'automation.workflows.view',
];

const DECLARED_BY_COMMUNICATION = [
    'communication.audience.view',
    'communication.consent.manage',
    'communication.inbox.view',
    'communication.messages.send',
    'communication.messages.send_transactional',
    'communication.messages.view',
    'communication.notifications.view',
    'communication.settings.manage',
    'communication.suppressions.manage',
    'communication.templates.manage',
    'communication.templates.view',
    'communication.timeline.log',
    'communication.timeline.view',
];

const DECLARED_BY_REPORTS = [
    'reports.reports.export',
    'reports.reports.view',
    'reports.saved.manage',
    'reports.saved.manage_all',
];

const DECLARED_BY_META_ADS = [
    'meta_ads.accounts.manage',
    'meta_ads.accounts.view',
    'meta_ads.campaigns.manage',
    'meta_ads.campaigns.view',
    'meta_ads.insights.export',
    'meta_ads.insights.view',
    'meta_ads.settings.manage',
];

const DECLARED_BY_NOTES = [
    'notes.notes.manage',
    'notes.notes.view',
];

const DECLARED_BY_SOCIAL = [
    'social.accounts.manage',
    'social.accounts.view',
    'social.analytics.view',
    'social.comments.manage_rules',
    'social.comments.moderate',
    'social.comments.reply',
    'social.comments.view',
    'social.imports.create',
    'social.leadads.manage',
    'social.leadads.view',
    'social.library.manage',
    'social.library.view',
    'social.media.manage',
    'social.media.view',
    'social.messages.reply',
    'social.messages.view',
    'social.posts.ai_assist',
    'social.posts.approve',
    'social.posts.comment',
    'social.posts.create',
    'social.posts.delete',
    'social.posts.edit',
    'social.posts.schedule',
    'social.posts.view',
    'social.settings.manage',
];

const DECLARED_BY_SUPPORT = [
    'support.canned.manage',
    'support.canned.view',
    'support.kb.manage',
    'support.kb.publish',
    'support.kb.view',
    'support.queues.manage',
    'support.reports.view',
    'support.settings.manage',
    'support.sla.manage',
    'support.tickets.assign',
    'support.tickets.create',
    'support.tickets.edit',
    'support.tickets.merge',
    'support.tickets.reply',
    'support.tickets.resolve',
    'support.tickets.view',
];

const DECLARED_BY_EMAIL_MARKETING = [
    'email_marketing.campaigns.create',
    'email_marketing.campaigns.delete',
    'email_marketing.campaigns.edit',
    'email_marketing.campaigns.send',
    'email_marketing.campaigns.view',
    'email_marketing.lists.manage',
    'email_marketing.lists.view',
    'email_marketing.reports.view',
    'email_marketing.sequences.activate',
    'email_marketing.sequences.manage',
    'email_marketing.sequences.view',
    'email_marketing.settings.manage',
    'email_marketing.subscribers.export',
    'email_marketing.subscribers.import',
    'email_marketing.subscribers.manage',
    'email_marketing.subscribers.view',
    'email_marketing.templates.manage',
    'email_marketing.templates.view',
];

const DECLARED_BY_SMS_MARKETING = [
    'sms_marketing.analytics.view',
    'sms_marketing.campaigns.create',
    'sms_marketing.campaigns.delete',
    'sms_marketing.campaigns.edit',
    'sms_marketing.campaigns.launch',
    'sms_marketing.campaigns.view',
    'sms_marketing.groups.manage',
    'sms_marketing.groups.view',
    'sms_marketing.senders.manage',
    'sms_marketing.senders.view',
    'sms_marketing.settings.manage',
    'sms_marketing.templates.manage',
    'sms_marketing.templates.view',
];

const DECLARED_BY_WHATSAPP_MARKETING = [
    'whatsapp_marketing.analytics.view',
    'whatsapp_marketing.campaigns.manage',
    'whatsapp_marketing.campaigns.send',
    'whatsapp_marketing.campaigns.view',
    'whatsapp_marketing.conversations.assign',
    'whatsapp_marketing.conversations.manage',
    'whatsapp_marketing.conversations.reply',
    'whatsapp_marketing.conversations.view',
    'whatsapp_marketing.flows.manage',
    'whatsapp_marketing.lists.manage',
    'whatsapp_marketing.lists.view',
    'whatsapp_marketing.quick_replies.manage',
    'whatsapp_marketing.quick_replies.view',
    'whatsapp_marketing.settings.manage',
    'whatsapp_marketing.templates.manage',
    'whatsapp_marketing.templates.view',
];

const DECLARED_BY_HRM = [
    'hrm.attendance.approve',
    'hrm.attendance.clock',
    'hrm.attendance.manage',
    'hrm.attendance.regularize',
    'hrm.attendance.view',
    'hrm.employees.create',
    'hrm.employees.lifecycle',
    'hrm.employees.manage',
    'hrm.employees.view',
    'hrm.holidays.manage',
    'hrm.holidays.view',
    'hrm.leave.approve',
    'hrm.leave.finalize',
    'hrm.leave.manage',
    'hrm.leave.request',
    'hrm.leave.view',
    'hrm.personal.view',
    'hrm.reports.view',
    'hrm.self.view',
    'hrm.settings.manage',
];

const DECLARED_BY_INVOICING = [
    'invoicing.credit_notes.issue',
    'invoicing.invoices.edit',
    'invoicing.invoices.issue',
    'invoicing.invoices.send',
    'invoicing.invoices.view',
    'invoicing.invoices.void',
    'invoicing.payments.record',
    'invoicing.payments.reverse',
    'invoicing.recurring.manage',
    'invoicing.recurring.view',
    'invoicing.reports.view',
    'invoicing.settings.manage',
];

const DECLARED_BY_PROJECTS = [
    'projects.approvals.decide',
    'projects.approvals.request',
    'projects.approvals.view',
    'projects.expenses.approve',
    'projects.expenses.manage',
    'projects.expenses.view',
    'projects.projects.edit',
    'projects.projects.manage_financials',
    'projects.projects.manage_members',
    'projects.projects.view',
    'projects.reports.view',
    'projects.settings.manage',
    'projects.tasks.assign',
    'projects.tasks.comment',
    'projects.tasks.edit',
    'projects.tasks.view',
    'projects.time.approve',
    'projects.time.log',
    'projects.time.view',
];

beforeEach(function () {
    $this->synchronizer = app(PermissionSynchronizer::class);
    $this->names = fn (): array => Permission::query()->orderBy('name')->pluck('name')->all();
});

it('creates the permissions the registered modules declare', function () {
    expect(Permission::query()->count())->toBe(0);

    $result = $this->synchronizer->sync();

    sort($result['created']);

    expect($result['created'])->toBe([...DECLARED_BY_AI, ...DECLARED_BY_AUTOMATION, ...DECLARED_BY_COMMUNICATION, ...DECLARED_BY_CORE, ...DECLARED_BY_CRM, ...DECLARED_BY_EMAIL_MARKETING, ...DECLARED_BY_HRM, ...DECLARED_BY_INVOICING, ...DECLARED_BY_META_ADS, ...DECLARED_BY_NOTES, ...DECLARED_BY_PAYROLL, ...DECLARED_BY_PROJECTS, ...DECLARED_BY_REPORTS, ...DECLARED_BY_SMS_MARKETING, ...DECLARED_BY_SOCIAL, ...DECLARED_BY_SUPPORT, ...DECLARED_BY_TALENT, ...DECLARED_BY_WHATSAPP_MARKETING])
        ->and($result['stale'])->toBe([])
        ->and($result['pruned'])->toBe([])
        ->and(($this->names)())->toBe([...DECLARED_BY_AI, ...DECLARED_BY_AUTOMATION, ...DECLARED_BY_COMMUNICATION, ...DECLARED_BY_CORE, ...DECLARED_BY_CRM, ...DECLARED_BY_EMAIL_MARKETING, ...DECLARED_BY_HRM, ...DECLARED_BY_INVOICING, ...DECLARED_BY_META_ADS, ...DECLARED_BY_NOTES, ...DECLARED_BY_PAYROLL, ...DECLARED_BY_PROJECTS, ...DECLARED_BY_REPORTS, ...DECLARED_BY_SMS_MARKETING, ...DECLARED_BY_SOCIAL, ...DECLARED_BY_SUPPORT, ...DECLARED_BY_TALENT, ...DECLARED_BY_WHATSAPP_MARKETING])
        ->and(Permission::query()->where('guard_name', 'web')->where('module', 'core')->count())->toBe(count(DECLARED_BY_CORE))
        ->and(Permission::query()->where('guard_name', 'web')->where('module', 'crm')->count())->toBe(count(DECLARED_BY_CRM))
        ->and(Permission::query()->where('guard_name', 'web')->where('module', 'hrm')->count())->toBe(count(DECLARED_BY_HRM))
        ->and(Permission::query()->where('guard_name', 'web')->where('module', 'invoicing')->count())->toBe(count(DECLARED_BY_INVOICING))
        ->and(Permission::query()->where('guard_name', 'web')->where('module', 'projects')->count())->toBe(count(DECLARED_BY_PROJECTS));
});

it('is idempotent', function () {
    $this->synchronizer->sync();
    $ids = Permission::query()->orderBy('name')->pluck('id', 'name')->all();

    $again = $this->synchronizer->sync();

    expect($again)->toBe(['created' => [], 'stale' => [], 'pruned' => []])
        // Same rows, same ids: grants that point at them stay valid.
        ->and(Permission::query()->orderBy('name')->pluck('id', 'name')->all())->toBe($ids);
});

it('picks up the permissions of a module added later', function () {
    $this->synchronizer->sync();

    $this->app->register(CrmServiceProvider::class);

    $result = $this->synchronizer->sync();
    sort($result['created']);

    expect($result['created'])->toBe(['test_crm.leads.edit', 'test_crm.leads.view'])
        ->and(Permission::query()->where('module', 'test_crm')->count())->toBe(2)
        ->and(Permission::query()->where('module', 'crm')->count())->toBe(count(DECLARED_BY_CRM))
        ->and(Permission::query()->where('module', 'core')->count())->toBe(count(DECLARED_BY_CORE))
        ->and(Permission::query()->where('module', 'projects')->count())->toBe(count(DECLARED_BY_PROJECTS));
});

it('corrects the module a permission is filed under', function () {
    $this->synchronizer->sync();
    Permission::query()->where('name', 'core.audit.view')->update(['module' => 'somewhere-else']);

    $this->synchronizer->sync();

    expect(Permission::query()->where('name', 'core.audit.view')->value('module'))->toBe('core');
});

describe('permissions no module declares any more', function () {
    beforeEach(function () {
        $this->synchronizer->sync();

        $this->acme = $this->createTenant('acme');
        $this->globex = $this->createTenant('globex');

        // A permission of a module that has since been removed, still granted in two tenants.
        $this->legacy = Permission::query()->create(['name' => 'legacy.reports.view', 'guard_name' => 'web', 'module' => 'legacy']);

        foreach ([$this->acme, $this->globex] as $tenant) {
            DB::table('role_has_permissions')->insert([
                'permission_id' => $this->legacy->id,
                'role_id' => $this->roleOf($tenant, Role::MANAGER)->id,
                'scope' => 'all',
            ]);
        }
    });

    it('are reported as stale and left alone by default', function () {
        $result = $this->synchronizer->sync();

        expect($result)->toBe(['created' => [], 'stale' => ['legacy.reports.view'], 'pruned' => []])
            ->and(Permission::query()->whereKey($this->legacy->id)->exists())->toBeTrue()
            ->and(DB::table('role_has_permissions')->where('permission_id', $this->legacy->id)->count())->toBe(2);
    });

    it('are removed with every grant of them, in every tenant, when pruning is asked for', function () {
        $result = $this->synchronizer->sync(prune: true);

        expect($result)->toBe(['created' => [], 'stale' => ['legacy.reports.view'], 'pruned' => ['legacy.reports.view']])
            ->and(Permission::query()->whereKey($this->legacy->id)->exists())->toBeFalse()
            ->and(DB::table('role_has_permissions')->where('permission_id', $this->legacy->id)->count())->toBe(0)
            // Everything still declared survives, grants included.
            ->and(($this->names)())->toBe([...DECLARED_BY_AI, ...DECLARED_BY_AUTOMATION, ...DECLARED_BY_COMMUNICATION, ...DECLARED_BY_CORE, ...DECLARED_BY_CRM, ...DECLARED_BY_EMAIL_MARKETING, ...DECLARED_BY_HRM, ...DECLARED_BY_INVOICING, ...DECLARED_BY_META_ADS, ...DECLARED_BY_NOTES, ...DECLARED_BY_PAYROLL, ...DECLARED_BY_PROJECTS, ...DECLARED_BY_REPORTS, ...DECLARED_BY_SMS_MARKETING, ...DECLARED_BY_SOCIAL, ...DECLARED_BY_SUPPORT, ...DECLARED_BY_TALENT, ...DECLARED_BY_WHATSAPP_MARKETING])
            ->and(app(RoleManager::class)->grantedNames($this->roleOf($this->acme, Role::MANAGER)))
            ->toBe(['core.apps.view', 'core.contacts.manage', 'core.contacts.view', 'core.members.invite', 'core.members.view', 'core.organization.view', 'core.roles.view']);
    });

    it('never touch permissions of another guard', function () {
        $foreign = Permission::query()->create(['name' => 'api.tokens.issue', 'guard_name' => 'api', 'module' => 'api']);

        $result = $this->synchronizer->sync(prune: true);

        expect($result['stale'])->toBe(['legacy.reports.view'])
            ->and(Permission::query()->whereKey($foreign->id)->exists())->toBeTrue();
    });
});

describe('php artisan permissions:sync', function () {
    it('reports what it added', function () {
        $declared = [...DECLARED_BY_AI, ...DECLARED_BY_AUTOMATION, ...DECLARED_BY_COMMUNICATION, ...DECLARED_BY_CORE, ...DECLARED_BY_CRM, ...DECLARED_BY_EMAIL_MARKETING, ...DECLARED_BY_HRM, ...DECLARED_BY_INVOICING, ...DECLARED_BY_META_ADS, ...DECLARED_BY_NOTES, ...DECLARED_BY_PAYROLL, ...DECLARED_BY_PROJECTS, ...DECLARED_BY_REPORTS, ...DECLARED_BY_SMS_MARKETING, ...DECLARED_BY_SOCIAL, ...DECLARED_BY_SUPPORT, ...DECLARED_BY_TALENT, ...DECLARED_BY_WHATSAPP_MARKETING];

        $this->artisan('permissions:sync')
            ->expectsOutputToContain(count($declared).' permission(s) added.')
            ->expectsOutputToContain('core.members.view')
            ->assertSuccessful();

        expect(($this->names)())->toBe($declared);

        $this->artisan('permissions:sync')->expectsOutputToContain('0 permission(s) added.')->assertSuccessful();
    });

    it('warns about stale permissions and removes them only with --prune', function () {
        $this->synchronizer->sync();
        Permission::query()->create(['name' => 'legacy.reports.view', 'guard_name' => 'web', 'module' => 'legacy']);

        $this->artisan('permissions:sync')
            ->expectsOutputToContain('1 permission(s) are no longer declared by any module. Re-run with --prune to remove them.')
            ->expectsOutputToContain('legacy.reports.view')
            ->assertSuccessful();

        expect(Permission::query()->where('name', 'legacy.reports.view')->exists())->toBeTrue();

        $this->artisan('permissions:sync', ['--prune' => true])
            ->expectsOutputToContain('legacy.reports.view')
            ->doesntExpectOutputToContain('Re-run with --prune')
            ->assertSuccessful();

        expect(($this->names)())->toBe([...DECLARED_BY_AI, ...DECLARED_BY_AUTOMATION, ...DECLARED_BY_COMMUNICATION, ...DECLARED_BY_CORE, ...DECLARED_BY_CRM, ...DECLARED_BY_EMAIL_MARKETING, ...DECLARED_BY_HRM, ...DECLARED_BY_INVOICING, ...DECLARED_BY_META_ADS, ...DECLARED_BY_NOTES, ...DECLARED_BY_PAYROLL, ...DECLARED_BY_PROJECTS, ...DECLARED_BY_REPORTS, ...DECLARED_BY_SMS_MARKETING, ...DECLARED_BY_SOCIAL, ...DECLARED_BY_SUPPORT, ...DECLARED_BY_TALENT, ...DECLARED_BY_WHATSAPP_MARKETING]);
    });
});
