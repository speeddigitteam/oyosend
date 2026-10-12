<?php

declare(strict_types=1);

namespace App\Modules\Talent\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Modules\Talent\Actions\ChecklistManager;
use App\Modules\Talent\Actions\EmployeeLookup;
use App\Modules\Talent\Automation\ActionReceiptStore;
use App\Modules\Talent\Enums\ChecklistKind;
use App\Modules\Talent\Models\ChecklistTemplate;
use Illuminate\Validation\ValidationException;

/**
 * Starts an onboarding or offboarding checklist for an employee from a template (the default one
 * when none is named). The checklist's unique source key is derived from the idempotency key and a
 * durable receipt stores the output, so a retry returns the same checklist.
 */
final class StartChecklistAction implements ActionDefinition
{
    public function __construct(
        private readonly ChecklistManager $checklists,
        private readonly EmployeeLookup $employees,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'talent.checklist.start';
    }

    public static function label(): string
    {
        return 'automation.actions.talent.checklist.start';
    }

    public static function module(): string
    {
        return 'talent';
    }

    public static function requiredPermission(): string
    {
        return 'talent.onboarding.manage';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['employee_id', 'kind'],
            'properties' => [
                'employee_id' => ['type' => 'string'],
                'kind' => ['type' => 'string', 'enum' => ['onboarding', 'offboarding']],
                'template_id' => ['type' => ['string', 'null']],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['checklist_id'],
            'properties' => ['checklist_id' => ['type' => ['string', 'null']], 'task_count' => ['type' => 'integer']],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input, $idempotencyKey): array {
            $kind = ChecklistKind::tryFrom((string) ($input['kind'] ?? ''))
                ?? throw ValidationException::withMessages(['kind' => __('Choose onboarding or offboarding.')]);
            $employee = $this->employees->require((string) ($input['employee_id'] ?? ''), 'employee_id', allowTerminated: true);
            $templateId = isset($input['template_id']) && $input['template_id'] !== '' ? (string) $input['template_id'] : null;
            $template = $templateId === null ? null : ChecklistTemplate::query()->findOrFail($templateId);

            $checklist = $this->checklists->start($employee, $kind, $template, 'automation:'.$idempotencyKey);

            return [
                'checklist_id' => $checklist?->id,
                'task_count' => $checklist === null ? 0 : $checklist->tasks()->count(),
            ];
        });
    }
}
