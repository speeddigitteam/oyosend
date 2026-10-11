<?php

declare(strict_types=1);

namespace App\Modules\Projects;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\AppStore\ScheduledJobDefinition;
use App\Core\Billing\Enums\LimitKind;
use App\Core\Contacts\Contracts\ContactMergeReference;
use App\Core\Modules\AppOffer;
use App\Core\Modules\LimitDefinition;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Core\Modules\WidgetDefinition;
use App\Modules\Projects\Actions\ProjectsContactMergeReference;
use App\Modules\Projects\Automation\Actions\AssignTaskAction;
use App\Modules\Projects\Automation\Actions\CreateProjectAction;
use App\Modules\Projects\Automation\Actions\CreateTaskAction;
use App\Modules\Projects\Automation\Triggers\MilestoneCompletedTrigger;
use App\Modules\Projects\Automation\Triggers\ProjectCreatedTrigger;
use App\Modules\Projects\Automation\Triggers\TaskCompletedTrigger;
use App\Modules\Projects\Automation\Triggers\TaskCreatedTrigger;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Jobs\MaintainProjectTasks;
use App\Modules\Projects\Models\ActivityHistory;
use App\Modules\Projects\Models\Approval;
use App\Modules\Projects\Models\Attachment;
use App\Modules\Projects\Models\ChecklistItem;
use App\Modules\Projects\Models\ClientAccess;
use App\Modules\Projects\Models\CommentMention;
use App\Modules\Projects\Models\Expense;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectCategory;
use App\Modules\Projects\Models\ProjectMember;
use App\Modules\Projects\Models\ProjectTemplate;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskAssignee;
use App\Modules\Projects\Models\TaskComment;
use App\Modules\Projects\Models\TaskDependency;
use App\Modules\Projects\Models\TaskWatcher;
use App\Modules\Projects\Models\TimeEntry;
use App\Modules\Projects\Widgets\ProjectsOverviewWidget;

/** First-party project delivery, work planning, and time-cost management module. */
final class ProjectsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->tag(ProjectsContactMergeReference::class, ContactMergeReference::class);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'projects',
            name: 'Project Management',
            version: '1.0.0',
            dependencies: ['core'],
            permissions: [
                new PermissionDefinition('projects.projects.view', 'projects_projects', scopable: true),
                new PermissionDefinition('projects.projects.edit', 'projects_projects', scopable: true),
                new PermissionDefinition('projects.projects.manage_members', 'projects_projects', scopable: true),
                new PermissionDefinition('projects.projects.manage_financials', 'projects_projects', scopable: true, sensitive: true),
                new PermissionDefinition('projects.tasks.view', 'projects_tasks', scopable: true),
                new PermissionDefinition('projects.tasks.edit', 'projects_tasks', scopable: true),
                new PermissionDefinition('projects.tasks.assign', 'projects_tasks', scopable: true),
                new PermissionDefinition('projects.tasks.comment', 'projects_tasks', scopable: true),
                new PermissionDefinition('projects.time.view', 'projects_time', scopable: true),
                new PermissionDefinition('projects.time.log', 'projects_time', scopable: true),
                new PermissionDefinition('projects.time.approve', 'projects_time', scopable: true, sensitive: true),
                new PermissionDefinition('projects.expenses.view', 'projects_expenses', scopable: true),
                new PermissionDefinition('projects.expenses.manage', 'projects_expenses', scopable: true),
                new PermissionDefinition('projects.expenses.approve', 'projects_expenses', scopable: true, sensitive: true),
                new PermissionDefinition('projects.approvals.view', 'projects_approvals'),
                new PermissionDefinition('projects.approvals.request', 'projects_approvals'),
                new PermissionDefinition('projects.approvals.decide', 'projects_approvals', sensitive: true),
                new PermissionDefinition('projects.reports.view', 'projects_reports'),
                new PermissionDefinition('projects.settings.manage', 'projects_settings', sensitive: true),
            ],
            navigation: [
                new NavigationItem('projects', 'projects.index', 'folder-kanban', 'projects.projects.view', 'apps', 15, 'projects.index'),
                new NavigationItem('projectTime', 'projects.time.index', 'clock', 'projects.time.view', 'apps', 16, 'projects.time.*'),
                new NavigationItem('projectExpenses', 'projects.expenses.index', 'receipt', 'projects.expenses.view', 'apps', 17, 'projects.expenses.*'),
                new NavigationItem('projectApprovals', 'projects.approvals.index', 'badge-check', 'projects.approvals.view', 'apps', 18, 'projects.approvals.*', ['projects.approvals.request', 'projects.approvals.decide']),
                new NavigationItem('projectReports', 'projects.reports.index', 'chart-no-axes-combined', 'projects.reports.view', 'apps', 19, 'projects.reports.*'),
                new NavigationItem('projectSettings', 'projects.settings.index', 'settings', 'projects.settings.manage', 'manage', 36, 'projects.settings.*'),
            ],
            widgets: [
                new WidgetDefinition('projects.overview', 'ProjectsOverviewWidget', ProjectsOverviewWidget::class, 'projects.projects.view', span: 5, order: 14),
            ],
            triggers: [ProjectCreatedTrigger::class, TaskCreatedTrigger::class, TaskCompletedTrigger::class, MilestoneCompletedTrigger::class],
            actions: [CreateProjectAction::class, CreateTaskAction::class, AssignTaskAction::class],
            morphMap: [
                'project_category' => ProjectCategory::class,
                'project_template' => ProjectTemplate::class,
                'project' => Project::class,
                'project_member' => ProjectMember::class,
                'project_sprint' => Sprint::class,
                'project_milestone' => Milestone::class,
                'project_task' => Task::class,
                'project_task_assignee' => TaskAssignee::class,
                'project_task_watcher' => TaskWatcher::class,
                'project_task_dependency' => TaskDependency::class,
                'project_checklist_item' => ChecklistItem::class,
                'project_task_comment' => TaskComment::class,
                'project_comment_mention' => CommentMention::class,
                'project_attachment' => Attachment::class,
                'project_time_entry' => TimeEntry::class,
                'project_expense' => Expense::class,
                'project_approval' => Approval::class,
                'project_client_access' => ClientAccess::class,
                'project_activity_history' => ActivityHistory::class,
            ],
            limits: [
                new LimitDefinition('projects.active_projects', LimitKind::Gauge, static fn (): int => Project::query()->whereNotIn('status', [ProjectStatus::Completed, ProjectStatus::Cancelled])->count()),
            ],
            description: 'Plan client work, collaborate on tasks, track time and control project costs.',
            category: AppCategory::ProjectManagement,
            icon: 'folder-kanban',
            featured: true,
            features: ['Projects, templates and visual work planning', 'Tasks, dependencies, sprints and milestones', 'Time, workload, budgets and expenses', 'Approvals and controlled client collaboration'],
            offers: [new AppOffer('included', 'Included', AppBillingType::Free)],
            releaseNotes: 'Initial project management release.',
            routes: self::routes(),
            scheduledJobs: [new ScheduledJobDefinition('projects.task_maintenance', MaintainProjectTasks::class, ScheduledJobFrequency::EveryMinute)],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');
    }

    /** @return list<string> */
    private static function routes(): array
    {
        return [
            'projects.index', 'projects.store', 'projects.show', 'projects.update', 'projects.status',
            'projects.members.store', 'projects.members.destroy',
            'projects.tasks.store', 'projects.tasks.update', 'projects.tasks.status', 'projects.tasks.assign', 'projects.tasks.watch', 'projects.tasks.unwatch',
            'projects.tasks.comments.store', 'projects.tasks.checklists.store', 'projects.tasks.checklists.update', 'projects.tasks.dependencies.store',
            'projects.milestones.store', 'projects.milestones.status', 'projects.sprints.store', 'projects.sprints.status',
            'projects.attachments.store', 'projects.attachments.download', 'projects.attachments.destroy',
            'projects.time.index', 'projects.task-options.index', 'projects.time.store', 'projects.time.submit', 'projects.time.approve',
            'projects.expenses.index', 'projects.expenses.store', 'projects.expenses.submit', 'projects.expenses.approve',
            'projects.approvals.index', 'projects.approvals.store', 'projects.approvals.decide', 'projects.approvals.cancel',
            'projects.client-access.store', 'projects.client-access.destroy',
            'projects.portal.show', 'projects.portal.comments.store',
            'projects.reports.index', 'projects.settings.index', 'projects.settings.categories.store', 'projects.settings.templates.store',
        ];
    }
}
