<?php

declare(strict_types=1);

namespace App\Modules\Support\Models\Concerns;

use App\Core\Audit\Concerns\Auditable;
use Illuminate\Support\Str;

/** Audits plain configuration records under `support.{model}.{verb}`. */
trait AuditsConfig
{
    use Auditable;

    public function auditEventName(string $verb): string
    {
        return 'support.'.Str::snake(class_basename($this)).'.'.$verb;
    }
}
