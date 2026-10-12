<?php

declare(strict_types=1);

namespace App\Modules\Automation\Engine;

use App\Modules\Automation\Graph\ValidationReport;
use Illuminate\Validation\ValidationException;

/** A workflow cannot be published: carries the validator's full report for the builder. */
final class InvalidWorkflow extends ValidationException
{
    public ValidationReport $report;

    public static function from(ValidationReport $report): self
    {
        $messages = [];

        foreach ($report->issues() as $issue) {
            if ($issue['severity'] === 'error') {
                $messages[] = $issue['message'];
            }
        }

        $exception = self::withMessages(['graph' => array_slice(array_values(array_unique($messages)), 0, 10)]);
        $exception->report = $report;

        return $exception;
    }
}
