<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\SmsMarketing\Enums\TemplatePurpose;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable message text with variables from the fixed allow-list (see `Support\TemplateVariables`).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property TemplatePurpose $purpose
 * @property string $body
 * @property bool $is_active
 */
class Template extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'sms_marketing_templates';

    /** @var list<string> */
    protected $fillable = ['name', 'purpose', 'body', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['purpose' => TemplatePurpose::class, 'is_active' => 'boolean'];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'sms_marketing.template.'.$verb;
    }

    /** @return list<string> */
    protected function auditExcluded(): array
    {
        return ['body'];
    }
}
