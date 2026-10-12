<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Talent\Actions\ApplicationManager;
use App\Modules\Talent\Actions\CandidateManager;
use App\Modules\Talent\Actions\ChecklistManager;
use App\Modules\Talent\Actions\OpeningManager;
use App\Modules\Talent\Actions\RecruitmentCatalogue;
use App\Modules\Talent\Actions\ReviewCycleManager;
use App\Modules\Talent\Models\Application;
use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\ChecklistTemplate;
use App\Modules\Talent\Models\JobOpening;
use App\Modules\Talent\Models\Review;
use App\Modules\Talent\Models\ReviewCycle;
use App\Modules\Talent\Models\ReviewForm;
use Tests\TestCase;

/**
 * Building blocks for Talent tests. Combine with {@see InteractsWithHrm} (`uses(InteractsWithHrm::class,
 * InteractsWithTalent::class)`): employees, role grants and the HRM clock come from there.
 *
 * @mixin TestCase
 */
trait InteractsWithTalent
{
    protected function talentTenant(string $slug = 'talent-ws'): Tenant
    {
        $tenant = $this->createTenant($slug, ['timezone' => 'Asia/Dhaka', 'currency' => 'BDT']);
        $this->entitle($tenant, 'hrm', 'talent');

        return $tenant;
    }

    protected function talentUrl(Tenant $tenant, string $path): string
    {
        return $this->tenantUrl($tenant, '/talent'.$path);
    }

    protected function membershipFor(Tenant $tenant, User $user): TenantMembership
    {
        return $this->membershipOf($tenant, $user);
    }

    /** @param array<string, mixed> $overrides */
    protected function talentOpening(Tenant $tenant, array $overrides = [], string $status = 'open'): JobOpening
    {
        return $this->inTenant($tenant, function () use ($overrides, $status): JobOpening {
            $owner = TenantMembership::query()->where('is_owner', true)->firstOrFail();

            return app(OpeningManager::class)->create([
                'title' => 'Backend Engineer',
                'headcount' => 1,
                'status' => $status,
                ...$overrides,
            ], $owner);
        });
    }

    /** @param array<string, mixed> $overrides */
    protected function talentCandidate(Tenant $tenant, array $overrides = []): Candidate
    {
        return $this->inTenant($tenant, function () use ($overrides): Candidate {
            $owner = TenantMembership::query()->where('is_owner', true)->firstOrFail();

            return app(CandidateManager::class)->create([
                'first_name' => 'Tania',
                'last_name' => 'Islam',
                'email' => 'tania@example.test',
                ...$overrides,
            ], $owner, (bool) ($overrides['confirm_duplicate'] ?? false));
        });
    }

    protected function talentApplication(Tenant $tenant, ?JobOpening $opening = null, ?Candidate $candidate = null): Application
    {
        $opening ??= $this->talentOpening($tenant);
        $candidate ??= $this->talentCandidate($tenant);

        return $this->inTenant($tenant, fn (): Application => app(ApplicationManager::class)->apply($opening, $candidate, [], null));
    }

    /** @param list<array<string, mixed>> $tasks */
    protected function talentTemplate(Tenant $tenant, string $kind = 'onboarding', array $tasks = [], bool $default = true): ChecklistTemplate
    {
        $tasks = $tasks === [] ? [
            ['title' => 'Prepare laptop', 'assignee_role' => 'hr', 'due_offset_days' => -2],
            ['title' => 'Welcome meeting', 'assignee_role' => 'manager', 'due_offset_days' => 0],
            ['title' => 'Read the handbook', 'assignee_role' => 'employee', 'due_offset_days' => 5],
        ] : $tasks;

        return $this->inTenant($tenant, fn (): ChecklistTemplate => app(ChecklistManager::class)->saveTemplate(null, [
            'name' => ucfirst($kind).' standard',
            'kind' => $kind,
            'is_default' => $default,
            'is_active' => true,
            'tasks' => $tasks,
        ]));
    }

    /** Two competencies weighted 60% / 40%, scale 1..5. */
    protected function talentForm(Tenant $tenant, int $scale = 5): ReviewForm
    {
        return $this->inTenant($tenant, fn (): ReviewForm => app(ReviewCycleManager::class)->saveForm(null, [
            'name' => 'Annual form '.bin2hex(random_bytes(3)),
            'scale_max' => $scale,
            'is_active' => true,
            'items' => [
                ['name' => 'Delivery', 'weight_bp' => 6000],
                ['name' => 'Teamwork', 'weight_bp' => 4000],
            ],
        ]));
    }

    protected function talentCycle(Tenant $tenant, ?ReviewForm $form = null, bool $requiresSelf = true, ?string $startsOn = null): ReviewCycle
    {
        $form ??= $this->talentForm($tenant);

        return $this->inTenant($tenant, fn (): ReviewCycle => app(ReviewCycleManager::class)->saveCycle(null, [
            'name' => 'FY26 review',
            'form_id' => $form->id,
            'starts_on' => $startsOn ?? '2026-03-01',
            'ends_on' => '2026-06-30',
            'requires_self_review' => $requiresSelf,
        ], null));
    }

    /**
     * A manager and a report (both employees), a launched cycle, and the form's item ids.
     *
     * @return array{0: Employee, 1: Employee, 2: ReviewCycle, 3: Review, 4: list<string>}
     */
    protected function talentReviewSetup(Tenant $tenant, bool $requiresSelf = true): array
    {
        $manager = $this->hire($tenant, ['first_name' => 'Mona', 'joined_on' => '2025-01-01']);
        $employee = $this->hire($tenant, ['first_name' => 'Emon', 'manager_id' => $manager->id, 'joined_on' => '2025-06-01']);
        $form = $this->talentForm($tenant);
        $cycle = $this->talentCycle($tenant, $form, $requiresSelf);
        $this->inTenant($tenant, fn () => app(ReviewCycleManager::class)->launch($cycle, null));
        $items = $this->inTenant($tenant, fn () => $form->items()->orderBy('position')->pluck('id')->all());
        $review = $this->inTenant($tenant, fn () => Review::query()->where('employee_id', $employee->id)->sole());

        return [$manager, $employee, $cycle, $review, $items];
    }

    protected function pipelineStage(Tenant $tenant, string $name): string
    {
        return $this->inTenant($tenant, function () use ($name): string {
            $pipeline = app(RecruitmentCatalogue::class)->defaultPipeline();

            return $pipeline->stages()->where('name', $name)->firstOrFail()->id;
        });
    }
}
