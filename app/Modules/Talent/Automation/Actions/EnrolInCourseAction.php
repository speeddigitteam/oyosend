<?php

declare(strict_types=1);

namespace App\Modules\Talent\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Modules\Talent\Actions\EmployeeLookup;
use App\Modules\Talent\Actions\TrainingManager;
use App\Modules\Talent\Automation\ActionReceiptStore;
use App\Modules\Talent\Models\Course;
use App\Modules\Talent\Models\TrainingSession;

/**
 * Enrols an employee in a course (optionally in one scheduled session). The durable receipt makes a
 * retry return the first enrolment instead of failing on "already enrolled".
 */
final class EnrolInCourseAction implements ActionDefinition
{
    public function __construct(
        private readonly TrainingManager $training,
        private readonly EmployeeLookup $employees,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'talent.course.enrol';
    }

    public static function label(): string
    {
        return 'automation.actions.talent.course.enrol';
    }

    public static function module(): string
    {
        return 'talent';
    }

    public static function requiredPermission(): string
    {
        return 'talent.enrolments.manage';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['employee_id', 'course_id'],
            'properties' => [
                'employee_id' => ['type' => 'string'],
                'course_id' => ['type' => 'string'],
                'session_id' => ['type' => ['string', 'null']],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['enrolment_id'],
            'properties' => ['enrolment_id' => ['type' => 'string'], 'course_id' => ['type' => 'string'], 'employee_id' => ['type' => 'string']],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input): array {
            $employee = $this->employees->require((string) ($input['employee_id'] ?? ''));
            $course = Course::query()->findOrFail((string) ($input['course_id'] ?? ''));
            $sessionId = isset($input['session_id']) && $input['session_id'] !== '' ? (string) $input['session_id'] : null;
            $session = $sessionId === null ? null : TrainingSession::query()->findOrFail($sessionId);
            $enrolment = $this->training->enrol($course, $employee, $session, null);

            return ['enrolment_id' => $enrolment->id, 'course_id' => $course->id, 'employee_id' => $employee->id];
        });
    }
}
