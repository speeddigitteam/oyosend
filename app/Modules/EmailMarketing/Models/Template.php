<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A reusable email design: a list of content blocks, copied into a campaign when it is chosen.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $subject
 * @property string|null $preheader
 * @property list<array<string, mixed>> $blocks
 * @property Carbon|null $updated_at
 */
class Template extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'email_marketing_templates';

    /** @var list<string> */
    protected $fillable = ['name', 'subject', 'preheader', 'blocks'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['blocks' => 'array'];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'email_marketing.template.'.$verb;
    }

    /** @return list<string> */
    protected function auditExcluded(): array
    {
        return ['blocks'];
    }
}
