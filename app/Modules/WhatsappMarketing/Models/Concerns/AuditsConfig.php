<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Models\Concerns;

use App\Core\Audit\Concerns\Auditable;
use Illuminate\Support\Str;

/** Audits configuration records under `whatsapp_marketing.{model}.{verb}`. */
trait AuditsConfig
{
    use Auditable;

    public function auditEventName(string $verb): string
    {
        return 'whatsapp_marketing.'.Str::snake(class_basename($this)).'.'.$verb;
    }
}
